<?php

declare(strict_types=1);

require_once __DIR__ . '/MdnsCodec.php';
require_once __DIR__ . '/DiagnosisEngine.php';
require_once __DIR__ . '/ThreadNetwork.php';

/**
 * Verdichtet dekodierte mDNS-Antworten zu einem strukturierten Lagebild:
 * Border Router, betriebsbereite und koppelbereite Matter-Geräte samt
 * Hostnamen, Adressen und Quell-IP der Annonce.
 *
 * Reine Funktionen — die Tests füttern sie mit echten Paketmitschnitten.
 */
class MatterDiscovery
{
    public const SERVICE_MESHCOP        = '_meshcop._udp.local';
    public const SERVICE_MATTER         = '_matter._tcp.local';
    public const SERVICE_COMMISSIONABLE = '_matterc._udp.local';

    /**
     * @param array<int, array{from: string, message: array<string, mixed>}> $responses
     * @param array<int, string> $ownAddresses IPv4- und IPv6-Adressen des eigenen Hosts
     * @return array{
     *     borderRouters: array<int, array{instance: string, name: string, host: string, addresses: array<int, string>, source: string, txt: array<string, string>}>,
     *     operationalDevices: array<int, array{instance: string, host: string, port: int, addresses: array<int, string>, source: string, sleepy: bool|null}>,
     *     commissionableDevices: array<int, array{instance: string, host: string, port: int, addresses: array<int, string>, source: string, commissioningMode: int|null}>,
     *     missingRouterTxt: array<int, string>,
     *     missingSrv: array<int, string>,
     *     missingAddresses: array<int, string>,
     *     missingTxt: array<int, string>
     * }
     */
    public static function collect(array $responses, array $ownAddresses): array
    {
        // --- Rohdaten über alle Antworten hinweg einsammeln ---------------
        $ptr       = []; // dienst => [instanzname => quelle]
        $srv       = []; // instanzname => {target, port}
        $srvOrigin = []; // instanzname => {name, source} — für gezielt erfragte Einträge ohne PTR
        $txt       = []; // instanzname => array
        $addresses = []; // hostname => [adresse, ...]

        foreach ($responses as $response) {
            $source = preg_replace('/:\d+$/', '', $response['from']);
            foreach ($response['message']['records'] as $record) {
                $name = $record['name'];
                // Alle Namen kleingeschrieben ablegen: mDNS-Namen sind case-insensitiv,
                // und Gerät und Advertising-Proxy schreiben denselben Instanznamen nicht
                // zwingend gleich.
                switch ($record['type']) {
                    case MdnsCodec::TYPE_PTR:
                        // Auch das Ziel kleingeschrieben als Schlüssel — die erste Schreibweise bleibt für die Anzeige
                        $ptr[strtolower($name)][strtolower($record['target'])] ??= ['instance' => $record['target'], 'source' => $source];
                        break;
                    case MdnsCodec::TYPE_SRV:
                        $srv[strtolower($name)] ??= ['target' => $record['target'], 'port' => $record['port']];
                        $srvOrigin[strtolower($name)] ??= ['name' => (string)$name, 'source' => $source];
                        break;
                    case MdnsCodec::TYPE_TXT:
                        $txt[strtolower($name)] ??= $record['txt'];
                        break;
                    case MdnsCodec::TYPE_A:
                    case MdnsCodec::TYPE_AAAA:
                        $addresses[strtolower($name)][] = $record['address'];
                        break;
                }
            }
        }
        foreach ($addresses as $host => $list) {
            $addresses[$host] = array_values(array_unique($list));
        }

        // --- Dienste zu Objekten auflösen ---------------------------------
        $missingSrv       = [];
        $missingAddresses = [];

        $resolve = static function (string $service, bool $skipOwn = false) use (
            $ptr,
            $srv,
            $srvOrigin,
            $addresses,
            $ownAddresses,
            &$missingSrv,
            &$missingAddresses
        ): array {
            // Eine gezielt gestellte Frage nach einem Betriebsnamen beantwortet der
            // Responder mit SRV — ohne PTR, denn durchsucht wurde kein Dienst, benannt
            // war ein Eintrag. Diese Antwort zählt genauso als Annonce; ohne sie fiele
            // ausgerechnet das unter den Tisch, was einen Fehlalarm ausräumt
            // (20.09.2026 am nuc: Der Apple TV beantwortet die Direktfrage nach zwei
            // Thread-Knoten mit zwei SRV und zwei AAAA — und keinem einzigen PTR).
            $announced = $ptr[strtolower($service)] ?? [];
            $suffix    = '.' . strtolower($service);
            foreach ($srv as $instanceKey => $unused) {
                if (!str_ends_with($instanceKey, $suffix) || isset($announced[$instanceKey])) {
                    continue;
                }
                $announced[$instanceKey] = [
                    'instance' => $srvOrigin[$instanceKey]['name'] ?? $instanceKey,
                    'source'   => $srvOrigin[$instanceKey]['source'] ?? '',
                ];
            }

            $result = [];
            foreach ($announced as $instanceKey => $announcement) {
                if ($skipOwn && self::isOwnAddress($announcement['source'], $ownAddresses)) {
                    continue;
                }
                $instance = $announcement['instance'];
                $entry    = [
                    'instance'  => $instance,
                    'host'      => '',
                    'port'      => 0,
                    'addresses' => [],
                    'source'    => $announcement['source'],
                ];
                if (isset($srv[$instanceKey])) {
                    $entry['host'] = $srv[$instanceKey]['target'];
                    $entry['port'] = $srv[$instanceKey]['port'];
                    $hostKey       = strtolower($entry['host']);
                    if (isset($addresses[$hostKey])) {
                        $entry['addresses'] = $addresses[$hostKey];
                    }
                    // Ohne IPv6-Adresse gilt der Host als unaufgelöst — auch wenn ein
                    // A-Record da ist. Der Apple TV beantwortet die kombinierte Abfrage
                    // nur mit seiner IPv4 (Mitschnitt 08.09.2026); für Thread-Route und
                    // Border-Router-Abgleich zählt aber allein die Link-Local.
                    if (!self::hasIpv6($entry['addresses'])) {
                        $missingAddresses[] = $entry['host'];
                    }
                } else {
                    $missingSrv[] = $instance;
                }
                $result[] = $entry;
            }

            return $result;
        };

        // Ohne die _meshcop-TXT-Angaben (Netzname, Extended PAN ID, Thread-Version) gehört
        // ein Border Router zu keinem Thread-Netz und fällt aus der Netzbewertung heraus —
        // Loerdys Apple TV stand deshalb unter „gefunden", aber in keinem Netz
        // (Forum t/144417). Solche Router werden gezielt nachgefragt.
        $borderRoutersRaw = $resolve(self::SERVICE_MESHCOP);
        $borderRouters    = [];
        $missingRouterTxt = [];
        foreach ($borderRoutersRaw as $br) {
            $routerTxt       = $txt[strtolower($br['instance'])] ?? [];
            $borderRouters[] = [
                'instance'  => $br['instance'],
                'name'      => explode('.', $br['instance'])[0],
                'host'      => $br['host'],
                'addresses' => $br['addresses'],
                'source'    => $br['source'],
                'txt'       => $routerTxt,
            ];
            if ($routerTxt === []) {
                $missingRouterTxt[] = $br['instance'];
            }
        }

        // Annoncen des eigenen Hosts sind keine Geräte: Unter Linux annonciert Symcon
        // einen Dummy-Record für seine eigene Fabric (…-FFFFFFEFFFFFFFFF), und die
        // Antwort kommt per Multicast-Loopback zurück.
        // Schläft das Gerät? Der Schlüssel ICD sagt es ausdrücklich; sonst das Session Idle
        // Interval SII: Ein Batteriegerät meldet sich selten und nennt ein langes Intervall
        // (KLIPPBOK 15800 ms, MYGGBETT 17000 ms), ein Gerät am Strom ein kurzes (GRILLPLATS
        // 2000 ms, andere 500 ms) — alles echte Werte vom 18.09.2026. Das bloße Vorhandensein
        // von SII/SAI taugt nicht: Auch die GRILLPLATS am Strom trägt beide. Ohne TXT bleibt
        // es unbekannt, damit ein vermisstes Gerät später richtig eingeordnet wird.
        $operationalDevices    = array_map(
            static function (array $device) use ($txt): array {
                $record           = $txt[strtolower($device['instance'])] ?? null;
                $device['sleepy'] = $record === null ? null : self::sleepyFromTxt($record);

                return $device;
            },
            $resolve(self::SERVICE_MATTER, true)
        );
        $commissionableDevices = $resolve(self::SERVICE_COMMISSIONABLE);

        // Ob das Kopplungsfenster wirklich offen ist, steht im TXT-Schlüssel CM
        // (0 = nicht im Kopplungsmodus, 1 = Fenster offen, 2 = per Administrator
        // geöffnet). Shelly annonciert _matterc nach jedem Boot ~15 Minuten lang
        // mit CM=0 (Extended Discovery) — ohne diesen Blick zählte das als
        // "koppelbereit" (Lehrgeld 08.09.2026). Fehlt das TXT, bleibt der Modus
        // unbekannt (null) und wird gezielt nachgefragt. Ein vorhandenes TXT ohne
        // brauchbaren CM-Wert bleibt ebenfalls unbekannt — aber ohne Nachfrage.
        $missingTxt = [];
        foreach ($commissionableDevices as &$device) {
            $record = $txt[strtolower($device['instance'])] ?? null;
            if ($record === null) {
                $missingTxt[]                = $device['instance'];
                $device['commissioningMode'] = null;
                continue;
            }
            $raw                         = array_change_key_case($record, CASE_UPPER)['CM'] ?? null;
            $device['commissioningMode'] = ($raw !== null && ctype_digit((string)$raw)) ? (int)$raw : null;
        }
        unset($device);

        return [
            'borderRouters'         => $borderRouters,
            'operationalDevices'    => $operationalDevices,
            'commissionableDevices' => $commissionableDevices,
            'missingRouterTxt'      => array_values(array_unique($missingRouterTxt)),
            'missingSrv'            => array_values(array_unique($missingSrv)),
            'missingAddresses'      => array_values(array_unique($missingAddresses)),
            'missingTxt'            => array_values(array_unique($missingTxt)),
        ];
    }

