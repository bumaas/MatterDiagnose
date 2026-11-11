<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ChangeTracker.php';

/**
 * „Nach dem Namen wurde nicht gefragt“ ist keine Aussage über das Gerät.
 *
 * nuc, 11.11.2026: GRILLPLATS (Id 11) stand seit Tagen als „nicht mehr erreichbar“ da, der
 * Shelly Plug (Id 7) als „im Netz, aber Symcon findet sie nicht“. Im Wächterlauf um 16:13
 * fiel die Nachfrage beim Namen aus, beide wanderten in den Hinweis own_devices_unasked.
 * Die Änderungsmeldung schrieb einen neuen Befund und zweimal „Behoben“, eine Stunde später
 * dasselbe umgekehrt. An den Geräten hatte sich nichts geändert.
 *
 * Titel und Gerätenamen unten sind die der beiden archivierten Meldungen (Variable Changes,
 * 11.11.2026 16:13:32 und 17:13:55).
 *
 * Seither übernimmt ein nicht gefragtes Gerät das Urteil des Vorlaufs, wie das ungetestete
 * Präfix (build 64). Der Bericht zeigt den Hinweis weiter, nur die Änderungsmeldung schweigt.
 */

$grillplats = 'GRILLPLATS Plug (Id 11)';
$shelly     = 'Shelly Plug S Gen3 (Id 7)';
$dimmer     = 'Shelly Dimmer Gen4 (Id 10)';

$titel = [
    'own_devices_unsubscribed'      => '%d gekoppelte(s) Gerät(e) sind nicht mehr erreichbar',
    'own_devices_unsubscribed_ping' => '%d gekoppelte(s) Gerät(e) sind im Netz, aber Symcon findet sie nicht',
    'own_devices_unasked'           => '%d gekoppelte(s) Gerät(e) sagen sich nicht an, nach dem Namen wurde nicht gefragt',
    'own_devices_announce_missing'  => '%d gekoppelte(s) Gerät(e) melden sich nicht mehr an, sind aber im Netzwerk',
];
$finding = static fn(string $id, string $severity, array $devices = []): array => [
    'severity' => $severity,
    'id'       => $id,
    'params'   => [],
    'title'    => sprintf($titel[$id] ?? $id, count($devices)),
] + ($devices === [] ? [] : ['devices' => $devices]);
$geraete = [
    ['nodeId' => 7, 'name' => 'Shelly Plug S Gen3', 'visible' => false],
    ['nodeId' => 10, 'name' => 'Shelly Dimmer Gen4', 'visible' => false],
    ['nodeId' => 11, 'name' => 'GRILLPLATS Plug', 'visible' => false],
];
$shot = static fn(array $findings, int $time): array => ChangeTracker::snapshot($geraete, ['DIRIGERA #666D', 'Wohnzimmer'], $findings, $time);
$run  = static function (?array $previous, array $current): array {
    $stored = ChangeTracker::carryOver($previous, $current);

    return [$stored, ChangeTracker::diff($previous, $stored)];
};
$ids = static fn(array $changes): array => array_map(static fn(array $c): string => $c['id'] . ':' . ($c['params']['finding'] ?? ''), $changes);

// --- Der Fall vom nuc -----------------------------------------------------------
$vorher = $shot([
    $finding('own_devices_unsubscribed', 'blocker', [$grillplats]),
    $finding('own_devices_unsubscribed_ping', 'blocker', [$shelly]),
], 1000);
$ohneFrage = $shot([$finding('own_devices_unasked', 'notice', [$shelly, $grillplats])], 2000);
[$stored1, $changes1] = $run($vorher, $ohneFrage);
assertSame([], $ids($changes1), 'Lauf ohne Namensfrage meldet nichts, wenn beide Geräte schon vermisst waren');
assertSame('blocker', $stored1['findings']['own_devices_unsubscribed'] ?? null, '„Nicht mehr erreichbar“ bleibt gespeichert');
assertSame('blocker', $stored1['findings']['own_devices_unsubscribed_ping'] ?? null, '„Im Netz, aber Symcon findet sie nicht“ bleibt gespeichert');
assertSame([$grillplats], $stored1['findingDevices']['own_devices_unsubscribed'] ?? null, 'Das Gerät bleibt bei seinem Befund');
assertSame([$shelly], $stored1['findingDevices']['own_devices_unsubscribed_ping'] ?? null, 'Das zweite Gerät ebenso');
assertTrue(!isset($stored1['findings']['own_devices_unasked']), 'Der Hinweis wird nicht gespeichert');
assertTrue(!isset($stored1['findingTitles']['own_devices_unasked']), 'Sein Titel auch nicht');
assertTrue(!isset($stored1['findingDevices']['own_devices_unasked']), 'Seine Geräteliste auch nicht');
assertSame(2000, $stored1['time'] ?? null, 'Zeit des neuen Laufs');

// Der nächste Lauf fragt wieder und kommt zum alten Urteil: auch dann keine Meldung
[, $changes2] = $run($stored1, $shot([
    $finding('own_devices_unsubscribed', 'blocker', [$grillplats]),
    $finding('own_devices_unsubscribed_ping', 'blocker', [$shelly]),
], 3000));
assertSame([], $ids($changes2), 'Der Lauf mit Namensfrage danach meldet weder „Neuer Befund“ noch „Behoben“');

