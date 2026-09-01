<?php

declare(strict_types=1);

require_once __DIR__ . '/OsAdapter.php';

/**
 * Bewertet die Erhebungsdaten (mDNS-Funde, Erreichbarkeitstests, Systemdaten)
 * und erzeugt daraus Befunde. Reine Logik ohne Netzwerk- oder Systemzugriffe —
 * vollständig per Unit-Test abgedeckt.
 *
 * Ein Befund besteht aus Stufe ('ok' | 'hinweis' | 'blocker'), einer stabilen
 * ID und Parametern; die Übersetzung in Anzeigetexte übernimmt das Modul.
 */
class DiagnoseEngine
{
    public const STUFE_OK      = 'ok';
    public const STUFE_HINWEIS = 'hinweis';
    public const STUFE_BLOCKER = 'blocker';

    /**
     * @param array{
     *     ipv6Adressen: array<int, string>,
     *     mdnsAntworten: bool,
     *     borderRouter: array<int, array{name: string, host: string, adressen: array<int, string>, quelle: string, txt: array<string, string>}>,
     *     geraeteBetrieb: array<int, array{instanz: string, host: string, adressen: array<int, string>, quelle: string}>,
     *     geraeteKoppelbereit: array<int, array{instanz: string, host: string, adressen: array<int, string>, quelle: string}>,
     *     threadPraefixe: array<string, array{erreichbar: bool|null, testAdresse: string, gateway: string|null}>,
     *     eigeneAnkuendigung: bool,
     *     plattform: string,
     *     port5353Belegung: array<int, string>
     * } $e
     * @return array<int, array{stufe: string, id: string, params: array<string, string>}>
     */
    public static function auswerten(array $e): array
    {
        $befunde = [];

        // --- IPv6 auf dem eigenen System ---------------------------------
        $eigeneNichtLinkLocal = array_values(array_filter(
            $e['ipv6Adressen'],
            static fn(string $a): bool => stripos($a, 'fe80:') !== 0
        ));
        if ($eigeneNichtLinkLocal === []) {
            $befunde[] = self::befund(self::STUFE_BLOCKER, 'no_ipv6', []);
        } else {
            $befunde[] = self::befund(self::STUFE_OK, 'ipv6_ok', [
                'addresses' => implode(', ', array_slice($eigeneNichtLinkLocal, 0, 3)),
            ]);
        }

        // --- Kam überhaupt mDNS an? ---------------------------------------
        if (!$e['mdnsAntworten']) {
            $befunde[] = self::befund(self::STUFE_BLOCKER, 'mdns_silent', []);

            return $befunde; // ohne mDNS sind alle weiteren Aussagen wertlos
        }

        // --- Thread Border Router -----------------------------------------
        if ($e['borderRouter'] === []) {
            $befunde[] = self::befund(self::STUFE_HINWEIS, 'no_border_router', []);
        } else {
            $namen     = array_map(static fn(array $br): string => $br['name'], $e['borderRouter']);
            $befunde[] = self::befund(self::STUFE_OK, 'border_router_found', [
                'count' => (string)count($namen),
                'names' => implode(', ', $namen),
            ]);
        }

        // --- Sichtbare Matter-Geräte --------------------------------------
        if ($e['geraeteKoppelbereit'] !== []) {
            $befunde[] = self::befund(self::STUFE_OK, 'commissionable_found', [
                'count' => (string)count($e['geraeteKoppelbereit']),
                'hosts' => implode(', ', array_map(
                    // Fallback auf das Instanz-Label, solange der Hostname
                    // noch nicht aufgelöst ist
                    static fn(array $g): string => $g['host'] !== ''
                        ? $g['host']
                        : explode('.', $g['instanz'])[0],
                    $e['geraeteKoppelbereit']
                )),
            ]);
        } else {
            $befunde[] = self::befund(self::STUFE_HINWEIS, 'no_commissionable', []);
        }
        if ($e['geraeteBetrieb'] !== []) {
            $befunde[] = self::befund(self::STUFE_OK, 'operational_found', [
                'count' => (string)count($e['geraeteBetrieb']),
            ]);
        }

        // --- Erreichbarkeit der Thread-Präfixe ----------------------------
        foreach ($e['threadPraefixe'] as $praefix => $info) {
            if ($info['erreichbar'] === true) {
                $befunde[] = self::befund(self::STUFE_OK, 'thread_prefix_reachable', [
                    'prefix' => $praefix,
                ]);
            } elseif ($info['erreichbar'] === false) {
                if (($info['routeVorhanden'] ?? null) === true) {
                    // Route existiert — ausbleibende Antworten sind bei
                    // schlafenden Thread-Geräten kein Beleg für ein Problem
                    $befunde[] = self::befund(self::STUFE_HINWEIS, 'thread_prefix_no_reply', [
                        'prefix' => $praefix,
                    ]);
                } else {
                    $befunde[] = self::befund(self::STUFE_BLOCKER, 'thread_prefix_unreachable', [
                        'prefix'  => $praefix,
                        'command' => OsAdapter::routeAddCommand($e['plattform'], $praefix, $info['gateway']),
                    ]);
                }
            } else {
                $befunde[] = self::befund(self::STUFE_HINWEIS, 'thread_prefix_untested', [
                    'prefix' => $praefix,
                ]);
            }
        }

        // --- Annonciert sich der eigene Matter-Stack? ---------------------
        if ($e['eigeneAnkuendigung']) {
            $befunde[] = self::befund(self::STUFE_OK, 'own_controller_ok', []);
        } else {
            $befunde[] = self::befund(self::STUFE_BLOCKER, 'own_controller_missing', []);
        }

        // --- Port-5353-Konkurrenz (nur Windows relevant) ------------------
        if (strcasecmp($e['plattform'], 'Windows') === 0 && $e['port5353Belegung'] !== []) {
            $befunde[] = self::befund(self::STUFE_HINWEIS, 'port5353_competition', [
                'processes' => implode(', ', array_unique($e['port5353Belegung'])),
            ]);
        }

        return self::sortiert($befunde);
    }

