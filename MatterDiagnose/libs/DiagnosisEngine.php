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
     * Fabric-Plätze, die ein Matter-Gerät mindestens bieten muss (Standard) —
     * und die die allermeisten Geräte genau bieten. Ab so vielen belegten
     * Plätzen ist die Tabelle eines Standardgeräts voll.
     */
    private const FABRIC_SLOTS_TYPICAL = 5;

    /**
     * @param array{
     *     ipv6Addresses: array<int, string>,
     *     mdnsResponses: bool,
     *     mdnsProbeResponders?: int|null,
     *     borderRouters: array<int, array{name: string, host: string, addresses: array<int, string>, source: string, txt: array<string, string>}>,
     *     operationalDevices: array<int, array{instance: string, host: string, addresses: array<int, string>, source: string}>,
     *     commissionableDevices: array<int, array{instance: string, host: string, addresses: array<int, string>, source: string}>,
     *     threadPrefixes: array<string, array{reachable: bool|null, testAddress: string, gateway: string|null, routeExists?: bool|null, pingSkipped?: bool, interface?: string|null}>,
     *     platform: string,
     *     controllerPresent?: bool|null,
     *     ownFabricId?: string|null,
     *     knownDevices?: array<int, array{nodeId: int, name: string, label?: string, subscription: ?string, visible: bool, ambiguous: bool}>,
     *     devicesAmbiguous?: bool,
     *     foreignFabrics?: array<string, int>,
     *     threadNetworks?: array{routers: int, unknown: array<int, string>, networks: array<int, array<string, mixed>>}|null,
     *     routeAssessment?: array{notPersistent: array<int, array<string, mixed>>, stale: array<int, array<string, mixed>>, gatewayUnknown: array<int, array<string, mixed>>}|null
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
            // Mit Hersteller, sonst sagt ein Gerätename wie "Wohnzimmer" nichts
            // darüber aus, welches Gerät im Haus gemeint ist.
            $names      = array_map(
                static function (array $br): string {
                    $vendor = (string)($br['txt']['vn'] ?? '');

                    return $vendor === '' ? $br['name'] : sprintf('%s (%s)', $br['name'], $vendor);
                },
                $input['borderRouters']
            );
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
            // Wächterlauf: ohne Ping bleibt nur die Route als Aussage. Sie zu
            // prüfen ist billig und deckt den häufigsten Dauerbetriebs-Fall ab
            // (Route nach Neustart verloren), ohne schlafende Geräte zu wecken.
            if (($info['pingSkipped'] ?? false) === true && $info['reachable'] === null) {
                $routeExists = $info['routeExists'] ?? null;
                if ($routeExists === true) {
                    $findings[] = self::finding(self::SEVERITY_OK, 'thread_prefix_route_ok', [
                        'prefix' => $prefix,
                    ]);
                } elseif ($routeExists === false) {
                    $findings[] = self::finding(self::SEVERITY_BLOCKER, 'thread_prefix_unreachable', [
                        'prefix'  => $prefix,
                        'command' => OsAdapter::routeAddCommand($input['platform'], $prefix, $info['gateway'], $info['interface'] ?? null),
                    ]);
                } else {
                    $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_prefix_untested', [
                        'prefix' => $prefix,
                    ]);
                }
                continue;
            }
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
                        'command' => OsAdapter::routeAddCommand($input['platform'], $prefix, $info['gateway'], $info['interface'] ?? null),
                    ]);
                }
            } else {
                $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_prefix_untested', [
                    'prefix' => $prefix,
                ]);
            }
        }

        // --- Thread-Netz-Gesundheit und Routenbewertung (ab 0.4) -----------
        $findings = array_merge($findings, self::evaluateThreadNetworks($input));
        $findings = array_merge($findings, self::evaluateRoutes($input));

        // --- Abgleich mit den in Symcon gekoppelten Geräten ----------------
        $findings = array_merge($findings, self::evaluateInventory($input));

        // Bewusst NICHT geprüft (Rücksprache mit paresy, 02.09.2026):
        // - Eigene Controller-Annonce: Für die Geräteanbindung ist sie irrelevant —
        //   Symcon ist als Controller Konsument. Stand 9.1 annonciert nur der Linux-
        //   Stack einen Dummy-Record (…-FFFFFFEFFFFFFFFF, TXT DUMMY), Windows nichts;
        //   mit dem nächsten Symcon-Update wird auf beiden Plattformen ein korrekter
        //   Wert annonciert (relevant für OTA-Firmware-Updates). Höchstens ein
        //   Info-Befund käme dafür in Frage, nie ein Hinweis. Die künftige Matter
        //   Bridge {C6CE0C60-7075-4477-87CD-FADDCB4FB4E4} annonciert sich regulär.
        // - Port-5353-Konkurrenz: Symcon hält den Port nicht selbst, sondern nutzt
        //   Bonjour (Windows) bzw. Avahi (Linux); ohne die startet Symcon nicht.
        //   Bonjour als "Störer" zu melden war falsch und der Rat, es zu stoppen, schädlich.

        return self::sortFindings($findings);
    }

    /**
     * Thread-Netz-Gesundheit aus den Border-Router-Annoncen (ThreadNetwork::assess):
     * ein Netz mit mehreren Routern in einer Partition ist der Sollzustand; ein
     * einzelner Router ist ein Einzelrisiko; mehrere Extended PAN IDs sind getrennte
     * Netze; verschiedene Partitionen oder Zeitstempel im selben Netz sind Störungen.
     *
     * @param array<string, mixed> $input
     * @return array<int, array{severity: string, id: string, params: array<string, string>}>
     */
    private static function evaluateThreadNetworks(array $input): array
    {
        $assessment = $input['threadNetworks'] ?? null;
        if ($assessment === null || (int)($assessment['routers'] ?? 0) === 0) {
            return [];
        }
        $networks = $assessment['networks'] ?? [];
        $findings = [];
        $label    = static fn(array $network): string => $network['name'] !== '' ? (string)$network['name'] : (string)$network['xp'];

        // Gerätenamen immer mit Hersteller ("Wohnzimmer (Apple)"): Der Name
        // allein steht nirgends am Gerät und ist für sich genommen nichtssagend.
        $routerList = static fn(array $network): string => implode(
            ', ',
            $network['routerLabels'] ?? $network['routers'] ?? []
        );

        if (count($networks) >= 2) {
            $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_networks_split', [
                'count'    => (string)count($networks),
                'networks' => implode('; ', array_map(
                    static fn(array $network): string => sprintf('%s über %s', $label($network), $routerList($network)),
                    $networks
                )),
            ]);
        }
        foreach ($networks as $network) {
            if (count($network['partitions']) > 1) {
                $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_partitions', [
                    'network' => $label($network),
                    'count'   => (string)count($network['partitions']),
                    'routers' => $routerList($network),
                ]);
            }
            if (count($network['timestamps']) > 1) {
                $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_dataset_mismatch', [
                    'network' => $label($network),
                    'routers' => $routerList($network),
                ]);
            }
        }
        if ((int)$assessment['routers'] === 1) {
            $name = $networks[0]['routerLabels'][0]
                ?? $networks[0]['routers'][0]
                ?? ($assessment['unknown'][0] ?? '');
            $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_single_border_router', [
                'name' => (string)$name,
            ]);
        } elseif (count($networks) === 1
            && count($networks[0]['routers']) >= 2
            && count($networks[0]['partitions']) === 1
            && count($networks[0]['timestamps']) <= 1) {
            $network    = $networks[0];
            $findings[] = self::finding(self::SEVERITY_OK, 'thread_network_ok', [
                'name'     => $label($network),
                'count'    => (string)count($network['routers']),
                'routers'  => $routerList($network),
                'versions' => implode(', ', $network['versions']),
                'primary'  => (string)($network['primaryBbrLabel'] ?? $network['primaryBbr'] ?? '-'),
            ]);
        }

        return $findings;
    }

    /**
     * Routenbewertung (RouteTable::assess): flüchtige, veraltete und ins Leere
     * zeigende Routen zu Thread-Präfixen — alle als Hinweis, denn akut ist die
     * Kopplung nicht gestört; sie fällt erst beim nächsten Neustart bzw. Wechsel aus.
     *
     * @param array<string, mixed> $input
     * @return array<int, array{severity: string, id: string, params: array<string, string>}>
     */
    private static function evaluateRoutes(array $input): array
    {
        $assessment = $input['routeAssessment'] ?? null;
        if ($assessment === null) {
            return [];
        }
        $platform = (string)($input['platform'] ?? '');
        $findings = [];
        foreach ($assessment['notPersistent'] ?? [] as $route) {
            $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_route_not_persistent', [
                'prefix'  => (string)$route['prefix'],
                'gateway' => (string)$route['gateway'],
                'command' => OsAdapter::routePersistCommand((string)$route['prefix'], (int)$route['length'], (string)$route['gateway'], $route['interface'] ?? null),
            ]);
        }
        foreach ($assessment['stale'] ?? [] as $route) {
            $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_route_stale', [
                'prefix'  => (string)$route['prefix'],
                'gateway' => (string)$route['gateway'],
                'command' => OsAdapter::routeDeleteCommand($platform, (string)$route['prefix'], (int)$route['length'], $route['gateway'], $route['interface'] ?? null),
            ]);
        }
        foreach ($assessment['gatewayUnknown'] ?? [] as $route) {
            $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_route_gateway_unknown', [
                'prefix'  => (string)$route['prefix'],
                'gateway' => (string)$route['gateway'],
                'command' => OsAdapter::routeDeleteCommand($platform, (string)$route['prefix'], (int)$route['length'], $route['gateway'], $route['interface'] ?? null),
            ]);
        }

        return $findings;
    }

    /**
     * Bewertet den Abgleich zwischen den in Symcon gekoppelten Geräten und den
     * Annoncen im Netz. Das ist die Sicht für den laufenden Betrieb: Ein Gerät,
     * das Symcon kennt, sich aber nicht mehr annonciert, ist offline —
     * leere Batterie, außer Reichweite oder Border Router weg.
     *
     * Ohne Angaben zum Controller (Schlüssel fehlt) bleibt der Abschnitt still.
     *
     * @param array<string, mixed> $input
     * @return array<int, array{severity: string, id: string, params: array<string, string>}>
     */
    private static function evaluateInventory(array $input): array
    {
        $controllerPresent = $input['controllerPresent'] ?? null;
        if ($controllerPresent === null) {
            return [];
        }
        if ($controllerPresent === false) {
            return [self::finding(self::SEVERITY_NOTICE, 'no_matter_controller', [])];
        }

        $findings = [];
        $known    = $input['knownDevices'] ?? [];

        if ($known === []) {
            $findings[] = self::finding(self::SEVERITY_OK, 'no_own_devices', []);
        } else {
            if (($input['ownFabricId'] ?? null) === null) {
                $findings[] = self::finding(self::SEVERITY_NOTICE, 'fabric_unknown', []);
            }
            if (($input['devicesAmbiguous'] ?? false) === true) {
                $findings[] = self::finding(self::SEVERITY_NOTICE, 'own_devices_ambiguous', []);
            }

            $missing      = [];
            $unsubscribed = [];
            $states       = [];
            foreach ($known as $device) {
                if (($device['visible'] ?? false) === true) {
                    continue;
                }
                $subscription = $device['subscription'] ?? null;
                // Ein Abonnement, das nicht "OK" meldet, unterscheidet ein
                // stilles Gerät von einem, das Symcon aktiv vermisst.
                if (is_string($subscription) && $subscription !== '' && stripos($subscription, 'OK') !== 0) {
                    $unsubscribed[] = self::deviceLabel($device);
                    $states[]       = $subscription;
                } else {
                    $missing[] = self::deviceLabel($device);
                }
            }

            if ($unsubscribed !== []) {
                $findings[] = self::finding(self::SEVERITY_BLOCKER, 'own_devices_unsubscribed', [
                    'count'   => (string)count($unsubscribed),
                    'devices' => implode(', ', $unsubscribed),
                    'states'  => implode(', ', array_unique($states)),
                ]);
            }
            if ($missing !== []) {
                $findings[] = self::finding(self::SEVERITY_NOTICE, 'own_devices_missing', [
                    'count'   => (string)count($missing),
                    'devices' => implode(', ', $missing),
                ]);
            }
            if ($missing === [] && $unsubscribed === []) {
                $findings[] = self::finding(self::SEVERITY_OK, 'own_devices_visible', [
                    'visible' => (string)count($known),
                    'total'   => (string)count($known),
                ]);
            }
        }

        $findings = array_merge($findings, self::evaluateFabricSlots($known));

        return $findings;
    }

    /**
     * Wie viele Plätze der Fabric-Tabelle sind bei den eigenen Geräten belegt?
     *
     * Ein Matter-Gerät kann nur einer begrenzten Zahl von Systemen gleichzeitig
     * angehören — der Standard verlangt mindestens fünf Plätze, die meisten
     * Geräte haben genau fünf. Ist die Tabelle voll, scheitert jede weitere
     * Kopplung mit einer Meldung, die nicht auf die Ursache zeigt; das Gerät
     * arbeitet ansonsten tadellos, weshalb man von selbst nie darauf kommt.
     *
     * Die Zahl stammt aus den Annoncen im Netz und ist damit eine Untergrenze.
     * Die tatsächliche Kapazität des Geräts kennt nur die Symcon-Konsole
     * (Konfigurator → Info → "Verbundene Systeme (x von y)"); für ein Modul ist
     * sie nicht lesbar. Gewarnt wird deshalb ab dem fünften belegten Platz —
     * dem Punkt, ab dem ein Standardgerät voll ist. Ein Hinweis auf den letzten
     * freien Platz (vier belegt) wäre folgenlos und bleibt bewusst aus.
     *
     * @param array<int, array<string, mixed>> $known
     * @return array<int, array{severity: string, id: string, params: array<string, string>}>
     */
    private static function evaluateFabricSlots(array $known): array
    {
        $full    = [];
        $maxFull = 0;

        foreach ($known as $device) {
            $fabrics = $device['fabrics'] ?? null;
            if (!is_int($fabrics) || $fabrics < self::FABRIC_SLOTS_TYPICAL) {
                continue;
            }
            $full[]  = self::deviceLabelWithEndpoints($device);
            $maxFull = max($maxFull, $fabrics);
        }

        if ($full === []) {
            return [];
        }

        return [self::finding(self::SEVERITY_NOTICE, 'device_fabrics_full', [
            'count'   => (string)count($full),
            'fabrics' => (string)$maxFull,
            'devices' => implode(', ', $full),
        ])];
    }

    /**
     * Beschriftung für die Fabric-Befunde: Produktname und Node-ID, damit die
     * Zeile im Konfigurator auffindbar ist, dazu die Symcon-Namen der Endpunkte,
     * unter denen der Anwender das Gerät kennt.
     *
     * @param array<string, mixed> $device
     */
    private static function deviceLabelWithEndpoints(array $device): string
    {
        // Bewusst nicht das vom Modul vorbereitete "label": Dort hängt die
        // Altersangabe der letzten Daten dran, die hier nichts zur Sache tut.
        $label     = sprintf('%s (Id %d)', (string)($device['name'] ?? ''), (int)($device['nodeId'] ?? 0));
        $endpoints = $device['endpointNames'] ?? [];
        if (!is_array($endpoints) || $endpoints === []) {
            return $label;
        }

        return $label . ' [' . implode(', ', $endpoints) . ']';
    }

    /** "Name (Node 6)" bzw. die vom Modul vorbereitete Beschriftung mit Altersangabe. */
    private static function deviceLabel(array $device): string
    {
        if (isset($device['label']) && $device['label'] !== '') {
            return (string)$device['label'];
        }

        return sprintf('%s (Id %d)', (string)($device['name'] ?? ''), (int)($device['nodeId'] ?? 0));
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
