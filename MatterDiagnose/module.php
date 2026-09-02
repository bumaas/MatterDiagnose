<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/MdnsBrowser.php';
require_once __DIR__ . '/libs/MatterDiscovery.php';
require_once __DIR__ . '/libs/DiagnosisEngine.php';
require_once __DIR__ . '/libs/OsAdapter.php';

/**
 * Matter Diagnose — prüft die häufigsten Stolpersteine bei der Einbindung von
 * Matter-Geräten (insbesondere Matter over Thread) und übersetzt die Befunde
 * in Klartext samt Handlungsempfehlung.
 */
class MatterDiagnose extends IPSModuleStrict
{
    private const VAR_IDENT_REPORT = 'Report';

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
        $this->RegisterVariableString(
            self::VAR_IDENT_REPORT,
            $this->Translate('Last Report'),
            ['PRESENTATION' => VARIABLE_PRESENTATION_WEB_CONTENT]
        );
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        if ($Ident === 'Diagnosis') {
            $this->runDiagnosis();

            return;
        }
        throw new InvalidArgumentException('Unbekannte Aktion: ' . $Ident);
    }

    private function runDiagnosis(): void
    {
        // Rust-Edition: Wanduhr-Limit von 30 s abschalten (unter C++ ein No-op)
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        $start = microtime(true);

        $this->UpdateFormField('ProgressText', 'visible', true);
        $this->UpdateFormField('ProgressText', 'caption', $this->Translate('Searching for Matter devices and border routers...'));

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

        // --- Thread-Präfixe und deren Erreichbarkeit ----------------------
        $this->UpdateFormField('ProgressText', 'caption', $this->Translate('Testing reachability of the Thread network...'));
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

        $routeTable = OsAdapter::execute(OsAdapter::routeShowCommand($platform));

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
            foreach ($candidates as $address) {
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
            ];
        }

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
        ]);

        $this->showFindings($findings);
    }

    /** @param array<int, array{severity: string, id: string, params: array<string, string>}> $findings */
    private function showFindings(array $findings): void
    {
        $symbols = [
            DiagnosisEngine::SEVERITY_OK      => '✅',
            DiagnosisEngine::SEVERITY_NOTICE  => '⚠️',
            DiagnosisEngine::SEVERITY_BLOCKER => '❌',
        ];

        $rows = [];
        $html = '<div style="font-family: sans-serif;">';
        foreach ($findings as $finding) {
            $texts  = $this->findingTexts($finding['id'], $finding['params']);
            $symbol = $symbols[$finding['severity']];

            $rows[] = [
                'Status'  => $symbol,
                'Finding' => $texts['title'],
                'Details' => $texts['text'],
            ];

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
        $this->UpdateFormField('Findings', 'values', json_encode($rows, JSON_THROW_ON_ERROR));
        $this->UpdateFormField('Findings', 'rowCount', max(1, min(12, count($rows))));
        $this->UpdateFormField('ProgressText', 'caption', $this->Translate('Diagnosis finished. Details including recommendations are stored in the "Last Report" variable.'));
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
                'Not a single device in the network answered the multicast query. Either the network blocks multicast (e.g. Docker without host network, VLAN isolation, guest WLAN) or a local firewall drops the responses.',
                'Check whether Symcon runs in a network that permits multicast (Docker: use --network host).',
            ],
            'mdns_ok' => [
                'mDNS works, but no Matter service is announced',
                '%count% device(s) answered a general mDNS query, so multicast is fine. However, neither a Thread border router nor a Matter device announced itself in this network.',
                'If you expect Matter devices here, make sure they are powered and in the same network (VLAN) as Symcon.',
            ],
            'no_border_router' => [
                'No Thread border router found',
                'Matter over Thread devices need a Thread border router (e.g. IKEA DIRIGERA, Apple HomePod/Apple TV, Google Hub). None announced itself in this network. Matter devices using WLAN are not affected.',
                'If you want to use Thread devices, add a border router to the network first.',
            ],
            'border_router_found' => [
                'Thread border router found: %names%',
                '%count% Thread border router(s) are active in this network.',
                '',
            ],
            'commissionable_found' => [
                '%count% device(s) ready for pairing',
                'Devices announcing an open commissioning window: %hosts%.',
                '',
            ],
            'no_commissionable' => [
                'No device is currently ready for pairing',
                'No open commissioning window was announced. If you are about to pair a device, put it into pairing mode first — the window typically closes after 15 minutes.',
                '',
            ],
            'operational_found' => [
                '%count% commissioned Matter announcement(s) visible',
                'Devices already belonging to a Matter fabric are announcing themselves in this network.',
                '',
            ],
            'thread_prefix_reachable' => [
                'Thread network %prefix% is reachable',
                'This host can reach devices inside the Thread network.',
                '',
            ],
            'thread_prefix_unreachable' => [
                'Thread network %prefix% is NOT reachable',
                'The Thread devices live behind the border router in a separate IPv6 network, and this host has no route to it. Pairing and communication will fail even though everything else looks fine. Windows in particular does not adopt these routes automatically.',
                'Run the following command with administrator rights, then run the diagnosis again: %command%',
            ],
            'thread_prefix_no_reply' => [
                'Thread network %prefix%: route exists, devices did not answer',
                'This host has a route to the Thread network, but no device answered the test. Battery-powered Thread devices sleep most of the time — this is usually harmless.',
                'If pairing still fails, run the diagnosis again while the device is awake (e.g. right after pressing its button).',
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
