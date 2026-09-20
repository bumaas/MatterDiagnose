<?php

declare(strict_types=1);

/**
 * Klarnamen für fremde LAN-Geräte aus dem Reverse-Eintrag des Routers.
 *
 * Der eigene Echo Dot stand als „3D59C51D251F" in der Geräteliste; die FRITZ!Box kennt
 * ihn als „EchoDot-Kueche", weil er sich per DHCP so gemeldet hat. Gefragt wird nur nach
 * Geräten, die Symcon nicht kennt — Thread-Geräte haben keinen Eintrag.
 *
 * Ohne A-Record kennt das Modul nur die IPv6, und darauf nennt die FRITZ!Box bloß den
 * mDNS-Namen. Deshalb der Umweg IPv6 → Name → dessen IPv4 → Klarname.
 *
 * Auflösen kostet Zeit, die der Lauf nicht hat: `gethostbyaddr` kennt keinen
 * Zeitschalter, ein Resolver ohne passende Einträge lässt jede Anfrage in den Timeout
 * laufen. Die Runde bekommt deshalb eine Frist, eine Höchstzahl an Abfragen und eine
 * Schwelle, ab der eine Antwort als zu langsam gilt.
 *
 * Die Namensauflösung selbst kommt als Rückruf herein (im Modul `OsAdapter`), damit die
 * Zeitregeln ohne Netz prüfbar sind.
 */
class ReverseLookup
{
    /**
     * Sammelt Reverse-Namen für Geräte ohne Symcon-Eintrag.
     *
     * @param array<int, array<string, mixed>> $devices    Geräteliste (DeviceInventory::build)
     * @param callable(string): ?string        $reverseName Adresse → Name, null ohne Eintrag
     * @param callable(string): ?string        $resolveIpv4 Name → IPv4, null ohne Eintrag
     * @param callable(): float                $clock       Uhr (im Modul microtime(true))
     * @param float                            $deadline    Zeitpunkt, an dem die Runde enden muss
     * @param int                              $maxQueries  Höchstzahl an Abfragen
     * @param float                            $slow        Ab dieser Dauer gilt eine Antwort als zu langsam
     * @return array{names: array<string, string>, queries: int, stop: string|null}
     *         stop: null = regulär zu Ende, 'slow' = Resolver zu langsam, 'deadline' = Frist
     *         abgelaufen, 'limit' = Höchstzahl erreicht
     */
    public static function collect(
        array $devices,
        callable $reverseName,
        callable $resolveIpv4,
        callable $clock,
        float $deadline,
        int $maxQueries,
        float $slow
    ): array {
        $state = ['names' => [], 'queries' => 0, 'stop' => null];
        if ($clock() >= $deadline) {
            $state['stop'] = 'deadline';

            return $state;
        }

        foreach ($devices as $device) {
            if (($device['nodeId'] ?? null) !== null) {
                continue;
            }
            if ($state['queries'] >= $maxQueries) {
                $state['stop'] = 'limit';
                break;
            }
            $vorher           = $clock();
            [$address, $name] = self::forDevice(
                array_values((array)($device['addresses'] ?? [])),
                $reverseName,
                $resolveIpv4,
                $state
            );
            if ($address !== null && $name !== null) {
                $state['names'][$address] = $name;
            }
            if (($clock() - $vorher) > $slow) {
                $state['stop'] = 'slow';
                break;
            }
        }

        return $state;
    }

    /**
     * Der Reverse-Eintrag zu einem Gerät, notfalls über zwei Ecken.
     *
     * Welche Adressen eine Annonce mitbringt, schwankt von Lauf zu Lauf: Kommt kein
     * A-Record, kennt das Modul nur IPv6 — und darauf antwortet die FRITZ!Box bloß mit
     * dem mDNS-Namen („3D59C51D251F.fritz.box"). Dessen IPv4 aufzulösen und darauf noch
     * einmal rückwärts zu fragen liefert den Klarnamen („EchoDot-Kueche").
     *
     * @param array<int, string>                            $addresses
     * @param callable(string): ?string                     $reverseName
     * @param callable(string): ?string                     $resolveIpv4
     * @param array{names: array<string, string>, queries: int, stop: string|null} $state
     * @return array{0: string|null, 1: string|null} Adresse und Name, jeweils null ohne Treffer
     */
    private static function forDevice(array $addresses, callable $reverseName, callable $resolveIpv4, array &$state): array
    {
        foreach ($addresses as $address) {
            if (filter_var((string)$address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            $state['queries']++;

            return [(string)$address, $reverseName((string)$address)];
        }

        foreach ($addresses as $address) {
            $state['queries']++;
            $name = $reverseName((string)$address);
            if ($name === null) {
                continue;
            }
            $ipv4 = $resolveIpv4($name);
            $state['queries']++;
            if ($ipv4 === null) {
                return [(string)$address, $name];
            }

            return [(string)$address, $reverseName($ipv4) ?? $name];
        }

        return [null, null];
    }
}
