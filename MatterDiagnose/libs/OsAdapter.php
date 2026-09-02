<?php

declare(strict_types=1);

/**
 * Plattformabhängige Kommandos bauen und deren Ausgaben parsen.
 *
 * Die Methoden, die Strings bauen oder parsen, sind rein und per Unit-Test
 * abgedeckt; nur execute() berührt das System.
 */
class OsAdapter
{
    public const PLATFORM_WINDOWS = 'Windows';
    public const PLATFORM_LINUX   = 'Linux';

    public static function platform(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? self::PLATFORM_WINDOWS : self::PLATFORM_LINUX;
    }

    /**
     * Führt ein Kommando aus und liefert die Ausgabe als UTF-8.
     *
     * Die Windows-Konsole liefert CP850 — ohne Umkodierung scheitert später
     * jedes json_encode still (Lehrgeld 27.08.2026).
     */
    public static function execute(string $command): string
    {
        $raw = (string)shell_exec($command . ' 2>&1');
        if (self::platform() === self::PLATFORM_WINDOWS) {
            $converted = @iconv('CP850', 'UTF-8//IGNORE', $raw);
            if (is_string($converted)) {
                return $converted;
            }
        }

        return $raw;
    }

    /**
     * Empfehlungs-Kommando, um die Route zu einem Thread-Präfix zu setzen.
     * Wird nur als Text angezeigt, nie ausgeführt — das Setzen braucht
     * Administratorrechte und bleibt eine bewusste Nutzerentscheidung.
     */
    public static function routeAddCommand(string $platform, string $prefix, ?string $gateway, ?string $interface = null): string
    {
        $gw = $gateway ?? '<Gateway-Adresse des Border-Routers>';
        if (strcasecmp($platform, self::PLATFORM_WINDOWS) === 0) {
            // store=persistent: ohne den Zusatz landet die Route je nach Werkzeug nur
            // im aktiven Speicher und ist nach dem nächsten Neustart weg (nuc, 02.09.2026).
            return sprintf('netsh interface ipv6 add route %s/64 %s %s store=persistent', $prefix, $interface ?? '"Ethernet"', $gw);
        }

        return sprintf('ip -6 route add %s/64 via %s', $prefix, $gw);
    }

    /** Empfehlungs-Kommando, um eine (veraltete oder ins Leere zeigende) Route zu entfernen. */
    public static function routeDeleteCommand(string $platform, string $prefix, int $length, ?string $gateway, ?string $interface): string
    {
        if (strcasecmp($platform, self::PLATFORM_WINDOWS) === 0) {
            return rtrim(sprintf('netsh interface ipv6 delete route %s/%d %s %s', $prefix, $length, $interface ?? '"Ethernet"', $gateway ?? ''));
        }

        return $gateway === null
            ? sprintf('ip -6 route del %s/%d', $prefix, $length)
            : sprintf('ip -6 route del %s/%d via %s', $prefix, $length, $gateway);
    }

    /**
     * Macht eine nur aktive Windows-Route dauerhaft: löschen und mit store=persistent
     * neu anlegen (ein "add" auf eine bestehende aktive Route schlägt fehl).
     */
    public static function routePersistCommand(string $prefix, int $length, string $gateway, ?string $interface): string
    {
        $if = $interface ?? '"Ethernet"';

        return sprintf(
            'netsh interface ipv6 delete route %s/%d %s %s && netsh interface ipv6 add route %s/%d %s %s store=persistent',
            $prefix, $length, $if, $gateway, $prefix, $length, $if, $gateway
        );
    }

    /** Kommando für den persistenten Routenspeicher (nur Windows). */
    public static function routeShowPersistentCommand(): string
    {
        return 'netsh interface ipv6 show route store=persistent';
    }

    /** Ping-Kommando für eine IPv6-Adresse (Wiederholungen wegen schlafender Thread-Geräte). */
    public static function pingCommand(string $platform, string $address, int $count, int $timeoutMs): string
    {
        if (strcasecmp($platform, self::PLATFORM_WINDOWS) === 0) {
            return sprintf('ping -6 -n %d -w %d %s', $count, $timeoutMs, $address);
        }
        $timeoutS = max(1, (int)ceil($timeoutMs / 1000));

        // BusyBox- wie iputils-ping verstehen -c und -W (Sekunden)
        return sprintf('ping -6 -c %d -W %d %s', $count, $timeoutS, $address);
    }

