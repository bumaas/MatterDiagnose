<?php

declare(strict_types=1);

require_once __DIR__ . '/SymconInventory.php';
require_once __DIR__ . '/DeviceIdentity.php';
require_once __DIR__ . '/ThreadNetwork.php';

/**
 * Geräteliste: verdichtet die Matter-Annoncen zu einem Inventar je physischem Gerät.
 *
 * Ein Gerät annonciert sich einmal je System (Fabric), dem es angehört — 37 Ansagen
 * auf dem nuc waren 13 Geräte (18.09.2026). Verbindendes Merkmal ist der Hostname
 * der Annonce. Je Gerät stehen die Eigenschaften, die im Alltag zählen: Anbindung
 * (Thread oder LAN/WLAN), Betriebsart (Batterie oder Netz), Zahl der Systeme und ob
 * Symcon dabei ist, über wen es annonciert wird, seine Adressen.
 *
 * Reine Funktionen — die Tests füttern sie mit den Strukturen aus MatterDiscovery
 * und SymconInventory.
 */
class DeviceInventory
{
    public const LINK_THREAD = 'thread';
    public const LINK_LAN    = 'lan';

    public const POWER_BATTERY = 'battery';
    public const POWER_MAINS   = 'mains';
    public const POWER_UNKNOWN = 'unknown';

    /** Quelle der Annonce ist das Gerät selbst (LAN-/WLAN-Gerät) */
    public const VIA_SELF = 'self';

    /**
     * @param array<int, array{instance: string, host: string, addresses: array<int, string>, source: string, sleepy?: bool|null}> $operational
     * @param array<int, array{name: string, source: string}> $borderRouters
     * @param array<int, array{nodeId: int, name: string, sleepy?: bool|null}> $known in Symcon gekoppelte Geräte
     * @param array<int, string> $ownFabrics Compressed Fabric IDs der eigenen Controller
     * @param array<int, array{host: string, addresses: array<int, string>, vendor: string, model: string}> $identities aus DeviceIdentity::fromResponses
     * @return array<int, array{host: string, name: string, nodeId: int|null, vendor: string, model: string, link: string, power: string, fabrics: int, fabricIds: array<int, string>, symcon: bool, via: string, addresses: array<int, string>}>
     */
    public static function build(array $operational, array $borderRouters, array $known, array $ownFabrics, array $identities = []): array
    {
        $fabrics = array_map('strtoupper', $ownFabrics);
        $byNode  = [];
        foreach ($known as $device) {
            $byNode[SymconInventory::nodeHex((int)$device['nodeId'])] = $device;
        }
        $routerBySource = [];
        // Beschriftet wie im Befund „Thread Border Router gefunden": Name mit Hersteller,
        // sonst sagt „Wohnzimmer" nicht, welches Gerät gemeint ist (Burkhard, 18.09.2026).
        foreach ($borderRouters as $router) {
            $routerBySource[$router['source']] = ThreadNetwork::routerLabel((string)$router['name'], $router['txt']['vn'] ?? null);
        }

        $devices = [];
        foreach ($operational as $announcement) {
            $parsed = SymconInventory::parseOperationalName((string)$announcement['instance']);
            if ($parsed === null || $parsed['reserved']) {
                continue;
            }
            $host = strtolower(trim((string)$announcement['host']));
            $key  = $host !== '' ? $host : strtolower((string)$announcement['instance']);
            if (!isset($devices[$key])) {
                $devices[$key] = [
                    'host'      => $host !== '' ? (string)$announcement['host'] : '',
                    'name'      => '',
                    'nodeId'    => null,
                    'vendor'    => '',
                    'model'     => '',
                    'link'      => self::LINK_THREAD,
                    'power'     => self::POWER_UNKNOWN,
                    'fabrics'   => 0,
                    'fabricIds' => [],
                    'symcon'    => false,
                    'via'       => '',
                    'bridgedBy' => '',
                    'addresses' => [],
                    '_fabrics'  => [],
                    '_sleepy'   => [],
                    '_verdict'  => null,
                    '_product'  => '',
                ];
            }
            $entry = &$devices[$key];
            $entry['_fabrics'][$parsed['fabric']] = true;
            $entry['_sleepy'][]                   = $announcement['sleepy'] ?? null;
            foreach ($announcement['addresses'] as $address) {
                if (!in_array($address, $entry['addresses'], true)) {
                    $entry['addresses'][] = $address;
                }
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    $entry['link'] = self::LINK_LAN;
                }
            }
            if ($entry['via'] === '') {
                $source = (string)$announcement['source'];
                if (isset($routerBySource[$source])) {
                    $entry['via'] = $routerBySource[$source];
                } elseif (in_array($source, $announcement['addresses'], true)) {
                    $entry['via'] = self::VIA_SELF;
                } else {
                    $entry['via'] = $source;
                }
            }
            if (in_array($parsed['fabric'], $fabrics, true)) {
                $entry['symcon'] = true;
                $knownDevice     = $byNode[$parsed['node']] ?? null;
                if ($knownDevice !== null) {
                    $entry['name']     = (string)$knownDevice['name'];
                    $entry['nodeId']   = (int)$knownDevice['nodeId'];
                    $entry['vendor']   = trim((string)($knownDevice['vendor'] ?? ''));
                    $entry['_product'] = trim((string)($knownDevice['product'] ?? ''));
                    // Symcons Urteil (ICD-Kennzeichnung, Batteriewerte) schlägt die Annonce
                    if (($knownDevice['sleepy'] ?? null) !== null) {
                        $entry['_verdict'] = (bool)$knownDevice['sleepy'];
                    }
                }
            }
            unset($entry);
        }

