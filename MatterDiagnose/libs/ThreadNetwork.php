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
            $result[$field] = isset($txt[$field]) ? self::printable($txt[$field]) : null;
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
                    'xp'          => $xp,
                    'name'        => '',
                    'routers'     => [],
                    'partitions'  => [],
                    'timestamps'  => [],
                    'versions'    => [],
                    'vendors'     => [],
                    'omrPrefixes' => [],
                    'primaryBbr'  => null,
                ];
            }
            $network            = &$networks[$xp];
            $network['routers'][] = $name;
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
                $network['primaryBbr'] = $name;
            }
            unset($network);
        }

        foreach ($networks as &$network) {
            foreach (['partitions', 'timestamps', 'omrPrefixes'] as $key) {
                $network[$key] = array_values(array_unique($network[$key]));
            }
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
