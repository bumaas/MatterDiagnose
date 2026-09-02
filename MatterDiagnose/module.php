<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/MdnsBrowser.php';
require_once __DIR__ . '/libs/MatterDiscovery.php';
require_once __DIR__ . '/libs/DiagnosisEngine.php';
require_once __DIR__ . '/libs/OsAdapter.php';
require_once __DIR__ . '/libs/SymconInventory.php';
require_once __DIR__ . '/libs/ChangeTracker.php';
require_once __DIR__ . '/libs/ThreadNetwork.php';
require_once __DIR__ . '/libs/RouteTable.php';

/**
 * Matter Diagnose — prüft die häufigsten Stolpersteine bei der Einbindung von
 * Matter-Geräten (insbesondere Matter over Thread) und übersetzt die Befunde
 * in Klartext samt Handlungsempfehlung.
 *
 * Ab 0.3 zusätzlich für den laufenden Betrieb: Abgleich der in Symcon
 * gekoppelten Geräte mit dem, was sich im Netz annonciert, und ein zyklischer
 * Wächterlauf, der Änderungen gegenüber dem Vorlauf meldet.
 */
class MatterDiagnose extends IPSModuleStrict
{
    private const VAR_IDENT_REPORT          = 'Report';
    private const VAR_IDENT_HEALTHY         = 'Healthy';
    private const VAR_IDENT_KNOWN_DEVICES   = 'KnownDevices';
    private const VAR_IDENT_VISIBLE_DEVICES = 'VisibleDevices';
    private const VAR_IDENT_BORDER_ROUTERS  = 'BorderRouters';
    private const VAR_IDENT_LAST_RUN        = 'LastRun';
    private const VAR_IDENT_CHANGES         = 'Changes';

    private const PROP_MONITOR_INTERVAL = 'MonitorInterval';
    private const ATTR_SNAPSHOT         = 'Snapshot';
    private const TIMER_MONITOR         = 'Monitor';

    /** Zeitbudgets in Sekunden — bewusst unter dem 30-s-Limit der Rust-Edition */
    private const BUDGET_MDNS      = 4.0;
    private const BUDGET_FOLLOW_UP = 2.0;
    private const BUDGET_PROBE     = 2.0;
    private const BUDGET_TOTAL     = 24.0;

    /** DNS-SD-Diensteaufzählung — jeder mDNS-Responder antwortet darauf (RFC 6763, 9). */
    private const SERVICE_ENUMERATION = '_services._dns-sd._udp.local';

    public function Create(): void
    {
        parent::Create();
        // Vorgabe 60 Minuten: Der Wächter soll ohne Zutun laufen (0 = aus bleibt möglich).
        $this->RegisterPropertyInteger(self::PROP_MONITOR_INTERVAL, 60);
        $this->RegisterAttributeString(self::ATTR_SNAPSHOT, '');

        // Wertanzeige statt Schalter: Die Schalterdarstellung setzt eine
        // Variablenaktion voraus, hier wird aber nur angezeigt.
        $this->RegisterVariableBoolean(
            self::VAR_IDENT_HEALTHY,
            $this->Translate('Matter network OK'),
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                'OPTIONS'      => json_encode([
                    ['Value' => false, 'Caption' => $this->Translate('Problem'), 'ColorActive' => true, 'ColorValue' => 0xFF0000],
                    ['Value' => true, 'Caption' => $this->Translate('OK'), 'ColorActive' => true, 'ColorValue' => 0x00FF00],
                ], JSON_THROW_ON_ERROR),
            ],
            10
        );
        $this->RegisterVariableInteger(
            self::VAR_IDENT_KNOWN_DEVICES,
            $this->Translate('Paired devices'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
            20
        );
        $this->RegisterVariableInteger(
            self::VAR_IDENT_VISIBLE_DEVICES,
            $this->Translate('Devices reporting in'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
            30
        );
        $this->RegisterVariableInteger(
            self::VAR_IDENT_BORDER_ROUTERS,
            $this->Translate('Thread border routers'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION],
            40
        );
        $this->RegisterVariableInteger(
            self::VAR_IDENT_LAST_RUN,
            $this->Translate('Last check'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME, 'DATE' => 1, 'TIME' => 2],
            50
        );
        $this->RegisterVariableString(
            self::VAR_IDENT_CHANGES,
            $this->Translate('Last changes'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'MULTILINE' => true],
            60
        );
        $this->RegisterVariableString(
            self::VAR_IDENT_REPORT,
            $this->Translate('Last Report'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_WEB_CONTENT],
            70
        );

        // Timer dürfen nur in Create() entstehen; das Intervall setzt ApplyChanges.
        $this->RegisterTimer(
            self::TIMER_MONITOR,
            0,
            'IPS_RequestAction(' . $this->InstanceID . ", 'Monitor', true);"
        );
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $minutes = max(0, $this->ReadPropertyInteger(self::PROP_MONITOR_INTERVAL));
        $this->SetTimerInterval(self::TIMER_MONITOR, $minutes * 60 * 1000);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'Diagnosis') {
            $this->runDiagnosis(false);

            return;
        }
        if ($Ident === 'Monitor') {
            $this->runDiagnosis(true);

            return;
        }
        throw new InvalidArgumentException('Unbekannte Aktion: ' . $Ident);
    }

