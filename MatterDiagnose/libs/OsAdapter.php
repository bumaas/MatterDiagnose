<?php

declare(strict_types=1);

/**
 * Plattformabhängige Kommandos bauen und deren Ausgaben parsen.
 *
 * Die Methoden, die Strings bauen oder parsen, sind rein und per Unit-Test
 * abgedeckt; nur ausfuehren() berührt das System.
 */
class OsAdapter
{
    public const PLATTFORM_WINDOWS = 'Windows';
    public const PLATTFORM_LINUX   = 'Linux';

    public static function plattform(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? self::PLATTFORM_WINDOWS : self::PLATTFORM_LINUX;
    }

    /**
     * Führt ein Kommando aus und liefert die Ausgabe als UTF-8.
     *
     * Die Windows-Konsole liefert CP850 — ohne Umkodierung scheitert später
     * jedes json_encode still (Lehrgeld 27.08.2026).
     */
    public static function ausfuehren(string $kommando): string
    {
        $raw = (string)shell_exec($kommando . ' 2>&1');
        if (self::plattform() === self::PLATTFORM_WINDOWS) {
            $konvertiert = @iconv('CP850', 'UTF-8//IGNORE', $raw);
            if (is_string($konvertiert)) {
                return $konvertiert;
            }
        }

        return $raw;
    }

    /**
     * Empfehlungs-Kommando, um die Route zu einem Thread-Präfix zu setzen.
     * Wird nur als Text angezeigt, nie ausgeführt — das Setzen braucht
     * Administratorrechte und bleibt eine bewusste Nutzerentscheidung.
     */
    public static function routeAddCommand(string $plattform, string $praefix, ?string $gateway): string
    {
        $gw = $gateway ?? '<Gateway-Adresse des Border-Routers>';
        if (strcasecmp($plattform, self::PLATTFORM_WINDOWS) === 0) {
            return sprintf('netsh interface ipv6 add route %s/64 "Ethernet" %s', $praefix, $gw);
        }

        return sprintf('ip -6 route add %s/64 via %s', $praefix, $gw);
    }

    /** Ping-Kommando für eine IPv6-Adresse (Wiederholungen wegen schlafender Thread-Geräte). */
    public static function pingCommand(string $plattform, string $adresse, int $anzahl, int $timeoutMs): string
    {
        if (strcasecmp($plattform, self::PLATTFORM_WINDOWS) === 0) {
            return sprintf('ping -6 -n %d -w %d %s', $anzahl, $timeoutMs, $adresse);
        }
        $timeoutS = max(1, (int)ceil($timeoutMs / 1000));

        // BusyBox- wie iputils-ping verstehen -c und -W (Sekunden)
        return sprintf('ping -6 -c %d -W %d %s', $anzahl, $timeoutS, $adresse);
    }

    /**
     * Liest aus einer Ping-Ausgabe die Zahl der empfangenen Antworten.
     * Versteht deutsches und englisches Windows sowie iputils/BusyBox.
     */
    public static function parsePingEmpfangen(string $ausgabe): ?int
    {
        $muster = [
            '/Empfangen\s*=\s*(\d+)/',        // Windows deutsch
            '/Received\s*=\s*(\d+)/',         // Windows englisch
            '/(\d+)\s+(?:packets\s+)?received/', // iputils / BusyBox
        ];
        foreach ($muster as $regex) {
            if (preg_match($regex, $ausgabe, $m) === 1) {
                return (int)$m[1];
            }
        }

        return null;
    }

    /** Kommando, das die IPv6-Routingtabelle auflistet. */
    public static function routeShowCommand(string $plattform): string
    {
        if (strcasecmp($plattform, self::PLATTFORM_WINDOWS) === 0) {
            return 'netsh interface ipv6 show route';
        }

        return 'ip -6 route';
    }

    /**
     * Prüft, ob die Routingtabelle eine Route für das /64-Präfix enthält.
     * Ein einfacher Textvergleich genügt für netsh wie für ip -6 route.
     */
    public static function parseRouteVorhanden(string $ausgabe, string $praefix): bool
    {
        return str_contains(strtolower($ausgabe), strtolower($praefix . '/64'));
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
    public static function parseNetstat5353(string $ausgabe): array
    {
        $pids = [];
        foreach (preg_split('/\R/', $ausgabe) ?: [] as $zeile) {
            if (preg_match('/:5353\s+\S+\s+(\d+)\s*$/', trim($zeile), $m) === 1) {
                $pids[] = (int)$m[1];
            }
        }

        return array_values(array_unique($pids));
    }

    /**
     * PID-zu-Prozessname aus einer "tasklist /FO CSV /NH"-Ausgabe.
     *
     * @return array<int, string>
     */
    public static function parseTasklistCsv(string $ausgabe): array
    {
        $result = [];
        foreach (preg_split('/\R/', $ausgabe) ?: [] as $zeile) {
            $felder = str_getcsv(trim($zeile), ',', '"', '');
            if (count($felder) >= 2 && is_numeric($felder[1])) {
                $result[(int)$felder[1]] = (string)$felder[0];
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
    public static function eigeneIpv4Adressen(): array
    {
        $result     = [];
        $interfaces = @net_get_interfaces();
        if (!is_array($interfaces)) {
            return $result;
        }
        foreach ($interfaces as $if) {
            foreach ($if['unicast'] ?? [] as $eintrag) {
                $adresse = (string)($eintrag['address'] ?? '');
                if ($adresse !== '127.0.0.1'
                    && filter_var($adresse, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $result[] = $adresse;
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
    public static function eigeneIpv6Adressen(): array
    {
        $result     = [];
        $interfaces = @net_get_interfaces();
        if (!is_array($interfaces)) {
            return $result;
        }
        foreach ($interfaces as $if) {
            foreach ($if['unicast'] ?? [] as $eintrag) {
                // Familie anhand des Adressformats erkennen — die Konstante
                // AF_INET6 gehört zur sockets-Extension und ist plattformabhängig.
                $adresse = preg_replace('/%.*$/', '', (string)($eintrag['address'] ?? ''));
                if ($adresse !== '::1'
                    && filter_var($adresse, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                    $result[] = $adresse;
                }
            }
        }

        return array_values(array_unique($result));
    }
}