    /**
     * Liest aus einer Ping-Ausgabe die Zahl der empfangenen Antworten.
     * Versteht deutsches und englisches Windows sowie iputils/BusyBox.
     */
    public static function parsePingReceived(string $output): ?int
    {
        $patterns = [
            '/Empfangen\s*=\s*(\d+)/',           // Windows deutsch
            '/Received\s*=\s*(\d+)/',            // Windows englisch
            '/(\d+)\s+(?:packets\s+)?received/', // iputils / BusyBox
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $output, $matches) === 1) {
                return (int)$matches[1];
            }
        }

        return null;
    }

    /** Kommando, das die IPv6-Routingtabelle auflistet. */
    public static function routeShowCommand(string $platform): string
    {
        if (strcasecmp($platform, self::PLATFORM_WINDOWS) === 0) {
            return 'netsh interface ipv6 show route';
        }

        return 'ip -6 route';
    }

    /**
     * Prüft, ob die Routingtabelle eine Route für das Thread-Präfix enthält.
     * Bewusst ohne die feste Längenangabe /64: Auch spezifischere Routen
     * (z. B. zwei /65 als Schutz gegen RA-Invalidierung durch den Heimrouter)
     * erfüllen den Zweck. Ein Textvergleich genügt für netsh wie ip -6 route.
     */
    public static function parseRouteExists(string $output, string $prefix): bool
    {
        return str_contains(strtolower($output), strtolower($prefix));
    }

    /**
     * Eigene IPv4-Adressen (ohne Loopback) — für den Abgleich, ob eine
     * mDNS-Annonce von der eigenen Anlage stammt.
     *
     * @return array<int, string>
     */
    public static function ownIpv4Addresses(): array
    {
        $interfaces = @net_get_interfaces();

        return is_array($interfaces) ? self::ipv4AddressesFromInterfaces($interfaces) : [];
    }

    /**
     * IPv4-Adressen aller LAN-Interfaces (ohne Loopback, ohne VPN-Tunnel).
     *
     * @param array<string, array<string, mixed>> $interfaces Struktur von net_get_interfaces()
     * @return array<int, string>
     */
    public static function ipv4AddressesFromInterfaces(array $interfaces): array
    {
        $result = [];
        foreach ($interfaces as $name => $interface) {
            if (self::isVpnInterface((string)$name, $interface)) {
                continue;
            }
            foreach ($interface['unicast'] ?? [] as $entry) {
                $address = (string)($entry['address'] ?? '');
                if ($address !== '127.0.0.1'
                    && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $result[] = $address;
                }
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Erkennt VPN-/Tunnel-Interfaces, deren Adressen nichts über das LAN aussagen.
     * Anlass (SymBox Neustadt, 02.09.2026): Die einzige globale IPv6 gehörte zu
     * tailscale0 — die Diagnose meldete damit "IPv6 vorhanden", obwohl eth0 nur
     * eine Link-Local-Adresse hatte. Erkannt wird über den Namen (Linux: Schlüssel,
     * Windows: "description") und über die typischen Adressbereiche.
     *
     * @param array<string, mixed> $interface
     */
    public static function isVpnInterface(string $name, array $interface): bool
    {
        $label = $name . ' ' . (string)($interface['description'] ?? '');
        if (preg_match('/tailscale|wireguard|zerotier|openvpn|nordlynx|\b(wg|tun|tap|utun|ppp|zt)\d*\b/i', $label) === 1) {
            return true;
        }
        foreach ($interface['unicast'] ?? [] as $entry) {
            $address = (string)($entry['address'] ?? '');
            // Tailscale: CGNAT-Bereich 100.64.0.0/10 und ULA-Präfix fd7a:115c:a1e0::/48
            if (self::inCidr($address, '100.64.0.0', 10) || self::inCidr($address, 'fd7a:115c:a1e0::', 48)) {
                return true;
            }
        }

        return false;
    }

    /** Prüft, ob $address im Netz $network/$bits liegt (IPv4 und IPv6, falsche Familie ⇒ false). */
    public static function inCidr(string $address, string $network, int $bits): bool
    {
        $a = @inet_pton($address);
        $n = @inet_pton($network);
        if (!is_string($a) || !is_string($n) || strlen($a) !== strlen($n)) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        if (substr($a, 0, $fullBytes) !== substr($n, 0, $fullBytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($a[$fullBytes]) & $mask) === (ord($n[$fullBytes]) & $mask);
    }

    /**
     * Eigene IPv6-Adressen (ohne Loopback) über die eingebaute PHP-Funktion —
     * plattformneutral, kein Shell-Aufruf nötig.
     *
     * @return array<int, string>
     */
    public static function ownIpv6Addresses(): array
    {
        $interfaces = @net_get_interfaces();

        return is_array($interfaces) ? self::ipv6AddressesFromInterfaces($interfaces) : [];
    }

    /**
     * IPv6-Adressen aller LAN-Interfaces (ohne Loopback, ohne VPN-Tunnel).
     *
     * @param array<string, array<string, mixed>> $interfaces Struktur von net_get_interfaces()
     * @return array<int, string>
     */
    public static function ipv6AddressesFromInterfaces(array $interfaces): array
    {
        $result = [];
        foreach ($interfaces as $name => $interface) {
            if (self::isVpnInterface((string)$name, $interface)) {
                continue;
            }
            foreach ($interface['unicast'] ?? [] as $entry) {
                // Familie anhand des Adressformats erkennen — die Konstante
                // AF_INET6 gehört zur sockets-Extension und ist plattformabhängig.
                $address = preg_replace('/%.*$/', '', (string)($entry['address'] ?? ''));
                if ($address !== '::1'
                    && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                    $result[] = $address;
                }
            }
        }

        return array_values(array_unique($result));
    }
}
