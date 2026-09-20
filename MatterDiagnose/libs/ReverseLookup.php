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
        // Uhr und Regeln reisen im Zustand mit, weil jede einzelne Abfrage sie prüft —
        // nicht erst das fertig abgefragte Gerät. Ein Gerät ohne IPv4 fragt sonst jede
        // seiner IPv6-Adressen ab, und bei einem Resolver im Timeout sind das vier
        // Timeouts am Stück (Cloud-Review 20.09.2026).
        $state = [
            'names'    => [],
            'queries'  => 0,
            'stop'     => null,
            'clock'    => $clock,
            'deadline' => $deadline,
            'max'      => $maxQueries,
            'slow'     => $slow,
        ];

        foreach ($devices as $device) {
            if (($device['nodeId'] ?? null) !== null) {
                continue;
            }
            [$address, $name] = self::forDevice(
                array_values((array)($device['addresses'] ?? [])),
                $reverseName,
                $resolveIpv4,
                $state
            );
            if ($address !== null && $name !== null) {
                $state['names'][$address] = $name;
            }
            if ($state['stop'] !== null) {
                break;
            }
        }

        return ['names' => $state['names'], 'queries' => $state['queries'], 'stop' => $state['stop']];
    }

    /**
     * Eine einzelne Abfrage — und die Stelle, an der die Runde endet.
     *
     * Vor der Abfrage: Ist die Frist abgelaufen oder die Höchstzahl erreicht, wird gar
     * nicht mehr gefragt. Danach: War die Antwort langsamer als erlaubt, hängt der
     * Resolver, und jede weitere Abfrage kostete dasselbe noch einmal.
     *
     * Eine bereits laufende Abfrage lässt sich nicht abbrechen — `gethostbyaddr` hat
     * keinen Zeitschalter. Die Runde überzieht deshalb im schlechtesten Fall um genau
     * ein Timeout, nicht um deren acht.
     *
     * @param array{names: array<string, string>, queries: int, stop: string|null, clock: callable(): float, deadline: float, max: int, slow: float} $state
     */
    private static function ask(callable $resolver, string $question, array &$state): ?string
    {
        if ($state['stop'] !== null) {
            return null;
        }
        if ($state['queries'] >= $state['max']) {
            $state['stop'] = 'limit';

            return null;
        }
        $vorher = ($state['clock'])();
        if ($vorher >= $state['deadline']) {
            $state['stop'] = 'deadline';

            return null;
        }

        $state['queries']++;
        $answer = $resolver($question);

        if ((($state['clock'])() - $vorher) > $state['slow']) {
            $state['stop'] = 'slow';
        }

        return $answer;
    }

    /**
     * Der Reverse-Eintrag zu einem Gerät, notfalls über zwei Ecken.
     *
     * Welche Adressen eine Annonce mitbringt, schwankt von Lauf zu Lauf: Kommt kein
     * A-Record, kennt das Modul nur IPv6 — und darauf antwortet die FRITZ!Box bloß mit
     * dem mDNS-Namen („3D59C51D251F.fritz.box"). Dessen IPv4 aufzulösen und darauf noch
     * einmal rückwärts zu fragen liefert den Klarnamen („EchoDot-Kueche").
     *
     * @param array<int, string>        $addresses
     * @param callable(string): ?string $reverseName
     * @param callable(string): ?string $resolveIpv4
     * @param array{names: array<string, string>, queries: int, stop: string|null, clock: callable(): float, deadline: float, max: int, slow: float} $state
     * @return array{0: string|null, 1: string|null} Adresse und Name, jeweils null ohne Treffer
     */
    private static function forDevice(array $addresses, callable $reverseName, callable $resolveIpv4, array &$state): array
    {
        foreach ($addresses as $address) {
            if (filter_var((string)$address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }

            return [(string)$address, self::ask($reverseName, (string)$address, $state)];
        }

        foreach ($addresses as $address) {
            $name = self::ask($reverseName, (string)$address, $state);
            if ($name === null) {
                if ($state['stop'] !== null) {
                    break;
                }

                continue;
            }
            $ipv4 = self::ask($resolveIpv4, $name, $state);
            if ($ipv4 === null) {
                return [(string)$address, $name];
            }

            return [(string)$address, self::ask($reverseName, $ipv4, $state) ?? $name];
        }

        return [null, null];
    }
}
