<?php

declare(strict_types=1);

require_once __DIR__ . '/MdnsCodec.php';
require_once __DIR__ . '/DiagnosisEngine.php';

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
     *     borderRouters: array<int, array{name: string, host: string, addresses: array<int, string>, source: string, txt: array<string, string>}>,
     *     operationalDevices: array<int, array{instance: string, host: string, port: int, addresses: array<int, string>, source: string}>,
     *     commissionableDevices: array<int, array{instance: string, host: string, port: int, addresses: array<int, string>, source: string, commissioningMode: int|null}>,
     *     ownAnnouncement: bool,
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
        $txt       = []; // instanzname => array
        $addresses = []; // hostname => [adresse, ...]

        foreach ($responses as $response) {
            $source = preg_replace('/:\d+$/', '', $response['from']);
            foreach ($response['message']['records'] as $record) {
                $name = $record['name'];
                switch ($record['type']) {
                    case MdnsCodec::TYPE_PTR:
                        $ptr[strtolower($name)][$record['target']] ??= $source;
                        break;
                    case MdnsCodec::TYPE_SRV:
                        $srv[$name] ??= ['target' => $record['target'], 'port' => $record['port']];
                        break;
                    case MdnsCodec::TYPE_TXT:
                        $txt[$name] ??= $record['txt'];
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

        $resolve = static function (string $service) use (
            $ptr,
            $srv,
            $addresses,
            &$missingSrv,
            &$missingAddresses
        ): array {
            $result = [];
            foreach ($ptr[strtolower($service)] ?? [] as $instance => $source) {
                $entry = [
                    'instance'  => $instance,
                    'host'      => '',
                    'port'      => 0,
                    'addresses' => [],
                    'source'    => $source,
                ];
                if (isset($srv[$instance])) {
                    $entry['host'] = $srv[$instance]['target'];
                    $entry['port'] = $srv[$instance]['port'];
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

        $borderRoutersRaw = $resolve(self::SERVICE_MESHCOP);
        $borderRouters    = [];
        foreach ($borderRoutersRaw as $br) {
            $borderRouters[] = [
                'name'      => explode('.', $br['instance'])[0],
                'host'      => $br['host'],
                'addresses' => $br['addresses'],
                'source'    => $br['source'],
                'txt'       => $txt[$br['instance']] ?? [],
            ];
        }

        $operationalDevices    = $resolve(self::SERVICE_MATTER);
        $commissionableDevices = $resolve(self::SERVICE_COMMISSIONABLE);

        // Ob das Kopplungsfenster wirklich offen ist, steht im TXT-Schlüssel CM
        // (0 = nicht im Kopplungsmodus, 1 = Fenster offen, 2 = per Administrator
        // geöffnet). Shelly annonciert _matterc nach jedem Boot ~15 Minuten lang
        // mit CM=0 (Extended Discovery) — ohne diesen Blick zählte das als
        // "koppelbereit" (Lehrgeld 08.09.2026). Fehlt das TXT, bleibt der Modus
        // unbekannt (null) und wird gezielt nachgefragt.
        $missingTxt = [];
        foreach ($commissionableDevices as &$device) {
            $mode = $txt[$device['instance']]['CM'] ?? null;
            if ($mode === null) {
                $missingTxt[] = $device['instance'];
            }
            $device['commissioningMode'] = $mode === null ? null : (int)$mode;
        }
        unset($device);

        // --- Hat unsere eigene Anlage geantwortet? ------------------------
        $own             = array_map('strtolower', $ownAddresses);
        $ownAnnouncement = false;
        foreach ($operationalDevices as $device) {
            if (in_array(strtolower($device['source']), $own, true)) {
                $ownAnnouncement = true;
                break;
            }
        }

        return [
            'borderRouters'         => $borderRouters,
            'operationalDevices'    => $operationalDevices,
            'commissionableDevices' => $commissionableDevices,
            'ownAnnouncement'       => $ownAnnouncement,
            'missingSrv'            => array_values(array_unique($missingSrv)),
            'missingAddresses'      => array_values(array_unique($missingAddresses)),
            'missingTxt'            => array_values(array_unique($missingTxt)),
        ];
    }

    /**
     * Stellt die Nachfragen für die zweite mDNS-Runde in der Reihenfolge ihrer
     * Bedeutung zusammen, damit die Kappung auf $limit nie das Wichtige trifft:
     * zuerst die AAAA der Border Router ohne IPv6-Adresse (ohne ihre Link-Local
     * ist keine Routenbewertung möglich), dann die TXT der _matterc-Annoncen
     * (Kopplungsmodus), dann die übrigen AAAA, zuletzt die SRV. Anlass
     * (08.09.2026): 29 _matter-Instanzen des Apple-Proxys ohne SRV füllten die
     * Liste, die AAAA-Nachfrage für den Border Router fiel hinten runter.
     *
     * @param array{borderRouters: array<int, array{host: string, addresses: array<int, string>}>, missingSrv: array<int, string>, missingAddresses: array<int, string>, missingTxt: array<int, string>} $survey
     * @return array<int, array{name: string, type: int}>
     */
    public static function followUpQuestions(array $survey, int $limit = 20): array
    {
        $questions = [];
        $seen      = [];
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
        foreach ($survey['missingTxt'] as $instance) {
            $add($instance, MdnsCodec::TYPE_TXT);
        }
        foreach ($survey['missingAddresses'] as $host) {
            $add($host, MdnsCodec::TYPE_AAAA);
        }
        foreach ($survey['missingSrv'] as $instance) {
            $add($instance, MdnsCodec::TYPE_SRV);
        }

        return array_slice($questions, 0, max(0, $limit));
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
                $candidate = $linkLocal ?? ($br['addresses'][0] ?? null);
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
