<?php

declare(strict_types=1);

require_once __DIR__ . '/OsAdapter.php';

/**
 * Liest die IPv6-Routingtabelle (netsh unter Windows, ip -6 route unter Linux)
 * und bewertet die Routen zu Thread-Präfixen:
 *
 *  - notPersistent: Route nur im aktiven Speicher (Windows) — nach dem nächsten
 *    Neustart weg. Beleg vom nuc (02.09.2026): "show route store=persistent" war leer.
 *  - stale: Route zu einem ULA-Präfix, das kein Border Router mehr annonciert und
 *    kein Gerät mehr nutzt — typisch nach Präfixwechsel (Reset/Tausch des Routers).
 *  - gatewayUnknown: Gateway ist keine Link-Local-Adresse eines aktuellen Border
 *    Routers — der Router wurde getauscht oder hat eine neue Adresse.
 *
 * Reine Logik ohne Systemzugriff. Die netsh-Ausgabe kommt in Codepage 850, die
 * Kopfzeilen enthalten deshalb Byte-Reste; gelesen werden nur die ASCII-Spalten
 * Typ, Präfix, Index und Gateway.
 */
class RouteTable
{
    /**
     * @return array<int, array{prefix: string, length: int, gateway: ?string, interface: string, type: string}>
     */
    public static function parse(string $platform, string $output): array
    {
        $windows = strcasecmp($platform, OsAdapter::PLATFORM_WINDOWS) === 0;
        $routes  = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($windows) {
                // Veröff.  Typ  Met  Präfix/Länge  Idx  Gateway-oder-Schnittstellenname
                //
                // Die Metrik ist NICHT immer eine Zahl: Im persistenten Speicher
                // steht dort "Standard" bzw. "Default" (echter Mitschnitt nuc,
                // 02.09.2026: "Nein  Andere  Standard  fd89:6b7:bc55::/64  12  fe80::…").
                // Mit \d+ fiel jede persistente Zeile durch das Raster, und das
                // Modul warnte ausgerechnet dann vor fehlender Dauerhaftigkeit,
                // wenn die Route gerade dauerhaft gesetzt worden war.
                if (preg_match('/^\S+\s+(\S+)\s+\S+\s+([0-9A-Fa-f:]+)\/(\d{1,3})\s+(\d+)\s+(.+)$/', $line, $m) !== 1) {
                    continue;
                }
                $type      = $m[1];
                $prefix    = $m[2];
                $length    = (int)$m[3];
                $interface = $m[4];
                $tail      = trim($m[5]);
                $gateway   = self::isIpv6($tail) ? strtolower($tail) : null;
            } else {
                // fd89::/64 via fe80::1 dev eth0 proto ra metric 100 …  ("default …" wird übersprungen)
                if (preg_match('/^([0-9A-Fa-f:]+)\/(\d{1,3})(?:\s+via\s+(\S+))?\s+dev\s+(\S+)(?:.*?\bproto\s+(\S+))?/', $line, $m) !== 1) {
                    continue;
                }
                $prefix    = $m[1];
                $length    = (int)$m[2];
                $gateway   = ($m[3] ?? '') !== '' ? strtolower($m[3]) : null;
                $interface = $m[4];
                $type      = $m[5] ?? '';
            }
            $network = self::network($prefix, $length);
            if ($network === null) {
                continue;
            }
            $routes[] = [
                'prefix'    => $network,
                'length'    => $length,
                'gateway'   => $gateway,
                'interface' => $interface,
                'type'      => $type,
            ];
        }

