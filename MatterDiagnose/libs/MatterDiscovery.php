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
     *     commissionableDevices: array<int, array{instance: string, host: string, port: int, addresses: array<int, string>, source: string}>,
     *     ownAnnouncement: bool,
     *     missingSrv: array<int, string>,
     *     missingAddresses: array<int, string>
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
                    } else {
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
        ];
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
