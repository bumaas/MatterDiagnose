<?php

declare(strict_types=1);

/**
 * Thread-Netz-Gesundheit aus den _meshcop._udp-TXT-Records der Border Router.
 *
 * Reine Logik ohne Netzwerkzugriff. Die relevanten TXT-Schlüssel (Thread
 * MeshCoP Border Agent Advertisement):
 *   xp  Extended PAN ID (8 Byte)      — identifiziert das Thread-Netz
 *   nn  Network Name                  — lesbarer Netzname
 *   pt  Partition ID (4 Byte)         — Teilnetz; verschiedene IDs = Netz zerfallen
 *   at  Active Timestamp (8 Byte)     — Version des aktiven Datensatzes
 *   sb  State Bitmap (4 Byte)         — Bit 7: BBR aktiv, Bit 8: BBR primär
 *   tv  Thread Version, vn/mn Hersteller/Modell, dn Domain Name
 *   omr Off-Mesh-Routable-Präfix (1 Byte Länge + Präfixbytes) — nur bei manchen
 *       Herstellern (IKEA DIRIGERA), Apple lässt es weg
 *
 * Stolperstein aus dem nuc-LAN (02.09.2026): Apple TV und DIRIGERA melden
 * dieselbe Partition-ID in umgekehrter Byte-Reihenfolge (C3F6CB75 / 75CBF6C3).
 * Ein Vergleich muss deshalb beide Reihenfolgen als gleich werten.
 */
class ThreadNetwork
{
    private const HEX_FIELDS  = ['xp', 'xa', 'id', 'pt', 'at', 'sb', 'bb', 'dd'];
    private const TEXT_FIELDS = ['nn', 'vn', 'mn', 'tv', 'dn', 'rv', 'vp'];

    private const SB_BBR_ACTIVE  = 0x80;
    private const SB_BBR_PRIMARY = 0x100;

    /**
     * Typisiert die rohen TXT-Werte: Binärfelder als Großbuchstaben-Hex, Textfelder
     * als String, OMR als Präfix + Länge, Statusbits als Flags.
     *
     * @param array<string, string> $txt
     * @return array<string, mixed>
     */
    public static function parseMeshcop(array $txt): array
    {
        $result = ['bbrActive' => false, 'bbrPrimary' => false, 'omr' => null, 'partitionKey' => null];
        foreach (self::HEX_FIELDS as $field) {
            $value          = $txt[$field] ?? '';
            $result[$field] = $value === '' ? null : strtoupper(bin2hex($value));
        }
        foreach (self::TEXT_FIELDS as $field) {
            // fremder Text, landet in Befunden und Bericht (MCP-Regel 17)
            $result[$field] = isset($txt[$field]) ? ForeignText::clean(self::printable($txt[$field])) : null;
        }
        if ($result['pt'] !== null) {
            $result['partitionKey'] = self::normalizePartition($result['pt']);
        }
        if ($result['sb'] !== null) {
            $bits                 = (int)hexdec(substr($result['sb'], -8));
            $result['bbrActive']  = ($bits & self::SB_BBR_ACTIVE) !== 0;
            $result['bbrPrimary'] = ($bits & self::SB_BBR_PRIMARY) !== 0;
        }
        $omr = $txt['omr'] ?? '';
        if (strlen($omr) >= 2) {
            $length = ord($omr[0]);
            $bytes  = substr($omr, 1);
            if ($length <= 128 && strlen($bytes) <= 16) {
                $address = inet_ntop(str_pad($bytes, 16, chr(0)));
                if ($address !== false) {
                    $result['omr'] = ['prefix' => $address, 'length' => $length];
                }
            }
        }

        return $result;
    }

    /**
     * Macht die Partition-ID unabhängig von der Byte-Reihenfolge des Herstellers:
     * Die lexikografisch kleinere der beiden Darstellungen ist der Schlüssel.
     */
    public static function normalizePartition(string $hex): string
    {
        $hex    = strtoupper($hex);
        $binary = @hex2bin($hex);
        if (!is_string($binary)) {
            return $hex;
        }
        $reversed = strtoupper(bin2hex(strrev($binary)));

        return strcmp($hex, $reversed) <= 0 ? $hex : $reversed;
    }

