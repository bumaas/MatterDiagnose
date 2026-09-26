<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/ChangeTracker.php';

/**
 * Änderungserkennung zwischen zwei Läufen — die Grundlage des Wächterbetriebs.
 */

$device = static fn(int $nodeId, string $name, bool $visible): array => [
    'nodeId' => $nodeId, 'name' => $name, 'visible' => $visible,
];
$finding = static fn(string $id, string $severity): array => [
    'id' => $id, 'severity' => $severity, 'params' => [],
];

/** @return array<int, string> */
$ids = static function (array $changes): array {
    return array_map(static fn(array $change): string => $change['id'], $changes);
};

// --- Erster Lauf meldet nichts -------------------------------------------
$base = ChangeTracker::snapshot([$device(6, 'Sensor', true)], ['DIRIGERA'], [$finding('ipv6_ok', 'ok')], 1000);
assertSame([], ChangeTracker::diff(null, $base), 'Ohne Vorgänger keine Änderungsmeldung');
assertSame([], ChangeTracker::diff(['version' => 0, 'devices' => []], $base), 'Momentaufnahme fremder Version wird verworfen');
assertSame([], ChangeTracker::diff(['devices' => []], $base), 'Momentaufnahme ohne Version wird verworfen');
assertSame([], ChangeTracker::diff($base, $base), 'Unveränderter Lauf meldet nichts');

// --- Gerät verschwindet und kommt zurück ---------------------------------
$gone    = ChangeTracker::snapshot([$device(6, 'Sensor', false)], ['DIRIGERA'], [$finding('ipv6_ok', 'ok')], 2000);
$changes = ChangeTracker::diff($base, $gone);
assertSame(['device_disappeared'], $ids($changes), 'Verschwundenes Gerät wird gemeldet');
assertSame('Sensor', $changes[0]['params']['name'], 'Gerätename in der Meldung');
assertSame('6', $changes[0]['params']['node'], 'Node-ID in der Meldung');
assertSame(['device_reappeared'], $ids(ChangeTracker::diff($gone, $base)), 'Rückkehr wird gemeldet');

// Erstmals gesehene Geräte lösen keine Meldung aus
$added = ChangeTracker::snapshot(
    [$device(6, 'Sensor', true), $device(9, 'Neu gekoppelt', false)],
    ['DIRIGERA'],
    [$finding('ipv6_ok', 'ok')],
    3000
);
assertSame([], ChangeTracker::diff($base, $added), 'Frisch gekoppeltes Gerät ist keine Änderung');

// --- Border Router --------------------------------------------------------
$noRouter = ChangeTracker::snapshot([$device(6, 'Sensor', true)], [], [$finding('ipv6_ok', 'ok')], 4000);
assertSame(['border_router_gone'], $ids(ChangeTracker::diff($base, $noRouter)), 'Verschwundener Border Router wird gemeldet');
assertSame('DIRIGERA', ChangeTracker::diff($base, $noRouter)[0]['params']['name'], 'Name des Border Routers in der Meldung');
assertSame(['border_router_new'], $ids(ChangeTracker::diff($noRouter, $base)), 'Neuer Border Router wird gemeldet');

// --- Befunde --------------------------------------------------------------
$ok      = ChangeTracker::snapshot([], [], [$finding('thread_prefix_reachable', 'ok')], 5000);
$blocked = ChangeTracker::snapshot([], [], [$finding('thread_prefix_unreachable', 'blocker')], 6000);
$notice  = ChangeTracker::snapshot([], [], [$finding('thread_prefix_unreachable', 'notice')], 7000);

