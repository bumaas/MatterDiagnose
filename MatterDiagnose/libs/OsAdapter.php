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
     * Name eines Geräts aus dem Reverse-Eintrag des Routers ("192.168.178.69" →
     * "EchoDot-Kueche.fritz.box"). Die FRITZ!Box und die meisten Router beantworten das
     * für jedes Gerät, das sich per DHCP mit Namen gemeldet hat — damit bekommt ein
     * fremdes LAN-Gerät einen Klarnamen statt seiner Matter-Kennung (18.09.2026).
     *
     * Ohne Eintrag gibt gethostbyaddr die Adresse selbst zurück; das gilt hier als
     * „nichts gefunden". Einen Zeitschalter hat die Funktion nicht — der Aufrufer misst
     * die Dauer und bricht die Runde ab, wenn der Resolver hängt.
     */
    public static function reverseName(string $address): ?string
    {
        $name = @gethostbyaddr($address);
        if ($name === false || $name === '' || strcasecmp($name, $address) === 0) {
            return null;
        }

        return $name;
    }

    /**
     * IPv4-Adresse zu einem Namen ("3D59C51D251F.fritz.box" → "192.168.178.69").
     * Der Umweg ist nötig, weil der Router den Klarnamen nur an der IPv4 führt: Ein
     * Reverse auf die IPv6 liefert bloß den mDNS-Namen zurück (gemessen 18.09.2026).
     */
    public static function resolveIpv4(string $name): ?string
    {
        $ip = @gethostbyname($name);

        return ($ip === $name || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) ? null : $ip;
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

        // Bei einem Link-Local-Gateway verlangt der Kernel die Schnittstelle.
        return self::linuxDevice(sprintf('ip -6 route add %s/64 via %s', $prefix, $gw), $interface);
    }

    private static function linuxDevice(string $command, ?string $interface): string
    {
        return $interface === null || $interface === '' ? $command : $command . ' dev ' . $interface;
    }

    /** Empfehlungs-Kommando, um eine (veraltete oder ins Leere zeigende) Route zu entfernen. */
    public static function routeDeleteCommand(string $platform, string $prefix, int $length, ?string $gateway, ?string $interface): string
    {
        if (strcasecmp($platform, self::PLATFORM_WINDOWS) === 0) {
            return rtrim(sprintf('netsh interface ipv6 delete route %s/%d %s %s', $prefix, $length, $interface ?? '"Ethernet"', $gateway ?? ''));
        }

        return self::linuxDevice(
            $gateway === null
                ? sprintf('ip -6 route del %s/%d', $prefix, $length)
                : sprintf('ip -6 route del %s/%d via %s', $prefix, $length, $gateway),
            $interface
        );
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

    /**
     * Kommando für die Routen samt Lebensdauer (nur Windows) — die Tabellenansicht
     * zeigt RA-gelernte Routen als „Manuell", erst level=verbose nennt die Gültigkeitsdauer.
     */
    public static function routeShowVerboseCommand(): string
    {
        return 'netsh interface ipv6 show route level=verbose';
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
     * Wie viele Ping-Versuche passen in die Restzeit, wenn keiner beantwortet wird?
     * Windows wartet je Versuch das Timeout ab und legt zwischen den Versuchen 1 s
     * Pause ein: n·T + (n−1)·1 s — gemessen am nuc (18.09.2026) 13,9 s für fünf
     * Versuche zu 2 s. iputils/BusyBox senden im Sekundentakt und warten nur am
     * Ende das Timeout ab: (n−1)·1 s + T. Eine Sekunde bleibt für den Prozessstart.
     * Unter zwei Versuchen lohnt der Test bei schlafenden Geräten nicht — dann 0,
     * und der Befund lautet „nicht geprüft" statt Budget-Überlauf.
     */
    public static function pingAttempts(float $remainingSeconds, int $timeoutMs, int $maxAttempts, string $platform = self::PLATFORM_WINDOWS): int
    {
        $timeout = max(0.001, $timeoutMs / 1000);
        $windows = strcasecmp($platform, self::PLATFORM_WINDOWS) === 0;
        $budget  = $remainingSeconds - 1.0;
        $best    = 0;
        for ($n = 1; $n <= $maxAttempts; $n++) {
            $cost = $windows ? $n * $timeout + ($n - 1) : ($n - 1) + $timeout;
            if ($cost > $budget) {
                break;
            }
            $best = $n;
        }

        return $best < 2 ? 0 : $best;
    }

    /**
     * Meldet die Shell, dass es das aufgerufene Programm nicht gibt? In schlanken
     * Docker-Containern fehlen `ip` und `ping` (reblade, Forum t/142087/1140). Ohne
     * diese Erkennung wurde die Fehlermeldung als leere Routentabelle gelesen — und
     * „keine Route" im Wächterlauf zum roten „nicht erreichbar".
     *
     * Echte Wortlaute: dash „sh: 1: ip: not found", BusyBox „sh: iplos: not found",
     * bash „bash: line 1: ipnix: command not found". Eine leere Ausgabe beweist nichts.
     */
    public static function commandMissing(string $output): bool
    {
        return preg_match('/^\S+:(?:\s*(?:line\s+)?\d+:)?\s*\S+:\s*(?:command\s+)?not found\s*$/m', $output) === 1;
    }

    /** Pfad der Routingtabelle, die der Linux-Kernel ohne jedes Werkzeug bereitstellt. */
    public const PROC_IPV6_ROUTE = '/proc/net/ipv6_route';

    /**
     * Inhalt von /proc/net/ipv6_route — der Rückweg, wenn `ip` fehlt (rein lesend).
     *
     * @return string|null null, wenn es die Datei nicht gibt (Windows)
     */
    public static function readProcIpv6Route(string $path = self::PROC_IPV6_ROUTE): ?string
    {
        $content = is_file($path) ? @file_get_contents($path) : false;

        return is_string($content) ? $content : null;
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

    /** Verzeichnis, unter dem Linux die IPv6-Einstellungen je Schnittstelle führt. */
    public const IPV6_CONF_PATH = '/proc/sys/net/ipv6/conf';

    /**
     * Die drei IPv6-Einstellungen, an denen Matter over Thread unter Linux hängt.
     * Symcon prüft genau diese und bietet im Matter-Konfigurator an, sie zu setzen
     * (Warnung „There is a misconfiguration on your Linux OS …", Knopf „Fix Settings“,
     * schreibt /etc/sysctl.d/20-symcon-matter.conf). Die Doku nennt den Fall, aber
     * keine Namen — die Liste stammt aus dem Kernel-Binary der Testbox (20.09.2026).
     */
    public const IPV6_CONF_OPTIONS = ['forwarding', 'accept_ra', 'accept_ra_rt_info_max_plen'];

    /**
     * Liest die IPv6-Einstellungen je Schnittstelle (nur Linux, rein lesend).
     * Der Basispfad ist überschreibbar, damit der Test gegen einen Mitschnitt läuft.
     *
     * Ein nicht lesbarer Wert bleibt null — die Bewertung urteilt dann nicht über ihn.
     *
     * @return array<string, array<string, int|null>>|null null, wenn es das Verzeichnis nicht gibt (Windows)
     */
    public static function readIpv6Conf(string $base = self::IPV6_CONF_PATH): ?array
    {
        $directories = @glob($base . '/*', GLOB_ONLYDIR);
        if ($directories === false || $directories === []) {
            return null;
        }

        $result = [];
        foreach ($directories as $directory) {
            $values = [];
            foreach (self::IPV6_CONF_OPTIONS as $option) {
                $raw             = @file_get_contents($directory . '/' . $option);
                $values[$option] = is_string($raw) && preg_match('/^-?\d+$/', trim($raw)) === 1
                    ? (int)trim($raw)
                    : null;
            }
            $result[basename($directory)] = $values;
        }

        return $result;
    }

    /**
     * Fehlt eine Einstellung auf jeder Schnittstelle, während accept_ra lesbar ist?
     * Dann kennt der Kernel sie gar nicht — anders als ein unlesbarer Wert (null in
     * readIpv6Conf), über den kein Urteil möglich ist.
     *
     * accept_ra_rt_info_max_plen gibt es nur mit CONFIG_IPV6_ROUTE_INFO, und nur damit
     * verarbeitet der Kernel Route-Information-Optionen überhaupt (net/ipv6/addrconf.c
     * und ndisc.c, beides unter #ifdef CONFIG_IPV6_ROUTE_INFO; Kconfig: „If unsure,
     * say N"; nachgelesen 21.09.2026). Ein solcher Kernel lernt die Route ins
     * Thread-Netz nie — reblades Synology (Forum t/142087/1140).
     *
     * @return bool|null null, wenn es das Verzeichnis nicht gibt (Windows) oder nichts lesbar ist
     */
    public static function ipv6ConfOptionMissing(string $option, string $base = self::IPV6_CONF_PATH): ?bool
    {
        $directories = @glob($base . '/*', GLOB_ONLYDIR);
        if ($directories === false || $directories === []) {
            return null;
        }

        $readable = false;
        foreach ($directories as $directory) {
            if (is_file($directory . '/' . $option)) {
                return false;
            }
            $readable = $readable || is_file($directory . '/accept_ra');
        }

        return $readable ? true : null;
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