    /**
     * Gruppiert die Border Router nach Thread-Netz (Extended PAN ID) und sammelt
     * je Netz Partitionen, Zeitstempel, Versionen, Hersteller und OMR-Präfixe.
     *
     * @param array<int, array{name: string, addresses: array<int, string>, txt: array<string, string>}> $borderRouters
     * `routers` zählt alle gefundenen Border Router, auch die ohne lesbaren Datensatz —
     * die stehen in `unknown` und bilden kein Netz. Wer aus der Zahl auf die Mitglieder
     * eines Netzes schließt, urteilt zu früh (Cloud-Review 20.09.2026).
     *
     * @return array{routers: int, unknown: array<int, string>, networks: array<int, array<string, mixed>>}
     */
    public static function assess(array $borderRouters): array
    {
        $networks = [];
        $unknown  = [];
        foreach ($borderRouters as $router) {
            $parsed = self::parseMeshcop($router['txt'] ?? []);
            $name   = (string)($router['name'] ?? '');
            if ($parsed['xp'] === null) {
                $unknown[] = $name;
                continue;
            }
            $xp = $parsed['xp'];
            if (!isset($networks[$xp])) {
                $networks[$xp] = [
                    'xp'              => $xp,
                    'name'            => '',
                    'routers'         => [],
                    'routerLabels'    => [],
                    'partitions'      => [],
                    'timestamps'      => [],
                    'versions'        => [],
                    'vendors'         => [],
                    'omrPrefixes'     => [],
                    'primaryBbr'      => null,
                    'primaryBbrLabel' => null,
                ];
            }
            $network              = &$networks[$xp];
            $network['routers'][] = $name;

            // Der Gerätename allein ("Wohnzimmer") sagt niemandem, um welches
            // Gerät es geht. Mit dem Hersteller dahinter ist es auffindbar.
            $network['routerLabels'][] = self::routerLabel($name, $parsed['vn']);
            if ($network['name'] === '' && $parsed['nn'] !== null) {
                $network['name'] = $parsed['nn'];
            }
            if ($parsed['partitionKey'] !== null) {
                $network['partitions'][] = $parsed['partitionKey'];
            }
            if ($parsed['at'] !== null) {
                $network['timestamps'][] = $parsed['at'];
            }
            if ($parsed['tv'] !== null) {
                $network['versions'][] = $parsed['tv'];
            }
            if ($parsed['vn'] !== null) {
                $network['vendors'][] = $parsed['vn'];
            }
            if ($parsed['omr'] !== null) {
                $network['omrPrefixes'][] = self::prefix64($parsed['omr']['prefix']) ?? $parsed['omr']['prefix'];
            }
            if ($parsed['bbrPrimary'] && $network['primaryBbr'] === null) {
                $network['primaryBbr']      = $name;
                $network['primaryBbrLabel'] = self::routerLabel($name, $parsed['vn']);
            }
            unset($network);
        }

        foreach ($networks as &$network) {
            foreach (['partitions', 'timestamps', 'omrPrefixes'] as $key) {
                $network[$key] = array_values(array_unique($network[$key]));
            }
            $network['timestamps'] = self::mergeShiftedTimestamps($network['timestamps']);
            foreach (['versions', 'vendors'] as $key) {
                $values = array_values(array_unique($network[$key]));
                sort($values);
                $network[$key] = $values;
            }
        }
        unset($network);

        return [
            'routers'  => count($borderRouters),
            'unknown'  => $unknown,
            'networks' => array_values($networks),
        ];
    }

    /**
     * Fasst Zeitstempel zusammen, die nur die Kodierung unterscheidet. Die Spezifikation legt
     * die Sekunden in die oberen 48 Bit (darunter 15 Bit Ticks und das U-Bit), Apple hält sich
     * daran. Der SLZB-MR5U mit OpenThread schreibt die Sekunden roh: `0000000066E89C30` neben
     * Apples `000066E89C300000` (Boui, t/144417/64, ActiveTimestampTest). Gleich ist nur genau
     * diese Verschiebung um 16 Bit; die Spezifikationsform bleibt stehen. Ticks und U-Bit
     * gehen in den Vergleich nicht ein, die rohe Form hat für sie keinen Platz.
     *
     * Jeder Wert hat höchstens einen Partner. Bei heutigen Sekunden beginnt auch die
     * Spezifikationsform mit `0000`; ohne diese Regel fielen X, X<<16 und X<<32 zu einem
     * Stand zusammen (Code-Review 15.10.2026).
     *
     * @param array<int, string> $timestamps Hex, je 16 Zeichen
     * @return array<int, string>
     */
    private static function mergeShiftedTimestamps(array $timestamps): array
    {
        $raw      = [];
        $partners = [];
        foreach ($timestamps as $timestamp) {
            foreach ($timestamps as $other) {
                if ($timestamp !== $other && strlen($timestamp) === 16 && strlen($other) === 16
                    && str_starts_with($timestamp, '0000')
                    && substr($other, 0, 12) === substr($timestamp, 4)) {
                    $raw[]      = $timestamp;
                    $partners[] = $other;
                }
            }
        }

        return array_values(array_diff($timestamps, array_diff($raw, $partners)));
    }

