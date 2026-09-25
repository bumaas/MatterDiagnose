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
    /**
     * Aufbau der Momentaufnahme; ältere Stände werden verworfen statt fehlgedeutet.
     * 2 (17.09.2026): Befunde je Gegenstand (Präfix, Route) statt nur je ID.
     */
    public const VERSION = 2;

    /**
     * Befunde, deren Wechsel keine Meldung wert ist: Ob gerade ein Kopplungsfenster
     * offen ist, ändert sich bei jedem Kopplungsversuch und nach jedem Neustart eines
     * Shelly (CM=0) — ohne dass etwas gestört wäre.
     */
    private const UNTRACKED_FINDINGS = ['no_commissionable', 'no_commissionable_closed_only', 'commissionable_found'];

    /** Ohne mDNS enthält ein Lauf keine Aussage über Geräte, Router und übrige Befunde. */
    private const SILENT_FINDING = 'mdns_silent';

    /** So viele Geräte nennt eine Änderungsmeldung beim Namen; die übrigen werden gezählt. */
    public const NAMED_DEVICES = 3;

    /**
     * Baut die Momentaufnahme eines Laufs.
     *
     * @param array<int, array{nodeId: int, name: string, visible: bool, sleepy?: bool|null}> $devices
     * @param array<int, string> $borderRouters
     * Befunde, die je Präfix oder Route auftreten, tragen ein „subject"; ihr Schlüssel
     * ist dann "<id>@<subject>". Sonst verschmölzen zwei veraltete Routen zu einem
     * Eintrag, und das Auftauchen der zweiten bliebe ungemeldet.
     *
     * @param array<int, array{severity: string, id: string, params: array<string, string>, subject?: string}> $findings
     * @return array{version: int, time: int, devices: array<int, array{nodeId: int, name: string, visible: bool}>, borderRouters: array<int, string>, findings: array<string, string>, findingTitles: array<string, string>}
     */
    public static function snapshot(array $devices, array $borderRouters, array $findings, int $time): array
    {
        // „sleepy" wandert mit in die Momentaufnahme, weil ein vermisstes Gerät nichts
        // mehr annonciert: Ob es auf Batterie läuft, weiß nur der Lauf, in dem es sich
        // zuletzt gemeldet hat (Forum t/144417). Für den Vergleich zählt es nicht.
        $slim = [];
        foreach ($devices as $device) {
            $slim[] = [
                'nodeId'  => (int)$device['nodeId'],
                'name'    => (string)$device['name'],
                'visible' => (bool)$device['visible'],
                'sleepy'  => isset($device['sleepy']) ? (bool)$device['sleepy'] : null,
                // Der Host ebenso: Ein Gerät, das sich für Symcon nicht mehr meldet, ist nur
                // über ihn unter den Annoncen anderer Systeme wiederzufinden (build 43).
                'host'    => isset($device['host']) && $device['host'] !== '' ? (string)$device['host'] : null,
            ];
        }

        $routers = array_values(array_unique(array_map('strval', $borderRouters)));
        sort($routers);

        $severities  = [];
        $titles      = [];
        $deviceLists = [];
        foreach ($findings as $finding) {
            if (in_array((string)$finding['id'], self::UNTRACKED_FINDINGS, true)) {
                continue;
            }
            $key              = (string)$finding['id'] . (isset($finding['subject']) ? '@' . $finding['subject'] : '');
            $severities[$key] = (string)$finding['severity'];
            if (isset($finding['title']) && $finding['title'] !== '') {
                $titles[$key] = (string)$finding['title'];
            }
            // Die Geräte eines Befunds wandern mit wie sein Titel: Beim behobenen Befund
            // stehen sie sonst nirgends mehr (build 62).
            if (isset($finding['devices']) && is_array($finding['devices']) && $finding['devices'] !== []) {
                $deviceLists[$key] = array_values(array_map('strval', $finding['devices']));
            }
        }
        ksort($severities);
        ksort($titles);
        ksort($deviceLists);

        return [
            'version'        => self::VERSION,
            'time'           => $time,
            'devices'        => $slim,
            'borderRouters'  => $routers,
            'findings'       => $severities,
            'findingTitles'  => $titles,
            'findingDevices' => $deviceLists,
        ];
    }

    /**
     * Die Momentaufnahme, die nach einem Lauf gespeichert wird. Ein stummer Lauf
     * (mDNS tot) hat nichts über Geräte, Border Router und die übrigen Befunde
     * erfahren — er übernimmt deshalb den Stand des Vorlaufs und ergänzt nur den
     * Ausfall. Sonst meldete ein einzelner Aussetzer alle Befunde als behoben und
     * der nächste gute Lauf alle wieder als neu (Review 17.09.2026).
     *
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    public static function carryOver(?array $previous, array $snapshot): array
    {
        if (!isset($snapshot['findings'][self::SILENT_FINDING])
            || $previous === null
            || ($previous['version'] ?? null) !== self::VERSION) {
            return $snapshot;
        }

        $carried                                   = $previous;
        $carried['time']                           = $snapshot['time'];
        $carried['findings'][self::SILENT_FINDING] = $snapshot['findings'][self::SILENT_FINDING];
        if (isset($snapshot['findingTitles'][self::SILENT_FINDING])) {
            $carried['findingTitles'][self::SILENT_FINDING] = $snapshot['findingTitles'][self::SILENT_FINDING];
        }
        ksort($carried['findings']);
        if (isset($carried['findingTitles'])) {
            ksort($carried['findingTitles']);
        }

        return $carried;
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

        foreach ($newFindings as $key => $severity) {
            $newRank = $rank[$severity] ?? 2;
            if ($newRank === 2) {
                continue; // ein neuer OK-Befund ist keine Meldung wert
            }
            $oldRank = isset($oldFindings[$key]) ? ($rank[$oldFindings[$key]] ?? 2) : 2;
            if ($newRank < $oldRank) {
                $changes[] = [
                    'id'     => 'finding_new',
                    'params' => [
                        'finding'  => self::findingId((string)$key),
                        'severity' => (string)$severity,
                        'title'    => (string)($new['findingTitles'][$key] ?? $key),
                    ] + self::deviceParams($new['findingDevices'][$key] ?? []),
                ];
            }
        }
        foreach ($oldFindings as $key => $severity) {
            $oldRank = $rank[$severity] ?? 2;
            if ($oldRank === 2) {
                continue;
            }
            $newRank = isset($newFindings[$key]) ? ($rank[$newFindings[$key]] ?? 2) : 2;
            if ($newRank > $oldRank) {
                $changes[] = [
                    'id'     => 'finding_resolved',
                    'params' => [
                        'finding' => self::findingId((string)$key),
                        // Der behobene Befund fehlt im neuen Lauf — sein Titel
                        // steht deshalb nur noch in der alten Momentaufnahme.
                        'title'   => (string)($old['findingTitles'][$key] ?? $new['findingTitles'][$key] ?? $key),
                    ] + self::deviceParams($old['findingDevices'][$key] ?? []),
                ];
            }
        }

        return $changes;
    }

    /**
     * Was der Vorlauf über den Energiesparbetrieb der Geräte wusste: Node-ID => schläft.
     * Geräte ohne Angabe fehlen in der Liste.
     *
     * @param array<string, mixed>|null $snapshot
     * @return array<int, bool>
     */
    /**
     * Die Änderungen als Liste für die Statusvariable. Das Aufzählungszeichen ist kein
     * Schmuck: Ob eine Darstellung die Zeilenumbrüche zeigt, hängt an der Option
     * „Mehrere Zeilen" der Variablen — fehlt sie, klebten die Einträge bisher aneinander
     * („… ist wieder zu sehenGerät …", Rainer 18.09.2026). So bleiben sie in jeder
     * Ansicht getrennt.
     *
     * @param array<int, string> $lines
     */
    public static function bulletList(array $lines): string
    {
        $lines = array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== ''));

        return implode("\n", array_map(static fn(string $line): string => '• ' . $line, $lines));
    }

    public static function sleepyByNode(?array $snapshot): array
    {
        $result = [];
        foreach ($snapshot['devices'] ?? [] as $device) {
            if (isset($device['sleepy'])) {
                $result[(int)($device['nodeId'] ?? 0)] = (bool)$device['sleepy'];
            }
        }

        return $result;
    }

    /**
     * Zuletzt gemerkter mDNS-Host je Node-ID aus dem Vorlauf.
     *
     * @return array<int, string>
     */
    public static function hostByNode(?array $snapshot): array
    {
        $result = [];
        foreach ($snapshot['devices'] ?? [] as $device) {
            if (isset($device['host']) && $device['host'] !== '') {
                $result[(int)($device['nodeId'] ?? 0)] = (string)$device['host'];
            }
        }

        return $result;
    }

    /**
     * Die ersten Geräte beim Namen, die übrigen als Zahl — „2 gekoppelte Geräte …" allein
     * sagte nicht, welche (Burkhard 25.09.2026). Ohne Geräte keine Parameter.
     *
     * @param mixed $devices
     * @return array<string, string>
     */
    private static function deviceParams(mixed $devices): array
    {
        if (!is_array($devices) || $devices === []) {
            return [];
        }
        $devices = array_values(array_map('strval', $devices));

        return [
            'devices' => implode(', ', array_slice($devices, 0, self::NAMED_DEVICES)),
            'more'    => (string)max(0, count($devices) - self::NAMED_DEVICES),
        ];
    }

    /** Befund-ID ohne den Gegenstand ("thread_route_stale@fd89::" → "thread_route_stale"). */
    private static function findingId(string $key): string
    {
        return explode('@', $key, 2)[0];
    }
}
