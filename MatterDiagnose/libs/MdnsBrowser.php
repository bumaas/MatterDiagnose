<?php

declare(strict_types=1);

require_once __DIR__ . '/MdnsCodec.php';
require_once __DIR__ . '/MdnsResponses.php';

/**
 * Dünner Netzwerk-Wrapper um MdnsCodec: verschickt Multicast-Queries und
 * sammelt die Antworten ein. Bewusst klein gehalten — die Logik steckt im
 * Codec (Unit-Tests) und in der DiagnosisEngine (Unit-Tests); hier bleibt nur
 * der Socket-Anteil, der im Test durch Fixtures ersetzt wird.
 *
 * Stolpersteine, die dieser Wrapper umschifft (Lehrgeld 01.09.2026):
 * - Ein per stream_socket_client "verbundener" UDP-Socket verwirft Antworten
 *   fremder Absender — deshalb stream_socket_server auf einem ephemeren Port.
 * - stream_socket_recvfrom ignoriert stream_set_timeout — deshalb
 *   stream_select vor jedem Empfang.
 * - Das QU-Bit sorgt dafür, dass die Antworten unicast an unseren Port gehen;
 *   eingehender Multicast würde je nach Firewall verworfen.
 * - Bei mehreren Interfaces (VPN!) wandert der Multicast sonst zufällig über
 *   das falsche hinaus — deshalb ans LAN-Interface binden.
 *
 * Seit build 67 ist der obige Weg (freier Port, QU-Bit) nur noch Rückfall und für
 * Direktabfragen da. Ohne Ziel fragt der Browser wie Avahi/Bonjour: Port 5353 per
 * SO_REUSEADDR neben dem System-Responder, Gruppenbeitritt auf IPv4 (224.0.0.251) und
 * IPv6 (ff02::fb) über die Schnittstelle der IPv6-Standardroute. Erprobt 25.09.2026 am
 * nuc (Windows, neben Bonjour, Firewall ließ Multicast durch) und an der Testbox (Linux,
 * neben Avahi). Anlass: Alexandros Apple TV antwortete nur auf IPv6-Multicast.
 */
class MdnsBrowser
{
    private const MDNS_GROUP = '224.0.0.251:5353';
    private const MDNS_PORT  = 5353;
    private const GROUP_V4   = '224.0.0.251';
    private const GROUP_V6   = 'ff02::fb';

    /** Welcher Weg die letzte Multicast-Frage stellte — für das Debug. */
    public string $lastMode = '';

    /**
     * @param int|string|null $interface Schnittstelle für Multicast (Windows: Index, Linux:
     *                                   Name). null = unbekannt, dann nur IPv4 über die Vorgabe.
     */
    public function __construct(private int|string|null $interface = null)
    {
    }

    /**
     * Verschickt eine Query für die übergebenen Namen und sammelt bis zum
     * Ablauf des Zeitbudgets alle dekodierbaren Antworten ein. Bleibt das
     * Ergebnis komplett leer, wird die Query wiederholt.
     *
     * @param array<int, array{name: string, type: int}> $questions
     * @param string|null $target Ziel-IPv4 für eine Direktabfrage statt der Multicast-Gruppe:
     *                            Ein Responder beantwortet eine an ihn gerichtete Anfrage
     *                            unicast an unseren Port — auch dann, wenn er auf die
     *                            Multicast-Anfrage per Multicast geantwortet hätte.
     * @return array<int, array{from: string, message: array<string, mixed>, raw: string}>
     */
    public function query(array $questions, float $timeoutSeconds = 4.0, int $attempts = 2, ?string $target = null): array
    {
        // Seit build 67 fragt das Modul wie Avahi und Bonjour: von Port 5353, über IPv4 und
        // IPv6, Antworten per Multicast. Alexandros Apple TV (Forum t/144417/47) antwortete
        // nur so — auf IPv4 gar nicht, auf IPv6-Multicast schon. Geht das nicht (Erweiterung
        // sockets fehlt, Port nicht belegbar), bleibt der bisherige Weg.
        if ($target === null) {
            $participant = $this->participate($questions, $timeoutSeconds, $attempts);
            if ($participant !== null) {
                return $participant;
            }
            $this->lastMode = 'freier Port, nur IPv4';
        }

        $destination = $target === null ? self::MDNS_GROUP : $target . ':5353';
        $local  = self::localAddress();
        $socket = @stream_socket_server('udp://' . $local . ':0', $errno, $errstr, STREAM_SERVER_BIND);
        if ($socket === false) {
            $socket = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND);
        }
        if ($socket === false) {
            throw new RuntimeException('UDP-Socket konnte nicht angelegt werden: ' . $errstr);
        }