    /** Die Felder fürs Debug, in fester Reihenfolge: woran die Befunde hängen, dazu Name und Hersteller. */
    private const DEBUG_FIELDS = ['nn', 'vn', 'tv', 'xp', 'pt', 'at', 'sb'];

    /**
     * Die Werte der _meshcop-Felder für das Debug, Binärfelder als Hex („xp=BAE5… at=0000…“).
     * Bis build 90 standen dort nur die Schlüssel; für den Datensatz-Vergleich bei Boui
     * (t/144417/59, SLZB-MR5U neben HomePod) fehlten damit die Bytes, die `dns-sd` nur als
     * „?“ zeigt (MeshcopDebugTest). Freier Text mit Leerzeichen, Klammern, Anführungszeichen
     * oder „=“ steht in Hochkommas, ein Hochkomma darin verdoppelt; sonst ließe sich die
     * Zeile nicht mehr in Felder zerlegen (Code-Review 15.10.2026).
     *
     * @param array<string, string> $txt
     */
    public static function debugFields(array $txt): string
    {
        $parsed = self::parseMeshcop($txt);
        $parts  = [];
        foreach (self::DEBUG_FIELDS as $field) {
            if (isset($txt[$field]) && ($parsed[$field] ?? null) !== null) {
                $value   = (string)$parsed[$field];
                $parts[] = $field . '=' . ($value === '' || preg_match('/[\s()\'"=]/', $value) === 1
                    ? "'" . str_replace("'", "''", $value) . "'"
                    : $value);
            }
        }

        return implode(' ', $parts);
    }

    /**
     * Die TXT-Angabe eines Border Routers fürs Debug: erst die Schlüssel, deren Wert dort
     * nicht steht, dann in Klammern die Felder mit Wert (debugFields). Bis build 93 standen
     * alle Schlüssel vorn und die mit Wert ein zweites Mal, ohne Felder kam ein leeres „()“.
     *
     * @param array<string, string> $txt
     */
    public static function debugTxt(array $txt): string
    {
        $fields = self::debugFields($txt);
        $shown  = [];
        if ($fields !== '') {
            preg_match_all('/(?:^| )(\w+)=/', $fields, $found);
            $shown = $found[1];
        }
        $keys = array_values(array_filter(
            array_map('strval', array_keys($txt)),
            static fn(string $key): bool => !in_array($key, $shown, true)
        ));

        return trim(implode(' ', $keys) . ($fields === '' ? '' : ' (' . $fields . ')'));
    }

    /**
     * "Wohnzimmer (Apple)" — Gerätename mit Hersteller. Fehlt die
     * Herstellerangabe im TXT-Datensatz, bleibt es beim nackten Namen; eine
     * leere Klammer wäre schlechter als gar keine.
     */
    /** Anzeigename eines Border Routers: Gerätename mit Hersteller aus dem TXT-Schlüssel vn. */
    public static function routerLabel(string $name, ?string $vendor): string
    {
        // Der Hersteller kommt auch roh aus dem TXT (DeviceInventory) — fremder Text
        $vendor = $vendor === null ? '' : ForeignText::clean($vendor);

        return $vendor === '' ? $name : sprintf('%s (%s)', $name, $vendor);
    }

    /** Druckbare ASCII-Werte bleiben Text, alles andere wird als 0x-Hex dargestellt. */
    private static function printable(string $value): string
    {
        return preg_match('/^[\x20-\x7e]*$/', $value) === 1 ? $value : '0x' . strtoupper(bin2hex($value));
    }

    /** /64-Präfix in kanonischer Schreibweise oder null. */
    private static function prefix64(string $address): ?string
    {
        $binary = @inet_pton($address);
        if (!is_string($binary) || strlen($binary) !== 16) {
            return null;
        }

        return inet_ntop(substr($binary, 0, 8) . str_repeat(chr(0), 8));
    }
}