    /**
     * Stellt die Nachfragen für die nächste mDNS-Runde in der Reihenfolge ihrer
     * Bedeutung zusammen, damit die Kappung auf $limit nie das Wichtige trifft:
     * zuerst die AAAA der Border Router ohne IPv6-Adresse (ohne ihre Link-Local
     * ist keine Routenbewertung möglich), dann die TXT der Border Router selbst
     * (ohne sie gehören sie zu keinem Thread-Netz), dann die TXT der
     * _matterc-Annoncen (Kopplungsmodus), dann die SRV (erst sie liefern Hostnamen), zuletzt die
     * übrigen AAAA. Anlass (08.09.2026): 29 _matter-Instanzen des Apple-Proxys
     * ohne SRV füllten die Liste, die AAAA-Nachfrage für den Border Router fiel
     * hinten runter — und umgekehrt verhungerten die SRV, sobald reine
     * IPv4-Hosts jede Runde erneut nach AAAA gefragt wurden, obwohl sie nie
     * antworten. Deshalb kommen bereits gestellte Fragen ($asked) nicht wieder;
     * liefert der Aufruf nichts mehr, ist die Nachfrage erschöpft.
     *
     * @param array{borderRouters: array<int, array{host: string, addresses: array<int, string>}>, missingSrv: array<int, string>, missingAddresses: array<int, string>, missingTxt: array<int, string>} $survey
     * @param array<int, array{name: string, type: int}> $asked bereits gestellte Fragen aller Runden
     * @return array<int, array{name: string, type: int}>
     */
    public static function followUpQuestions(array $survey, int $limit = 20, array $asked = []): array
    {
        $questions = [];
        $seen      = [];
        foreach ($asked as $question) {
            $seen[strtolower($question['name']) . '/' . $question['type']] = true;
        }
        $add       = static function (string $name, int $type) use (&$questions, &$seen): void {
            $key = strtolower($name) . '/' . $type;
            if ($name === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key]  = true;
            $questions[] = ['name' => $name, 'type' => $type];
        };

        foreach ($survey['borderRouters'] as $router) {
            if (!self::hasIpv6($router['addresses'])) {
                $add($router['host'], MdnsCodec::TYPE_AAAA);
            }
        }
        foreach ($survey['missingRouterTxt'] ?? [] as $instance) {
            $add($instance, MdnsCodec::TYPE_TXT);
        }
        foreach ($survey['missingTxt'] as $instance) {
            $add($instance, MdnsCodec::TYPE_TXT);
        }
        foreach ($survey['missingSrv'] as $instance) {
            $add($instance, MdnsCodec::TYPE_SRV);
        }
        foreach ($survey['missingAddresses'] as $host) {
            $add($host, MdnsCodec::TYPE_AAAA);
        }

        return array_slice($questions, 0, max(0, $limit));
    }

