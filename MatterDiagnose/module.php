<?php

declare(strict_types=1);

require_once __DIR__ . '/libs/MdnsBrowser.php';
require_once __DIR__ . '/libs/MatterErhebung.php';
require_once __DIR__ . '/libs/DiagnoseEngine.php';
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
    private const BUDGET_NACHFRAGE = 2.0;
    private const BUDGET_GESAMT    = 24.0;

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
        if ($Ident === 'Diagnose') {
            $this->diagnoseAusfuehren();

            return;
        }
        throw new InvalidArgumentException('Unbekannte Aktion: ' . $Ident);
    }

    private function diagnoseAusfuehren(): void
    {
        // Rust-Edition: Wanduhr-Limit von 30 s abschalten (unter C++ ein No-op)
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }
        $start = microtime(true);

        $this->UpdateFormField('FortschrittText', 'visible', true);
        $this->UpdateFormField('FortschrittText', 'caption', $this->Translate('Searching for Matter devices and border routers...'));

        // --- Erhebung -----------------------------------------------------
        $eigeneV6 = OsAdapter::eigeneIpv6Adressen();
        $eigene   = array_merge($eigeneV6, OsAdapter::eigeneIpv4Adressen());

        $browser   = new MdnsBrowser();
        $antworten = [];
        $mdnsOk    = true;
        try {
            $antworten = $browser->query(
                [
                    ['name' => MatterErhebung::DIENST_MESHCOP, 'type' => MdnsCodec::TYPE_PTR],
                    ['name' => MatterErhebung::DIENST_MATTER, 'type' => MdnsCodec::TYPE_PTR],
                    ['name' => MatterErhebung::DIENST_KOPPELBEREIT, 'type' => MdnsCodec::TYPE_PTR],
                ],
                self::BUDGET_MDNS
            );
        } catch (RuntimeException $e) {
            $this->LogMessage('mDNS: ' . $e->getMessage(), KL_ERROR);
            $mdnsOk = false;
        }

        $lage = MatterErhebung::sammeln($antworten, $eigene);

        // Fehlende SRV/AAAA-Records gezielt nachfragen (eine Runde genügt)
        $nachfragen = [];
        foreach ($lage['fehlendeSrv'] as $instanz) {
            $nachfragen[] = ['name' => $instanz, 'type' => MdnsCodec::TYPE_SRV];
        }
        foreach ($lage['fehlendeAdressen'] as $host) {
            $nachfragen[] = ['name' => $host, 'type' => MdnsCodec::TYPE_AAAA];
        }
        if ($nachfragen !== [] && $mdnsOk) {
            try {
                $antworten = array_merge(
                    $antworten,
                    $browser->query(array_slice($nachfragen, 0, 20), self::BUDGET_NACHFRAGE)
                );
                $lage = MatterErhebung::sammeln($antworten, $eigene);
            } catch (RuntimeException $e) {
                $this->LogMessage('mDNS-Nachfrage: ' . $e->getMessage(), KL_WARNING);
            }
        }

        // --- Thread-Präfixe und deren Erreichbarkeit ----------------------
        $this->UpdateFormField('FortschrittText', 'caption', $this->Translate('Testing reachability of the Thread network...'));
        $geraeteAlle     = array_merge($lage['geraeteBetrieb'], $lage['geraeteKoppelbereit']);
        $geraeteAdressen = [];
        foreach ($geraeteAlle as $g) {
            foreach ($g['adressen'] as $a) {
                $geraeteAdressen[] = $a;
            }
        }
        $praefixe  = DiagnoseEngine::threadPraefixe($geraeteAdressen, $eigeneV6);
        $gateways  = MatterErhebung::praefixGateways($praefixe, $geraeteAlle, $lage['borderRouter']);
        $plattform = OsAdapter::plattform();

        $threadPraefixe = [];
        foreach ($gateways as $praefix => $info) {
            $verbleibend = self::BUDGET_GESAMT - (microtime(true) - $start);
            if ($verbleibend < 5.0) {
                // Budget aufgebraucht — Präfix bleibt ungetestet statt die
                // Diagnose in den Timeout zu treiben
                $threadPraefixe[$praefix] = [
                    'erreichbar'  => null,
                    'testAdresse' => $info['testAdresse'],
                    'gateway'     => $info['gateway'],
                ];
                continue;
            }
            // Thread-Endgeräte schlafen — mehrere Versuche mit Geduld
            $ausgabe   = OsAdapter::ausfuehren(
                OsAdapter::pingCommand($plattform, $info['testAdresse'], 5, 2000)
            );
            $empfangen = OsAdapter::parsePingEmpfangen($ausgabe);

            $threadPraefixe[$praefix] = [
                'erreichbar'  => $empfangen === null ? null : $empfangen > 0,
                'testAdresse' => $info['testAdresse'],
                'gateway'     => $info['gateway'],
            ];
        }

        // --- Port-5353-Konkurrenz (nur Windows) ---------------------------
        $port5353 = [];
        if ($plattform === OsAdapter::PLATTFORM_WINDOWS) {
            $pids     = OsAdapter::parseNetstat5353(OsAdapter::ausfuehren(OsAdapter::netstatUdpCommand()));
            $prozesse = OsAdapter::parseTasklistCsv(OsAdapter::ausfuehren('tasklist /FO CSV /NH'));
            foreach ($pids as $pid) {
                $name = $prozesse[$pid] ?? ('PID ' . $pid);
                if (!preg_match('/^(ips|symcon)/i', $name)) {
                    $port5353[] = $name;
                }
            }
        }

        // --- Bewertung ----------------------------------------------------
        $befunde = DiagnoseEngine::auswerten([
            'ipv6Adressen'        => $eigeneV6,
            'mdnsAntworten'       => $mdnsOk && $antworten !== [],
            'borderRouter'        => $lage['borderRouter'],
            'geraeteBetrieb'      => $lage['geraeteBetrieb'],
            'geraeteKoppelbereit' => $lage['geraeteKoppelbereit'],
            'threadPraefixe'      => $threadPraefixe,
            'eigeneAnkuendigung'  => $lage['eigeneAnkuendigung'],
            'plattform'           => $plattform,
            'port5353Belegung'    => $port5353,
        ]);

        $this->befundeAnzeigen($befunde);
    }

    /** @param array<int, array{stufe: string, id: string, params: array<string, string>}> $befunde */
    private function befundeAnzeigen(array $befunde): void
    {
        $symbole = [
            DiagnoseEngine::STUFE_OK      => '✅',
            DiagnoseEngine::STUFE_HINWEIS => '⚠️',
            DiagnoseEngine::STUFE_BLOCKER => '❌',
        ];

        $zeilen = [];
        $html   = '<div style="font-family: sans-serif;">';
        foreach ($befunde as $befund) {
            $texte  = $this->befundTexte($befund['id'], $befund['params']);
            $symbol = $symbole[$befund['stufe']];

            $zeilen[] = [
                'Status'  => $symbol,
                'Befund'  => $texte['titel'],
                'Details' => $texte['text'],
            ];

            $html .= '<p><b>' . $symbol . ' ' . htmlspecialchars($texte['titel']) . '</b><br>'
                . nl2br(htmlspecialchars($texte['text']));
            if ($texte['empfehlung'] !== '') {
                $html .= '<br><i>' . nl2br(htmlspecialchars($texte['empfehlung'])) . '</i>';
            }
            $html .= '</p>';
        }
        $html .= '<p style="color: gray;">' . htmlspecialchars(
            sprintf($this->Translate('Diagnosis from %s'), date('d.m.Y H:i:s'))
        ) . '</p></div>';

        $this->SetValue(self::VAR_IDENT_REPORT, $html);
        $this->UpdateFormField('Befunde', 'values', json_encode($zeilen, JSON_THROW_ON_ERROR));
        $this->UpdateFormField('Befunde', 'rowCount', max(1, min(12, count($zeilen))));
        $this->UpdateFormField('FortschrittText', 'caption', $this->Translate('Diagnosis finished. Details including recommendations are stored in the "Last Report" variable.'));
    }

    /** @return array{titel: string, text: string, empfehlung: string} */
    private function befundTexte(string $id, array $params): array
    {
        // Schlüssel sind englische Originaltexte (Übersetzung via locale.json)
        $katalog = [
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
            'thread_prefix_untested' => [
                'Thread network %prefix% could not be tested',
                'The reachability test was skipped (time budget) or its result was inconclusive. Thread devices sleep most of the time, which can hide them from a short test.',
                'Run the diagnosis again.',
            ],
            'own_controller_missing' => [
                'Your Symcon does not announce itself as a Matter controller',
                'No Matter announcement was received from this Symcon installation. If a Matter Controller instance exists, its network stack did not start correctly — pairing will fail regardless of the device.',
                'Check that a Matter Controller instance exists. If it does, contact Symcon support with this finding.',
            ],
            'own_controller_ok' => [
                'Symcon announces itself as a Matter controller',
                'The local Matter stack is active and visible in the network.',
                '',
            ],
            'port5353_competition' => [
                'Other programs share the mDNS port',
                'These programs are also using UDP port 5353: %processes%. This is usually fine, but a program binding the port exclusively can prevent Symcon from receiving mDNS.',
                'If Symcon does not announce itself, try stopping these programs one at a time (e.g. Bonjour) and repeat the diagnosis.',
            ],
        ];

        if (!isset($katalog[$id])) {
            return ['titel' => $id, 'text' => json_encode($params) ?: '', 'empfehlung' => ''];
        }

        $ersetzungen = [];
        foreach ($params as $schluessel => $wert) {
            $ersetzungen['%' . $schluessel . '%'] = $wert;
        }

        [$titel, $text, $empfehlung] = $katalog[$id];

        return [
            'titel'      => strtr($this->Translate($titel), $ersetzungen),
            'text'       => strtr($this->Translate($text), $ersetzungen),
            'empfehlung' => $empfehlung === '' ? '' : strtr($this->Translate($empfehlung), $ersetzungen),
        ];
    }
}
