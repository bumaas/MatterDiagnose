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
    public static function routeAddCommand(string $platform, string $prefix, ?string $gateway): string
    {
        $gw = $gateway ?? '<Gateway-Adresse des Border-Routers>';
        if (strcasecmp($platform, self::PLATFORM_WINDOWS) === 0) {
            return sprintf('netsh interface ipv6 add route %s/64 "Ethernet" %s', $prefix, $gw);
        }

        return sprintf('ip -6 route add %s/64 via %s', $prefix, $gw);
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
     * Prüft, ob die Routingtabelle eine Route für das /64-Präfix enthält.
     * Ein einfacher Textvergleich genügt für netsh wie für ip -6 route.
     */
    public static function parseRouteExists(string $output, string $prefix): bool
    {
        return str_contains(strtolower($output), strtolower($prefix . '/64'));
    }

    /**
     * Kommando, das die UDP-Belegung auflistet (für die 5353-Konkurrenzanalyse, nur Windows).
     */
    public static function netstatUdpCommand(): string
    {
        return 'netstat -ano -p udp';
    }

    /**
     * PIDs aller Sockets auf Port 5353 aus einer netstat -ano-Ausgabe.
     *
     * @return array<int, int>
     */
    public static function parseNetstat5353(string $output): array
    {
        $pids = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/:5353\s+\S+\s+(\d+)\s*$/', trim($line), $matches) === 1) {
                $pids[] = (int)$matches[1];
            }
        }

        return array_values(array_unique($pids));
    }

    /**
     * PID-zu-Prozessname aus einer "tasklist /FO CSV /NH"-Ausgabe.
     *
     * @return array<int, string>
     */
    public static function parseTasklistCsv(string $output): array
    {
        $result = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $fields = str_getcsv(trim($line), ',', '"', '');
            if (count($fields) >= 2 && is_numeric($fields[1])) {
                $result[(int)$fields[1]] = (string)$fields[0];
            }
        }

        return $result;
    }

    /**
     * Eigene IPv4-Adressen (ohne Loopback) — für den Abgleich, ob eine
     * mDNS-Annonce von der eigenen Anlage stammt.
     *
     * @return array<int, string>
     */
    public static function ownIpv4Addresses(): array
    {
        $result     = [];
        $interfaces = @net_get_interfaces();
        if (!is_array($interfaces)) {
            return $result;
        }
        foreach ($interfaces as $interface) {
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
     * Eigene IPv6-Adressen (ohne Loopback) über die eingebaute PHP-Funktion —
     * plattformneutral, kein Shell-Aufruf nötig.
     *
     * @return array<int, string>
     */
    public static function ownIpv6Addresses(): array
    {
        $result     = [];
        $interfaces = @net_get_interfaces();
        if (!is_array($interfaces)) {
            return $result;
        }
        foreach ($interfaces as $interface) {
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