$new = ChangeTracker::diff($ok, $blocked);
assertSame(['finding_new'], $ids($new), 'Neuer Blocker wird gemeldet');
assertSame('thread_prefix_unreachable', $new[0]['params']['finding'], 'Befund-ID in der Meldung');
assertSame('blocker', $new[0]['params']['severity'], 'Schweregrad in der Meldung');
assertSame(['finding_resolved'], $ids(ChangeTracker::diff($blocked, $ok)), 'Behobener Blocker wird gemeldet');
assertSame(['finding_new'], $ids(ChangeTracker::diff($notice, $blocked)), 'Verschlechterung von Hinweis auf Blocker wird gemeldet');
assertSame(['finding_resolved'], $ids(ChangeTracker::diff($blocked, $notice)), 'Verbesserung von Blocker auf Hinweis wird gemeldet');
assertSame([], $ids(ChangeTracker::diff($ok, ChangeTracker::snapshot([], [], [$finding('ipv6_ok', 'ok')], 8000))), 'Ein neuer OK-Befund ist keine Meldung');

// --- Momentaufnahme übersteht den Weg durch das Attribut (JSON) -----------
$roundTrip = json_decode((string)json_encode($base, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
assertSame($base, $roundTrip, 'Momentaufnahme ist JSON-rund');
assertSame([], ChangeTracker::diff($roundTrip, $base), 'Nach JSON-Umlauf keine Scheinänderung');
assertSame(['device_disappeared'], $ids(ChangeTracker::diff($roundTrip, $gone)), 'Nach JSON-Umlauf werden Änderungen erkannt');
assertSame(ChangeTracker::VERSION, $base['version'], 'Momentaufnahme trägt die Versionsnummer');

// --- Review 17.09.2026 -------------------------------------------------------
$try = static function (callable $fn): mixed {
    try {
        return $fn();
    } catch (Throwable $e) {
        return 'Ausnahme: ' . get_class($e);
    }
};
$subjectFinding = static fn(string $id, string $severity, string $subject): array => [
    'id' => $id, 'severity' => $severity, 'params' => [], 'subject' => $subject,
];

// Befunde, die je Präfix oder Route vorkommen, werden je Gegenstand verglichen
$routeA  = ChangeTracker::snapshot([], [], [$subjectFinding('thread_route_stale', 'notice', 'fd89:1::')], 100);
$routeAB = ChangeTracker::snapshot([], [], [$subjectFinding('thread_route_stale', 'notice', 'fd89:1::'), $subjectFinding('thread_route_stale', 'notice', 'fd89:2::')], 200);
$routeB  = ChangeTracker::snapshot([], [], [$subjectFinding('thread_route_stale', 'notice', 'fd89:2::')], 300);
$addB    = ChangeTracker::diff($routeA, $routeAB);
assertSame(['finding_new'], $ids($addB), 'Zweite veraltete Route wird gemeldet');
assertSame('thread_route_stale', $addB[0]['params']['finding'] ?? null, 'Meldung trägt die reine Befund-ID');
assertSame(['finding_new', 'finding_resolved'], $ids(ChangeTracker::diff($routeA, $routeB)), 'Route A behoben, Route B neu: beides gemeldet');

// Ein stummer Lauf (mDNS tot) meldet den Ausfall — aber nicht alle übrigen Befunde als behoben
$healthy = ChangeTracker::snapshot(
    [$device(6, 'Sensor', true)],
    ['DIRIGERA'],
    [$finding('thread_single_border_router', 'notice'), $finding('own_devices_unsubscribed', 'blocker')],
    1000
);
$silent  = ChangeTracker::snapshot([], [], [$finding('mdns_silent', 'blocker')], 2000);
$carried = $try(static fn() => ChangeTracker::carryOver($healthy, $silent));
assertSame(['finding_new'], is_array($carried) ? $ids(ChangeTracker::diff($healthy, $carried)) : $carried, 'Stummer Lauf: nur „mDNS stumm" wird gemeldet');
$again = ChangeTracker::snapshot([$device(6, 'Sensor', true)], ['DIRIGERA'], [$finding('thread_single_border_router', 'notice'), $finding('own_devices_unsubscribed', 'blocker')], 3000);
assertSame(['finding_resolved'], is_array($carried) ? $ids(ChangeTracker::diff($carried, $again)) : $carried, 'Nächster guter Lauf: nur „mDNS stumm" gilt als behoben');
assertSame(2000, is_array($carried) ? ($carried['time'] ?? null) : $carried, 'Übernommene Momentaufnahme trägt die Zeit des stummen Laufs');
assertSame($healthy, $try(static fn() => ChangeTracker::carryOver($healthy, $healthy)), 'Guter Lauf wird unverändert übernommen');
assertSame($silent, $try(static fn() => ChangeTracker::carryOver(null, $silent)), 'Stummer Lauf ohne Vorgänger bleibt, wie er ist');

// Ein Kopplungsfenster auf und zu ist keine Änderung, die eine Benachrichtigung verdient
$closedWindow = ChangeTracker::snapshot([], [], [$finding('no_commissionable', 'notice')], 100);
$openWindow   = ChangeTracker::snapshot([], [], [$finding('commissionable_found', 'ok')], 200);
$cm0Window    = ChangeTracker::snapshot([], [], [$finding('no_commissionable_closed_only', 'notice')], 300);
assertSame([], ChangeTracker::diff($closedWindow, $openWindow), 'Kopplungsfenster öffnet: keine Änderungsmeldung');
assertSame([], ChangeTracker::diff($openWindow, $closedWindow), 'Kopplungsfenster schließt: keine Änderungsmeldung');
assertSame([], ChangeTracker::diff($openWindow, $cm0Window), 'Shelly nach Neustart (CM=0): keine Änderungsmeldung');

// --- Forum t/144417: die Schlafangabe überlebt den nächsten Lauf ---------------------
// Ein vermisstes Gerät annonciert nichts mehr — ob es auf Batterie läuft, weiß nur der
// Vorlauf, in dem es sich noch gemeldet hat.
$sleepySnapshot = ChangeTracker::snapshot(
    [['nodeId' => 18, 'name' => 'Smart Lock Go', 'visible' => true, 'sleepy' => true],
        ['nodeId' => 28, 'name' => 'GRILLPLATS Plug', 'visible' => true, 'sleepy' => false]],
    [],
    [],
    100
);
assertSame(true, $sleepySnapshot['devices'][0]['sleepy'] ?? 'fehlt', 'Momentaufnahme merkt sich „schläft"');
assertSame(false, $sleepySnapshot['devices'][1]['sleepy'] ?? 'fehlt', 'Momentaufnahme merkt sich „am Strom"');
$sleepyGone = ChangeTracker::snapshot(
    [['nodeId' => 18, 'name' => 'Smart Lock Go', 'visible' => false, 'sleepy' => null],
        ['nodeId' => 28, 'name' => 'GRILLPLATS Plug', 'visible' => true, 'sleepy' => false]],
    [],
    [],
    200
);
assertSame(['device_disappeared'], $ids(ChangeTracker::diff($sleepySnapshot, $sleepyGone)), 'Die Schlafangabe allein ist keine Änderung');
$sleepyAbfrage = static function (?array $snapshot): mixed {
    try {
        return ChangeTracker::sleepyByNode($snapshot);
    } catch (Throwable $e) {
        return 'Ausnahme: ' . get_class($e);
    }
};
assertSame([18 => true, 28 => false], $sleepyAbfrage($sleepySnapshot), 'Schlafangaben des Vorlaufs abfragbar');
assertSame([], $sleepyAbfrage(null), 'Ohne Vorlauf keine Angaben');

// --- Build 43: Host je Gerät in der Momentaufnahme ---
// Ein vermisstes Gerät annonciert für Symcon nichts mehr — seinen Host kennt nur der Lauf,
// in dem es zuletzt sichtbar war. Ohne ihn lässt sich nicht sagen, ob es sich noch für
// andere Systeme meldet.
$hostSnapshot = ChangeTracker::snapshot(
    [
        ['nodeId' => 28, 'name' => 'GRILLPLATS Plug', 'visible' => true, 'sleepy' => false, 'host' => 'CA1ACE989841CBEB.local'],
        ['nodeId' => 18, 'name' => 'Smart Lock Go', 'visible' => false, 'sleepy' => true, 'host' => null],
        ['nodeId' => 30, 'name' => 'Ohne Angabe', 'visible' => false],
    ],
    [],
    [],
    1000
);
assertSame('CA1ACE989841CBEB.local', $hostSnapshot['devices'][0]['host'] ?? '(fehlt)', 'Host wandert in die Momentaufnahme');
assertSame([28 => 'CA1ACE989841CBEB.local'], ChangeTracker::hostByNode($hostSnapshot), 'Hosts des Vorlaufs abfragbar, ohne Angabe ausgelassen');
assertSame([], ChangeTracker::hostByNode(null), 'Ohne Vorlauf keine Hosts');

// --- Build 48: Änderungen als Aufzählung (Rainer, Forum t/144417/17) -------------------
// Ohne Aufzählungszeichen klebten drei Änderungen in einer Zeile aneinander, sobald die
// Darstellung der Variablen die Zeilenumbrüche nicht zeigt.
assertSame(
    "• Gerät Shelly Power Strip Gen4 (Id 8) ist wieder zu sehen\n• Gerät Shelly Power Strip Gen4 (Id 9) ist wieder zu sehen",
    ChangeTracker::bulletList(['Gerät Shelly Power Strip Gen4 (Id 8) ist wieder zu sehen', 'Gerät Shelly Power Strip Gen4 (Id 9) ist wieder zu sehen']),
    'Jeder Eintrag beginnt mit einem Aufzählungszeichen, getrennt durch Zeilenumbruch'
);
assertSame('• Ein einziger Eintrag', ChangeTracker::bulletList(['Ein einziger Eintrag']), 'Auch ein einzelner Eintrag bekommt das Zeichen');
assertSame('', ChangeTracker::bulletList([]), 'Keine Änderungen: leerer Text');
assertSame('• A', ChangeTracker::bulletList(['  A  ', '', '   ']), 'Leere Einträge fallen heraus, Leerraum wird getrimmt');

// --- Build 62: Die Änderungsmeldung nennt die betroffenen Geräte (Burkhard 25.09.2026) ---
// „Behoben: 1 gekoppelte(s) Gerät(e) sind nicht mehr erreichbar" sagte nicht, welches.
// Beim behobenen Befund steht die Liste nur noch in der alten Momentaufnahme.
$deviceFinding = static fn(string $id, string $severity, array $devices): array => [
    'id' => $id, 'severity' => $severity, 'params' => [], 'devices' => $devices,
];
$shellys = ['Shelly Dimmer Gen4 (Id 10)', 'Shelly Plug S Gen3 (Id 7)'];
$vorher  = ChangeTracker::snapshot([], [], [$finding('own_devices_visible', 'ok')], 100);
$gestört = ChangeTracker::snapshot([], [], [$deviceFinding('own_devices_announce_missing', 'notice', $shellys)], 200);
$neu     = ChangeTracker::diff($vorher, $gestört);
assertSame('Shelly Dimmer Gen4 (Id 10), Shelly Plug S Gen3 (Id 7)', $neu[0]['params']['devices'] ?? '(fehlt)', 'Neuer Befund nennt seine Geräte');
assertSame('0', $neu[0]['params']['more'] ?? '(fehlt)', 'Bei zwei Geräten bleibt keins ungenannt');
$behoben = ChangeTracker::diff($gestört, $vorher);
assertSame('Shelly Dimmer Gen4 (Id 10), Shelly Plug S Gen3 (Id 7)', $behoben[0]['params']['devices'] ?? '(fehlt)', 'Behobener Befund nennt die Geräte aus dem Vorlauf');
$fünf   = ['A (Id 1)', 'B (Id 2)', 'C (Id 3)', 'D (Id 4)', 'E (Id 5)'];
$vielen = ChangeTracker::diff($vorher, ChangeTracker::snapshot([], [], [$deviceFinding('own_devices_missing', 'notice', $fünf)], 300));
assertSame('A (Id 1), B (Id 2), C (Id 3)', $vielen[0]['params']['devices'] ?? '(fehlt)', 'Genannt werden die ersten drei');
assertSame('2', $vielen[0]['params']['more'] ?? '(fehlt)', 'Die übrigen werden gezählt');
$ohne = ChangeTracker::diff($vorher, ChangeTracker::snapshot([], [], [$finding('thread_single_border_router', 'notice')], 400));
assertSame(false, isset($ohne[0]['params']['devices']), 'Befund ohne Geräte bekommt keine Liste');
// Eine Momentaufnahme von build 61 kennt die Listen noch nicht — kein Fehler, nur ohne Namen
$alt = $gestört;
unset($alt['findingDevices']);
assertSame(['finding_resolved'], $ids(ChangeTracker::diff($alt, $vorher)), 'Alte Momentaufnahme ohne Geräteliste bleibt vergleichbar');
$rund = json_decode((string)json_encode($gestört, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR);
assertSame($gestört, $rund, 'Momentaufnahme mit Gerätelisten ist JSON-rund');

// --- Build 72: ein Gerät, eine Meldung (Burkhard 26.09.2026) ------------------------------
// „Gerät Shelly Plug S Gen3 (Id 7) ist wieder zu sehen" und „Behoben: 1 gekoppelte(s)
// Gerät(e) sind nicht mehr erreichbar — Shelly Plug S Gen3 (Id 7)" standen untereinander.
// Nennt ein neuer oder behobener Befund das Gerät, entfällt die eigene Gerätezeile.
$plug      = ['nodeId' => 7, 'name' => 'Shelly Plug S Gen3', 'visible' => false];
$plugDa    = ['visible' => true] + $plug;
$weg       = ChangeTracker::snapshot([$plug], [], [$deviceFinding('own_devices_unsubscribed', 'blocker', ['Shelly Plug S Gen3 (Id 7)'])], 100);
$wieder    = ChangeTracker::snapshot([$plugDa], [], [$finding('own_devices_visible', 'ok')], 200);
assertSame(['finding_resolved'], $ids(ChangeTracker::diff($weg, $wieder)), 'Rückkehr: nur der behobene Befund, keine zweite Gerätezeile');
assertSame(['finding_new'], $ids(ChangeTracker::diff($wieder, $weg)), 'Ausfall: nur der neue Befund, keine zweite Gerätezeile');
// Bleibt der Befund stehen (ein anderes Gerät fehlt schon), ist die Gerätezeile die einzige Meldung
$dimmer     = ['nodeId' => 10, 'name' => 'Shelly Dimmer Gen4', 'visible' => false];
$einer      = ChangeTracker::snapshot([$plugDa, $dimmer], [], [$deviceFinding('own_devices_unsubscribed', 'blocker', ['Shelly Dimmer Gen4 (Id 10)'])], 300);
$beide      = ChangeTracker::snapshot([$plug, $dimmer], [], [$deviceFinding('own_devices_unsubscribed', 'blocker', ['Shelly Dimmer Gen4 (Id 10)', 'Shelly Plug S Gen3 (Id 7)'])], 400);
assertSame(['device_disappeared'], $ids(ChangeTracker::diff($einer, $beide)), 'Befund besteht fort: Gerätezeile bleibt');
// Nur beim Namen genannte Geräte gelten als abgedeckt — die gezählten behalten ihre Zeile
$viele = [];
$vieleWeg = [];
$namen = [];
foreach ([1, 2, 3, 4] as $n) {
    $viele[]    = ['nodeId' => $n, 'name' => 'G' . $n, 'visible' => true];
    $vieleWeg[] = ['nodeId' => $n, 'name' => 'G' . $n, 'visible' => false];
    $namen[]    = 'G' . $n . ' (Id ' . $n . ')';
}
$vielAus = ChangeTracker::diff(
    ChangeTracker::snapshot($viele, [], [$finding('own_devices_visible', 'ok')], 500),
    ChangeTracker::snapshot($vieleWeg, [], [$deviceFinding('own_devices_missing', 'notice', $namen)], 600)
);
assertSame(['device_disappeared', 'finding_new'], $ids($vielAus), 'Viertes Gerät (nur gezählt) behält seine Zeile');
assertSame('4', $vielAus[0]['params']['node'] ?? '(fehlt)', 'Die verbliebene Zeile gehört dem ungenannten Gerät');
