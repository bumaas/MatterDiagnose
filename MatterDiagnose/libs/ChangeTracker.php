<?php

declare(strict_types=1);

require_once __DIR__ . '/DiagnosisEngine.php';

/**
 * Vergleicht zwei Diagnoseläufe miteinander. Erst dadurch wird aus der
 * Momentaufnahme eine Betriebsüberwachung: Gemeldet wird, was sich seit dem
 * letzten Lauf geändert hat — verschwundene Geräte, verschwundene Border
 * Router, neu aufgetretene und behobene Befunde.
 *
 * Reine Funktionen; die Momentaufnahme wird vom Modul als JSON in einem
 * Attribut abgelegt.
 */
class ChangeTracker
{
    /** Aufbau der Momentaufnahme; ältere Stände werden verworfen statt fehlgedeutet. */
    public const VERSION = 1;

    /**
     * Baut die Momentaufnahme eines Laufs.
     *
     * @param array<int, array{nodeId: int, name: string, visible: bool}> $devices
     * @param array<int, string> $borderRouters
     * @param array<int, array{severity: string, id: string, params: array<string, string>}> $findings
     * @return array{version: int, time: int, devices: array<int, array{nodeId: int, name: string, visible: bool}>, borderRouters: array<int, string>, findings: array<string, string>}
     */
    public static function snapshot(array $devices, array $borderRouters, array $findings, int $time): array
    {
        $slim = [];
        foreach ($devices as $device) {
            $slim[] = [
                'nodeId'  => (int)$device['nodeId'],
                'name'    => (string)$device['name'],
                'visible' => (bool)$device['visible'],
            ];
        }

        $routers = array_values(array_unique(array_map('strval', $borderRouters)));
        sort($routers);

        $severities = [];
        $titles     = [];
        foreach ($findings as $finding) {
            $id              = (string)$finding['id'];
            $severities[$id] = (string)$finding['severity'];
            if (isset($finding['title']) && $finding['title'] !== '') {
                $titles[$id] = (string)$finding['title'];
            }
        }
        ksort($severities);
        ksort($titles);

        return [
            'version'       => self::VERSION,
            'time'          => $time,
            'devices'       => $slim,
            'borderRouters' => $routers,
            'findings'      => $severities,
            'findingTitles' => $titles,
        ];
    }

    /**
     * Ermittelt die Änderungen zwischen zwei Momentaufnahmen.
     *
     * Der erste Lauf (kein oder unbrauchbarer Vorgänger) meldet nichts — sonst
     * käme beim Einrichten des Moduls eine Flut von "neu"-Meldungen.
     *
     * @param array<string, mixed>|null $old
     * @param array<string, mixed> $new
     * @return array<int, array{id: string, params: array<string, string>}>
     */
    public static function diff(?array $old, array $new): array
    {
        if ($old === null || ($old['version'] ?? null) !== self::VERSION) {
            return [];
        }

        $changes = [];

        // --- Geräte -------------------------------------------------------
        $oldDevices = [];
        foreach ($old['devices'] ?? [] as $device) {
            $oldDevices[(int)($device['nodeId'] ?? 0)] = $device;
        }
        foreach ($new['devices'] ?? [] as $device) {
            $nodeId = (int)($device['nodeId'] ?? 0);
            if (!isset($oldDevices[$nodeId])) {
                continue; // erstmals gesehen (frisch gekoppelt) — keine Änderungsmeldung
            }
            $wasVisible = (bool)($oldDevices[$nodeId]['visible'] ?? false);
            $isVisible  = (bool)($device['visible'] ?? false);
            if ($wasVisible === $isVisible) {
                continue;
            }
            $changes[] = [
                'id'     => $isVisible ? 'device_reappeared' : 'device_disappeared',
                'params' => [
                    'name' => (string)($device['name'] ?? ''),
                    'node' => (string)$nodeId,
                ],
            ];
        }

        // --- Border Router ------------------------------------------------
        $oldRouters = array_map('strval', $old['borderRouters'] ?? []);
        $newRouters = array_map('strval', $new['borderRouters'] ?? []);
        foreach (array_diff($oldRouters, $newRouters) as $name) {
            $changes[] = ['id' => 'border_router_gone', 'params' => ['name' => $name]];
        }
        foreach (array_diff($newRouters, $oldRouters) as $name) {
            $changes[] = ['id' => 'border_router_new', 'params' => ['name' => $name]];
        }

        // --- Befunde ------------------------------------------------------
        $rank = [
            DiagnosisEngine::SEVERITY_BLOCKER => 0,
            DiagnosisEngine::SEVERITY_NOTICE  => 1,
            DiagnosisEngine::SEVERITY_OK      => 2,
        ];
        $oldFindings = $old['findings'] ?? [];
        $newFindings = $new['findings'] ?? [];

        foreach ($newFindings as $id => $severity) {
            $newRank = $rank[$severity] ?? 2;
            if ($newRank === 2) {
                continue; // ein neuer OK-Befund ist keine Meldung wert
            }
            $oldRank = isset($oldFindings[$id]) ? ($rank[$oldFindings[$id]] ?? 2) : 2;
            if ($newRank < $oldRank) {
                $changes[] = [
                    'id'     => 'finding_new',
                    'params' => [
                        'finding'  => (string)$id,
                        'severity' => (string)$severity,
                        'title'    => (string)($new['findingTitles'][$id] ?? $id),
                    ],
                ];
            }
        }
        foreach ($oldFindings as $id => $severity) {
            $oldRank = $rank[$severity] ?? 2;
            if ($oldRank === 2) {
                continue;
            }
            $newRank = isset($newFindings[$id]) ? ($rank[$newFindings[$id]] ?? 2) : 2;
            if ($newRank > $oldRank) {
                $changes[] = [
                    'id'     => 'finding_resolved',
                    'params' => [
                        'finding' => (string)$id,
                        // Der behobene Befund fehlt im neuen Lauf — sein Titel
                        // steht deshalb nur noch in der alten Momentaufnahme.
                        'title'   => (string)($old['findingTitles'][$id] ?? $new['findingTitles'][$id] ?? $id),
                    ],
                ];
            }
        }

        return $changes;
    }
}
