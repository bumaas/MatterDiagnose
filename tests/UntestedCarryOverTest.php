<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ChangeTracker.php';

/**
 * „Konnte nicht getestet werden" ist keine Aussage über das Thread-Netz (build 64).
 *
 * nuc, 25.09.2026: Der stündliche Wächterlauf pingt nicht und meldet „Weg besteht"
 * (thread_prefix_route_ok). Der erste Handlauf danach bekam aus dem Ping kein Ergebnis
 * (thread_prefix_untested) — die Änderungsmeldung schickte „Neuer Befund", der zweite
 * Handlauf eine Minute später „Behoben". Zwei Push-Meldungen ohne Inhalt.
 *
 * Seither übernimmt ein ungetestetes Netz den Stand des Vorlaufs für genau dieses Präfix,
 * wie ein stummer mDNS-Lauf den ganzen Vorlauf übernimmt (carryOver). Der Bericht zeigt
 * den Hinweis weiter; nur die Änderungsmeldung schweigt.
 */

$prefix  = 'fd89:6b7:bc55::';
$finding = static fn(string $id, string $severity, string $subject = ''): array => [
    'severity' => $severity,
    'id'       => $id,
    'params'   => [],
    'title'    => $id,
] + ($subject === '' ? [] : ['subject' => $subject]);
$shot = static fn(array $findings, int $time): array => ChangeTracker::snapshot([], ['DIRIGERA', 'Wohnzimmer'], $findings, $time);
$run  = static function (?array $previous, array $current): array {
    $stored = ChangeTracker::carryOver($previous, $current);

    return [$stored, ChangeTracker::diff($previous, $stored)];
};
$ids = static fn(array $changes): array => array_map(static fn(array $c): string => $c['id'] . ':' . ($c['params']['finding'] ?? ''), $changes);

// --- Der Fall vom nuc -----------------------------------------------------------
$waechter = $shot([$finding('ipv6_ok', 'ok'), $finding('thread_prefix_route_ok', 'ok', $prefix)], 1000);
$hand1    = $shot([$finding('ipv6_ok', 'ok'), $finding('thread_prefix_untested', 'notice', $prefix)], 2000);
[$stored1, $changes1] = $run($waechter, $hand1);
assertSame([], $changes1, 'Ungetesteter Handlauf nach dem Wächterlauf meldet nichts');
assertSame('ok', $stored1['findings']['thread_prefix_route_ok@' . $prefix] ?? null, 'Gespeichert bleibt die letzte Aussage „Weg besteht"');
assertTrue(!isset($stored1['findings']['thread_prefix_untested@' . $prefix]), 'Das Ungetestet wird nicht gespeichert');
assertSame(2000, $stored1['time'] ?? null, 'Zeit des neuen Laufs');

$hand2 = $shot([$finding('ipv6_ok', 'ok'), $finding('thread_prefix_reachable', 'ok', $prefix)], 3000);
[, $changes2] = $run($stored1, $hand2);
assertSame([], $changes2, 'Der erfolgreiche Handlauf danach meldet kein „Behoben"');

// --- Ein roter Stand verschwindet nicht durch einen ungetesteten Lauf -----------
$rot = $shot([$finding('thread_prefix_unreachable', 'blocker', $prefix)], 4000);
[$stored3, $changes3] = $run($rot, $shot([$finding('thread_prefix_untested', 'notice', $prefix)], 5000));
assertSame([], $changes3, 'Kein falsches „Behoben" für „nicht erreichbar"');
assertSame('blocker', $stored3['findings']['thread_prefix_unreachable@' . $prefix] ?? null, 'Der rote Befund bleibt gespeichert');
[, $changes4] = $run($stored3, $shot([$finding('thread_prefix_reachable', 'ok', $prefix)], 6000));
assertSame(['finding_resolved:thread_prefix_unreachable'], $ids($changes4), 'Erst ein echter Test meldet „Behoben"');

// --- Nur dieses Präfix, nur die Erreichbarkeit ----------------------------------
$other  = 'fd24:eaa2:e48b:1::';
$vorher = $shot([
    $finding('thread_prefix_route_ok', 'ok', $prefix),
    $finding('thread_prefix_no_reply', 'notice', $other),
    $finding('thread_route_stale', 'notice', $prefix),
], 7000);
$jetzt  = $shot([
    $finding('thread_prefix_untested', 'notice', $prefix),
    $finding('thread_prefix_reachable', 'ok', $other),
    $finding('own_devices_unsubscribed', 'blocker'),
], 8000);
[$stored5, $changes5] = $run($vorher, $jetzt);
assertSame(
    ['finding_new:own_devices_unsubscribed', 'finding_resolved:thread_prefix_no_reply', 'finding_resolved:thread_route_stale'],
    $ids($changes5),
    'Andere Präfixe, Routenbefunde und Geräte werden weiter verglichen'
);
assertTrue(!isset($stored5['findings']['thread_route_stale@' . $prefix]), 'Die Routenbewertung desselben Präfixes wird nicht übernommen');

// --- Ohne Vorlauf-Aussage bleibt es beim Hinweis ---------------------------------
$neu = $shot([$finding('thread_prefix_untested', 'notice', $prefix)], 9000);
assertSame('notice', ChangeTracker::carryOver($shot([], 8500), $neu)['findings']['thread_prefix_untested@' . $prefix] ?? null, 'Neues Netz ohne frühere Aussage: Hinweis bleibt');
assertSame($neu, ChangeTracker::carryOver(null, $neu), 'Erster Lauf: unverändert');

// --- „Kein ping" ist ein Dauerzustand, keine fehlende Aussage --------------------
$ohnePing = $shot([$finding('thread_prefix_untested_no_ping', 'notice', $prefix)], 9500);
[, $changes6] = $run($waechter, $ohnePing);
assertSame(['finding_new:thread_prefix_untested_no_ping'], $ids($changes6), 'thread_prefix_untested_no_ping wird weiter gemeldet');