    /**
     * Link-Local-Adressen der aktuellen Border Router für den Gateway-Abgleich —
     * aber nur, wenn sie für ALLE bekannt sind. Liefert ein Border Router per mDNS
     * nur GUA/ULA (Avahi/OTBR auf einem Pi), lässt sich sein Gateway nicht belegen;
     * dann lieber kein Urteil als ein Löschrat ohne Beweislage (Review 08.09.2026).
     *
     * @param array<int, array{host: string, addresses: array<int, string>}> $borderRouters
     * @return array<int, string> kleingeschrieben; leer, wenn die Liste unvollständig wäre
     */
    public static function borderRouterLinkLocals(array $borderRouters): array
    {
        $linkLocals = [];
        foreach ($borderRouters as $router) {
            $found = null;
            foreach ($router['addresses'] as $address) {
                if (stripos($address, 'fe80:') === 0) {
                    $found = strtolower($address);
                    break;
                }
            }
            if ($found === null) {
                return [];
            }
            $linkLocals[] = $found;
        }

        return $linkLocals;
    }

    /** Session Idle Interval (ms), ab dem ein Gerät als Energiesparknoten gilt. */
    public const SLEEPY_SII_MS = 5000;

    /**
     * Schlafangabe aus dem TXT einer _matter-Annonce: true (Batterie/Energiesparen),
     * false (am Strom) oder null, wenn das TXT nichts dazu sagt.
     *
     * @param array<string, string> $txt
     */
    public static function sleepyFromTxt(array $txt): ?bool
    {
        $upper = [];
        foreach ($txt as $key => $value) {
            if ($key !== '') {
                $upper[strtoupper((string)$key)] = (string)$value;
            }
        }
        if ($upper === []) {
            return null;
        }
        if (isset($upper['ICD'])) {
            return true;
        }
        if (isset($upper['SII']) && ctype_digit($upper['SII'])) {
            return (int)$upper['SII'] >= self::SLEEPY_SII_MS;
        }

        return false;
    }

