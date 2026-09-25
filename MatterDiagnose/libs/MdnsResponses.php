<?php

declare(strict_types=1);

require_once __DIR__ . '/MdnsCodec.php';

/**
 * Aufbereitung empfangener mDNS-Pakete, seit das Modul wie ein normaler Teilnehmer fragt
 * (build 67): von Port 5353, über IPv4 und IPv6, Antworten per Multicast.
 *
 * Zwei Folgen davon fängt diese Klasse ab — ohne Socket, damit testbar:
 * - Der Socket hört den ganzen Link mit (Sonos, AirPlay, Geschirrspüler …). `relevant()`
 *   behält nur Antworten, die eine unserer Fragen beantworten.
 * - Dieselbe Antwort kommt oft zweimal, über IPv4 und IPv6, und ein Gerät, das nur über
 *   IPv6 antwortet (Alexandros Apple TV, Forum t/144417/47), hat eine IPv6 als Absender. Der
 *   Absender ist im Modul aber Schlüssel — für Direktabfragen und die Zuordnung Gerät →
 *   Border Router. `preferIpv4()` ersetzt deshalb einen IPv6-Absender durch die IPv4
 *   desselben Hosts (aus den A/AAAA-Einträgen der Antworten) und stellt IPv4 nach vorn.
 */
class MdnsResponses
{
    /** Nackte Adresse aus „ip:port", „[ipv6]:port" oder „[ipv6%zone]:port". */
    public static function address(string $from): string
    {
        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $from, $m) === 1) {
            $from = $m[1];
        } elseif (substr_count($from, ':') === 1) {
            $from = (string)preg_replace('/:\d+$/', '', $from);
        }

        return strtolower((string)preg_replace('/%.*$/', '', $from));
    }

    /**
     * Nur Antworten, die mindestens einen Eintrag zu einem gefragten Namen tragen.
     *
     * @param array<int, array{from: string, message: array<string, mixed>, raw?: string}> $responses
     * @param array<int, array{name: string, type: int}> $questions
     * @return array<int, array{from: string, message: array<string, mixed>, raw?: string}>
     */
    public static function relevant(array $responses, array $questions): array
    {
        $names = array_flip(array_map(static fn(array $q): string => strtolower(rtrim((string)$q['name'], '.')), $questions));

        return array_values(array_filter($responses, static function (array $response) use ($names): bool {
            if (!($response['message']['isResponse'] ?? false)) {
                return false;
            }
            foreach ($response['message']['records'] ?? [] as $record) {
                if (isset($names[strtolower(rtrim((string)($record['name'] ?? ''), '.'))])) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * IPv6-Absender durch die IPv4 desselben Hosts ersetzen, wo die Antworten sie verraten,
     * und IPv4-Absender nach vorn stellen (stabil). Ein ersetzter Absender behält die
     * ursprüngliche Adresse in `via`.
     *
     * @param array<int, array{from: string, message: array<string, mixed>, raw?: string}> $responses
     * @return array<int, array{from: string, message: array<string, mixed>, raw?: string, via?: string}>
     */
    public static function preferIpv4(array $responses): array
    {
        $ipv4ByHost = [];
        $hostsByV6  = [];
        foreach ($responses as $response) {
            foreach ($response['message']['records'] ?? [] as $record) {
                $host = strtolower((string)($record['name'] ?? ''));
                if (($record['type'] ?? 0) === MdnsCodec::TYPE_A) {
                    $ipv4ByHost[$host][] = (string)$record['address'];
                } elseif (($record['type'] ?? 0) === MdnsCodec::TYPE_AAAA) {
                    $hostsByV6[strtolower((string)$record['address'])][] = $host;
                }
            }
        }

        $ipv4 = [];
        $ipv6 = [];
        foreach ($responses as $response) {
            $address = self::address($response['from']);
            if (!str_contains($address, ':')) {
                $ipv4[] = $response;
                continue;
            }
            $mapped = null;
            foreach (array_unique($hostsByV6[$address] ?? []) as $host) {
                if (isset($ipv4ByHost[$host])) {
                    $mapped = $ipv4ByHost[$host][0];
                    break;
                }
            }
            if ($mapped === null) {
                $ipv6[] = $response;
                continue;
            }
            $port             = preg_match('/:(\d+)$/', $response['from'], $m) === 1 ? $m[1] : '5353';
            $response['via']  = $address;
            $response['from'] = $mapped . ':' . $port;
            $ipv4[]           = $response;
        }

        // Echte IPv4-Absender vor den umgeschriebenen, damit ein Router, der auf beiden
        // Familien antwortet, seinen gewohnten Absender behält.
        usort($ipv4, static fn(array $a, array $b): int => (int)isset($a['via']) <=> (int)isset($b['via']));

        return array_merge($ipv4, $ipv6);
    }
}