    /**
     * @param bool $quick Wächterlauf: kein Ping (schlafende Geräte bleiben in
     *                    Ruhe, das Zeitbudget bleibt klein) und keine
     *                    Formular-Rückmeldung, weil kein Formular offen ist.
     */
    private function runDiagnosis(bool $quick): void
    {
        // Rust-Edition: Wanduhr-Limit von 30 s abschalten (unter C++ ein No-op)
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        $start = microtime(true);

        if (!$quick) {
            $this->UpdateFormField('ProgressText', 'visible', true);
            $this->UpdateFormField('ProgressText', 'caption', $this->Translate('Searching for Matter devices and border routers...'));
        }

        // --- Erhebung -----------------------------------------------------
        $ownIpv6      = OsAdapter::ownIpv6Addresses();
        $ownAddresses = array_merge($ownIpv6, OsAdapter::ownIpv4Addresses());

        $browser   = new MdnsBrowser();
        $responses = [];
        $mdnsOk    = true;
        try {
            $responses = $browser->query(
                [
                    ['name' => MatterDiscovery::SERVICE_MESHCOP, 'type' => MdnsCodec::TYPE_PTR],
                    ['name' => MatterDiscovery::SERVICE_MATTER, 'type' => MdnsCodec::TYPE_PTR],
                    ['name' => MatterDiscovery::SERVICE_COMMISSIONABLE, 'type' => MdnsCodec::TYPE_PTR],
                ],
                self::BUDGET_MDNS
            );
        } catch (RuntimeException $e) {
            $this->LogMessage('mDNS: ' . $e->getMessage(), KL_ERROR);
            $mdnsOk = false;
        }

        // Kein einziger Matter-Dienst? Dann eine allgemeine Probe schicken, um
        // "Multicast blockiert" von "kein Matter im Netz" zu unterscheiden.
        $probeResponders = null;
        if ($mdnsOk && $responses === []) {
            try {
                $probe           = $browser->query(
                    [['name' => self::SERVICE_ENUMERATION, 'type' => MdnsCodec::TYPE_PTR]],
                    self::BUDGET_PROBE,
                    1
                );
                $probeResponders = count(array_unique(array_map(
                    static fn(array $response): string => preg_replace('/:\d+$/', '', $response['from']) ?? $response['from'],
                    $probe
                )));
            } catch (RuntimeException $e) {
                $this->LogMessage('mDNS-Probe: ' . $e->getMessage(), KL_WARNING);
            }
        }

        $survey = MatterDiscovery::collect($responses, $ownAddresses);

        // Fehlende SRV/AAAA-Records gezielt nachfragen. Zwei Runden, weil die
        // Auflösung gestaffelt ist: erst liefert SRV den Hostnamen, dann erst
        // lässt sich dessen AAAA erfragen.
        for ($round = 0; $round < 2 && $mdnsOk; $round++) {
            $followUps = [];
            foreach ($survey['missingSrv'] as $instance) {
                $followUps[] = ['name' => $instance, 'type' => MdnsCodec::TYPE_SRV];
            }
            foreach ($survey['missingAddresses'] as $host) {
                $followUps[] = ['name' => $host, 'type' => MdnsCodec::TYPE_AAAA];
            }
            if ($followUps === []) {
                break;
            }
            try {
                $responses = array_merge(
                    $responses,
                    $browser->query(array_slice($followUps, 0, 20), self::BUDGET_FOLLOW_UP)
                );
                $survey = MatterDiscovery::collect($responses, $ownAddresses);
            } catch (RuntimeException $e) {
                $this->LogMessage('mDNS-Nachfrage: ' . $e->getMessage(), KL_WARNING);
                break;
            }
        }

        // --- Abgleich mit den in Symcon gekoppelten Geräten ----------------
        if (!$quick) {
            $this->UpdateFormField('ProgressText', 'caption', $this->Translate('Comparing with the devices paired in Symcon...'));
        }
        $previous  = $this->readSnapshot();
        $inventory = $this->collectInventory($survey['operationalDevices']);

        // Ein einzelnes verlorenes mDNS-Paket darf keinen Fehlalarm auslösen:
        // Fehlt ein bekanntes Gerät oder ein zuvor gesehener Border Router,
        // wird genau einmal nachgefragt, bevor das Ergebnis zählt.
        if ($mdnsOk && $this->missesSomethingKnown($inventory, $survey, $previous)) {
            try {
                $responses = array_merge($responses, $browser->query(
                    [
                        ['name' => MatterDiscovery::SERVICE_MESHCOP, 'type' => MdnsCodec::TYPE_PTR],
                        ['name' => MatterDiscovery::SERVICE_MATTER, 'type' => MdnsCodec::TYPE_PTR],
                    ],
                    self::BUDGET_MDNS,
                    1
                ));
                $survey    = MatterDiscovery::collect($responses, $ownAddresses);
                $inventory = $this->collectInventory($survey['operationalDevices']);
            } catch (RuntimeException $e) {
                $this->LogMessage('mDNS-Nachfrage (Abgleich): ' . $e->getMessage(), KL_WARNING);
            }
        }

        // --- Thread-Präfixe und deren Erreichbarkeit ----------------------
        if (!$quick) {
            $this->UpdateFormField('ProgressText', 'caption', $this->Translate('Testing reachability of the Thread network...'));
        }
        $allDevices      = array_merge($survey['operationalDevices'], $survey['commissionableDevices']);
        $deviceAddresses = [];
        foreach ($allDevices as $device) {
            foreach ($device['addresses'] as $address) {
                $deviceAddresses[] = $address;
            }
        }
        $prefixes = DiagnosisEngine::threadPrefixes($deviceAddresses, $ownIpv6);
        $gateways = MatterDiscovery::prefixGateways($prefixes, $allDevices, $survey['borderRouters']);
        $platform = OsAdapter::platform();

        $routeTable   = OsAdapter::execute(OsAdapter::routeShowCommand($platform));
        $routes       = RouteTable::parse($platform, $routeTable);
        $lanInterface = RouteTable::interfaceForAddresses($routes, $ownIpv6);
        // Windows hält aktive und persistente Routen getrennt — nur letztere überleben einen Neustart.
        $persistentRoutes = null;
        if ($platform === OsAdapter::PLATFORM_WINDOWS) {
            $persistentRoutes = RouteTable::parse($platform, OsAdapter::execute(OsAdapter::routeShowPersistentCommand()));
        }

        $threadPrefixes = [];
        foreach ($gateways as $prefix => $info) {
            $routeExists = OsAdapter::parseRouteExists($routeTable, $prefix);

            // Kandidaten fürs Anpingen: betriebsbereite Geräte zuerst — die
            // koppelbereiten sind oft Karteileichen früherer Fehlversuche
            $candidates = [];
            foreach ($deviceAddresses as $address) {
                if (DiagnosisEngine::prefix64($address) === $prefix) {
                    $candidates[] = $address;
                }
            }
            $candidates = array_slice(array_unique($candidates), 0, 2);

            $reachable = null;
            foreach ($quick ? [] : $candidates as $address) {
                $remaining = self::BUDGET_TOTAL - (microtime(true) - $start);
                if ($remaining < 5.0) {
                    break; // Budget aufgebraucht — lieber "ungetestet" als Timeout
                }
                // Thread-Endgeräte schlafen — mehrere Versuche mit Geduld
                $output   = OsAdapter::execute(
                    OsAdapter::pingCommand($platform, $address, 5, 2000)
                );
                $received = OsAdapter::parsePingReceived($output);
                if ($received !== null) {
                    $reachable = $received > 0;
                }
                if ($reachable === true) {
                    break;
                }
            }

            $threadPrefixes[$prefix] = [
                'reachable'   => $reachable,
                'testAddress' => $info['testAddress'],
                'gateway'     => $info['gateway'],
                'routeExists' => $routeExists,
                'pingSkipped' => $quick,
                'interface'   => $lanInterface,
            ];
        }

        // --- Thread-Netz-Gesundheit und Routenbewertung -------------------
        $threadNetworks = ThreadNetwork::assess($survey['borderRouters']);
        $prefixesInUse  = array_keys($prefixes);
        foreach ($threadNetworks['networks'] as $network) {
            foreach ($network['omrPrefixes'] as $omrPrefix) {
                $prefixesInUse[] = $omrPrefix;

                // Der Netzname ("MyHome2081938520") steht in den Ansagen der
                // Border Router, der Adressbereich kommt aus den Geräteadressen.
                // Erst zusammen ergeben sie eine Angabe, die der Anwender
                // wiedererkennt — deshalb wandert der Name hier zum Präfix.
                if (isset($threadPrefixes[$omrPrefix]) && ($network['name'] ?? '') !== '') {
                    $threadPrefixes[$omrPrefix]['network'] = (string)$network['name'];
                }
            }
        }
        $borderRouterLinkLocals = [];
        foreach ($survey['borderRouters'] as $router) {
            foreach ($router['addresses'] as $address) {
                if (stripos($address, 'fe80:') === 0) {
                    $borderRouterLinkLocals[] = strtolower($address);
                }
            }
        }
        $routeAssessment = RouteTable::assess(
            $routes,
            $persistentRoutes,
            array_values(array_unique($prefixesInUse)),
            $borderRouterLinkLocals,
            $ownIpv6,
            $platform
        );

        // --- Bewertung ----------------------------------------------------
        $findings = DiagnosisEngine::evaluate([
            'ipv6Addresses'         => $ownIpv6,
            'mdnsResponses'         => $mdnsOk && $responses !== [],
            'mdnsProbeResponders'   => $probeResponders,
            'borderRouters'         => $survey['borderRouters'],
            'operationalDevices'    => $survey['operationalDevices'],
            'commissionableDevices' => $survey['commissionableDevices'],
            'threadPrefixes'        => $threadPrefixes,
            'platform'              => $platform,
            'controllerPresent'     => $inventory['controllerPresent'],
            'ownFabricId'           => $inventory['ownFabricId'],
            'knownDevices'          => $inventory['knownDevices'],
            'devicesAmbiguous'      => $inventory['devicesAmbiguous'],
            'foreignFabrics'        => $inventory['foreignFabrics'],
            'threadNetworks'        => $threadNetworks,
            'routeAssessment'       => $routeAssessment,
        ]);

        // --- Änderungen gegenüber dem letzten Lauf ------------------------
        $borderRouterNames = array_map(
            static fn(array $router): string => $router['name'],
            $survey['borderRouters']
        );
        // Die Titel wandern mit in die Momentaufnahme: Ein behobener Befund
        // fehlt im nächsten Lauf, sein Klartext wäre sonst nicht mehr greifbar.
        $titledFindings = [];
        foreach ($findings as $finding) {
            $titledFindings[] = $finding + ['title' => $this->findingTexts($finding['id'], $finding['params'])['title']];
        }
        $snapshot = ChangeTracker::snapshot($inventory['knownDevices'], $borderRouterNames, $titledFindings, time());
        $changes  = ChangeTracker::diff($previous, $snapshot);
        $this->WriteAttributeString(self::ATTR_SNAPSHOT, json_encode($snapshot, JSON_THROW_ON_ERROR));

        $this->updateStatusVariables($inventory, $borderRouterNames, $findings, $changes);
        $this->showFindings($findings, $changes, $quick);
    }

