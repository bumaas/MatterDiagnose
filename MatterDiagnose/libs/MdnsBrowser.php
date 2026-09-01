<?php

declare(strict_types=1);

require_once __DIR__ . '/MdnsCodec.php';

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
 */
class MdnsBrowser
{
    private const MDNS_GROUP = '224.0.0.251:5353';

    /**
     * Verschickt eine Query für die übergebenen Namen und sammelt bis zum
     * Ablauf des Zeitbudgets alle dekodierbaren Antworten ein. Bleibt das
     * Ergebnis komplett leer, wird die Query wiederholt.
     *
     * @param array<int, array{name: string, type: int}> $questions
     * @return array<int, array{from: string, message: array<string, mixed>, raw: string}>
     */
    public function query(array $questions, float $timeoutSeconds = 4.0, int $attempts = 2): array
    {
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
                $sent = @stream_socket_sendto($socket, $query, 0, self::MDNS_GROUP);
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
