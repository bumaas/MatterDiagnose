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