    /**
     * Sammelt, was Symcon über seine Matter-Geräte weiß, und gleicht es mit den
     * Annoncen im Netz ab.
     *
     * Alle Zugriffe auf die Matter-Kernmodule sind abgesichert: Deren
     * Formularaufbau ist nicht dokumentiert und darf die Diagnose nicht kippen.
     *
     * @param array<int, array{instance: string, host: string, addresses: array<int, string>, source: string}> $operational
     * @return array{controllerPresent: bool, ownFabricId: ?string, knownDevices: array<int, mixed>, devicesAmbiguous: bool, foreignFabrics: array<string, int>}
     */
    private function collectInventory(array $operational): array
    {
        $empty = [
            'controllerPresent' => false,
            'ownFabricId'       => null,
            'knownDevices'      => [],
            'devicesAmbiguous'  => false,
            'foreignFabrics'    => [],
        ];

        $controllers = IPS_GetInstanceListByModuleID(SymconInventory::GUID_CONTROLLER);
        if ($controllers === []) {
            return $empty;
        }
        $controllerId = (int)$controllers[0];

        $fabric = null;
        try {
            $form   = json_decode(IPS_GetConfigurationForm($controllerId), true, 64, JSON_THROW_ON_ERROR);
            $fabric = is_array($form) ? SymconInventory::fabricIdFromControllerForm($form) : null;
        } catch (Throwable $e) {
            $this->LogMessage('Matter-Controller-Formular: ' . $e->getMessage(), KL_WARNING);
        }

        $known = [];
        foreach (IPS_GetInstanceListByModuleID(SymconInventory::GUID_CONFIGURATOR) as $configuratorId) {
            try {
                $form = json_decode(IPS_GetConfigurationForm((int)$configuratorId), true, 64, JSON_THROW_ON_ERROR);
                if (is_array($form)) {
                    $known = array_merge($known, SymconInventory::devicesFromConfiguratorForm($form));
                }
            } catch (Throwable $e) {
                $this->LogMessage('Matter-Konfigurator-Formular: ' . $e->getMessage(), KL_WARNING);
            }
        }
        if ($known === []) {
            // Rückfallweg: die Geräteinstanzen am Controller selbst
            $known = SymconInventory::devicesFromInstances($this->deviceInstances($controllerId));
        }

        $match = SymconInventory::matchDevices($known, $operational, $fabric);
        $usage = SymconInventory::fabricUsage($known, $operational, $fabric);

        // Beschriftung mit Node-ID und Alter der letzten Daten — für die
        // Befundtexte, die die Engine nur noch zusammensetzt. Dazu die Zahl der
        // Fabrics, in denen dasselbe Gerät steckt (Fabric-Tabelle je Gerät).
        $devices = [];
        foreach ($match['devices'] as $device) {
            $device['label']   = $this->deviceLabel($device);
            $device['fabrics'] = $usage[(int)$device['nodeId']] ?? null;
            $devices[]         = $device;
        }

        return [
            'controllerPresent' => true,
            'ownFabricId'       => $fabric,
            'knownDevices'      => $devices,
            'devicesAmbiguous'  => $match['ambiguous'],
            'foreignFabrics'    => $match['foreignFabrics'],
        ];
    }

