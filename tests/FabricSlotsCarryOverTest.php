<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ChangeTracker.php';

/**
 * „Kein Platz mehr frei" wird einmal gemeldet und nie als behoben (build 73).
 *
 * Die Zahl der Systeme stammt aus den Annoncen im Netz und ist eine Untergrenze:
 * Fehlt in einem Lauf eine Annonce, zählt das Gerät 4 statt 5. Auf dem nuc kam der
 * Hinweis so dreimal und ging dreimal wieder — 18.09. 19:27/19:44, 25.09. 17:34/
 * 26.09. 09:42, 29.09. 10:18/02.10. 16:29 —, ohne dass ein System entfernt wurde.
 * Der Hinweis ist eine Information, keine Warnung, der man nachgehen muss
 * (Burkhard 02.10.2026). Er bleibt deshalb in der Momentaufnahme stehen, wenn er
 * aus einem Lauf verschwindet: kein „Behoben", und beim nächsten Auftauchen kein
 * zweites „Neuer Befund". Der Bericht zeigt weiter den aktuellen Stand.
 */

$alpstuga = 'ALPSTUGA air quality monitor (Id 13)';
$fp300    = 'Presence Multi-Sensor FP300 (Id 15)';
$voll     = static fn(array $devices): array => [
    'severity' => 'notice',
    'id'       => 'device_fabrics_full',
    'params'   => ['count' => (string)count($devices), 'fabrics' => '5', 'devices' => implode(', ', $devices)],
    'title'    => count($devices) . ' Gerät(e) sind mit 5 Systemen gekoppelt — möglicherweise ist kein Platz mehr frei',
    'devices'  => $devices,
];
$ok   = ['severity' => 'ok', 'id' => 'ipv6_ok', 'params' => [], 'title' => 'ipv6_ok'];
$shot = static fn(array $findings, int $time): array => ChangeTracker::snapshot([], ['DIRIGERA', 'Wohnzimmer'], $findings, $time);
$run  = static function (?array $previous, array $current): array {
    $stored = ChangeTracker::carryOver($previous, $current);

    return [$stored, ChangeTracker::diff($previous, $stored)];
};
$ids = static fn(array $changes): array => array_map(static fn(array $c): string => $c['id'] . ':' . ($c['params']['finding'] ?? ''), $changes);

// --- Erstes Auftreten wird gemeldet ---------------------------------------------
$leer = $shot([$ok], 1000);
[$stored1, $changes1] = $run($leer, $shot([$ok, $voll([$alpstuga])], 2000));
assertSame(['finding_new:device_fabrics_full'], $ids($changes1), 'Erstes Auftreten von „kein Platz mehr frei" wird gemeldet');

// --- Der Fall vom nuc: Annonce fehlt, Hinweis verschwindet ------------------------
[$stored2, $changes2] = $run($stored1, $shot([$ok], 3000));
assertSame([], $changes2, 'Verschwundener Hinweis meldet kein „Behoben"');
assertSame('notice', $stored2['findings']['device_fabrics_full'] ?? null, 'Der Hinweis bleibt in der Momentaufnahme stehen');
assertSame(3000, $stored2['time'] ?? null, 'Zeit des neuen Laufs');

// --- Er taucht wieder auf, jetzt mit einem zweiten Gerät ------------------------
[$stored3, $changes3] = $run($stored2, $shot([$ok, $voll([$alpstuga, $fp300])], 4000));
assertSame([], $changes3, 'Wiederauftauchen meldet kein zweites „Neuer Befund"');
assertSame([$alpstuga, $fp300], $stored3['findingDevices']['device_fabrics_full'] ?? null, 'Gespeichert werden die Geräte des aktuellen Laufs');

[, $changes4] = $run($stored3, $shot([$ok], 5000));
assertSame([], $changes4, 'Auch mit zwei Geräten kein „Behoben"');

// --- Andere Befunde werden weiter verglichen --------------------------------------
$route = ['severity' => 'notice', 'id' => 'thread_route_stale', 'params' => [], 'title' => 'thread_route_stale', 'subject' => 'fd89:6b7:bc55::'];
[$stored5, $changes5] = $run($shot([$voll([$alpstuga]), $route], 6000), $shot([$ok], 7000));
assertSame(['finding_resolved:thread_route_stale'], $ids($changes5), 'Andere Befunde melden „Behoben" wie bisher');
assertTrue(!isset($stored5['findings']['thread_route_stale@fd89:6b7:bc55::']), 'Andere Befunde werden nicht übernommen');

// --- Erster Lauf und stummer Lauf bleiben unberührt -------------------------------
$neu = $shot([$ok], 8000);
assertSame($neu, ChangeTracker::carryOver(null, $neu), 'Erster Lauf: unverändert');
