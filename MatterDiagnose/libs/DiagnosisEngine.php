<?php

declare(strict_types=1);

require_once __DIR__ . '/OsAdapter.php';
require_once __DIR__ . '/SymconInventory.php';

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

    /** Urteil über eine Systemeinstellung (siehe sysctlVerdict). */
    private const SYSCTL_BAD     = 'bad';
    private const SYSCTL_GOOD    = 'good';
    private const SYSCTL_MIXED   = 'mixed';
    private const SYSCTL_UNKNOWN = 'unknown';

    /**
     * @param array{
     *     ipv6Addresses: array<int, string>,
     *     mdnsResponses: bool,
     *     mdnsProbeResponders?: int|null,
     *     borderRouters: array<int, array{name: string, host: string, addresses: array<int, string>, source: string, txt: array<string, string>}>,
     *     operationalDevices: array<int, array{instance: string, host: string, addresses: array<int, string>, source: string}>,
     *     commissionableDevices: array<int, array{instance: string, host: string, addresses: array<int, string>, source: string, commissioningMode?: int|null}>,
     *     threadPrefixes: array<string, array{reachable: bool|null, testAddress: string, gateway: string|null, routeExists?: bool|null, pingSkipped?: bool, interface?: string|null}>,
     *     platform: string,
     *     sysctl?: array<string, array<string, int|null>>|null,
     *     controllerPresent?: bool|null,
     *     ownFabricId?: string|null,
     *     knownDevices?: array<int, array{nodeId: int, name: string, label?: string, subscription: ?string, visible: bool, ambiguous: bool, sleepy?: bool|null, host?: ?string, announcedElsewhere?: int}>,
     *     devicesAmbiguous?: bool,
     *     threadNetworks?: array{routers: int, unknown: array<int, string>, networks: array<int, array<string, mixed>>}|null,
     *     routeAssessment?: array{notPersistent: array<int, array<string, mixed>>, stale: array<int, array<string, mixed>>, gatewayUnknown: array<int, array<string, mixed>>, learned?: array<int, array<string, mixed>>}|null
     * } $input
     * @return array<int, array{severity: string, id: string, params: array<string, string>, subject?: string}>
     */
    /**
     * Ist Thread hier überhaupt im Spiel? Ein Border Router im Netz, ein Thread-Netz in
     * der Erhebung oder ein gekoppeltes Gerät ohne IPv4 genügt. Nur dann hängt an IPv6
     * die Funktion — sonst ist es eine Aussage über die Zukunft, kein Blocker.
     *
     * @param array<string, mixed> $input
     */
    private static function threadInvolved(array $input): bool
    {
        if (($input['borderRouters'] ?? []) !== [] || ($input['threadPrefixes'] ?? []) !== []) {
            return true;
        }
        foreach ($input['operationalDevices'] ?? [] as $device) {
            $ipv4 = array_filter(
                $device['addresses'] ?? [],
                static fn(string $address): bool => filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            );
            if (($device['addresses'] ?? []) !== [] && $ipv4 === []) {
                return true;   // annonciert nur IPv6 — typisch für ein Thread-Gerät
            }
        }

        return false;
    }

    /**
     * Beurteilt die IPv6-Einstellungen eines Linux-Systems (OsAdapter::readIpv6Conf).
     *
     * Warum das zählt: Ein Thread Border Router gibt die Route in sein Netz per Router
     * Advertisement bekannt, als Route Information Option. Der Linux-Kernel verwirft die
     * Ansage stillschweigend, wenn eine der drei Einstellungen nicht passt — die Geräte
     * sind dann unerreichbar, ohne dass irgendwo ein Fehler steht. Symcon prüft dasselbe
     * und bietet im Matter-Konfigurator die Korrektur an („Fix Settings“).
     *
     * Bedeutung der Werte laut Kernel-Doku (ip-sysctl, 20.09.2026 nachgelesen):
     * accept_ra 0 = Ansagen ablehnen, 1 = annehmen, solange forwarding aus ist, 2 = auch
     * bei eingeschaltetem forwarding; accept_ra_rt_info_max_plen = größte Präfixlänge, die
     * aus einer Route Information übernommen wird (Vorgabe 0, Symcon setzt 128) — bei
     * einem Wert unter 64 fällt die Route ins Thread-Netz (/64) weg.
     *
     * Geurteilt wird nur, wenn es für Thread überhaupt auf sie ankommt und wenn **jede**
     * betrachtete Schnittstelle betroffen ist; ein einziger guter Wert lässt den Befund
     * entfallen. Das Zusammenspiel von „all“ und der einzelnen Schnittstelle ist je
     * Einstellung verschieden — ein Urteil über den Einzelfall wäre geraten.
     *
     * @param array<string, mixed> $input
     * @return array<int, array{severity: string, id: string, params: array<string, string>}>
     */
    private static function sysctlFindings(array $input): array
    {
        $values = $input['sysctl'] ?? null;
        if (!is_array($values) || $values === [] || !self::threadInvolved($input)) {
            return [];
        }

        // "default" ist nur die Vorlage für künftige Schnittstellen, "lo" die
        // Loopback-Schnittstelle — über beide kommt nie ein Router Advertisement.
        $scopes = array_diff_key($values, array_flip(['default', 'lo']));
        if ($scopes === []) {
            return [];
        }

        $verdicts = [
            'sysctl_ra_ignored' => self::sysctlVerdict(
                $scopes,
                static fn(array $o): ?bool => isset($o['accept_ra']) ? $o['accept_ra'] === 0 : null
            ),
            'sysctl_forwarding' => self::sysctlVerdict($scopes, static function (array $o): ?bool {
                if (!isset($o['accept_ra'], $o['forwarding'])) {
                    return null;
                }

                return $o['forwarding'] === 1 && $o['accept_ra'] !== 2;
            }),
            'sysctl_route_info' => self::sysctlVerdict(
                $scopes,
                static fn(array $o): ?bool => isset($o['accept_ra_rt_info_max_plen'])
                    ? $o['accept_ra_rt_info_max_plen'] < 64
                    : null
            ),
        ];

        // Ist accept_ra 0, sind die übrigen Einstellungen belanglos — ein zweiter
        // Befund daneben verwirrte nur.
        if ($verdicts['sysctl_ra_ignored'] === self::SYSCTL_BAD) {
            return [self::finding(self::SEVERITY_BLOCKER, 'sysctl_ra_ignored', [])];
        }

        $findings = [];
        if ($verdicts['sysctl_forwarding'] === self::SYSCTL_BAD) {
            $findings[] = self::finding(self::SEVERITY_BLOCKER, 'sysctl_forwarding', []);
        }
        if ($verdicts['sysctl_route_info'] === self::SYSCTL_BAD) {
            $lengths    = array_filter(array_column($scopes, 'accept_ra_rt_info_max_plen'), 'is_int');
            $findings[] = self::finding(self::SEVERITY_BLOCKER, 'sysctl_route_info', [
                'value' => (string)($lengths === [] ? 0 : min($lengths)),
            ]);
        }
        if ($findings !== []) {
            return $findings;
        }

        // Entwarnung nur, wenn jede Einstellung eindeutig gut ist. Ein uneinheitliches
        // Bild (eine Schnittstelle gut, eine schlecht) bleibt unbewertet: Wie „all“ und
        // die einzelne Schnittstelle zusammenwirken, ist je Einstellung verschieden.
        return array_values($verdicts) === [self::SYSCTL_GOOD, self::SYSCTL_GOOD, self::SYSCTL_GOOD]
            ? [self::finding(self::SEVERITY_OK, 'sysctl_ok', [])]
            : [];
    }

    /**
     * Wie steht es um eine Einstellung über alle Schnittstellen hinweg? Der Test liefert
     * null, wenn ihm ein Wert fehlt — solche Schnittstellen zählen nicht mit.
     *
     * @param array<string, array<string, int|null>> $scopes
     * @return self::SYSCTL_* bad = überall schlecht, good = überall gut, mixed = uneinheitlich,
     *                        unknown = nichts lesbar
     */
    private static function sysctlVerdict(array $scopes, callable $test): string
    {
        $bad  = 0;
        $good = 0;
        foreach ($scopes as $options) {
            $verdict = $test($options);
            if ($verdict === null) {
                continue;
            }
            $verdict ? $bad++ : $good++;
        }
        if ($bad === 0 && $good === 0) {
            return self::SYSCTL_UNKNOWN;
        }
        if ($bad === 0) {
            return self::SYSCTL_GOOD;
        }

        return $good === 0 ? self::SYSCTL_BAD : self::SYSCTL_MIXED;
    }

    public static function evaluate(array $input): array
    {
        $findings = [];

        // --- IPv6 auf dem eigenen System ---------------------------------
        $nonLinkLocal = array_values(array_filter(
            $input['ipv6Addresses'],
            static fn(string $address): bool => stripos($address, 'fe80:') !== 0
        ));
        if ($nonLinkLocal === []) {
            // Ohne Thread braucht Matter kein IPv6: Ralfs Anlage (Forum t/144417/22,
            // 18.09.2026) führt zwei WLAN-Geräte, die einwandfrei laufen, und bekam
            // trotzdem einen roten Blocker. Ein Befund ohne nötige Handlung ist keiner.
            $findings[] = self::threadInvolved($input)
                ? self::finding(self::SEVERITY_BLOCKER, 'no_ipv6', [])
                : self::finding(self::SEVERITY_NOTICE, 'no_ipv6_no_thread', []);
        } else {
            $findings[] = self::finding(self::SEVERITY_OK, 'ipv6_ok', [
                'addresses' => implode(', ', array_slice($nonLinkLocal, 0, 3)),
            ]);
        }

        // --- IPv6-Einstellungen des Linux-Systems -------------------------
        array_push($findings, ...self::sysctlFindings($input));

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
        // Koppelbereit ist nur, wessen Kopplungsfenster offen ist (CM >= 1). Ein
        // ausdrückliches CM=0 ist Extended Discovery — Shelly annonciert so nach
        // jedem Boot, ohne dass sich das Gerät koppeln ließe. Ohne TXT (null)
        // lässt sich das nicht widerlegen, dann zählt das Gerät weiterhin.
        $openForPairing = array_values(array_filter(
            $input['commissionableDevices'],
            static fn(array $device): bool => ($device['commissioningMode'] ?? null) !== 0
        ));
        if ($openForPairing !== []) {
            $findings[] = self::finding(self::SEVERITY_OK, 'commissionable_found', [
                'count' => (string)count($openForPairing),
                'hosts' => implode(', ', array_map(
                    // Fallback auf das Instanz-Label, solange der Hostname
                    // noch nicht aufgelöst ist
                    static fn(array $device): string => $device['host'] !== ''
                        ? $device['host']
                        : explode('.', $device['instance'])[0],
                    $openForPairing
                )),
            ]);
        } else {
            // Geräte mit ausdrücklich geschlossenem Fenster (CM=0) nennen: Sie leben —
            // wer gerade die Kopplungstaste gedrückt hat, sucht sonst an Strom und WLAN.
            $closed = array_values(array_filter(
                $input['commissionableDevices'],
                static fn(array $device): bool => ($device['commissioningMode'] ?? null) === 0
            ));
            if ($closed !== []) {
                $findings[] = self::finding(self::SEVERITY_NOTICE, 'no_commissionable_closed_only', [
                    'count' => (string)count($closed),
                    'hosts' => implode(', ', array_map(
                        static fn(array $device): string => $device['host'] !== ''
                            ? $device['host']
                            : explode('.', $device['instance'])[0],
                        $closed
                    )),
                ]);
            } else {
                $findings[] = self::finding(self::SEVERITY_NOTICE, 'no_commissionable', []);
            }
        }
        if ($input['operationalDevices'] !== []) {
            // Jedes Gerät annonciert sich einmal je System (Fabric), dem es angehört:
            // 37 Ansagen auf dem nuc waren 13 Geräte in 6 Systemen (18.09.2026). Gezählt
            // werden Geräte je Host (ohne Host zählt die Ansage) und Systeme je Fabric.
            // Controller-Datensätze (reservierte Node-ID, etwa der einer SymBox) sind weder
            // Gerät noch System — sonst stehen 14 Geräte über einer Liste mit 13.
            $hosts         = [];
            $systems       = [];
            $announcements = 0;
            foreach ($input['operationalDevices'] as $device) {
                $parsed = SymconInventory::parseOperationalName((string)$device['instance']);
                if ($parsed !== null && $parsed['reserved']) {
                    continue;
                }
                $host = strtolower((string)($device['host'] ?? ''));
                $hosts[$host !== '' ? $host : strtolower((string)$device['instance'])] = true;
                if ($parsed !== null) {
                    $systems[$parsed['fabric']] = true;
                }
                $announcements++;
            }
            $findings[] = self::finding(self::SEVERITY_OK, 'operational_found', [
                'count'         => (string)count($hosts),
                'announcements' => (string)$announcements,
                'systems'       => (string)count($systems),
            ]);
        }

        // --- Erreichbarkeit der Thread-Präfixe ----------------------------
        foreach ($input['threadPrefixes'] as $prefix => $info) {
            // Netzname und Adressbereich gehören in eine Angabe: Sonst ist im
            // Bericht einmal von "MyHome2081938520" und einmal von
            // "fd89:6b7:bc55::" die Rede, ohne dass erkennbar wäre, dass
            // dasselbe Netz gemeint ist.
            $prefixLabel = self::prefixLabel($prefix, $info['network'] ?? null);
            // Wächterlauf: ohne Ping bleibt nur die Route als Aussage. Sie zu
            // prüfen ist billig und deckt den häufigsten Dauerbetriebs-Fall ab
            // (Route nach Neustart verloren), ohne schlafende Geräte zu wecken.
            if (($info['pingSkipped'] ?? false) === true && $info['reachable'] === null) {
                $routeExists = $info['routeExists'] ?? null;
                if ($routeExists === true) {
                    $findings[] = self::finding(self::SEVERITY_OK, 'thread_prefix_route_ok', [
                        'prefix' => $prefixLabel,
                    ], $prefix);
                } elseif ($routeExists === false) {
                    $findings[] = self::finding(self::SEVERITY_BLOCKER, 'thread_prefix_unreachable', [
                        'prefix'  => $prefixLabel,
                        'command' => OsAdapter::routeAddCommand($input['platform'], $prefix, $info['gateway'], $info['interface'] ?? null),
                    ], $prefix);
                } else {
                    $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_prefix_untested', [
                        'prefix' => $prefixLabel,
                    ], $prefix);
                }
                continue;
            }
            if ($info['reachable'] === true) {
                $findings[] = self::finding(self::SEVERITY_OK, 'thread_prefix_reachable', [
                    'prefix' => $prefixLabel,
                ], $prefix);
            } elseif ($info['reachable'] === false) {
                if (($info['routeExists'] ?? null) === true) {
                    // Route existiert — ausbleibende Antworten sind bei
                    // schlafenden Thread-Geräten kein Beleg für ein Problem
                    $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_prefix_no_reply', [
                        'prefix' => $prefixLabel,
                    ], $prefix);
                } else {
                    $findings[] = self::finding(self::SEVERITY_BLOCKER, 'thread_prefix_unreachable', [
                        'prefix'  => $prefixLabel,
                        'command' => OsAdapter::routeAddCommand($input['platform'], $prefix, $info['gateway'], $info['interface'] ?? null),
                    ], $prefix);
                }
            } else {
                $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_prefix_untested', [
                    'prefix' => $prefixLabel,
                ], $prefix);
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

        // Dieselbe Beschriftung wie bei den Adressbereichen: Netzname und
        // Bereich zusammen. Sonst hieße dasselbe Netz im Bericht einmal
        // "MyHome2081938520" und einmal "fd89:6b7:bc55::". Ohne Namen bleibt
        // die Extended PAN ID als letzte Kennung.
        $label = static function (array $network): string {
            $name   = (string)($network['name'] ?? '');
            $prefix = (string)($network['omrPrefixes'][0] ?? '');
            if ($name === '') {
                return (string)$network['xp'];
            }

            return self::prefixLabel($prefix === '' ? $name : $prefix, $name);
        };

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

        // Dieselbe Beschriftung wie bei der Erreichbarkeit: Netzname und
        // Adressbereich zusammen, damit im Bericht erkennbar bleibt, dass von
        // demselben Netz die Rede ist. Bei veralteten Routen ist kein Netz mehr
        // bekannt — dort bleibt es beim Adressbereich allein.
        $label = static function (array $route) use ($input): string {
            $prefix = (string)$route['prefix'];

            return self::prefixLabel($prefix, $input['threadPrefixes'][$prefix]['network'] ?? null);
        };
        // Gegenstand für die Änderungserkennung: Präfix und Gateway, denn dasselbe
        // Präfix kann über zwei Border Router geroutet sein.
        $subject = static fn(array $route): string => sprintf(
            '%s/%d via %s',
            (string)$route['prefix'],
            (int)($route['length'] ?? 64),
            (string)$route['gateway']
        );

        foreach ($assessment['learned'] ?? [] as $route) {
            // Ohne bekannte Restlaufzeit (BusyBox auf der SymBox nennt stets
            // "expires 0sec") gibt es nichts zu berichten: Der Befund ist reine
            // Information, und sein Text nennt die Sekunden.
            if ((int)($route['validLifetime'] ?? 0) <= 0) {
                continue;
            }
            $params = [
                'prefix'   => $label($route),
                'gateway'  => (string)$route['gateway'],
                'lifetime' => (string)(int)($route['validLifetime'] ?? 0),
            ];
            // Zwei Befund-IDs statt einer mit Textvariante: Der Hinweis auf den
            // dauerhaften Eintrag muss übersetzbar bleiben (Katalog + locale.json).
            $findings[] = ($route['persistent'] ?? false) === true
                ? self::finding(self::SEVERITY_OK, 'thread_route_learned_with_persistent', $params, $subject($route))
                : self::finding(self::SEVERITY_OK, 'thread_route_learned', $params, $subject($route));
        }
        foreach ($assessment['notPersistent'] ?? [] as $route) {
            $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_route_not_persistent', [
                'prefix'  => $label($route),
                'gateway' => (string)$route['gateway'],
                'command' => OsAdapter::routePersistCommand((string)$route['prefix'], (int)$route['length'], (string)$route['gateway'], $route['interface'] ?? null),
            ], $subject($route));
        }
        foreach ($assessment['stale'] ?? [] as $route) {
            $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_route_stale', [
                'prefix'  => $label($route),
                'gateway' => (string)$route['gateway'],
                'command' => OsAdapter::routeDeleteCommand($platform, (string)$route['prefix'], (int)$route['length'], $route['gateway'], $route['interface'] ?? null),
            ], $subject($route));
        }
        foreach ($assessment['gatewayUnknown'] ?? [] as $route) {
            $findings[] = self::finding(self::SEVERITY_NOTICE, 'thread_route_gateway_unknown', [
                'prefix'  => $label($route),
                'gateway' => (string)$route['gateway'],
                'command' => OsAdapter::routeDeleteCommand($platform, (string)$route['prefix'], (int)$route['length'], $route['gateway'], $route['interface'] ?? null),
            ], $subject($route));
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

            $missing        = [];
            $missingBattery = [];
            $missingStates  = [];
            $silent         = [];
            $silentStates   = [];
            $unsubscribed   = [];
            $states         = [];
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
                } elseif ((int)($device['announcedElsewhere'] ?? 0) > 0) {
                    // Lebt und meldet sich — nur nicht für Symcon (Loerdys GRILLPLATS,
                    // Forum t/144417): kein totes Gerät, sondern eine hakende Kopplung.
                    $silent[]       = self::deviceLabel($device);
                    $silentStates[] = is_string($subscription) && $subscription !== '' ? $subscription : '?';
                } else {
                    // Der Abo-Status gehört in den Befund: Er entscheidet, ob eine
                    // fehlende Annonce überhaupt etwas bedeutet (Forum t/144417).
                    $missing[]       = self::deviceLabel($device);
                    $missingStates[] = is_string($subscription) && $subscription !== '' ? $subscription : '?';
                    if (($device['sleepy'] ?? null) === true) {
                        $missingBattery[] = $device['nodeId'] ?? 0;
                    }
                }
            }

            if ($unsubscribed !== []) {
                $findings[] = self::finding(self::SEVERITY_BLOCKER, 'own_devices_unsubscribed', [
                    'count'   => (string)count($unsubscribed),
                    'devices' => implode(', ', $unsubscribed),
                    'states'  => implode(', ', array_unique($states)),
                ]);
            }
            if ($silent !== []) {
                $findings[] = self::finding(self::SEVERITY_NOTICE, 'own_devices_silent_for_symcon', [
                    'count'   => (string)count($silent),
                    'devices' => implode(', ', $silent),
                    'states'  => implode(', ', array_unique($silentStates)),
                ]);
            }
            if ($missing !== []) {
                // Zwei IDs mit demselben Parametersatz: Die Geräteliste kennzeichnet
                // Batteriegeräte mit 🔋, und nur wenn wirklich eines dabei ist, erklärt der
                // Befundtext das Zeichen. In Loerdys Bericht stand die Erklärung zu einem
                // Zeichen, das nirgends auftauchte (Forum t/144417).
                $params = [
                    'count'   => (string)count($missing),
                    'devices' => implode(', ', $missing),
                    'states'  => implode(', ', array_unique($missingStates)),
                ];
                if ($missingBattery === []) {
                    $findings[] = self::finding(self::SEVERITY_NOTICE, 'own_devices_missing', $params);
                } else {
                    $findings[] = self::finding(self::SEVERITY_NOTICE, 'own_devices_missing_battery', $params);
                }
            }
            if ($missing === [] && $silent === [] && $unsubscribed === []) {
                $findings[] = self::finding(self::SEVERITY_OK, 'own_devices_visible', [
                    'total' => (string)count($known),
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

    /**
     * "MyHome2081938520 (fd89:6b7:bc55::)" — Netzname und Adressbereich in einer
     * Angabe. Der Name stammt aus den Ansagen der Border Router und ist das,
     * was der Anwender in seinen Apps wiedererkennt; der Adressbereich ist die
     * technische Entsprechung, die in den Befehlen auftaucht. Ist kein Name
     * bekannt (etwa bei einer veralteten Route), bleibt der Adressbereich allein.
     */
    private static function prefixLabel(string $prefix, ?string $network): string
    {
        $network = $network === null ? '' : trim($network);

        return $network === '' ? $prefix : sprintf('%s (%s)', $network, $prefix);
    }

    /**
     * "Name (Id 6)" bzw. die vom Modul vorbereitete Beschriftung mit Altersangabe,
     * bei einem Batteriegerät mit 🔋 dahinter. Das Zeichen statt eines Wortes, weil
     * die Engine keine Sprache kennt; erklärt wird es im Befundtext.
     */
    private static function deviceLabel(array $device): string
    {
        $label = isset($device['label']) && $device['label'] !== ''
            ? (string)$device['label']
            : sprintf('%s (Id %d)', (string)($device['name'] ?? ''), (int)($device['nodeId'] ?? 0));

        return ($device['sleepy'] ?? null) === true ? $label . ' 🔋' : $label;
    }

    /**
     * Leitet aus den annoncierten Geräteadressen die Thread-Präfixe ab:
     * ULA-Adressen (fd00::/8), deren /64 nicht zu den eigenen On-Link-Präfixen
     * gehört, liegen hinter einem Border Router.
     *
     * Ein Thread-Präfix muss kein ULA sein (build 45, Rainers Anlage): Delegiert der
     * Heimrouter ein globales Präfix, nimmt sich der Border Router daraus ein /64 als
     * OMR. Solche Präfixe zählen nur mit Beleg — $trustedPrefixes sind die OMR aus den
     * Border-Router-Annoncen und die /64 der Geräte, die ein Border Router stellvertretend
     * annonciert. Ohne Beleg bleibt es bei ULA, sonst würde ein gespiegeltes Nachbarsegment
     * wieder zum „Thread-Netz" (Loerdy, build 37).
     *
     * @param array<int, string> $deviceAddresses
     * @param array<int, string> $ownAddresses
     * @param array<int, string> $trustedPrefixes /64-Präfixe mit Beleg (OMR, Proxy)
     * @return array<string, string> Präfix => Beispiel-Adresse
     */
    public static function threadPrefixes(array $deviceAddresses, array $ownAddresses, array $trustedPrefixes = []): array
    {
        $trusted = array_map('strtolower', $trustedPrefixes);
        $ownPrefixes = [];
        foreach ($ownAddresses as $address) {
            $prefix = self::prefix64($address);
            if ($prefix !== null) {
                $ownPrefixes[$prefix] = true;
            }
        }

        $result = [];
        foreach ($deviceAddresses as $address) {
            $prefix = self::prefix64($address);
            if ($prefix === null || stripos($address, 'fe80:') === 0) {
                continue;
            }
            if (!self::isUla($address) && !in_array(strtolower($prefix), $trusted, true)) {
                continue;
            }
            if (isset($ownPrefixes[$prefix]) || isset($result[$prefix])) {
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

    /**
     * @param string|null $subject Gegenstand bei Befunden, die mehrfach auftreten können
     *                             (Präfix, Route) — die Änderungserkennung vergleicht je Gegenstand
     * @return array{severity: string, id: string, params: array<string, string>, subject?: string}
     */
    private static function finding(string $severity, string $id, array $params, ?string $subject = null): array
    {
        $finding = ['severity' => $severity, 'id' => $id, 'params' => $params];
        if ($subject !== null) {
            $finding['subject'] = $subject;
        }

        return $finding;
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