        return $routes;
    }

    /**
     * Schnittstelle (Windows: Index, Linux: Gerätename), an der die eigenen
     * Adressen hängen — für den empfohlenen Routenbefehl. Globale Adressen zuerst,
     * Link-Local nur als Rückfall (fe80::/64 gibt es an jedem Interface).
     *
     * @param array<int, array{prefix: string, length: int, gateway: ?string, interface: string}> $routes
     * @param array<int, string> $ownAddresses
     */
    public static function interfaceForAddresses(array $routes, array $ownAddresses): ?string
    {
        foreach ([false, true] as $allowLinkLocal) {
            foreach ($ownAddresses as $address) {
                if (self::isLinkLocal($address) !== $allowLinkLocal) {
                    continue;
                }
                foreach ($routes as $route) {
                    if ($route['gateway'] !== null || $route['length'] === 0) {
                        continue;
                    }
                    if (!$allowLinkLocal && self::isLinkLocal($route['prefix'])) {
                        continue;
                    }
                    if (self::inNetwork($address, $route['prefix'], $route['length'])) {
                        return $route['interface'];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, array{prefix: string, length: int, gateway: ?string, interface: string}> $routes
     * @param array<int, array{prefix: string, length: int}>|null $persistentRoutes Windows: Inhalt von store=persistent; Linux: null
     * @param array<int, string> $prefixesInUse Thread-/64-Präfixe aus OMR-Records und Geräteadressen
     * @param array<int, string> $borderRouterLinkLocals Link-Local-Adressen der aktuellen Border Router
     * @param array<int, string> $ownAddresses eigene Adressen (deren ULA-Präfix gehört zum LAN, nicht zu Thread)
     * @return array{notPersistent: array<int, array<string, mixed>>, stale: array<int, array<string, mixed>>, gatewayUnknown: array<int, array<string, mixed>>}
     */
    public static function assess(
        array $routes,
        ?array $persistentRoutes,
        array $prefixesInUse,
        array $borderRouterLinkLocals,
        array $ownAddresses,
        string $platform
    ): array {
        $result  = ['notPersistent' => [], 'stale' => [], 'gatewayUnknown' => []];
        $windows = strcasecmp($platform, OsAdapter::PLATFORM_WINDOWS) === 0;

        $ownPrefixes = [];
        foreach ($ownAddresses as $address) {
            $prefix = self::prefix64($address);
            if ($prefix !== null) {
                $ownPrefixes[$prefix] = true;
            }
        }
        $inUse = [];
        foreach ($prefixesInUse as $prefix) {
            $key = self::prefix64($prefix);
            if ($key !== null) {
                $inUse[$key] = true;
            }
        }
        $persistentKeys = null;
        if ($persistentRoutes !== null) {
            $persistentKeys = [];
            foreach ($persistentRoutes as $route) {
                $persistentKeys[$route['prefix'] . '/' . $route['length']] = true;
            }
        }
        $linkLocals = array_map('strtolower', $borderRouterLinkLocals);

        foreach ($routes as $route) {
            // Nur Thread-artige Routen: ULA-Präfix über ein Link-Local-Gateway
            if ($route['gateway'] === null || !self::isLinkLocal($route['gateway'])) {
                continue;
            }
            if (!self::isUla($route['prefix']) || $route['length'] < 48) {
                continue;
            }
            $prefix64 = self::prefix64($route['prefix']);
            if ($prefix64 === null || isset($ownPrefixes[$prefix64])) {
                continue; // das ULA-Präfix des eigenen LANs, geroutet über den Heimrouter
            }
            $entry = [
                'prefix'    => $route['prefix'],
                'length'    => $route['length'],
                'gateway'   => $route['gateway'],
                'interface' => $route['interface'],
            ];
            if (!isset($inUse[$prefix64])) {
                $result['stale'][] = $entry;
                continue;
            }
            if ($linkLocals !== [] && !in_array($route['gateway'], $linkLocals, true)) {
                $result['gatewayUnknown'][] = $entry;
                continue;
            }
            if ($windows && $persistentKeys !== null && !isset($persistentKeys[$route['prefix'] . '/' . $route['length']])) {
                $result['notPersistent'][] = $entry;
            }
        }

        return $result;
    }

    private static function isIpv6(string $value): bool
    {
        return str_contains($value, ':') && @inet_pton($value) !== false && strlen((string)@inet_pton($value)) === 16;
    }

    private static function isLinkLocal(string $address): bool
    {
        $binary = @inet_pton($address);

        return is_string($binary) && strlen($binary) === 16 && ord($binary[0]) === 0xFE && (ord($binary[1]) & 0xC0) === 0x80;
    }

    private static function isUla(string $address): bool
    {
        $binary = @inet_pton($address);

        return is_string($binary) && strlen($binary) === 16 && (ord($binary[0]) & 0xFE) === 0xFC;
    }

    /** Netzadresse eines Präfixes in kanonischer Schreibweise (Hostbits genullt). */
    private static function network(string $prefix, int $length): ?string
    {
        $binary = @inet_pton($prefix);
        if (!is_string($binary) || strlen($binary) !== 16 || $length < 0 || $length > 128) {
            return null;
        }
        $masked = '';
        for ($i = 0; $i < 16; $i++) {
            $bits = max(0, min(8, $length - $i * 8));
            $mask = $bits === 0 ? 0 : (0xFF << (8 - $bits)) & 0xFF;
            $masked .= chr(ord($binary[$i]) & $mask);
        }
        $result = inet_ntop($masked);

        return $result === false ? null : $result;
    }

    private static function inNetwork(string $address, string $network, int $length): bool
    {
        return self::network($address, $length) === self::network($network, $length);
    }

    private static function prefix64(string $address): ?string
    {
        return self::network($address, 64);
    }
}