    /**
     * /64-Präfixe der Geräte, die ein Border Router stellvertretend annonciert (Quelle
     * der Annonce = Adresse eines Border Routers) und die keine IPv4 haben — der Beleg,
     * dass ein Präfix ein Thread-Präfix ist, auch wenn es kein ULA ist (build 45).
     *
     * @param array<int, array{addresses: array<int, string>, source: string}> $devices
     * @param array<int, array{source: string}> $borderRouters
     * @return array<int, string>
     */
    public static function proxiedPrefixes(array $devices, array $borderRouters): array
    {
        $sources = array_flip(array_map(static fn(array $br): string => (string)$br['source'], $borderRouters));
        $result  = [];
        foreach ($devices as $device) {
            if (!isset($sources[(string)$device['source']])) {
                continue;
            }
            foreach ($device['addresses'] as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    continue 2; // LAN-Gerät — auch wenn ein Router es weiterreicht
                }
            }
            foreach ($device['addresses'] as $address) {
                if (stripos($address, 'fe80:') === 0) {
                    continue;
                }
                $prefix = DiagnosisEngine::prefix64($address);
                if ($prefix !== null && !in_array($prefix, $result, true)) {
                    $result[] = $prefix;
                }
            }
        }

        return $result;
    }

    /**
     * Adressen der Geräte, die hinter einem Thread Border Router liegen können.
     * Thread-Geräte haben nie eine IPv4-Adresse — wer eine hat (Shelly, Hue Bridge,
     * ein Gerät aus einem gespiegelten Nachbarsegment), hängt im LAN, und sein
     * ULA-Präfix ist ein Netzsegment, kein Thread-Netz. Ohne diese Trennung erklärte
     * das Modul Loerdys IoT-VLAN fdb2:3abb:80f6:2::/64 zum Thread-Netz und empfahl
     * eine Route über den Aqara-Hub (Forum t/144417, 18.09.2026).
     *
     * @param array<int, array{addresses: array<int, string>}> $devices
     * @return array<int, string>
     */
    public static function threadCandidateAddresses(array $devices): array
    {
        $result = [];
        foreach ($devices as $device) {
            $hasIpv4 = false;
            foreach ($device['addresses'] as $address) {
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $hasIpv4 = true;
                    break;
                }
            }
            if ($hasIpv4) {
                continue;
            }
            foreach ($device['addresses'] as $address) {
                $result[] = $address;
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Adressen für den Erreichbarkeitstest eines Thread-Präfixes, nach Betriebsart:
     * Netzgeräte zuerst (sie antworten), dann Unbekannte, Schlafende zuletzt. Auf dem
     * nuc traf der Ping sonst den schlafenden KLIPPBOK (0 von 4 Antworten) und meldete
     * „Weg besteht, kein Gerät antwortete", obwohl die GRILLPLATS am Strom im selben
     * Netz hängt (Debug-Auszug 18.09.2026).
     *
     * @param array<int, array{addresses: array<int, string>, sleepy?: bool|null}> $devices
     * @return array<int, string> höchstens $limit Adressen
     */
    public static function pingCandidates(array $devices, string $prefix, int $limit = 2): array
    {
        $rank  = [];
        $order = [];
        foreach ($devices as $index => $device) {
            $sleepy = $device['sleepy'] ?? null;
            $r      = $sleepy === false ? 0 : ($sleepy === null ? 1 : 2);
            foreach ($device['addresses'] as $address) {
                if (DiagnosisEngine::prefix64($address) !== $prefix || isset($rank[$address])) {
                    continue;
                }
                $rank[$address]  = $r;
                $order[$address] = count($order);
            }
        }
        uksort($rank, static fn(string $a, string $b): int => [$rank[$a], $order[$a]] <=> [$rank[$b], $order[$b]]);

        return array_slice(array_keys($rank), 0, max(0, $limit));
    }

    /**
     * Antworten ohne die des eigenen Hosts. Über Multicast-Loopback beantworten
     * Bonjour bzw. Avahi auf demselben Rechner jede Anfrage selbst — daran lässt
     * sich nicht ablesen, ob mDNS im Netz funktioniert.
     *
     * @param array<int, array{from: string}> $responses
     * @param array<int, string> $ownAddresses
     * @return array<int, array{from: string}>
     */
    public static function foreignResponses(array $responses, array $ownAddresses): array
    {
        return array_values(array_filter(
            $responses,
            static fn(array $response): bool => !self::isOwnAddress($response['from'], $ownAddresses)
        ));
    }

    /**
     * Steht fest, welche Thread-Präfixe in Gebrauch sind? Erst dann darf eine Route
     * als veraltet gelten (Review 17.09.2026: sonst empfahl das Modul, eine richtige
     * Route zu löschen, nur weil in diesem Lauf keine Geräteadresse ankam).
     *
     * Vollständig ist die Liste, wenn jeder Border Router sein OMR-Präfix nennt —
     * Apple-Border-Router tun das nicht — oder wenn jedes betriebsbereite Gerät bis
     * zur IPv6-Adresse aufgelöst ist. Ohne jede Matter-Antwort ist sie es nie.
     *
     * @param array{borderRouters: array<int, array{txt?: array<string, string>}>, operationalDevices: array<int, mixed>, missingSrv: array<int, string>, missingAddresses: array<int, string>} $survey
     */
    public static function prefixEvidenceComplete(array $survey): bool
    {
        if ($survey['borderRouters'] !== []) {
            $allWithOmr = true;
            foreach ($survey['borderRouters'] as $router) {
                if (ThreadNetwork::parseMeshcop($router['txt'] ?? [])['omr'] === null) {
                    $allWithOmr = false;
                    break;
                }
            }
            if ($allWithOmr) {
                return true;
            }
        }

        return $survey['operationalDevices'] !== []
            && $survey['missingSrv'] === []
            && $survey['missingAddresses'] === [];
    }

    /** Stammt die Quelle ("ip:port", "[ipv6]:port" oder nackte Adresse) vom eigenen Host? */
    private static function isOwnAddress(string $source, array $ownAddresses): bool
    {
        $address = preg_replace('/^\[(.*)\](?::\d+)?$/', '$1', $source) ?? $source;
        if (substr_count($address, ':') === 1) {
            $address = preg_replace('/:\d+$/', '', $address) ?? $address;
        }
        $address = strtolower(preg_replace('/%.*$/', '', $address) ?? $address);
        if ($address === '127.0.0.1' || $address === '::1') {
            return true;
        }

        return in_array($address, array_map('strtolower', $ownAddresses), true);
    }

    /**
     * @param array<int, string> $addresses
     */
    private static function hasIpv6(array $addresses): bool
    {
        foreach ($addresses as $address) {
            if (str_contains($address, ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ordnet jedem Thread-Präfix den passenden Border Router als Gateway zu:
     * bevorzugt den, dessen Advertising-Proxy das Gerät annonciert hat
     * (gleiche Quell-IP), als Gateway dessen Link-Local-Adresse.
     *
     * @param array<string, string> $prefixes Präfix => Beispiel-Adresse (aus DiagnosisEngine::threadPrefixes)
     * @param array<int, array{instance: string, host: string, addresses: array<int, string>, source: string}> $devices
     * @param array<int, array{name: string, host: string, addresses: array<int, string>, source: string}> $borderRouters
     * @return array<string, array{testAddress: string, gateway: string|null}>
     */
    public static function prefixGateways(array $prefixes, array $devices, array $borderRouters): array
    {
        $result = [];
        foreach ($prefixes as $prefix => $exampleAddress) {
            $source = null;
            foreach ($devices as $device) {
                foreach ($device['addresses'] as $address) {
                    if (DiagnosisEngine::prefix64($address) === $prefix) {
                        $source = $device['source'];
                        break 2;
                    }
                }
            }

            $gateway  = null;
            $fallback = null;
            foreach ($borderRouters as $br) {
                $linkLocal = null;
                foreach ($br['addresses'] as $address) {
                    if (stripos($address, 'fe80:') === 0) {
                        $linkLocal = $address;
                        break;
                    }
                }
                // Ohne Link-Local die erste IPv6-Adresse, nie eine IPv4 — ein Apple TV
                // nennt mitunter nur diese, und ein IPv6-Routenbefehl darüber ist ungültig.
                // Ohne IPv6 bleibt es beim Platzhalter im Befehl.
                $candidate = $linkLocal;
                foreach ($candidate === null ? $br['addresses'] : [] as $address) {
                    if (str_contains($address, ':')) {
                        $candidate = $address;
                        break;
                    }
                }
                if ($source !== null && $br['source'] === $source) {
                    $gateway = $candidate;
                    break;
                }
                $fallback ??= $candidate;
            }

            $result[$prefix] = [
                'testAddress' => $exampleAddress,
                'gateway'     => $gateway ?? $fallback,
            ];
        }

        return $result;
    }
}
