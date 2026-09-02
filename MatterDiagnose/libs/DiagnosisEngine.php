<?php

declare(strict_types=1);

require_once __DIR__ . '/OsAdapter.php';

/**
 * Bewertet die Erhebungsdaten (mDNS-Funde, Erreichbarkeitstests, Systemdaten)
 * und erzeugt daraus Befunde. Reine Logik ohne Netzwerk- oder Systemzugriffe —
 * vollständig per Unit-Test abgedeckt.
 *
 * Ein Befund besteht aus Schweregrad ('ok' | 'notice' | 'blocker'), einer
 * stabilen ID und Parametern; die Übersetzung in Anzeigetexte übernimmt das
 * Modul.
 */
class DiagnosisEngine
{
    public const SEVERITY_OK      = 'ok';
    public const SEVERITY_NOTICE  = 'notice';
    public const SEVERITY_BLOCKER = 'blocker';

    /**
     * @param array{
     *     ipv6Addresses: array<int, string>,
     *     mdnsResponses: bool,
     *     mdnsProbeResponders?: int|null,
     *     borderRouters: array<int, array{name: string, host: string, addresses: array<int, string>, source: string, txt: array<string, string>}>,
     *     operationalDevices: array<int, array{instance: string, host: string, addresses: array<int, string>, source: string}>,
     *     commissionableDevices: array<int, array{instance: string, host: string, addresses: array<int, string>, source: string}>,
     *     threadPrefixes: array<string, array{reachable: bool|null, testAddress: string, gateway: string|null, routeExists?: bool|null}>,
     *     ownAnnouncement: bool,
     *     platform: string,
     *     port5353Users: array<int, string>
     * } $input
     * @return array<int, array{severity: string, id: string, params: array<string, string>}>
     */
    public static function evaluate(array $input): array
    {
        $findings = [];

        // --- IPv6 auf dem eigenen System ---------------------------------
        $nonLinkLocal = array_values(array_filter(
            $input['ipv6Addresses'],
            static fn(string $address): bool => stripos($address, 'fe80:') !== 0
        ));
        if ($nonLinkLocal === []) {
            $findings[] = self::finding(self::SEVERITY_BLOCKER, 'no_ipv6', []);
        } else {
            $findings[] = self::finding(self::SEVERITY_OK, 'ipv6_ok', [
                'addresses' => implode(', ', array_slice($nonLinkLocal, 0, 3)),
            ]);
        }

        // --- Kam überhaupt mDNS an? ---------------------------------------
        // Die Matter-Abfragen allein können "Multicast tot" nicht von "kein
        // Matter im Netz" unterscheiden (Fehlalarm auf der SymBox Neustadt,
        // 02.09.2026). Dafür steht die allgemeine Probe _services._dns-sd._udp:
        // antwortet darauf jemand, funktioniert mDNS — es gibt nur nichts zu finden.
        if (!$input['mdnsResponses']) {
            $probeResponders = $input['mdnsProbeResponders'] ?? null;
            if ($probeResponders === null || $probeResponders < 1) {
                $findings[] = self::finding(self::SEVERITY_BLOCKER, 'mdns_silent', []);

                return $findings; // ohne mDNS sind alle weiteren Aussagen wertlos
            }
            $findings[] = self::finding(self::SEVERITY_OK, 'mdns_ok', [
                'count' => (string)$probeResponders,
            ]);
        }

        // --- Thread Border Router -----------------------------------------
        if ($input['borderRouters'] === []) {
            $findings[] = self::finding(self::SEVERITY_NOTICE, 'no_border_router', []);
        } else {
            $names      = array_map(static fn(array $br): string => $br['name'], $input['borderRouters']);
            $findings[] = self::finding(self::SEVERITY_OK, 'border_router_found', [
                'count' => (string)count($names),
                'names' => implode(', ', $names),
            ]);
        }

        // --- Sichtbare Matter-Geräte --------------------------------------
        if ($input['commissionableDevices'] !== []) {
            $findings[] = self::finding(self::SEVERITY_OK, 'commissionable_found', [
                'count' => (string)count($input['commissionableDevices']),
                'hosts' => implode(', ', array_map(
                    // Fallback auf das Instanz-Label, solange der Hostname
                    // noch nicht aufgelöst ist
                    static fn(array $device): string => $device['host'] !== ''
                        ? $device['host']
                        : explode('.', $device['instance'])[0],
                    $input['commissionableDevices']
                )),
            ]);
        } else {
            $findings[] = self::finding(self::SEVERITY_NOTICE, 'no_commissionable', []);
        }
        if ($input['operationalDevices'] !== []) {
            $findings[] = self::finding(self::SEVERITY_OK, 'operational_found', [
                'count' => (string)count($input['operationalDevices']),
            ]);
        }

        // --- Erreichbarkeit der Thread-Präfixe ----------------------------
        foreach ($input['threadPrefixes'] as $prefix => $info) {
            if ($info['reachable'] === true) {
                $findings[] = self::finding(self::SEVERITY_OK, 'thread_prefix_reachable', [
                    'prefix' => $prefix,
                ]);
            } elseif ($info['reachable'] === false) {
                if (($info['routeExists'] ?? null) === true) {
                    // Route existiert — ausbleibende Antworten sind bei
                    // schlafenden Thread-Geräten kein Beleg für ein Problem
                    $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_prefix_no_reply', [
                        'prefix' => $prefix,
                    ]);
                } else {
                    $findings[] = self::finding(self::SEVERITY_BLOCKER, 'thread_prefix_unreachable', [
                        'prefix'  => $prefix,
                        'command' => OsAdapter::routeAddCommand($input['platform'], $prefix, $info['gateway']),
                    ]);
                }
            } else {
                $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_prefix_untested', [
                    'prefix' => $prefix,
                ]);
            }
        }

        // --- Annonciert sich der eigene Matter-Stack? ---------------------
        if ($input['ownAnnouncement']) {
            $findings[] = self::finding(self::SEVERITY_OK, 'own_controller_ok', []);
        } else {
            // Nur ein Hinweis: Die Kopplung funktioniert nachweislich auch ohne
            // eigene Annonce (verifiziert 01.09.2026, Windows/Rust — der
            // Controller findet Geräte über eigene Abfragen).
            $findings[] = self::finding(self::SEVERITY_NOTICE, 'own_controller_missing', []);
        }

        // --- Port-5353-Konkurrenz (nur Windows relevant) ------------------
        if (strcasecmp($input['platform'], 'Windows') === 0 && $input['port5353Users'] !== []) {
            $findings[] = self::finding(self::SEVERITY_NOTICE, 'port5353_competition', [
                'processes' => implode(', ', array_unique($input['port5353Users'])),
            ]);
        }

        return self::sortFindings($findings);
    }

    /**
     * Leitet aus den annoncierten Geräteadressen die Thread-Präfixe ab:
     * ULA-Adressen (fd00::/8), deren /64 nicht zu den eigenen On-Link-Präfixen
     * gehört, liegen hinter einem Border Router.
     *
     * @param array<int, string> $deviceAddresses
     * @param array<int, string> $ownAddresses
     * @return array<string, string> Präfix => Beispiel-Adresse
     */
    public static function threadPrefixes(array $deviceAddresses, array $ownAddresses): array
    {
        $ownPrefixes = [];
        foreach ($ownAddresses as $address) {
            $prefix = self::prefix64($address);
            if ($prefix !== null) {
                $ownPrefixes[$prefix] = true;
            }
        }

        $result = [];
        foreach ($deviceAddresses as $address) {
            if (!self::isUla($address)) {
                continue;
            }
            $prefix = self::prefix64($address);
            if ($prefix === null || isset($ownPrefixes[$prefix]) || isset($result[$prefix])) {
                continue;
            }
            $result[$prefix] = $address;
        }

        return $result;
    }

    public static function isUla(string $address): bool
    {
        $binary = @inet_pton($address);

        return is_string($binary) && strlen($binary) === 16 && (ord($binary[0]) & 0xFE) === 0xFC;
    }

    /** Liefert das /64-Präfix in kanonischer Schreibweise oder null bei ungültiger Adresse. */
    public static function prefix64(string $address): ?string
    {
        $binary = @inet_pton($address);
        if (!is_string($binary) || strlen($binary) !== 16) {
            return null;
        }

        return inet_ntop(substr($binary, 0, 8) . str_repeat(chr(0), 8));
    }

    /** @return array{severity: string, id: string, params: array<string, string>} */
    private static function finding(string $severity, string $id, array $params): array
    {
        return ['severity' => $severity, 'id' => $id, 'params' => $params];
    }

    /**
     * Blocker zuerst, dann Hinweise, dann OK — innerhalb der Stufe stabil.
     *
     * @param array<int, array{severity: string, id: string, params: array<string, string>}> $findings
     * @return array<int, array{severity: string, id: string, params: array<string, string>}>
     */
    private static function sortFindings(array $findings): array
    {
        $rank = [self::SEVERITY_BLOCKER => 0, self::SEVERITY_NOTICE => 1, self::SEVERITY_OK => 2];
        usort(
            $findings,
            static fn(array $a, array $b): int => $rank[$a['severity']] <=> $rank[$b['severity']]
        );

        return $findings;
    }
}
