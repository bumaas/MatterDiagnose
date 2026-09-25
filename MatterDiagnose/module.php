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
require_once __DIR__ . '/libs/DeviceInventory.php';
require_once __DIR__ . '/libs/DeviceIdentity.php';
require_once __DIR__ . '/libs/RunBudget.php';
require_once __DIR__ . '/libs/ReverseLookup.php';

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
    private const PROP_FABRIC_NAMES     = 'FabricNames';
    private const ATTR_SNAPSHOT         = 'Snapshot';
    private const ATTR_DEVICES          = 'Devices';
    private const TIMER_MONITOR         = 'Monitor';

    /** Zeitbudgets in Sekunden — bewusst unter dem 30-s-Limit der Rust-Edition */
    private const BUDGET_MDNS      = 4.0;
    private const BUDGET_FOLLOW_UP = 2.0;
    private const BUDGET_PROBE     = 2.0;
    private const BUDGET_DIRECT    = 0.5;
    private const BUDGET_IDENTITY  = 1.0;
    private const BUDGET_TOTAL     = 24.0;

    /**
     * Zeit, die dem Erreichbarkeitstest am Ende in jedem Fall bleibt. Optionale
     * Erhebungsschritte tasten sie nicht an (Forum t/144417): Lieber eine Nachfragerunde
     * weniger als ein Lauf ohne Urteil über den Weg ins Thread-Netz.
     */
    private const BUDGET_PING_RESERVE = 7.0;

    /** Namen aus dem Router: Gesamtbudget, Höchstzahl der Abfragen, Reißleine je Abfrage */
    private const BUDGET_REVERSE      = 1.5;
    private const REVERSE_MAX         = 8;
    private const REVERSE_SLOW        = 0.4;

    /** Erreichbarkeitstest: höchstens so viele Versuche mit diesem Timeout je Adresse */
    private const PING_ATTEMPTS   = 5;
    private const PING_TIMEOUT_MS = 2000;

    /** DNS-SD-Diensteaufzählung — jeder mDNS-Responder antwortet darauf (RFC 6763, 9). */
    private const SERVICE_ENUMERATION = '_services._dns-sd._udp.local';

    public function Create(): void
    {
        parent::Create();
        // Vorgabe 60 Minuten: Der Wächter soll ohne Zutun laufen (0 = aus bleibt möglich).
        $this->RegisterPropertyInteger(self::PROP_MONITOR_INTERVAL, 60);
        // Namen für fremde Systeme (Fabrics) — die Annonce nennt nur eine Kennung
        $this->RegisterPropertyString(self::PROP_FABRIC_NAMES, '[]');
        $this->RegisterAttributeString(self::ATTR_SNAPSHOT, '');
        // Geräteliste des letzten Laufs — das Formular baut seine Spalten daraus (ein System je Spalte)
        $this->RegisterAttributeString(self::ATTR_DEVICES, '');

        // Wertanzeige statt Schalter: Die Schalterdarstellung setzt eine
        // Variablenaktion voraus, hier wird aber nur angezeigt.
        $this->RegisterVariableBoolean(
            self::VAR_IDENT_HEALTHY,
            $this->Translate('Matter network OK'),
            [
                'PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION,
                // Jede Option trägt alle Felder, die das Darstellungs-Formular liest —
                // auch die ungenutzten. Fehlt eines, bricht der Dialog der Konsole mit
                // "Ungültiges Formular" ab und die Visu meldet einen Nullwert
                // (Forum t/144417/24; Beleg in tests/fixtures/presentation).
                'OPTIONS'      => json_encode([
                    ['Value' => false, 'Caption' => $this->Translate('Problem'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0xFF0000, 'ContentColorActive' => false, 'ContentColorValue' => -1],
                    ['Value' => true, 'Caption' => $this->Translate('OK'), 'IconActive' => false, 'IconValue' => '', 'ColorActive' => true, 'ColorValue' => 0x00FF00, 'ContentColorActive' => false, 'ContentColorValue' => -1],
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

    /**
     * Die Geräteliste hat je System eine Spalte — wie viele, weiß erst der Lauf. Deshalb
     * entsteht das Formular hier aus form.json plus der gespeicherten Liste des letzten Laufs.
     */
    public function GetConfigurationForm(): string
    {
        $form   = json_decode((string)file_get_contents(__DIR__ . '/form.json'), true, 64, JSON_THROW_ON_ERROR);
        $stored = json_decode($this->ReadAttributeString(self::ATTR_DEVICES), true);
        if (!is_array($stored) || !isset($stored['columns'], $stored['rows'])) {
            return json_encode($form, JSON_THROW_ON_ERROR);
        }
        foreach ($form['actions'] as &$element) {
            if (($element['name'] ?? '') === 'Devices') {
                $element['columns']  = $this->deviceColumns($stored['columns']);
                $element['values']   = $stored['rows'];
                $element['rowCount'] = max(1, min(20, count($stored['rows'])));
            } elseif (($element['name'] ?? '') === 'FabricLegend') {
                $element['caption'] = $this->fabricLegend($stored['columns']);
                $element['visible'] = $stored['columns'] !== [];
            }
        }
        unset($element);

        // Auswahl der Benennungsliste: die fremden Systeme des letzten Laufs plus bereits
        // benannte, die gerade nicht zu sehen sind (sonst verschwände ihr Eintrag aus der Liste)
        $options = [];
        foreach ($stored['columns'] as $column) {
            if (!$column['own']) {
                $options[$column['id']] = DeviceInventory::columnChoice($column);
            }
        }
        foreach ($this->fabricNames() as $fabric => $name) {
            $options[$fabric] ??= $fabric;
        }
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'FabricNames') {
                foreach ($element['columns'] as &$column) {
                    if ($column['name'] === 'Id') {
                        $column['edit']['options'] = array_map(
                            static fn(string $id, string $caption): array => ['caption' => $caption, 'value' => $id],
                            array_keys($options),
                            $options
                        );
                    }
                }
                unset($column);
            }
        }
        unset($element);

        return json_encode($form, JSON_THROW_ON_ERROR);
    }

    /**
     * Vom Anwender benannte Systeme: Compressed Fabric ID => Name.
     *
     * @return array<string, string>
     */
    private function fabricNames(): array
    {
        $names = [];
        $rows  = json_decode($this->ReadPropertyString(self::PROP_FABRIC_NAMES), true);
        foreach (is_array($rows) ? $rows : [] as $row) {
            $id   = strtoupper(trim((string)($row['Id'] ?? '')));
            $name = trim((string)($row['Name'] ?? ''));
            if ($id !== '' && $name !== '') {
                $names[$id] = $name;
            }
        }

        return $names;
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
        $start  = microtime(true);
        $budget = new RunBudget(self::BUDGET_TOTAL, self::BUDGET_PING_RESERVE, $start);

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
        $this->debug('mDNS Erstabfrage', $this->describeResponses($responses));

        // Wer steckt hinter einer Nummer? Andere Dienste desselben Geräts (Shelly, Hue, Cast,
        // HomeKit, ESPHome) nennen Hersteller und Modell. Eigene kurze Runde, damit diese
        // Antworten nicht in das Urteil „mDNS funktioniert" einfließen.
        $identities = [];
        if ($mdnsOk && $budget->phaseAllowed(microtime(true), self::BUDGET_IDENTITY)) {
            try {
                $identityResponses = $browser->query(
                    array_map(static fn(string $service): array => ['name' => $service, 'type' => MdnsCodec::TYPE_PTR], DeviceIdentity::SERVICES),
                    self::BUDGET_IDENTITY,
                    1
                );
                $identities = DeviceIdentity::fromResponses($identityResponses);
                $this->debug('Identitätsdienste', $this->describeResponses($identityResponses));
                $this->debug('Identitäten', array_map(
                    static fn(array $identity): string => sprintf('%s → %s %s (%s)', $identity['host'] !== '' ? $identity['host'] : $identity['instance'], $identity['vendor'], $identity['model'], implode(', ', $identity['addresses'])),
                    $identities
                ));
            } catch (RuntimeException $e) {
                $this->LogMessage('mDNS-Identitätsdienste: ' . $e->getMessage(), KL_WARNING);
            }
        }

        // Kein einziger Matter-Dienst? Dann eine allgemeine Probe schicken, um
        // "Multicast blockiert" von "kein Matter im Netz" zu unterscheiden. Antworten
        // des eigenen Hosts zählen dabei nicht: Bonjour bzw. Avahi beantworten die
        // Anfrage per Multicast-Loopback auch dann, wenn das Netz nichts durchlässt.
        $probeResponders = null;
        if ($mdnsOk && MatterDiscovery::foreignResponses($responses, $ownAddresses) === []) {
            try {
                $probe           = MatterDiscovery::foreignResponses($browser->query(
                    [['name' => self::SERVICE_ENUMERATION, 'type' => MdnsCodec::TYPE_PTR]],
                    self::BUDGET_PROBE,
                    1
                ), $ownAddresses);
                $probeResponders = count(array_unique(array_map(
                    static fn(array $response): string => preg_replace('/:\d+$/', '', $response['from']) ?? $response['from'],
                    $probe
                )));
            } catch (RuntimeException $e) {
                $this->LogMessage('mDNS-Probe: ' . $e->getMessage(), KL_WARNING);
            }
        }

        $survey = MatterDiscovery::collect($responses, $ownAddresses);
        $this->debug('Eigene Adressen', $ownAddresses);

        // Fehlende SRV/AAAA/TXT-Records gezielt nachfragen. Bis zu drei Runden, weil
        // die Auflösung gestaffelt ist: erst liefert SRV den Hostnamen, dann erst
        // lässt sich dessen AAAA erfragen. Die Reihenfolge (Border Router zuerst)
        // bestimmt MatterDiscovery::followUpQuestions; bereits gestellte Fragen
        // kommen nicht wieder (Hosts ohne IPv6 antworten nie auf AAAA), und ohne
        // neue Fragen endet die Schleife vorzeitig. Nur ein Versuch je Runde: Dass eine
        // Nachfrage unbeantwortet bleibt, ist hier der Normalfall (IPv4-Hosts), und ein
        // zweiter Versuch kostet jedes Mal das doppelte Budget.
        $asked = [];
        for ($round = 0; $round < 3 && $mdnsOk; $round++) {
            if (!$budget->phaseAllowed(microtime(true), self::BUDGET_FOLLOW_UP)) {
                $this->debug('mDNS Nachfragen', 'abgebrochen — die Restzeit gehört dem Erreichbarkeitstest');
                break;
            }
            $followUps = MatterDiscovery::followUpQuestions($survey, 20, $asked);
            if ($followUps === []) {
                break;
            }
            array_push($asked, ...$followUps);
            try {
                $answers = $browser->query($followUps, self::BUDGET_FOLLOW_UP, 1);
                $this->debug(sprintf('mDNS Nachfrage %d', $round + 1), sprintf('%d Fragen, %s', count($followUps), $this->describeResponses($answers)));
                array_push($responses, ...$answers);
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
        $previous = $this->readSnapshot();
        // Die Formulare der Matter-Instanzen werden genau einmal je Lauf gelesen. Wie teuer
        // das ist, hängt an der Anlage — deshalb steht die gemessene Dauer im Debug und wird
        // nicht geschätzt (nuc und Testbox 18.09.2026: rund 1 s je Konfigurator).
        $inventarStart = microtime(true);
        $rawInventory  = $this->readInventory();
        $this->debug('Symcon-Inventar gelesen', sprintf(
            '%.1f s für %d Controller, danach %.1f s von %.1f s verbraucht',
            microtime(true) - $inventarStart,
            count($rawInventory['controllers']),
            $budget->elapsed(microtime(true)),
            self::BUDGET_TOTAL
        ));
        $inventory = $this->matchInventory($rawInventory, $survey['operationalDevices']);

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
                    // Diese Runde ist der größte Einzelposten des Laufs (volle mDNS-Zeit) und
                    // läuft nur, wenn etwas fehlt — bei Loerdy also immer. Kürzen, wenn sonst
                    // die Reserve für den Erreichbarkeitstest fiele.
                    $budget->phaseAllowed(microtime(true), self::BUDGET_MDNS) ? self::BUDGET_MDNS : self::BUDGET_FOLLOW_UP,
                    1
                ));
                $survey    = MatterDiscovery::collect($responses, $ownAddresses);
                $inventory = $this->matchInventory($rawInventory, $survey['operationalDevices']);
                $this->debug('mDNS-Abgleichsrunde', sprintf('%.1f s von %.1f s verbraucht', $budget->elapsed(microtime(true)), self::BUDGET_TOTAL));
            } catch (RuntimeException $e) {
                $this->LogMessage('mDNS-Nachfrage (Abgleich): ' . $e->getMessage(), KL_WARNING);
            }
        }

        // Direktabfrage je Border Router: Ein Advertising Proxy darf auf die
        // Multicast-Anfrage auch per Multicast antworten — das kommt an unserem
        // Port nie an. Eine an seine Adresse gerichtete Anfrage beantwortet er
        // unicast. Nur für die Erhebung; fehlende Einträge eines Geräts zeigen
        // sich so je Router (Loerdys stumme GRILLPLATS, Forum t/144417).
        if ($mdnsOk) {
            foreach ($survey['borderRouters'] as $router) {
                $target = $router['source'];
                if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    continue;
                }
                if (!$budget->phaseAllowed(microtime(true), self::BUDGET_DIRECT)) {
                    $this->debug('Direktabfragen', 'abgebrochen — die Restzeit gehört dem Erreichbarkeitstest');
                    break;
                }
                try {
                    $direct = $browser->query(
                        [['name' => MatterDiscovery::SERVICE_MATTER, 'type' => MdnsCodec::TYPE_PTR]],
                        self::BUDGET_DIRECT,
                        1,
                        $target
                    );
                    $this->debug('Direktabfrage ' . $router['name'] . ' (' . $target . ')', $this->describeDirect($direct, $survey['operationalDevices']));
                    if ($direct !== []) {
                        array_push($responses, ...$direct);
                    }
                } catch (RuntimeException $e) {
                    $this->debug('Direktabfrage ' . $router['name'], 'fehlgeschlagen: ' . $e->getMessage());
                }
            }
            $before = count($survey['operationalDevices']);
            $survey = MatterDiscovery::collect($responses, $ownAddresses);
            if (count($survey['operationalDevices']) !== $before) {
                $this->debug('Direktabfragen', sprintf('%d → %d betriebsbereite Geräte', $before, count($survey['operationalDevices'])));
                $inventory = $this->matchInventory($rawInventory, $survey['operationalDevices']);
            }
        }

        // Vermisste eigene Geräte namentlich nachfragen: Ihr Betriebsname steht fest
        // (<Fabric>-<Node>._matter._tcp), und ein Responder beantwortet eine an einen
        // Namen gerichtete Frage mit SRV, auch ohne PTR. Damit scheidet „ein Paket ist
        // verloren gegangen" als Erklärung aus — antwortet auch darauf niemand, fehlt
        // die Ansage wirklich (20.09.2026 am nuc: Der Apple TV antwortet stellvertretend
        // für zwei Thread-Knoten, die beiden Shellys schweigen auch namentlich).
        $vermisst = $mdnsOk ? SymconInventory::instanceNamesForMissing($inventory['knownDevices'], $inventory['ownFabrics']) : [];
        if ($vermisst !== [] && $budget->phaseAllowed(microtime(true), self::BUDGET_DIRECT * 2)) {
            try {
                $namentlich = $browser->query(
                    array_map(static fn(string $instance): array => ['name' => $instance, 'type' => MdnsCodec::TYPE_SRV], $vermisst),
                    self::BUDGET_DIRECT * 2,
                    1
                );
                $vorher = count($survey['operationalDevices']);
                if ($namentlich !== []) {
                    array_push($responses, ...$namentlich);
                    $survey    = MatterDiscovery::collect($responses, $ownAddresses);
                    $inventory = $this->matchInventory($rawInventory, $survey['operationalDevices']);
                }
                $this->debug('Namentliche Nachfrage', sprintf(
                    '%d vermisste(s) Gerät(e), %s, %d → %d betriebsbereite Annoncen',
                    count($vermisst),
                    $this->describeResponses($namentlich),
                    $vorher,
                    count($survey['operationalDevices'])
                ));
            } catch (RuntimeException $e) {
                $this->debug('Namentliche Nachfrage', 'fehlgeschlagen: ' . $e->getMessage());
            }
        }

        // TXT der eigenen Geräte ohne Schlafangabe nachfragen: Nur die Annonce verrät bei
        // Geräten ohne Batteriewerte in Symcon (KLIPPBOK, MYGGBETT), dass sie schlafen.
        $withoutSleep = $mdnsOk ? SymconInventory::instancesWithoutSleepInfo($inventory['knownDevices'], $survey['operationalDevices'], $inventory['ownFabrics']) : [];
        if ($withoutSleep !== [] && $budget->phaseAllowed(microtime(true), self::BUDGET_DIRECT * 2)) {
            try {
                $txtAnswers = $browser->query(
                    array_map(static fn(string $instance): array => ['name' => $instance, 'type' => MdnsCodec::TYPE_TXT], array_slice($withoutSleep, 0, 20)),
                    self::BUDGET_DIRECT * 2,
                    1
                );
                $this->debug('TXT-Nachfrage eigene Geräte', sprintf('%d Fragen, %s', min(20, count($withoutSleep)), $this->describeResponses($txtAnswers)));
                if ($txtAnswers !== []) {
                    array_push($responses, ...$txtAnswers);
                    $survey    = MatterDiscovery::collect($responses, $ownAddresses);
                    $inventory = $this->matchInventory($rawInventory, $survey['operationalDevices']);
                }
            } catch (RuntimeException $e) {
                $this->debug('TXT-Nachfrage eigene Geräte', 'fehlgeschlagen: ' . $e->getMessage());
            }
        }

        // Ein vermisstes Gerät annonciert nichts mehr — ob es auf Batterie läuft, weiß
        // nur der Lauf, in dem es sich zuletzt gemeldet hat (Forum t/144417).
        $remembered      = ChangeTracker::sleepyByNode($previous);
        $rememberedHosts = ChangeTracker::hostByNode($previous);
        foreach ($inventory['knownDevices'] as &$device) {
            $device['sleepy'] ??= $remembered[(int)$device['nodeId']] ?? null;
            $device['host']   ??= $rememberedHosts[(int)$device['nodeId']] ?? null;
        }
        unset($device);

        // Lebt ein vermisstes Gerät noch? Sein zuletzt gemerkter Host verrät, ob es sich
        // für andere Systeme meldet — dann hakt nur die Kopplung mit Symcon (build 43).
        $elsewhere = SymconInventory::silentForSymcon($inventory['knownDevices'], $survey['operationalDevices'], $inventory['ownFabrics']);
        foreach ($inventory['knownDevices'] as &$device) {
            $device['announcedElsewhere'] = $elsewhere[(int)$device['nodeId']] ?? 0;
        }
        unset($device);
        if ($elsewhere !== []) {
            $this->debug('Nur für andere Systeme', array_map(static fn(int $node, int $count): string => sprintf('Id %d: %d fremde(s) System(e)', $node, $count), array_keys($elsewhere), $elsewhere));
        }

        // Und meldet es sich vielleicht gar nicht als Matter-Gerät, aber unter einem
        // anderen Dienst? Dann ist es am Strom und im Netz — nur seine Matter-Ansage
        // fehlt, und „nicht erreichbar" wäre eine Behauptung (20.09.2026: zwei Shellys
        // am nuc standen auf „Nicht gefunden", lieferten aber Werte).
        $this->applyAliveEvidence($inventory['knownDevices'], $identities);

        // Fehlt der Beleg, kann auch nur ein Paket verloren sein: Die Identitätsrunde ist
        // eine einzige Frage, und am nuc pendelten zwei Shellys so stündlich zwischen Gelb
        // und Rot. Vor dem Urteil „nicht erreichbar" deshalb direkt an die Adresse fragen,
        // unter der das Gerät zuletzt geantwortet hat (build 63). Vor dem Ping, damit der
        // Erreichbarkeitstest die Zeit nicht aufzehrt.
        $rememberedAlive = ChangeTracker::aliveAddressesByNode($previous);
        $recheck         = $mdnsOk ? DeviceIdentity::recheckTargets($inventory['knownDevices'], $rememberedAlive) : [];
        $nachgefragt     = [];
        foreach ($recheck as $address => $nodes) {
            if (!$budget->phaseAllowed(microtime(true), self::BUDGET_DIRECT)) {
                $nachgefragt[] = sprintf('%s (Id %s): kein Budget', $address, implode(', ', $nodes));
                break;
            }
            try {
                $direkt = $browser->query(
                    array_map(static fn(string $service): array => ['name' => $service, 'type' => MdnsCodec::TYPE_PTR], DeviceIdentity::SERVICES),
                    self::BUDGET_DIRECT,
                    1,
                    $address
                );
                array_push($identities, ...DeviceIdentity::fromResponses($direkt));
                $nachgefragt[] = sprintf('%s (Id %s): %s', $address, implode(', ', $nodes), $this->describeResponses($direkt));
            } catch (RuntimeException $e) {
                $nachgefragt[] = sprintf('%s (Id %s): fehlgeschlagen: %s', $address, implode(', ', $nodes), $e->getMessage());
            }
        }
        if ($nachgefragt !== []) {
            $this->debug('Identität direkt nachgefragt', $nachgefragt);
            $this->applyAliveEvidence($inventory['knownDevices'], $identities);
        }

        // Die belegte Adresse wandert in die Momentaufnahme; ohne neuen Beleg bleibt die
        // alte, damit ein einzelner Aussetzer die Nachfrage im nächsten Lauf nicht verhindert.
        $lebend = [];
        foreach ($inventory['knownDevices'] as &$device) {
            $device['aliveAddresses'] ??= $rememberedAlive[(int)$device['nodeId']] ?? [];
            if ($device['aliveService'] !== '') {
                $lebend[] = sprintf(
                    'Id %d: %s%s',
                    (int)$device['nodeId'],
                    DeviceIdentity::serviceLabel($device['aliveService']),
                    $device['aliveModel'] === '' ? '' : ' (' . $device['aliveModel'] . ')'
                );
            }
        }
        unset($device);
        if ($lebend !== []) {
            $this->debug('Im Netz, aber ohne Matter-Ansage', $lebend);
        }

        $this->debugSurvey($survey);
        $this->debug('Symcon Geräte', array_map(
            static fn(array $device): string => sprintf(
                '%s (Id %d) sichtbar=%s schläft=%s Abo=%s',
                (string)$device['name'],
                (int)$device['nodeId'],
                ($device['visible'] ?? false) ? 'ja' : 'nein',
                ($device['sleepy'] ?? null) === null ? '?' : (($device['sleepy'] ?? false) ? 'ja' : 'nein'),
                (string)($device['subscription'] ?? '-')
            ),
            $inventory['knownDevices']
        ));

        // --- Thread-Präfixe und deren Erreichbarkeit ----------------------
        $this->debug('Zeitbedarf bis zum Erreichbarkeitstest', sprintf('%.1f s von %.1f s, Reserve %.1f s', $budget->elapsed(microtime(true)), self::BUDGET_TOTAL, self::BUDGET_PING_RESERVE));
        if (!$quick) {
            $this->UpdateFormField('ProgressText', 'caption', $this->Translate('Testing reachability of the Thread network...'));
        }
        // Nur Geräte ohne IPv4 können hinter einem Border Router liegen; ein gespiegeltes
        // Nachbarsegment mit eigenem ULA ist sonst ein „Thread-Netz" mit Routenbefehl.
        $allDevices      = array_merge($survey['operationalDevices'], $survey['commissionableDevices']);
        $deviceAddresses = MatterDiscovery::threadCandidateAddresses($allDevices);
        // Ein Thread-Präfix muss kein ULA sein (Rainers Aqara-Hub mit delegiertem globalen
        // /64): Belege sind das OMR der Border Router und die Präfixe der Geräte, die ein
        // Border Router stellvertretend annonciert.
        $threadNetworks  = ThreadNetwork::assess($survey['borderRouters']);
        $trustedPrefixes = MatterDiscovery::proxiedPrefixes($allDevices, $survey['borderRouters']);
        foreach ($threadNetworks['networks'] as $network) {
            array_push($trustedPrefixes, ...$network['omrPrefixes']);
        }
        $trustedPrefixes = array_values(array_unique($trustedPrefixes));
        $this->debug('Belegte Thread-Präfixe', $trustedPrefixes);
        $prefixes        = DiagnosisEngine::threadPrefixes($deviceAddresses, $ownIpv6, $trustedPrefixes);
        $gateways = MatterDiscovery::prefixGateways($prefixes, $allDevices, $survey['borderRouters']);
        $platform = OsAdapter::platform();

        // IPv6-Einstellungen des Systems (nur Linux; Dateien unter /proc/sys, rein lesend).
        // Stimmen sie nicht, verwirft der Kernel die Routenansage des Border Routers, ohne
        // dass irgendwo ein Fehler auftaucht.
        $sysctl = $platform === OsAdapter::PLATFORM_LINUX ? OsAdapter::readIpv6Conf() : null;
        if ($sysctl !== null) {
            $this->debug('IPv6-Einstellungen', $sysctl);
        }
        // Gibt es die Einstellung für Routenansagen gar nicht, lernt der Kernel den Weg
        // ins Thread-Netz nie (reblades Synology, Forum t/142087/1140).
        $routeInfoUnsupported = $platform === OsAdapter::PLATFORM_LINUX
            ? OsAdapter::ipv6ConfOptionMissing('accept_ra_rt_info_max_plen')
            : null;
        if ($routeInfoUnsupported === true) {
            $this->debug('IPv6-Einstellungen', 'accept_ra_rt_info_max_plen fehlt auf allen Schnittstellen — Kernel ohne Route Information');
        }

        $routeTable   = OsAdapter::execute(OsAdapter::routeShowCommand($platform));
        // Fehlt `ip` (Docker-Container), gilt /proc/net/ipv6_route; null heißt „unbekannt",
        // nicht „keine Route" — sonst wird im Wächterlauf daraus ein roter Befund.
        $routesKnown  = RouteTable::fromSystem(
            $platform,
            $routeTable,
            $platform === OsAdapter::PLATFORM_LINUX ? OsAdapter::readProcIpv6Route() : null
        );
        $routes       = $routesKnown ?? [];
        if ($platform === OsAdapter::PLATFORM_WINDOWS) {
            // Nur die Lebensdauer verrät, ob Windows eine Route per Router Advertisement
            // gelernt hat — solche Routen brauchen keinen persistenten Eintrag.
            $routes = RouteTable::annotateLifetimes(
                $routes,
                RouteTable::parseLifetimes(OsAdapter::execute(OsAdapter::routeShowVerboseCommand()))
            );
        }
        $lanInterface = RouteTable::interfaceForAddresses($routes, $ownIpv6);
        // Windows hält aktive und persistente Routen getrennt — nur letztere überleben einen Neustart.
        $persistentRoutes = null;
        if ($platform === OsAdapter::PLATFORM_WINDOWS) {
            $persistentRoutes = RouteTable::parse($platform, OsAdapter::execute(OsAdapter::routeShowPersistentCommand()));
        }

        $this->debug('Routentabelle', trim($routeTable));
        if ($platform === OsAdapter::PLATFORM_LINUX && OsAdapter::commandMissing($routeTable)) {
            $this->debug('Routentabelle', $routesKnown === null
                ? 'ip fehlt, /proc/net/ipv6_route nicht lesbar — Routen unbekannt'
                : 'ip fehlt — gelesen aus /proc/net/ipv6_route');
        }
        $this->debug('Routen geparst', $routes);
        if ($persistentRoutes !== null) {
            $this->debug('Routen persistent', $persistentRoutes);
        }

        $threadPrefixes = [];
        foreach ($gateways as $prefix => $info) {
            $routeExists = $routesKnown === null ? null : RouteTable::hasRouteFor($routes, $prefix);

            // Kandidaten fürs Anpingen: Netzgeräte zuerst, Schlafende zuletzt — und
            // betriebsbereite vor koppelbereiten, die oft Karteileichen früherer
            // Fehlversuche sind (allDevices ist in dieser Reihenfolge gebaut).
            $candidates = MatterDiscovery::pingCandidates($allDevices, $prefix, 2);

            $reachable       = null;
            $pingUnavailable = false;
            foreach ($quick ? [] : $candidates as $address) {
                // Thread-Endgeräte schlafen — mehrere Versuche mit Geduld, aber nur so
                // viele, wie ohne Antwort noch ins Budget passen
                $attempts = OsAdapter::pingAttempts($budget->remaining(microtime(true)), self::PING_TIMEOUT_MS, self::PING_ATTEMPTS, $platform);
                if ($attempts === 0) {
                    break; // Budget aufgebraucht — lieber "ungetestet" als Timeout
                }
                $output   = OsAdapter::execute(
                    OsAdapter::pingCommand($platform, $address, $attempts, self::PING_TIMEOUT_MS)
                );
                // Kein ping im Container: der wahre Grund statt „Zeitbudget" (build 59).
                if (OsAdapter::commandMissing($output)) {
                    $pingUnavailable = true;
                    $this->debug('Ping ' . $address, 'kein ping auf diesem System: ' . trim($output));
                    break;
                }
                $received = OsAdapter::parsePingReceived($output);
                $this->debug('Ping ' . $address, sprintf('%d Versuche, empfangen: %s', $attempts, $received === null ? '?' : (string)$received));
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
                'pingSkipped'     => $quick,
                'pingUnavailable' => $pingUnavailable,
                'interface'       => $lanInterface,
            ];
        }
        $this->debug('Thread-Präfixe', $threadPrefixes);

        // --- Thread-Netz-Gesundheit und Routenbewertung -------------------
        $prefixesInUse = array_keys($prefixes);
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
        // Gateway-Abgleich nur mit vollständiger Liste — fehlt einem Border Router die
        // Link-Local, bleibt die Liste leer und RouteTable::assess urteilt nicht.
        $borderRouterLinkLocals = MatterDiscovery::borderRouterLinkLocals($survey['borderRouters']);
        // Dasselbe gilt für „veraltete Route": Nur wenn feststeht, welche Präfixe genutzt
        // werden, darf eine Route als ungenutzt gelten (null = kein Urteil).
        $routeAssessment        = RouteTable::assess(
            $routes,
            $persistentRoutes,
            MatterDiscovery::prefixEvidenceComplete($survey) ? array_values(array_unique($prefixesInUse)) : null,
            $borderRouterLinkLocals,
            $ownIpv6,
            $platform
        );

        $this->debug('Routenbewertung', $routeAssessment);

        // --- Bewertung ----------------------------------------------------
        $findings = DiagnosisEngine::evaluate([
            'ipv6Addresses'         => $ownIpv6,
            'mdnsResponses'         => $mdnsOk && MatterDiscovery::foreignResponses($responses, $ownAddresses) !== [],
            'mdnsProbeResponders'   => $probeResponders,
            'borderRouters'         => $survey['borderRouters'],
            'operationalDevices'    => $survey['operationalDevices'],
            'commissionableDevices' => $survey['commissionableDevices'],
            'threadPrefixes'        => $threadPrefixes,
            'platform'              => $platform,
            'sysctl'                => $sysctl,
            'routeInfoUnsupported'  => $routeInfoUnsupported,
            'controllerPresent'     => $inventory['controllerPresent'],
            'ownFabricId'           => $inventory['ownFabricId'],
            'knownDevices'          => $inventory['knownDevices'],
            'devicesAmbiguous'      => $inventory['devicesAmbiguous'],
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
        // Ein stummer Lauf übernimmt den Stand des Vorlaufs (ChangeTracker::carryOver),
        // sonst meldete ein einzelner Aussetzer alles als behoben und danach als neu.
        $snapshot = ChangeTracker::carryOver(
            $previous,
            ChangeTracker::snapshot($inventory['knownDevices'], $borderRouterNames, $titledFindings, time())
        );
        $changes  = ChangeTracker::diff($previous, $snapshot);
        $this->WriteAttributeString(self::ATTR_SNAPSHOT, json_encode($snapshot, JSON_THROW_ON_ERROR));

        // Geräteliste: ein Eintrag je physischem Gerät, mit den Eigenschaften, die im
        // Alltag zählen (ab 0.5, Anregung Burkhard 18.09.2026).
        $devices       = DeviceInventory::build(
            $survey['operationalDevices'],
            $survey['borderRouters'],
            $inventory['knownDevices'],
            $inventory['ownFabrics'],
            $identities
        );
        $devices       = $this->resolveReverseNames($devices, $budget);
        $deviceColumns = DeviceInventory::fabricColumns($devices, $inventory['ownFabrics'], $this->fabricNames());
        $deviceRows    = $this->deviceRows($devices, $deviceColumns);
        $this->WriteAttributeString(self::ATTR_DEVICES, json_encode(['columns' => $deviceColumns, 'rows' => $deviceRows], JSON_THROW_ON_ERROR));

        $this->updateStatusVariables($inventory, $borderRouterNames, $findings, $changes);
        $this->showFindings($findings, $changes, $deviceRows, $deviceColumns, $quick);
        $this->debug('Gesamtdauer', sprintf('%.1f s', microtime(true) - $start));
    }

    /**
     * Klarnamen für fremde LAN-Geräte aus dem Reverse-Eintrag des Routers (ab build 51).
     *
     * Der eigene Echo Dot stand als „3D59C51D251F" in der Liste; die FRITZ!Box kennt ihn
     * als „EchoDot-Kueche", weil er sich per DHCP so gemeldet hat. Gefragt wird nur nach
     * Geräten, die Symcon nicht kennt — Thread-Geräte haben keinen Eintrag.
     *
     * Die Runde selbst steckt in `ReverseLookup`; hier bleiben nur die Anbindung an
     * `OsAdapter`, die Uhr und das Zeitbudget.
     *
     * @param array<int, array<string, mixed>> $devices
     * @return array<int, array<string, mixed>>
     */
    private function resolveReverseNames(array $devices, RunBudget $budget): array
    {
        // Nicht phaseAllowed: Die Reserve gehört dem Erreichbarkeitstest, und der ist hier
        // schon gelaufen. Sonst wäre die Runde nach einem vollen Lauf immer gesperrt
        // (18.09.2026 beobachtet: 18 s verbraucht, 6 s übrig, Guard verlangte 8,5 s).
        $start = microtime(true);
        if ($budget->remaining($start) < self::BUDGET_REVERSE) {
            $this->debug('Namen aus dem Router', 'übersprungen (Zeitbudget)');

            return $devices;
        }

        $ergebnis = ReverseLookup::collect(
            $devices,
            static fn(string $address): ?string => OsAdapter::reverseName($address),
            static fn(string $name): ?string => OsAdapter::resolveIpv4($name),
            static fn(): float => microtime(true),
            $start + self::BUDGET_REVERSE,
            self::REVERSE_MAX,
            self::REVERSE_SLOW
        );

        $abbruch = [
            'slow'     => ' — abgebrochen, der Resolver antwortet zu langsam',
            'deadline' => ' — abgebrochen, die Zeit der Runde ist aufgebraucht',
            'limit'    => ' — abgebrochen, Höchstzahl an Abfragen erreicht',
        ];
        $this->debug('Namen aus dem Router', sprintf(
            '%d Abfrage(n), %d Treffer, %.1f s%s',
            $ergebnis['queries'],
            count($ergebnis['names']),
            microtime(true) - $start,
            $abbruch[(string)$ergebnis['stop']] ?? ''
        ));

        return DeviceInventory::applyReverseNames($devices, $ergebnis['names']);
    }

    /**
     * Übersetzt die Geräteliste in Anzeigezeilen (Formular und Bericht).
     *
     * Je System eine Spalte F0, F1 … (Reihenfolge wie $columns) mit ✔, wo das Gerät dazugehört.
     *
     * @param array<int, array{host: string, name: string, nodeId: int|null, link: string, power: string, fabrics: int, fabricIds: array<int, string>, symcon: bool, via: string, addresses: array<int, string>}> $devices
     * @param array<int, array{id: string, label: string, own: bool, count: int}> $columns
     * @return array<int, array<string, string>>
     */
    private function deviceRows(array $devices, array $columns): array
    {
        $link  = [DeviceInventory::LINK_THREAD => 'Thread', DeviceInventory::LINK_LAN => 'LAN/WLAN'];
        $power = [DeviceInventory::POWER_BATTERY => 'battery', DeviceInventory::POWER_MAINS => 'mains', DeviceInventory::POWER_UNKNOWN => 'unknown'];
        $rows  = [];
        foreach ($devices as $device) {
            $name = $device['name'];
            if ($device['nodeId'] !== null) {
                $name .= sprintf(' (Id %d)', $device['nodeId']);
            }
            // Ein gebrücktes Gerät steht sonst als anonyme Nummer neben seinem Hub
            if (($device['bridgedBy'] ?? '') !== '') {
                $name .= ' ' . sprintf($this->Translate('(via %s)'), $device['bridgedBy']);
            }
            $row = [
                'Name'   => $name,
                'Vendor' => trim($device['vendor'] . ' ' . $device['model']),
                'Link'   => $this->Translate($link[$device['link']] ?? $device['link']),
                'Power'  => $this->Translate($power[$device['power']] ?? $device['power']),
            ];
            foreach ($columns as $index => $column) {
                $row['F' . $index] = in_array($column['id'], $device['fabricIds'], true) ? '✔' : '';
            }
            // LAN-/WLAN-Geräte melden sich selbst — sie brauchen keinen Border Router
            $row['Via']     = $device['via'] === DeviceInventory::VIA_SELF ? '–' : $device['via'];
            $row['Address'] = $device['addresses'][0] ?? '';
            $rows[]         = $row;
        }

        return $rows;
    }

    /**
     * Spaltendefinition der Geräteliste: die festen Spalten plus eine je System.
     *
     * @param array<int, array{id: string, label: string, own: bool, count: int}> $columns
     * @return array<int, array{caption: string, name: string, width: string}>
     */
    private function deviceColumns(array $columns): array
    {
        $definition = [
            ['caption' => 'Device', 'name' => 'Name', 'width' => '260px'],
            ['caption' => 'Manufacturer', 'name' => 'Vendor', 'width' => '180px'],
            ['caption' => 'Connection', 'name' => 'Link', 'width' => '90px'],
            ['caption' => 'Power', 'name' => 'Power', 'width' => '90px'],
        ];
        foreach ($columns as $index => $column) {
            $definition[] = ['caption' => DeviceInventory::columnTitle($column), 'name' => 'F' . $index, 'width' => '70px'];
        }
        $definition[] = ['caption' => 'Border router', 'name' => 'Via', 'width' => '200px'];
        $definition[] = ['caption' => 'Address', 'name' => 'Address', 'width' => 'auto'];

        return $definition;
    }

    /**
     * Erklärt die Systemspalten: welche Kennung hinter A, B, … steckt und wie viele Geräte darin sind.
     *
     * @param array<int, array{id: string, label: string, own: bool, count: int}> $columns
     */
    private function fabricLegend(array $columns): string
    {
        $parts = [];
        foreach ($columns as $column) {
            $parts[] = $column['own']
                ? sprintf($this->Translate('%s = this installation, %d device(s)'), $column['label'], $column['count'])
                : sprintf($this->Translate('%s = other system %s, %d device(s)'), DeviceInventory::columnTitle($column), $column['id'], $column['count']);
        }

        return $this->Translate('Systems: ') . implode('; ', $parts);
    }

    /**
     * Liest, was Symcon über seine Matter-Geräte weiß — der teure Teil der Erhebung.
     *
     * Alle Zugriffe auf die Matter-Kernmodule sind abgesichert: Deren
     * Formularaufbau ist nicht dokumentiert und darf die Diagnose nicht kippen.
     *
     * Jeder Controller hält seine eigene Fabric; seine Geräte werden nur aus den
     * Konfiguratoren gelesen, die an ihm hängen (ConnectionID, geprüft an nuc und
     * Testbox 17.09.2026). Mehrfach gelistete Geräte — zwei Konfiguratoren am selben
     * Controller — zählen einmal.
     *
     * Läuft genau einmal je Diagnose: Auf Loerdys SymBox mit 21 Geräten kostet allein
     * IPS_GetConfigurationForm rund 6 Sekunden, und bis build 45 wurde nach jeder
     * Nachfragerunde neu gelesen — am Ende fehlte die Zeit für den Erreichbarkeitstest
     * (Forum t/144417). Das Zuordnen zu den Annoncen macht matchInventory, ohne IPS.
     *
     * @return array{controllerPresent: bool, ownFabrics: array<int, string>, fabricUnknown: bool, controllers: array<int, array{fabric: ?string, known: array<int, mixed>}>}
     */
    private function readInventory(): array
    {
        $controllers = IPS_GetInstanceListByModuleID(SymconInventory::GUID_CONTROLLER);
        if ($controllers === []) {
            return ['controllerPresent' => false, 'ownFabrics' => [], 'fabricUnknown' => false, 'controllers' => []];
        }
        $configurators = IPS_GetInstanceListByModuleID(SymconInventory::GUID_CONFIGURATOR);

        $gelesen       = [];
        $fabrics       = [];
        $fabricUnknown = false;
        foreach ($controllers as $controllerId) {
            $controllerId = (int)$controllerId;

            $fabric = null;
            try {
                $form   = json_decode(IPS_GetConfigurationForm($controllerId), true, 64, JSON_THROW_ON_ERROR);
                $fabric = is_array($form) ? SymconInventory::fabricIdFromControllerForm($form) : null;
            } catch (Throwable $e) {
                $this->LogMessage('Matter-Controller-Formular: ' . $e->getMessage(), KL_WARNING);
            }
            $fabricUnknown = $fabricUnknown || $fabric === null;
            if ($fabric !== null) {
                $fabrics[] = $fabric;
            }

            $known = [];
            foreach ($configurators as $configuratorId) {
                $configuratorId = (int)$configuratorId;
                if ((int)IPS_GetInstance($configuratorId)['ConnectionID'] !== $controllerId) {
                    continue;
                }
                try {
                    $form = json_decode(IPS_GetConfigurationForm($configuratorId), true, 64, JSON_THROW_ON_ERROR);
                    if (is_array($form)) {
                        array_push($known, ...SymconInventory::devicesFromConfiguratorForm($form));
                    }
                } catch (Throwable $e) {
                    $this->LogMessage('Matter-Konfigurator-Formular: ' . $e->getMessage(), KL_WARNING);
                }
            }
            if ($known === []) {
                // Rückfallweg: die Geräteinstanzen am Controller selbst
                $known = SymconInventory::devicesFromInstances($this->deviceInstances($controllerId));
            }
            $known = SymconInventory::uniqueDevices($known);
            // Batteriewerte gehören zum teuren Teil: Sie hängen an den Endpunkt-Instanzen
            // in Symcon, nicht an den Annoncen — einmal lesen genügt.
            foreach ($known as &$bekannt) {
                $bekannt['batteryVariables'] = $this->hasBatteryVariables($bekannt);
            }
            unset($bekannt);

            $gelesen[] = ['fabric' => $fabric, 'known' => $known];
        }

        return [
            'controllerPresent' => true,
            'ownFabrics'        => $fabrics,
            'fabricUnknown'     => $fabricUnknown,
            'controllers'       => $gelesen,
        ];
    }

    /**
     * Ordnet die gelesenen Symcon-Geräte den Annoncen zu — ohne einen einzigen
     * IPS-Formularaufruf und deshalb nach jeder Nachfragerunde wiederholbar.
     *
     * @param array{controllerPresent: bool, ownFabrics: array<int, string>, fabricUnknown: bool, controllers: array<int, array{fabric: ?string, known: array<int, mixed>}>} $raw
     * @param array<int, array{instance: string, host: string, addresses: array<int, string>, source: string}> $operational
     * @return array{controllerPresent: bool, ownFabricId: ?string, ownFabrics: array<int, string>, knownDevices: array<int, mixed>, devicesAmbiguous: bool}
     */
    private function matchInventory(array $raw, array $operational): array
    {
        if (($raw['controllerPresent'] ?? false) !== true) {
            return [
                'controllerPresent' => false,
                'ownFabricId'       => null,
                'ownFabrics'        => [],
                'knownDevices'      => [],
                'devicesAmbiguous'  => false,
            ];
        }

        $devices   = [];
        $ambiguous = false;
        foreach ($raw['controllers'] as $controller) {
            $fabric = $controller['fabric'];
            $known  = $controller['known'];

            $match     = SymconInventory::matchDevices($known, $operational, $fabric);
            $usage     = SymconInventory::fabricUsage($known, $operational, $fabric);
            $ambiguous = $ambiguous || $match['ambiguous'];

            // Beschriftung mit Node-ID und Alter der letzten Daten — für die
            // Befundtexte, die die Engine nur noch zusammensetzt. Dazu die Zahl der
            // Fabrics, in denen dasselbe Gerät steckt (Fabric-Tabelle je Gerät).
            foreach ($match['devices'] as $device) {
                $device['label']   = $this->deviceLabel($device);
                $device['fabrics'] = $usage[(int)$device['nodeId']] ?? null;
                // Batteriebetrieb steht auch in Symcon selbst: Die Endpunkte eines
                // Batteriegeräts tragen die Variablen des PowerSource-Clusters. Das gilt
                // auch für ein Gerät, das sich nicht annonciert — dort ist die
                // Schlafangabe aus der Ansage gar nicht zu haben (Forum t/144417).
                //
                // Die Ansage hat Vorrang: Eine Bridge (DIRIGERA) führt die Batteriewerte
                // ihrer gebrückten Geräte, hängt aber selbst am Strom — sie annonciert sich
                // entsprechend und wird dadurch nicht zum Batteriegerät (Testbox 17.09.2026).
                // Umgekehrt haben manche Batteriegeräte keine Batteriewerte in Symcon
                // (KLIPPBOK, MYGGBETT); für die bleibt allein die Ansage.
                if (($device['sleepy'] ?? null) === null && ($device['batteryVariables'] ?? false) === true) {
                    $device['sleepy'] = true;
                }
                // Symcons Abo-Kennzeichnung „(ICD)" ist das letzte Wort: Rainers Aqara-Wandschalter
                // am Strom annonciert lange Intervalle, Symcon führt ihn ohne ICD (build 45).
                $device['sleepy'] = SymconInventory::sleepyFromSubscription($device['subscription'] ?? null) ?? ($device['sleepy'] ?? null);
                $devices[] = $device;
            }
        }

        return [
            'controllerPresent' => true,
            // „Unbekannt", sobald ein Controller seine Fabric nicht nennt — der Befund
            // fabric_unknown erklärt dann, warum nur über die Node-ID abgeglichen wird.
            'ownFabricId'       => ($raw['fabricUnknown'] ?? false) ? null : implode(', ', $raw['ownFabrics']),
            'ownFabrics'        => $raw['ownFabrics'],
            'knownDevices'      => $devices,
            'devicesAmbiguous'  => $ambiguous,
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

    /**
     * Trägt eines der Endpunkt-Geräte die Batterievariablen des PowerSource-Clusters?
     *
     * @param array<string, mixed> $device
     */
    private function hasBatteryVariables(array $device): bool
    {
        $instanzen = $device['endpointInstances'] ?? [];
        if (!is_array($instanzen) || $instanzen === []) {
            $instanzen = [(int)($device['instanceId'] ?? 0)];
        }

        $idents = [];
        foreach ($instanzen as $instanceId) {
            $instanceId = (int)$instanceId;
            if ($instanceId <= 0 || !IPS_InstanceExists($instanceId)) {
                continue;
            }
            foreach (IPS_GetChildrenIDs($instanceId) as $childId) {
                if (IPS_VariableExists((int)$childId)) {
                    $idents[] = (string)IPS_GetObject((int)$childId)['ObjectIdent'];
                }
            }
        }

        return SymconInventory::batteryFromVariables($idents);
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

    /**
     * Debug-Ausgabe für Rückfragen im Forum: Was kam per mDNS an, was steht in der
     * Routentabelle, wie wurde geurteilt. Sichtbar nur im Debug-Fenster der Instanz.
     */
    private function debug(string $topic, mixed $data): void
    {
        $text = is_string($data) ? $data : (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $this->SendDebug($topic, $text, 0);
    }

    /** @param array<int, array{from: string, message: array<string, mixed>}> $responses */
    private function describeResponses(array $responses): string
    {
        $sources = [];
        $records = 0;
        foreach ($responses as $response) {
            $source           = preg_replace('/:\d+$/', '', $response['from']) ?? $response['from'];
            $sources[$source] = ($sources[$source] ?? 0) + 1;
            $records += count($response['message']['records'] ?? []);
        }
        ksort($sources);
        $list = [];
        foreach ($sources as $source => $count) {
            $list[] = $source . ' ×' . $count;
        }

        return sprintf('%d Antworten mit %d Records von %d Quellen: %s', count($responses), $records, count($sources), implode(', ', $list));
    }

    /**
     * Was eine Direktabfrage an einen Border Router gebracht hat: Zahl der PTR-Einträge
     * für _matter._tcp und welche davon der Multicast-Weg nicht kannte.
     *
     * @param array<int, array{from: string, message: array<string, mixed>}> $responses
     * @param array<int, array{instance: string}> $known
     */
    private function describeDirect(array $responses, array $known): string
    {
        $seen = [];
        foreach ($known as $device) {
            $seen[strtolower($device['instance'])] = true;
        }
        $instances = [];
        foreach ($responses as $response) {
            foreach ($response['message']['records'] ?? [] as $record) {
                if ($record['type'] === MdnsCodec::TYPE_PTR && strcasecmp($record['name'], MatterDiscovery::SERVICE_MATTER) === 0) {
                    $instances[strtolower($record['target'])] = $record['target'];
                }
            }
        }
        $new = array_values(array_diff_key($instances, $seen));

        return sprintf('%d Antworten, %d _matter-Einträge, davon neu: %s', count($responses), count($instances), $new === [] ? 'keine' : implode(', ', $new));
    }

    /** @param array<string, mixed> $survey */
    private function debugSurvey(array $survey): void
    {
        $this->debug('Border Router', array_map(
            static fn(array $br): string => sprintf('%s ← %s, Host %s, %s, TXT %s', $br['instance'] ?? $br['name'], $br['source'], $br['host'] !== '' ? $br['host'] : '?', implode(' ', $br['addresses']) ?: 'keine Adresse', $br['txt'] === [] ? 'fehlt' : implode(' ', array_keys($br['txt']))),
            $survey['borderRouters']
        ));
        foreach (['operationalDevices' => '_matter._tcp', 'commissionableDevices' => '_matterc._udp'] as $key => $label) {
            $this->debug($label . ' (' . count($survey[$key]) . ')', array_map(
                static fn(array $device): string => sprintf(
                    '%s ← %s, Host %s, %s%s',
                    $device['instance'],
                    $device['source'],
                    $device['host'] !== '' ? $device['host'] : '?',
                    implode(' ', $device['addresses']) ?: 'keine Adresse',
                    array_key_exists('sleepy', $device) ? ', schläft=' . ($device['sleepy'] === null ? '?' : ($device['sleepy'] ? 'ja' : 'nein')) : (array_key_exists('commissioningMode', $device) ? ', CM=' . ($device['commissioningMode'] ?? '?') : '')
                ),
                $survey[$key]
            ));
        }
        $this->debug('Offen nach den Nachfragen', [
            'ohne SRV'          => $survey['missingSrv'],
            'ohne IPv6'         => $survey['missingAddresses'],
            'ohne TXT'          => $survey['missingTxt'],
            'Router ohne TXT'   => $survey['missingRouterTxt'] ?? [],
        ]);
    }

    /** @return array<string, mixed>|null */
    /**
     * Trägt je unsichtbarem Gerät ein, ob es sich unter einem anderen Dienst meldet
     * (`aliveService`, `aliveModel`, `aliveAddresses`). Ein zweiter Aufruf nach der
     * direkten Nachfrage setzt die Felder neu.
     *
     * @param array<int, array<string, mixed>> $devices
     * @param array<int, array<string, mixed>> $identities
     */
    private function applyAliveEvidence(array &$devices, array $identities): void
    {
        foreach ($devices as &$device) {
            $device['aliveService'] = '';
            $device['aliveModel']   = '';
            unset($device['aliveAddresses']);
            if (($device['visible'] ?? false) === true) {
                continue;
            }
            $beleg = DeviceIdentity::alive((string)($device['host'] ?? ''), [], $identities);
            if ($beleg === null) {
                continue;
            }
            $device['aliveService']   = $beleg['service'];
            $device['aliveModel']     = $beleg['model'];
            $device['aliveAddresses'] = $beleg['addresses'];
        }
        unset($device);
    }

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
            $this->SetValue(self::VAR_IDENT_CHANGES, ChangeTracker::bulletList($lines));
        }
    }

    /**
     * @param array<int, array{severity: string, id: string, params: array<string, string>}> $findings
     * @param array<int, array{id: string, params: array<string, string>}> $changes
     * @param array<int, array<string, string>> $deviceRows
     * @param array<int, array{id: string, label: string, own: bool, count: int}> $deviceColumns
     */
    private function showFindings(array $findings, array $changes, array $deviceRows, array $deviceColumns, bool $quick): void
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
        if ($deviceRows !== []) {
            $html .= '<p><b>' . htmlspecialchars($this->Translate('Devices in the network')) . '</b></p>'
                . '<table style="border-collapse: collapse; font-size: 90%;"><tr>';
            foreach ($this->deviceColumns($deviceColumns) as $column) {
                $html .= '<th style="text-align: left; padding: 2px 8px; border-bottom: 1px solid gray;">' . htmlspecialchars($this->Translate($column['caption'])) . '</th>';
            }
            $html .= '</tr>';
            foreach ($deviceRows as $row) {
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<td style="padding: 2px 8px;">' . htmlspecialchars($cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table><p style="font-size: 90%;"><i>' . htmlspecialchars($this->fabricLegend($deviceColumns)) . '</i></p>';
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
        // Spalten zuerst, sonst kennt die Liste die Systemspalten der Werte nicht
        $this->UpdateFormField('Devices', 'columns', json_encode($this->deviceColumns($deviceColumns), JSON_THROW_ON_ERROR));
        $this->UpdateFormField('Devices', 'values', json_encode($deviceRows, JSON_THROW_ON_ERROR));
        $this->UpdateFormField('Devices', 'rowCount', max(1, min(20, count($deviceRows))));
        $this->UpdateFormField('FabricLegend', 'caption', $this->fabricLegend($deviceColumns));
        $this->UpdateFormField('FabricLegend', 'visible', $deviceColumns !== []);
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
        $text = strtr($this->Translate($catalog[$id]), $replacements);

        // Welche Geräte betroffen sind, gehört in die Meldung — die ersten beim Namen,
        // die übrigen als Zahl (ab build 62, ChangeTracker::deviceParams).
        if (($params['devices'] ?? '') !== '') {
            $text .= ' — ' . $params['devices'];
            if ((int)($params['more'] ?? 0) > 0) {
                $text .= ' ' . sprintf($this->Translate('and %d more'), (int)$params['more']);
            }
        }

        return $text;
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
            'no_ipv6_no_thread' => [
                'No IPv6 address — not needed by your devices',
                'This host has no IPv6 address. Matter over Thread would require one, but there is no Thread border router and no Thread device here. Your Matter devices over LAN or WLAN work without IPv6.',
                'Nothing to do for now. Before you add your first Thread device, enable IPv6 on the network adapter and in your router.',
            ],
            'ipv6_ok' => [
                'IPv6 is available',
                'This host has the IPv6 addresses %addresses%.',
                '',
            ],
            'sysctl_ra_ignored' => [
                'This system ignores the announcements of the border router',
                'A Thread border router announces the way into its network. This host is set to ignore such announcements, so its Thread devices stay unreachable even though everything else is in order.',
                'Open the Matter configurator: it offers to correct this setting ("Fix Settings"). Afterwards run the diagnosis again.',
            ],
            'sysctl_forwarding' => [
                'Announcements of the border router are dropped because this host forwards IPv6',
                'This host is set up to forward IPv6 packets. Linux then ignores the announcements of a router — including the way into the Thread network — unless it is explicitly told to accept them anyway.',
                'Open the Matter configurator: it offers to correct this setting ("Fix Settings"). Afterwards run the diagnosis again.',
            ],
            'sysctl_route_info_unsupported' => [
                'This Linux kernel does not learn routes into the Thread network',
                'A Thread border router announces the way into its network as a route. The kernel of this system was built without support for such announcements (the setting accept_ra_rt_info_max_plen does not exist here), so it never learns the route on its own, and neither sysctl nor the Fix Settings button of the Matter configurator can change that. The route has to be set by hand. It is lost at every restart and must be adapted whenever the border router gets a new address range, for instance after a reset.',
                'Set the route by hand and have it set again after every restart, for example with a scheduled task on the host: %command%. After resetting a border router, run the diagnosis again — it shows the current route.',
            ],
            'sysctl_route_info' => [
                'Routes into the Thread network are discarded',
                'A Thread border router announces its network as a route. This host only accepts such routes up to a prefix length of %value%, while a Thread network needs 64. The announcement is therefore discarded without any error message, and the devices remain unreachable.',
                'Open the Matter configurator: it offers to correct this setting ("Fix Settings"). Afterwards run the diagnosis again.',
            ],
            'sysctl_ok' => [
                'The system accepts the announcements of the border router',
                'The IPv6 settings of this host allow routes into the Thread network to be adopted.',
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
            'no_commissionable_closed_only' => [
                'No device is currently ready for pairing',
                'No pairing window is open, but %count% device(s) are visible with a closed window: %hosts%. They are alive; if you just pressed a pairing button, the window did not open — the device may already belong to another system (factory reset needed) or the press was not recognised.',
                '',
            ],
            'operational_found' => [
                '%count% Matter device(s) report in',
                '%announcements% announcement(s) from %count% device(s) in %systems% system(s) — the one run by Symcon or others. A device announces itself once per system it belongs to, so a device paired with Symcon and Apple Home appears twice.',
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
                'A path into the Thread radio network exists. No device was contacted in this run — the monitoring run does so on purpose, so battery devices stay asleep.',
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
                '%count% paired device(s) do not announce themselves in the network',
                'Symcon knows these devices, but they are currently not announcing themselves: %devices%. Right now nothing is lost: the Matter controller reports their connection as "%states%", and as long as that says OK, values keep coming in — a device can stop announcing itself without losing an established connection, and a successful query in the Matter configurator runs over that same connection and therefore does not refute this finding. The announcement is, however, how Symcon finds a device again: after the next restart of Symcon, or once the device gets a new address, re-establishing the connection can fail. It does not have to: in one field test the device still delivered values after a restart although it stayed silent.',
                'Nothing is urgent as long as the values stay up to date, but restarting the device once (unplug it and plug it back in) brings the announcement back on a device with mains power.',
            ],
            'own_devices_missing_battery' => [
                '%count% paired device(s) do not announce themselves in the network',
                'Symcon knows these devices, but they are currently not announcing themselves: %devices% (🔋 = battery-powered and silent most of the time anyway). Right now nothing is lost: the Matter controller reports their connection as "%states%", and as long as that says OK, values keep coming in — a device can stop announcing itself without losing an established connection, and a successful query in the Matter configurator runs over that same connection and therefore does not refute this finding. The announcement is, however, how Symcon finds a device again: after the next restart of Symcon, or once the device gets a new address, re-establishing the connection can fail. It does not have to: in one field test the device still delivered values after a restart although it stayed silent.',
                'For a battery-powered device the announcement usually comes back by itself as soon as the device reports in again — check battery and range if it does not. A device on mains power that stays silent needs restarting the device once.',
            ],
            'own_devices_silent_for_symcon' => [
                '%count% paired device(s) announce themselves for other systems, but not for Symcon',
                'These devices are alive and announce themselves in the network — but only for other systems, not for the one run by Symcon: %devices%. The Matter controller reports their connection as "%states%"; as long as that says OK, values keep coming in, and a successful query in the Matter configurator runs over that same connection and therefore does not refute this finding. The announcement for Symcon is, however, how Symcon finds a device again: after the next restart of Symcon, or once the device gets a new address, re-establishing the connection can fail while Apple Home or Home Assistant keep working with it. It does not have to: in one field test the device still delivered values after a restart although it stayed silent for Symcon.',
                'Open the Matter configurator, click the info icon in the device row and look at "Connected Systems". If Symcon is missing there, the pairing on the device is gone — pair the device again. If Symcon is listed, the announcement is stuck on its way: for a Thread device restart the border router that announces it (the Apple TV, the hub), for a LAN/WLAN device restart the device itself. If it stays silent for Symcon, remove the device from Symcon and pair it again.',
            ],
            'own_devices_unsubscribed' => [
                '%count% paired device(s) cannot be reached any more',
                'These devices no longer announce themselves in the network — not even when asked by name — and they cannot be found under any other service either: %devices%. The Matter controller reports their connection state as %states%. Whether an already established connection still delivers values, the diagnosis cannot tell; what is certain is that Symcon cannot re-establish the connection in this state.',
                'Check power and range first. If the device is back but stays silent, open its instance and update the values once.',
            ],
            'own_devices_announce_missing' => [
                '%count% paired device(s) no longer announce themselves, but are still on the network',
                'The Matter controller reports the connection of these devices as "%states%", yet they are still answering in the network under another service (%services%): %devices%. So they are powered on and reachable — only their Matter announcement is gone. Values from an already established connection can keep coming in, but Symcon cannot find the device again, for example after a restart.',
                'Restart the device once (unplug it and plug it back in, or use the reboot in its web interface). On Shelly devices with Wi-Fi this is a known fault of the device: the announcement comes back after a reboot, and the relay stays on.',
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
            'thread_router_data_missing' => [
                'Thread network cannot be judged: %count% of %total% border routers report no network data',
                'These border routers did not send their Thread network data in this run: %routers%. Without it the diagnosis cannot tell whether all of them work in the same Thread network — a second, separate network would go unnoticed.',
                'Repeat the check in a few minutes; the data usually arrives on a later run. If it keeps missing, check whether the device is powered on and in the same network as Symcon.',
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
            'thread_route_learned' => [
                'Path into the Thread radio network %prefix% is learned automatically',
                'This host learns the route via %gateway% from the router advertisements of the border router and renews it by itself (currently valid for another %lifetime% seconds). No manual route is needed.',
                '',
            ],
            'thread_route_learned_with_persistent' => [
                'Path into the Thread radio network %prefix% is learned automatically',
                'This host learns the route via %gateway% from the router advertisements of the border router and renews it by itself (currently valid for another %lifetime% seconds). A permanent entry exists as well; it bridges the time after a restart until the first router advertisement and can stay.',
                '',
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
            'thread_prefix_untested_no_ping' => [
                'Thread radio network %prefix% could not be tested: no ping on this system',
                'This system has no ping program, which is common inside Docker containers. The reachability test could therefore not run, and the routing table could not be read either, so there is no statement about the path into the network.',
                'Run the test on a system that has ping — with Docker in host network mode, on the host itself: %command%',
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
