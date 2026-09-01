<?php

declare(strict_types=1);

require_once __DIR__ . '/MdnsCodec.php';
require_once __DIR__ . '/DiagnoseEngine.php';

/**
 * Verdichtet dekodierte mDNS-Antworten zu einem strukturierten Lagebild:
 * Border Router, betriebsbereite und koppelbereite Matter-Geräte samt
 * Hostnamen, Adressen und Quell-IP der Annonce.
 *
 * Reine Funktionen — die Tests füttern sie mit echten Paketmitschnitten.
 */
class MatterErhebung
{
    public const DIENST_MESHCOP        = '_meshcop._udp.local';
    public const DIENST_MATTER         = '_matter._tcp.local';
    public const DIENST_KOPPELBEREIT   = '_matterc._udp.local';

    /**
     * @param array<int, array{from: string, message: array<string, mixed>}> $antworten
     * @param array<int, string> $eigeneAdressen IPv4- und IPv6-Adressen des eigenen Hosts
     * @return array{
     *     borderRouter: array<int, array{name: string, host: string, adressen: array<int, string>, quelle: string, txt: array<string, string>}>,
     *     geraeteBetrieb: array<int, array{instanz: string, host: string, port: int, adressen: array<int, string>, quelle: string}>,
     *     geraeteKoppelbereit: array<int, array{instanz: string, host: string, port: int, adressen: array<int, string>, quelle: string}>,
     *     eigeneAnkuendigung: bool,
     *     fehlendeSrv: array<int, string>,
     *     fehlendeAdressen: array<int, string>
     * }
     */
    public static function sammeln(array $antworten, array $eigeneAdressen): array
    {
        // --- Rohdaten über alle Antworten hinweg einsammeln ---------------
        $ptr      = []; // dienst => [instanzname => quelle]
        $srv      = []; // instanzname => {target, port}
        $txt      = []; // instanzname => array
        $adressen = []; // hostname => [adresse, ...]

        foreach ($antworten as $antwort) {
            $quelle = preg_replace('/:\d+$/', '', $antwort['from']);
            foreach ($antwort['message']['records'] as $record) {
                $name = $record['name'];
                switch ($record['type']) {
                    case MdnsCodec::TYPE_PTR:
                        $ptr[strtolower($name)][$record['target']] ??= $quelle;
                        break;
                    case MdnsCodec::TYPE_SRV:
                        $srv[$name] ??= ['target' => $record['target'], 'port' => $record['port']];
                        break;
                    case MdnsCodec::TYPE_TXT:
                        $txt[$name] ??= $record['txt'];
                        break;
                    case MdnsCodec::TYPE_A:
                    case MdnsCodec::TYPE_AAAA:
                        $adressen[strtolower($name)][] = $record['address'];
                        break;
                }
            }
        }
        foreach ($adressen as $host => $liste) {
            $adressen[$host] = array_values(array_unique($liste));
        }

        // --- Dienste zu Objekten auflösen ---------------------------------
        $fehlendeSrv      = [];
        $fehlendeAdressen = [];

        $aufloesen = static function (string $dienst) use (
            $ptr,
            $srv,
            $adressen,
            &$fehlendeSrv,
            &$fehlendeAdressen
        ): array {
            $result = [];
            foreach ($ptr[strtolower($dienst)] ?? [] as $instanz => $quelle) {
                $eintrag = [
                    'instanz'  => $instanz,
                    'host'     => '',
                    'port'     => 0,
                    'adressen' => [],
                    'quelle'   => $quelle,
                ];
                if (isset($srv[$instanz])) {
                    $eintrag['host'] = $srv[$instanz]['target'];
                    $eintrag['port'] = $srv[$instanz]['port'];
                    $hostKey         = strtolower($eintrag['host']);
                    if (isset($adressen[$hostKey])) {
                        $eintrag['adressen'] = $adressen[$hostKey];
                    } else {
                        $fehlendeAdressen[] = $eintrag['host'];
                    }
                } else {
                    $fehlendeSrv[] = $instanz;
                }
                $result[] = $eintrag;
            }

            return $result;
        };

        $borderRouterRoh = $aufloesen(self::DIENST_MESHCOP);
        $borderRouter    = [];
        foreach ($borderRouterRoh as $br) {
            $borderRouter[] = [
                'name'     => explode('.', $br['instanz'])[0],
                'host'     => $br['host'],
                'adressen' => $br['adressen'],
                'quelle'   => $br['quelle'],
                'txt'      => $txt[$br['instanz']] ?? [],
            ];
        }

        $geraeteBetrieb      = $aufloesen(self::DIENST_MATTER);
        $geraeteKoppelbereit = $aufloesen(self::DIENST_KOPPELBEREIT);

        // --- Hat unsere eigene Anlage geantwortet? ------------------------
        $eigene             = array_map('strtolower', $eigeneAdressen);
        $eigeneAnkuendigung = false;
        foreach ($geraeteBetrieb as $g) {
            if (in_array(strtolower($g['quelle']), $eigene, true)) {
                $eigeneAnkuendigung = true;
                break;
            }
        }

        return [
            'borderRouter'        => $borderRouter,
            'geraeteBetrieb'      => $geraeteBetrieb,
            'geraeteKoppelbereit' => $geraeteKoppelbereit,
            'eigeneAnkuendigung'  => $eigeneAnkuendigung,
            'fehlendeSrv'         => array_values(array_unique($fehlendeSrv)),
            'fehlendeAdressen'    => array_values(array_unique($fehlendeAdressen)),
        ];
    }

    /**
     * Ordnet jedem Thread-Präfix den passenden Border Router als Gateway zu:
     * bevorzugt den, dessen Advertising-Proxy das Gerät annonciert hat
     * (gleiche Quell-IP), als Gateway dessen Link-Local-Adresse.
     *
     * @param array<string, string> $praefixe Präfix => Beispiel-Adresse (aus DiagnoseEngine::threadPraefixe)
     * @param array<int, array{instanz: string, host: string, adressen: array<int, string>, quelle: string}> $geraete
     * @param array<int, array{name: string, host: string, adressen: array<int, string>, quelle: string}> $borderRouter
     * @return array<string, array{testAdresse: string, gateway: string|null}>
     */
    public static function praefixGateways(array $praefixe, array $geraete, array $borderRouter): array
    {
        $result = [];
        foreach ($praefixe as $praefix => $beispielAdresse) {
            $quelle = null;
            foreach ($geraete as $g) {
                foreach ($g['adressen'] as $adresse) {
                    if (DiagnoseEngine::praefix64($adresse) === $praefix) {
                        $quelle = $g['quelle'];
                        break 2;
                    }
                }
            }

            $gateway = null;
            $ersatz  = null;
            foreach ($borderRouter as $br) {
                $linkLocal = null;
                foreach ($br['adressen'] as $adresse) {
                    if (stripos($adresse, 'fe80:') === 0) {
                        $linkLocal = $adresse;
                        break;
                    }
                }
                $kandidat = $linkLocal ?? ($br['adressen'][0] ?? null);
                if ($quelle !== null && $br['quelle'] === $quelle) {
                    $gateway = $kandidat;
                    break;
                }
                $ersatz ??= $kandidat;
            }

            $result[$praefix] = [
                'testAdresse' => $beispielAdresse,
                'gateway'     => $gateway ?? $ersatz,
            ];
        }

        return $result;
    }
}