        $rows = [];
        foreach ($devices as $entry) {
            $sleepy = array_filter($entry['_sleepy'], static fn(?bool $value): bool => $value !== null);
            if ($entry['_verdict'] !== null) {
                $sleepy = [$entry['_verdict']];
            }
            if (in_array(true, $sleepy, true)) {
                $entry['power'] = self::POWER_BATTERY;
            } elseif ($sleepy !== []) {
                $entry['power'] = self::POWER_MAINS;
            }
            // LAN-/WLAN-Geräte hängen praktisch immer am Strom; die Annonce sagt dazu nichts.
            if ($entry['link'] === self::LINK_LAN && $entry['power'] === self::POWER_UNKNOWN) {
                $entry['power'] = self::POWER_MAINS;
            }
            $entry['fabricIds'] = array_keys($entry['_fabrics']);
            sort($entry['fabricIds'], SORT_STRING);
            $entry['fabrics'] = count($entry['fabricIds']);
            if ($entry['name'] === '') {
                $entry['name'] = self::hostLabel($entry['host']);
            }
            // Hersteller: Symcon weiß es bei eigenen Geräten; sonst ein anderer Dienst desselben
            // Geräts oder die MAC-Adresse im Hostnamen. Der Produktname ist meist schon der Name.
            if ($entry['vendor'] !== '') {
                $entry['model'] = strcasecmp($entry['_product'], $entry['name']) === 0 ? '' : $entry['_product'];
            } else {
                $identity        = DeviceIdentity::identify($entry['host'], $entry['addresses'], $identities);
                $entry['vendor'] = $identity['vendor'];
                $entry['model']  = $identity['model'];
            }
            // Wie in der Annonce: eine Quelle, die selbst ein Border Router ist, steht als Name da.
            if ($entry['via'] === self::VIA_SELF && $entry['host'] !== '' && isset($routerBySource[$entry['host']])) {
                $entry['via'] = $routerBySource[$entry['host']];
            }
            usort($entry['addresses'], static fn(string $a, string $b): int => self::addressRank($a) <=> self::addressRank($b));
            unset($entry['_fabrics'], $entry['_sleepy'], $entry['_verdict'], $entry['_product']);
            $rows[] = $entry;
        }

        $rows = self::markBridged($rows);

        // Eigene Geräte zuerst, dann nach Namen — die Liste soll mit dem Bekannten beginnen.
        usort($rows, static fn(array $a, array $b): int => [$a['symcon'] ? 0 : 1, strtolower($a['name'])] <=> [$b['symcon'] ? 0 : 1, strtolower($b['name'])]);