    /**
     * Leitet aus den annoncierten Geräteadressen die Thread-Präfixe ab:
     * ULA-Adressen (fd00::/8), deren /64 nicht zu den eigenen On-Link-Präfixen
     * gehört, liegen hinter einem Border Router.
     *
     * @param array<int, string> $geraeteAdressen
     * @param array<int, string> $eigeneAdressen
     * @return array<string, string> Präfix => Beispiel-Adresse
     */
    public static function threadPraefixe(array $geraeteAdressen, array $eigeneAdressen): array
    {
        $eigenePraefixe = [];
        foreach ($eigeneAdressen as $adresse) {
            $p = self::praefix64($adresse);
            if ($p !== null) {
                $eigenePraefixe[$p] = true;
            }
        }

        $result = [];
        foreach ($geraeteAdressen as $adresse) {
            if (!self::istUla($adresse)) {
                continue;
            }
            $p = self::praefix64($adresse);
            if ($p === null || isset($eigenePraefixe[$p]) || isset($result[$p])) {
                continue;
            }
            $result[$p] = $adresse;
        }

        return $result;
    }

    public static function istUla(string $adresse): bool
    {
        $bin = @inet_pton($adresse);

        return is_string($bin) && strlen($bin) === 16 && (ord($bin[0]) & 0xFE) === 0xFC;
    }

    /** Liefert das /64-Präfix in kanonischer Schreibweise oder null bei ungültiger Adresse. */
    public static function praefix64(string $adresse): ?string
    {
        $bin = @inet_pton($adresse);
        if (!is_string($bin) || strlen($bin) !== 16) {
            return null;
        }

        return inet_ntop(substr($bin, 0, 8) . str_repeat(chr(0), 8));
    }

    /** @return array{stufe: string, id: string, params: array<string, string>} */
    private static function befund(string $stufe, string $id, array $params): array
    {
        return ['stufe' => $stufe, 'id' => $id, 'params' => $params];
    }

    /**
     * Blocker zuerst, dann Hinweise, dann OK — innerhalb der Stufe stabil.
     *
     * @param array<int, array{stufe: string, id: string, params: array<string, string>}> $befunde
     * @return array<int, array{stufe: string, id: string, params: array<string, string>}>
     */
    private static function sortiert(array $befunde): array
    {
        $rang = [self::STUFE_BLOCKER => 0, self::STUFE_HINWEIS => 1, self::STUFE_OK => 2];
        usort(
            $befunde,
            static fn(array $a, array $b): int => $rang[$a['stufe']] <=> $rang[$b['stufe']]
        );

        return $befunde;
    }
}