// Mehrere Läufe ohne Frage hintereinander
[$stored3, $changes3] = $run($stored1, $shot([$finding('own_devices_unasked', 'notice', [$shelly, $grillplats])], 4000));
assertSame([], $ids($changes3), 'Zweiter Lauf ohne Namensfrage: weiter keine Meldung');
assertSame([$grillplats], $stored3['findingDevices']['own_devices_unsubscribed'] ?? null, 'Das Urteil wandert von Lauf zu Lauf mit');

// --- Erst ein Lauf mit Frage meldet „Behoben“ -------------------------------------
[, $changes4] = $run($stored3, $shot([$finding('own_devices_unsubscribed_ping', 'blocker', [$shelly])], 5000));
assertSame(['finding_resolved:own_devices_unsubscribed'], $ids($changes4), 'Ist das Gerät wirklich wieder da, wird es gemeldet');
assertSame($grillplats, $changes4[0]['params']['devices'] ?? null, '„Behoben“ nennt das Gerät');

// --- Ein neu vermisstes Gerät ohne Frage bleibt ein neuer Hinweis ---------------
[$stored5, $changes5] = $run($vorher, $shot([$finding('own_devices_unasked', 'notice', [$shelly, $dimmer, $grillplats])], 6000));
assertSame(['finding_new:own_devices_unasked'], $ids($changes5), 'Ein Gerät ohne Urteil aus dem Vorlauf: der Hinweis wird gemeldet, „Behoben“ nicht');
assertSame('blocker', $stored5['findings']['own_devices_unsubscribed'] ?? null, 'Die alten Urteile bleiben trotzdem stehen');
assertSame('3 gekoppelte(s) Gerät(e) sagen sich nicht an, nach dem Namen wurde nicht gefragt', $changes5[0]['params']['title'] ?? null, 'Titel und Zahl des Hinweises bleiben, wie der Lauf sie schrieb');

// --- Steht der alte Befund auch im neuen Lauf, kommt das Gerät dazu -------------
$gemischt = $shot([
    $finding('own_devices_announce_missing', 'notice', [$dimmer]),
    $finding('own_devices_unasked', 'notice', [$grillplats]),
], 7000);
[$stored6, $changes6] = $run($shot([
    $finding('own_devices_announce_missing', 'notice', [$dimmer]),
    $finding('own_devices_unsubscribed', 'blocker', [$grillplats]),
], 6500), $gemischt);
assertSame([], $ids($changes6), 'Andere Befunde des Laufs stören die Übernahme nicht');
assertSame([$dimmer], $stored6['findingDevices']['own_devices_announce_missing'] ?? null, 'Fremde Gerätelisten bleiben unberührt');

// --- Ein Gerät, das der Vorlauf sah, hat kein Urteil zum Übernehmen -------------
$gesund = $shot([$finding('own_devices_visible', 'ok')], 8000);
$neu    = $shot([$finding('own_devices_unasked', 'notice', [$grillplats])], 9000);
[$stored7, $changes7] = $run($gesund, $neu);
assertSame(['finding_new:own_devices_unasked'], $ids($changes7), 'War das Gerät vorher sichtbar, ist der Hinweis neu');
assertSame('notice', $stored7['findings']['own_devices_unasked'] ?? null, 'Und er bleibt gespeichert');
assertSame($neu, ChangeTracker::carryOver(null, $neu), 'Erster Lauf: unverändert');

// --- Ein früherer Hinweis ist selbst kein Urteil --------------------------------
[, $changes8] = $run($stored7, $shot([$finding('own_devices_unasked', 'notice', [$grillplats])], 10000));
assertSame([], $ids($changes8), 'Derselbe Hinweis im Folgelauf: keine Meldung');
[, $changes9] = $run($stored7, $shot([$finding('own_devices_unsubscribed', 'blocker', [$grillplats])], 11000));
assertSame(['finding_new:own_devices_unsubscribed'], $ids($changes9), 'Das erste Urteil nach dem Hinweis wird gemeldet, der Hinweis selbst gilt nie als „Behoben“');

// Kommt das Gerät zurück, sagt das die Gerätezeile
$zurueck = ChangeTracker::snapshot([['nodeId' => 11, 'name' => 'GRILLPLATS Plug', 'visible' => true]], ['DIRIGERA #666D', 'Wohnzimmer'], [$finding('own_devices_visible', 'ok')], 12000);
$davor   = ChangeTracker::snapshot([['nodeId' => 11, 'name' => 'GRILLPLATS Plug', 'visible' => false]], ['DIRIGERA #666D', 'Wohnzimmer'], [$finding('own_devices_unasked', 'notice', [$grillplats])], 11500);
[, $changes10] = $run($davor, $zurueck);
assertSame(['device_reappeared:'], $ids($changes10), 'Nach dem Hinweis wieder sichtbar: eine Zeile „ist wieder zu sehen“');