        return $rows;
    }

    /**
     * Gebrückte Geräte kenntlich machen (Rainers Aqara Hub M3, 18.09.2026): Ein Hub, der
     * Fremdprotokolle nach Matter übersetzt, meldet jedes gebrückte Gerät unter eigenem
     * Namen — aber mit seiner eigenen Adresse. In der Liste stand es deshalb als anonyme
     * Nummer ohne Hersteller neben dem Hub, und niemand konnte sehen, dass beide dasselbe
     * Kästchen sind.
     *
     * Zwei Geräte mit derselben Adresse sind physisch eines. Wer davon der Träger ist,
     * wird nicht geraten: Entweder trägt einer die MAC der Adresse im Hostnamen, oder
     * genau einer der Gruppe ist in Symcon gekoppelt. Sonst bleibt die Gruppe unberührt —
     * eine falsche Richtung wäre schlimmer als gar keine Angabe.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public static function markBridged(array $rows): array
    {
        $byAddress = [];
        foreach ($rows as $index => $row) {
            foreach ($row['addresses'] as $address) {
                $key = strtolower(trim((string)$address));
                if ($key !== '' && !in_array($index, $byAddress[$key] ?? [], true)) {
                    $byAddress[$key][] = $index;
                }
            }
        }

        foreach ($byAddress as $address => $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            $carrier = self::carrierIndex($rows, $indexes, (string)$address);
            if ($carrier === null) {
                continue;
            }
            foreach ($indexes as $index) {
                if ($index === $carrier || $rows[$index]['bridgedBy'] !== '') {
                    continue;
                }
                $rows[$index]['bridgedBy'] = (string)$rows[$carrier]['name'];
                if ($rows[$index]['vendor'] === '') {
                    $rows[$index]['vendor'] = (string)$rows[$carrier]['vendor'];
                }
            }
        }

        return $rows;
    }

    /**
     * Der Träger einer Adressgruppe — mit Beleg oder gar nicht (siehe markBridged).
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, int> $indexes
     */
    private static function carrierIndex(array $rows, array $indexes, string $address): ?int
    {
        $mac = DeviceIdentity::macFromAddress($address);
        if ($mac !== null) {
            foreach ($indexes as $index) {
                if (stripos(self::hostLabel((string)$rows[$index]['host']), $mac) !== false) {
                    return $index;
                }
            }
        }

        $known = array_values(array_filter($indexes, static fn(int $index): bool => $rows[$index]['nodeId'] !== null));

        return count($known) === 1 ? $known[0] : null;
    }

    /**
     * Die Spalten „ein System je Spalte": zuerst die eigenen Fabrics (auch ohne Gerät —
     * ein leerer Symcon-Spalte ist eine Aussage), dann die fremden nach Zahl ihrer
     * Geräte, beschriftet A, B, C … Fremde Systeme haben aus der Annonce keinen Namen;
     * was der Anwender in $names eingetragen hat (Kennung => Name), steht statt des
     * Buchstabens. Den Buchstaben behält jedes System trotzdem (Feld letter) — rückten
     * die übrigen auf, hieße das gerade benannte „C" im nächsten Lauf plötzlich „A".
     *
     * @param array<int, array{fabricIds: array<int, string>}> $rows aus build()
     * @param array<int, string> $ownFabrics
     * @param array<string, string> $names Compressed Fabric ID => Name (Anwender)
     * @return array<int, array{id: string, label: string, letter: string, own: bool, named: bool, count: int}>
     */
    public static function fabricColumns(array $rows, array $ownFabrics, array $names = []): array
    {
        $own   = array_values(array_unique(array_map('strtoupper', $ownFabrics)));
        $named = [];
        foreach ($names as $fabric => $name) {
            if (trim((string)$name) !== '') {
                $named[strtoupper(trim((string)$fabric))] = trim((string)$name);
            }
        }
        $counts = [];
        foreach ($rows as $row) {
            foreach ($row['fabricIds'] as $fabric) {
                $counts[$fabric] = ($counts[$fabric] ?? 0) + 1;
            }
        }

        $columns = [];
        foreach ($own as $index => $fabric) {
            $columns[] = [
                'id'    => $fabric,
                'label'  => count($own) > 1 ? 'Symcon ' . ($index + 1) : 'Symcon',
                'letter' => '',
                'own'    => true,
                'named'  => false,
                'count' => $counts[$fabric] ?? 0,
            ];
        }

        $foreign = array_diff_key($counts, array_flip($own));
        uksort($foreign, static fn(string $a, string $b): int => [$foreign[$b], $a] <=> [$foreign[$a], $b]);
        $index = 0;
        foreach ($foreign as $fabric => $count) {
            $letter    = self::columnLetter($index++);
            $columns[] = [
                'id'     => (string)$fabric,
                'label'  => $named[$fabric] ?? $letter,
                'letter' => $letter,
                'own'    => false,
                'named'  => isset($named[$fabric]),
                'count'  => $count,
            ];
        }

        return $columns;
    }

    /** 0 → A … 25 → Z, 26 → AA — mehr als 26 fremde Systeme wären ein eigener Befund. */
    private static function columnLetter(int $index): string
    {
        $label = '';
        do {
            $label = chr(65 + $index % 26) . $label;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $label;
    }

    /** "CA1ACE989841CBEB.local" → "CA1ACE989841CBEB"; ohne Host der Hinweis, dass nichts aufgelöst wurde. */
    private static function hostLabel(string $host): string
    {
        if ($host === '') {
            return '?';
        }
        $label = preg_replace('/\.local\.?$/i', '', $host) ?? $host;

        return $label === '' ? $host : $label;
    }

    /** ULA (Thread-Adressen) zuerst, dann GUA, dann Link-Local, IPv4 zuletzt. */
    private static function addressRank(string $address): int
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return 3;
        }
        if (stripos($address, 'fe80:') === 0) {
            return 2;
        }
        if (DiagnosisEngine::isUla($address)) {
            return 0;
        }

        return 1;
    }
}
