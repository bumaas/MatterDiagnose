<?php

declare(strict_types=1);

require_once __DIR__ . '/MdnsCodec.php';

/**
 * Dünner Netzwerk-Wrapper um MdnsCodec: verschickt Multicast-Queries und
 * sammelt die Antworten ein. Bewusst klein gehalten — die Logik steckt im
 * Codec (Unit-Tests) und in der DiagnoseEngine (Unit-Tests); hier bleibt nur
 * der Socket-Anteil, der im Test durch Fixtures ersetzt wird.
 *
 * Stolpersteine, die dieser Wrapper umschifft (Lehrgeld 01.09.2026):
 * - Ein per stream_socket_client "verbundener" UDP-Socket verwirft Antworten
 *   fremder Absender — deshalb stream_socket_server auf einem ephemeren Port.
 * - stream_socket_recvfrom ignoriert stream_set_timeout — deshalb
 *   stream_select vor jedem Empfang.
 * - Das QU-Bit sorgt dafür, dass die Antworten unicast an unseren Port gehen;
 *   eingehender Multicast würde je nach Firewall verworfen.
 */
class MdnsBrowser
{
    private const MDNS_GROUP = '224.0.0.251:5353';

    /**
     * Verschickt eine Query für die übergebenen Namen und sammelt bis zum
     * Ablauf des Zeitbudgets alle dekodierbaren Antworten ein.
     *
     * @param array<int, array{name: string, type: int}> $questions
     * @return array<int, array{from: string, message: array<string, mixed>, raw: string}>
     */
    public function query(array $questions, float $timeoutSeconds = 4.0): array
    {
        $socket = @stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND);
        if ($socket === false) {
            throw new RuntimeException('UDP-Socket konnte nicht angelegt werden: ' . $errstr);
        }

        try {
            $query = MdnsCodec::encodeQuery(
                array_map(
                    static fn(array $q): array => ['name' => $q['name'], 'type' => $q['type'], 'unicast' => true],
                    $questions
                )
            );
            $sent = @stream_socket_sendto($socket, $query, 0, self::MDNS_GROUP);
            if ($sent !== strlen($query)) {
                throw new RuntimeException('mDNS-Query konnte nicht gesendet werden');
            }

            $responses = [];
            $deadline  = microtime(true) + $timeoutSeconds;
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

            return $responses;
        } finally {
            fclose($socket);
        }
    }
}