    /**
     * Fehlt etwas, das eigentlich da sein müsste? Grundlage für die einmalige
     * mDNS-Nachfrage vor dem Urteil.
     *
     * @param array<string, mixed> $inventory
     * @param array<string, mixed> $survey
     * @param array<string, mixed>|null $previous vorherige Momentaufnahme
     */
    private function missesSomethingKnown(array $inventory, array $survey, ?array $previous): bool
    {
        foreach ($inventory['knownDevices'] as $device) {
            if (($device['visible'] ?? false) !== true) {
                return true;
            }
        }

        $current = array_map(static fn(array $router): string => $router['name'], $survey['borderRouters']);
        foreach ($previous['borderRouters'] ?? [] as $name) {
            if (!in_array((string)$name, $current, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Alle Instanzen, die am Matter Controller hängen und eine NodeId tragen —
     * unabhängig davon, welches Gerätemodul sie verwenden.
     *
     * @return array<int, array{instanceId: int, name: string, nodeId: int}>
     */
    private function deviceInstances(int $controllerId): array
    {
        $instances = [];
        foreach (IPS_GetInstanceList() as $instanceId) {
            $instanceId = (int)$instanceId;
            if ((int)IPS_GetInstance($instanceId)['ConnectionID'] !== $controllerId) {
                continue;
            }
            $configuration = json_decode((string)IPS_GetConfiguration($instanceId), true);
            if (!is_array($configuration) || !isset($configuration['NodeId'])) {
                continue;
            }
            $instances[] = [
                'instanceId' => $instanceId,
                'name'       => IPS_GetName($instanceId),
                'nodeId'     => (int)$configuration['NodeId'],
            ];
        }

        return $instances;
    }

    /** "Türsensor (Node 6)" — bei vermissten Geräten mit dem Alter der letzten Daten. */
    private function deviceLabel(array $device): string
    {
        $label = sprintf('%s (Id %d)', (string)$device['name'], (int)$device['nodeId']);
        if (($device['visible'] ?? false) === true) {
            return $label;
        }

        $lastUpdate = $this->lastUpdate((int)($device['instanceId'] ?? 0));
        if ($lastUpdate <= 0) {
            return $label;
        }

        return sprintf(
            '%s (Id %d, %s)',
            (string)$device['name'],
            (int)$device['nodeId'],
            sprintf($this->Translate('last data %s ago'), $this->ageText(time() - $lastUpdate))
        );
    }

    /** Jüngster Zeitstempel unter den Statusvariablen einer Instanz (0 = keine). */
    private function lastUpdate(int $instanceId): int
    {
        if ($instanceId <= 0) {
            return 0;
        }
        $newest = 0;
        foreach (IPS_GetChildrenIDs($instanceId) as $childId) {
            if (!IPS_VariableExists((int)$childId)) {
                continue;
            }
            $newest = max($newest, (int)IPS_GetVariable((int)$childId)['VariableUpdated']);
        }

        return $newest;
    }

    private function ageText(int $seconds): string
    {
        if ($seconds < 3600) {
            return sprintf($this->Translate('%d minutes'), intdiv(max(0, $seconds), 60));
        }
        if ($seconds < 172800) {
            return sprintf($this->Translate('%d hours'), intdiv($seconds, 3600));
        }

        return sprintf($this->Translate('%d days'), intdiv($seconds, 86400));
    }

    /** @return array<string, mixed>|null */
    private function readSnapshot(): ?array
    {
        $raw = $this->ReadAttributeString(self::ATTR_SNAPSHOT);
        if ($raw === '') {
            return null;
        }
        try {
            $snapshot = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @param array<string, mixed> $inventory
     * @param array<int, string> $borderRouters
     * @param array<int, array{severity: string, id: string, params: array<string, string>}> $findings
     * @param array<int, array{id: string, params: array<string, string>}> $changes
     */
    private function updateStatusVariables(array $inventory, array $borderRouters, array $findings, array $changes): void
    {
        $visible = 0;
        foreach ($inventory['knownDevices'] as $device) {
            if (($device['visible'] ?? false) === true) {
                $visible++;
            }
        }
        $blockers = array_filter(
            $findings,
            static fn(array $finding): bool => $finding['severity'] === DiagnosisEngine::SEVERITY_BLOCKER
        );

        $this->SetValue(self::VAR_IDENT_HEALTHY, $blockers === []);
        $this->SetValue(self::VAR_IDENT_KNOWN_DEVICES, count($inventory['knownDevices']));
        $this->SetValue(self::VAR_IDENT_VISIBLE_DEVICES, $visible);
        $this->SetValue(self::VAR_IDENT_BORDER_ROUTERS, count($borderRouters));
        $this->SetValue(self::VAR_IDENT_LAST_RUN, time());

        // Nur bei echten Änderungen schreiben, damit ein Ereignis "bei
        // Aktualisierung" auf dieser Variablen genau dann feuert.
        if ($changes !== []) {
            $lines = array_map(fn(array $change): string => $this->changeText($change['id'], $change['params']), $changes);
            $this->SetValue(self::VAR_IDENT_CHANGES, implode("\n", $lines));
        }
    }

    /**
     * @param array<int, array{severity: string, id: string, params: array<string, string>}> $findings
     * @param array<int, array{id: string, params: array<string, string>}> $changes
     */
    private function showFindings(array $findings, array $changes, bool $quick): void
    {
        $symbols = [
            DiagnosisEngine::SEVERITY_OK      => '✅',
            DiagnosisEngine::SEVERITY_NOTICE  => '⚠️',
            DiagnosisEngine::SEVERITY_BLOCKER => '❌',
        ];

        $rows     = [];
        $commands = [];
        $html     = '<div style="font-family: sans-serif;">';

        if ($changes !== []) {
            $html .= '<p><b>' . htmlspecialchars($this->Translate('Changes since the previous check')) . '</b><br>';
            foreach ($changes as $change) {
                $html .= '• ' . htmlspecialchars($this->changeText($change['id'], $change['params'])) . '<br>';
            }
            $html .= '</p>';
        }

        foreach ($findings as $finding) {
            $texts  = $this->findingTexts($finding['id'], $finding['params']);
            $symbol = $symbols[$finding['severity']];

            $rows[] = [
                'Status'  => $symbol,
                'Finding' => $texts['title'],
                'Details' => $texts['text'],
                'Advice'  => $texts['advice'],
            ];

            // Auszuführende Befehle zusätzlich sammeln: Aus einer Tabellenzelle
            // lässt sich eine netsh-Zeile kaum kopieren.
            $command = trim((string)($finding['params']['command'] ?? ''));
            if ($command !== '' && !in_array($command, $commands, true)) {
                $commands[] = $command;
            }

            $html .= '<p><b>' . $symbol . ' ' . htmlspecialchars($texts['title']) . '</b><br>'
                . nl2br(htmlspecialchars($texts['text']));
            if ($texts['advice'] !== '') {
                $html .= '<br><i>' . nl2br(htmlspecialchars($texts['advice'])) . '</i>';
            }
            $html .= '</p>';
        }
        $html .= '<p style="color: gray;">' . htmlspecialchars(
            sprintf($this->Translate('Diagnosis from %s'), date('d.m.Y H:i:s'))
        ) . '</p></div>';

        $this->SetValue(self::VAR_IDENT_REPORT, $html);

        if ($quick) {
            return; // Wächterlauf: kein Formular offen, das aktualisiert werden könnte
        }
        $this->UpdateFormField('Findings', 'values', json_encode($rows, JSON_THROW_ON_ERROR));
        $this->UpdateFormField('Findings', 'rowCount', max(1, min(12, count($rows))));
        $this->UpdateFormField('Commands', 'value', implode("\n", $commands));
        $this->UpdateFormField('Commands', 'visible', $commands !== []);
        $this->UpdateFormField('ProgressText', 'caption', $this->Translate('Diagnosis finished. The full report is also stored in the "Last Report" variable.'));
    }

    /**
     * Klartext für eine Änderung gegenüber dem vorherigen Lauf.
     *
     * @param array<string, string> $params
     */
    private function changeText(string $id, array $params): string
    {
        $catalog = [
            'device_disappeared' => 'Device %name% (Id %node%) is no longer visible in the network',
            'device_reappeared'  => 'Device %name% (Id %node%) is visible again',
            'border_router_gone' => 'Thread border router %name% has disappeared',
            'border_router_new'  => 'New Thread border router: %name%',
            'finding_new'        => 'New finding: %title%',
            'finding_resolved'   => 'Resolved: %title%',
        ];
        if (!isset($catalog[$id])) {
            return $id;
        }

        $replacements = [];
        foreach ($params as $key => $value) {
            $replacements['%' . $key . '%'] = $value;
        }

        return strtr($this->Translate($catalog[$id]), $replacements);
    }

    /** @return array{title: string, text: string, advice: string} */
    private function findingTexts(string $id, array $params): array
    {
        // Schlüssel sind englische Originaltexte (Übersetzung via locale.json)
        $catalog = [
            'no_ipv6' => [
                'Your system has no IPv6 address',
                'Matter over Thread requires IPv6. Without an IPv6 address on this host, Thread devices are unreachable.',
                'Enable IPv6 on the network adapter and in your router.',
            ],
            'ipv6_ok' => [
                'IPv6 is available',
                'This host has the IPv6 addresses %addresses%.',
                '',
            ],
            'mdns_silent' => [
                'No mDNS responses received',
                'Not a single device in the network answered the broadcast query. Either the network does not allow such queries (e.g. Docker without host network, an isolated VLAN, a guest WLAN), or a firewall on this host discards the replies.',
                'Check whether Symcon runs in a network that permits broadcast queries (Docker: use --network host).',
            ],
            'mdns_ok' => [
                'Device discovery works, but no Matter device reports in',
                '%count% device(s) answered a general search request, so device discovery in the home network (mDNS) works. However, neither a Thread border router nor a Matter device reported in.',
                'If you expect Matter devices here, make sure they are powered and in the same network (VLAN) as Symcon.',
            ],
            'no_border_router' => [
                'No Thread border router found',
                'Devices using Matter over Thread need a Thread border router — the bridge between the Thread radio network and your home network (e.g. IKEA DIRIGERA, Apple HomePod/Apple TV, Google Hub). None reported in. Matter devices using WLAN are not affected.',
                'If you want to use Thread devices, add a border router to the network first.',
            ],
            'border_router_found' => [
                'Thread border router found: %names%',
                '%count% device(s) connect a Thread radio network to your home network.',
                '',
            ],
            'commissionable_found' => [
                '%count% device(s) ready for pairing',
                'These devices are currently open for pairing: %hosts%.',
                '',
            ],
            'no_commissionable' => [
                'No device is currently ready for pairing',
                'No device is currently open for pairing. If you are about to add one, put it into pairing mode first — that window usually closes again after 15 minutes.',
                '',
            ],
            'operational_found' => [
                '%count% Matter device(s) report in',
                'These devices already belong to a system — the one run by Symcon or another one — and are visible in the network.',
                '',
            ],
            'thread_prefix_reachable' => [
                'Thread radio network %prefix% is reachable',
                'This host can reach devices inside the Thread network.',
                '',
            ],
            'thread_prefix_unreachable' => [
                'Thread radio network %prefix% is NOT reachable',
                'The Thread devices sit behind the border router in an address range of their own, and this host has no path into it. Pairing and communication fail even though everything else looks fine. Windows in particular does not learn such paths on its own.',
                'Run the following command with administrator rights, then run the diagnosis again: %command%',
            ],
            'thread_prefix_no_reply' => [
                'Thread radio network %prefix%: path exists, no device answered',
                'This host can reach the Thread network, but no device answered the test. Battery-powered Thread devices sleep most of the time, so this is usually harmless.',
                'If pairing still fails, run the diagnosis again while the device is awake (e.g. right after pressing its button).',
            ],
            'thread_prefix_route_ok' => [
                'Thread radio network %prefix%: path exists',
                'A path into the Thread radio network exists. The monitoring run does not contact any device, so battery devices stay asleep.',
                '',
            ],
            'no_matter_controller' => [
                'No Matter controller in Symcon',
                'This installation has no Matter controller instance, so no devices can be paired. The network findings above still apply.',
                'Add a Matter controller instance if you want to use Matter devices with Symcon.',
            ],
            'no_own_devices' => [
                'No Matter devices paired yet',
                'The Matter controller is present, but no device is paired with it.',
                '',
            ],
            'fabric_unknown' => [
                'Could not read the ID of the Matter system used by Symcon',
                'The controller did not report the ID of its own system. Devices are therefore matched by their device Id alone, across every system in the network.',
                '',
            ],
            'own_devices_visible' => [
                'All paired devices report in (%total%)',
                'Every device paired with Symcon is currently visible in the network.',
                '',
            ],
            'own_devices_missing' => [
                '%count% paired device(s) are not visible in the network',
                'Symcon knows these devices, but they are currently invisible in the network: %devices%. Typical causes are an empty battery, a device out of range, or a border router that is switched off.',
                'Check power and range of these devices. Battery-powered Thread devices can stay silent for a while — repeat the check before replacing anything.',
            ],
            'own_devices_unsubscribed' => [
                '%count% paired device(s) are gone and no longer deliver values',
                'These devices are neither visible in the network nor delivering values: %devices%. The Matter controller reports their connection state as %states%.',
                'Check power and range first. If the device is back but stays silent, open its instance and update the values once.',
            ],
            'own_devices_ambiguous' => [
                'Device assignment is not unique',
                'Without the ID of the system used by Symcon, devices are matched by their device Id alone — and the same Id exists in more than one system in this network. A device counted as visible may in fact belong to another system.',
                '',
            ],
            'device_fabrics_full' => [
                '%count% device(s) are paired with %fabrics% systems — no slot may be left',
                'A Matter device can belong to only a limited number of systems at the same time; the standard requires at least five slots and most devices offer exactly five. These devices are already in %fabrics%: %devices%. Once the table is full, every further pairing fails — with an error message that does not name the cause.',
                'The Matter configurator shows which systems these are: click the info icon in the device row, section "Connected Systems". A system that is no longer needed can be removed there, which frees a slot. The count is taken from what the devices report in the network, so it is a lower bound.',
            ],
            'thread_network_ok' => [
                'Thread radio network %name% is in good shape',
                '%count% devices connect the Thread radio network to your home network: %routers%. They belong to the same network, use the same settings, and the network is not split. The connection into the home network is currently handled by %primary%. Thread version(s): %versions%.',
                '',
            ],
            'thread_single_border_router' => [
                'Only one Thread border router: %name%',
                'Every Thread device depends on this one device that connects the radio network to your home network. If it is switched off, goes to sleep or fails, all Thread devices become unreachable at once.',
                'For redundancy, add a second border router that joins the same Thread network (Apple, Google and IKEA can share the network credentials).',
            ],
            'thread_networks_split' => [
                '%count% separate Thread radio networks',
                'The border routers open up different Thread radio networks: %networks%. A device can only be reached through the border router of its own network, and if that one fails, its whole network is gone.',
                'One shared network is better: let the border routers join the same network by sharing its credentials — or accept the split knowingly.',
            ],
            'thread_partitions' => [
                'Thread radio network %network% has broken into %count% parts',
                'Its border routers (%routers%) report different network parts: the radio network has broken apart, and devices in one part can no longer reach those in the other.',
                'Check power and radio range between the border routers and the devices that relay the network; the parts usually find each other again after a few minutes.',
            ],
            'thread_dataset_mismatch' => [
                'Thread radio network %network%: the border routers use different settings',
                'The border routers (%routers%) report different versions of the network settings. One of them probably still runs an outdated configuration.',
                'Restart the border router with the older settings, or add it to the Thread network again.',
            ],
            'thread_route_not_persistent' => [
                'Path into the Thread radio network %prefix% is not permanent',
                'The path via %gateway% exists only until this host is restarted. After that, pairing and communication fail without any message.',
                'Make the route permanent (administrator rights): %command%',
            ],
            'thread_route_stale' => [
                'Outdated path to %prefix%',
                'This host keeps a path (via %gateway%) into an address range that no border router offers and no device uses any more. The range has probably changed because a border router was reset or replaced. The entry is harmless but misleading.',
                'Remove it: %command%',
            ],
            'thread_route_gateway_unknown' => [
                'Path to %prefix% leads to an unknown device',
                'The route uses the gateway %gateway%, but no current border router has this link-local address. The border router was probably replaced or got a new address, so the route leads nowhere.',
                'Delete the route and let the diagnosis propose a new one: %command%',
            ],
            'thread_prefix_untested' => [
                'Thread network %prefix% could not be tested',
                'The reachability test was skipped (time budget) or its result was inconclusive. Thread devices sleep most of the time, which can hide them from a short test.',
                'Run the diagnosis again.',
            ],
        ];

        if (!isset($catalog[$id])) {
            return ['title' => $id, 'text' => json_encode($params) ?: '', 'advice' => ''];
        }

        $replacements = [];
        foreach ($params as $key => $value) {
            $replacements['%' . $key . '%'] = $value;
        }

        [$title, $text, $advice] = $catalog[$id];

        return [
            'title'  => strtr($this->Translate($title), $replacements),
            'text'   => strtr($this->Translate($text), $replacements),
            'advice' => $advice === '' ? '' : strtr($this->Translate($advice), $replacements),
        ];
    }
}