        try {
            $query = MdnsCodec::encodeQuery(
                array_map(
                    static fn(array $question): array => [
                        'name'    => $question['name'],
                        'type'    => $question['type'],
                        'unicast' => true,
                    ],
                    $questions
                )
            );

            $responses = [];
            for ($attempt = 0; $attempt < max(1, $attempts); $attempt++) {
                $sent = @stream_socket_sendto($socket, $query, 0, $destination);
                if ($sent !== strlen($query)) {
                    throw new RuntimeException('mDNS-Query konnte nicht gesendet werden');
                }

                $deadline = microtime(true) + $timeoutSeconds;
                while (true) {
                    $remaining = $deadline - microtime(true);
                    if ($remaining <= 0) {
                        break;
                    }
                    $read   = [$socket];
                    $write  = null;
                    $except = null;
                    $ready  = @stream_select($read, $write, $except, 0, (int)($remaining * 1000000));
                    if ($ready === false || $ready < 1) {
                        continue;
                    }
                    $from = '';
                    $raw  = @stream_socket_recvfrom($socket, 9000, 0, $from);
                    if (!is_string($raw) || strlen($raw) < 12) {
                        continue;
                    }
                    try {
                        $message = MdnsCodec::decodeMessage($raw);
                    } catch (InvalidArgumentException) {
                        continue; // kaputtes Fremdpaket, ignorieren
                    }
                    if (!$message['isResponse']) {
                        continue;
                    }
                    $responses[] = ['from' => $from, 'message' => $message, 'raw' => $raw];
                    // Direktabfrage: Es antwortet genau ein Responder — nach seiner
                    // Antwort nur noch kurz auf Nachzügler warten statt das volle
                    // Zeitfenster abzusitzen (kostete 1 s je Border Router).
                    if ($target !== null) {
                        $deadline = min($deadline, microtime(true) + 0.25);
                    }
                }

                if ($responses !== []) {
                    break; // nur bei komplett leerem Ergebnis erneut fragen
                }
            }

            return $responses;
        } finally {
            fclose($socket);
        }
    }

    /**
     * Frage als normaler mDNS-Teilnehmer: Port 5353 neben Bonjour/Avahi (SO_REUSEADDR),
     * Gruppenbeitritt, ohne QU-Bit, IP-TTL 255. Der Socket hört den ganzen Link mit —
     * MdnsResponses filtert auf unsere Fragen und vereinheitlicht die Absender.
     *
     * @param array<int, array{name: string, type: int}> $questions
     * @return array<int, array{from: string, message: array<string, mixed>, raw: string, via?: string}>|null
     *         null, wenn der Weg nicht verfügbar ist
     */
    private function participate(array $questions, float $timeoutSeconds, int $attempts): ?array
    {
        if (!extension_loaded('sockets')) {
            return null;
        }
        $query   = MdnsCodec::encodeQuery(array_map(
            static fn(array $q): array => ['name' => $q['name'], 'type' => $q['type'], 'unicast' => false],
            $questions
        ));
        $sockets = [];
        $v4      = $this->openSocket(AF_INET);
        if ($v4 !== null) {
            $sockets['v4'] = $v4;
        }
        if ($this->interface !== null && $this->interface !== 0 && $this->interface !== '') {
            $v6 = $this->openSocket(AF_INET6);
            if ($v6 !== null) {
                $sockets['v6'] = $v6;
            }
        }
        if ($sockets === []) {
            return null;
        }
        $this->lastMode = 'Port 5353, ' . implode(' + ', array_map(static fn(string $f): string => $f === 'v4' ? 'IPv4' : 'IPv6', array_keys($sockets)));

        try {
            $received = [];
            for ($attempt = 0; $attempt < max(1, $attempts); $attempt++) {
                foreach ($sockets as $family => $socket) {
                    $group = $family === 'v4' ? self::GROUP_V4 : self::GROUP_V6 . '%' . $this->interface;
                    @socket_sendto($socket, $query, strlen($query), 0, $group, self::MDNS_PORT);
                }
                $deadline = microtime(true) + $timeoutSeconds;
                while (($remaining = $deadline - microtime(true)) > 0) {
                    $read   = array_values($sockets);
                    $write  = null;
                    $except = null;
                    if (@socket_select($read, $write, $except, 0, (int)min(200000, $remaining * 1000000)) < 1) {
                        continue;
                    }
                    foreach ($sockets as $family => $socket) {
                        while (@socket_recvfrom($socket, $raw, 9000, 0, $address, $port) > 0) {
                            if (!is_string($raw) || strlen($raw) < 12) {
                                continue;
                            }
                            try {
                                $message = MdnsCodec::decodeMessage($raw);
                            } catch (InvalidArgumentException) {
                                continue; // kaputtes Fremdpaket, ignorieren
                            }
                            $from       = $family === 'v6' ? '[' . $address . ']:' . $port : $address . ':' . $port;
                            $received[] = ['from' => $from, 'message' => $message, 'raw' => $raw];
                        }
                    }
                }
                $responses = MdnsResponses::relevant(MdnsResponses::preferIpv4($received), $questions);
                if ($responses !== []) {
                    return $responses;
                }
            }

            return [];
        } finally {
            foreach ($sockets as $socket) {
                socket_close($socket);
            }
        }
    }

    /**
     * Socket auf Port 5353 einer Familie, der Gruppe beigetreten; null, wenn das nicht geht.
     *
     * @return Socket|null
     */
    private function openSocket(int $family): ?Socket
    {
        $socket = @socket_create($family, SOCK_DGRAM, SOL_UDP);
        if ($socket === false) {
            return null;
        }
        $v6    = $family === AF_INET6;
        $proto = $v6 ? IPPROTO_IPV6 : IPPROTO_IP;
        $iface = $this->interface ?? 0;
        @socket_set_option($socket, SOL_SOCKET, SO_REUSEADDR, 1);
        if (defined('SO_REUSEPORT')) {
            @socket_set_option($socket, SOL_SOCKET, SO_REUSEPORT, 1);
        }
        if ($v6 && defined('IPV6_V6ONLY')) {
            @socket_set_option($socket, IPPROTO_IPV6, IPV6_V6ONLY, 1);
        }
        $ok = @socket_bind($socket, $v6 ? '::' : '0.0.0.0', self::MDNS_PORT)
              && @socket_set_option($socket, $proto, MCAST_JOIN_GROUP, ['group' => $v6 ? self::GROUP_V6 : self::GROUP_V4, 'interface' => $iface]);
        if (!$ok) {
            socket_close($socket);

            return null;
        }
        if ($v6) {
            @socket_set_option($socket, IPPROTO_IPV6, IPV6_MULTICAST_IF, $iface);
            @socket_set_option($socket, IPPROTO_IPV6, IPV6_MULTICAST_HOPS, 255);
            @socket_set_option($socket, IPPROTO_IPV6, IPV6_MULTICAST_LOOP, 0);
        } else {
            if ($iface !== 0) {
                @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_IF, $iface);
            }
            @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_TTL, 255);
            @socket_set_option($socket, IPPROTO_IP, IP_MULTICAST_LOOP, 0);
        }
        socket_set_nonblock($socket);

        return $socket;
    }

    /**
     * Ermittelt die Adresse des Interfaces, über das der Standard-Weg ins
     * Netz führt (UDP-Connect verschickt dabei kein einziges Paket).
     */
    private static function localAddress(): string
    {
        // Ziel ist nur ein Routen-Lookup — der UDP-Connect verschickt nichts.
        // Eine globale Adresse wählt das Interface mit der Default-Route
        // (nicht ein VPN wie Tailscale, das nur Teilnetze routet).
        $probe = @stream_socket_client('udp://8.8.8.8:53', $errno, $errstr, 1);
        if ($probe === false) {
            return '0.0.0.0';
        }
        $name = stream_socket_get_name($probe, false);
        fclose($probe);
        $pos = is_string($name) ? strrpos($name, ':') : false;

        return $pos === false ? '0.0.0.0' : substr($name, 0, $pos);
    }
}
