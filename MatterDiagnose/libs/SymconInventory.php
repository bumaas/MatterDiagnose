<?php

declare(strict_types=1);

/**
 * Bringt zusammen, was Symcon über seine Matter-Geräte weiß, und was das
 * Netzwerk über sie annonciert.
 *
 * Die Angaben aus Symcon stammen aus den Konfigurationsformularen der
 * Kernmodule (Matter Controller und Matter Konfigurator) — deren Aufbau ist
 * nicht dokumentiert, deshalb wertet diese Klasse defensiv aus und liefert
 * überall dort null/leere Listen, wo das erwartete Feld fehlt.
 *
 * Reine Funktionen ohne Symcon-Aufrufe: die Formulare werden als dekodiertes
 * Array übergeben, damit die Tests mit echten Mitschnitten arbeiten können
 * (tests/fixtures/symcon/).
 */
class SymconInventory
{
    /** Matter Controller (Splitter, hält die Fabric) */
    public const GUID_CONTROLLER = '{D94BC35A-38D8-5F72-6C49-F9D4AD95DE36}';

    /** Matter Konfigurator — listet die Geräte der Fabric */
    public const GUID_CONFIGURATOR = '{6740C65A-8DBA-E62B-25CE-52C855225B70}';

    /**
     * Node-IDs ab diesem Wert sind keine Geräte: Der Matter-Standard hält den
     * oberen Bereich für Sonderzwecke frei (Gruppen, temporäre IDs, CASE-Tags).
     * Beobachtet an der Annonce einer SymBox: …-FFFFFFEFFFFFFFFF.
     */
    private const NODE_RESERVED_FROM = 'FFFFFFEF00000000';

    /**
     * Liest die Compressed Fabric ID aus dem Formular des Matter Controllers
     * (Label "CompressedFabric", Beschriftung "Compressed Fabric ID: <16 Hex>").
     *
     * @param array<mixed> $form dekodiertes IPS_GetConfigurationForm des Controllers
     */
    public static function fabricIdFromControllerForm(array $form): ?string
    {
        $label = self::findNode(
            $form,
            static fn(array $node): bool => ($node['name'] ?? null) === 'CompressedFabric'
        );
        if ($label === null || !is_string($label['caption'] ?? null)) {
            return null;
        }
        if (preg_match('/\b([0-9A-Fa-f]{16})\b/', $label['caption'], $match) !== 1) {
            return null;
        }

        return strtoupper($match[1]);
    }

    /**
     * Liest die Geräte der Fabric aus dem Formular des Matter Konfigurators.
     * Nur die obersten Zeilen sind Geräte — Zeilen mit "parent" beschreiben
     * einzelne Endpunkte desselben Geräts.
     *
     * "instanceId" ist 0, wenn zu dem gekoppelten Gerät (noch) keine Instanz
     * angelegt wurde; "subscription" fehlt bei Geräten ohne Abonnement.
     *
     * @param array<mixed> $form dekodiertes IPS_GetConfigurationForm des Konfigurators
     * @return array<int, array{nodeId: int, name: string, vendor: string, product: string, subscription: ?string, instanceId: int}>
     */
    public static function devicesFromConfiguratorForm(array $form): array
    {
        $list = self::findNode(
            $form,
            static fn(array $node): bool => ($node['name'] ?? null) === 'Configurator'
                && ($node['type'] ?? null) === 'Configurator'
        );
        if ($list === null || !is_array($list['values'] ?? null)) {
            return [];
        }

        $devices = [];
        foreach ($list['values'] as $row) {
            if (!is_array($row) || isset($row['parent'])) {
                continue; // Endpunkt-Unterzeile, kein eigenes Gerät
            }
            $nodeId = $row['create']['configuration']['NodeId'] ?? $row['Id'] ?? null;
            if (!is_numeric($nodeId)) {
                continue;
            }
            $subscription = $row['Subscription'] ?? null;
            $devices[]    = [
                'nodeId'       => (int)$nodeId,
                'name'         => (string)($row['Name'] ?? ''),
                'vendor'       => (string)($row['VendorName'] ?? ''),
                'product'      => (string)($row['ProductName'] ?? ''),
                'subscription' => is_string($subscription) && $subscription !== '' ? $subscription : null,
                'instanceId'   => (int)($row['instanceID'] ?? 0),
            ];
        }

        return $devices;
    }

    /**
     * Rückfallweg, wenn das Konfigurator-Formular nicht auswertbar ist: die
     * Geräteinstanzen selbst. Mehrere Endpunkt-Instanzen derselben Node werden
     * zu einer Zeile zusammengefasst (die mit der kleinsten Instanz-ID gewinnt,
     * damit das Ergebnis unabhängig von der Aufzählungsreihenfolge ist).
     *
     * @param array<int, array{instanceId: int, name: string, nodeId: int}> $instances
     * @return array<int, array{nodeId: int, name: string, vendor: string, product: string, subscription: ?string, instanceId: int}>
     */
    public static function devicesFromInstances(array $instances): array
    {
        $byNode = [];
        foreach ($instances as $instance) {
            $nodeId = (int)($instance['nodeId'] ?? 0);
            if ($nodeId <= 0) {
                continue;
            }
            $instanceId = (int)($instance['instanceId'] ?? 0);
            if (isset($byNode[$nodeId]) && $byNode[$nodeId]['instanceId'] <= $instanceId) {
                continue;
            }
            $byNode[$nodeId] = [
                'nodeId'       => $nodeId,
                'name'         => (string)($instance['name'] ?? ''),
                'vendor'       => '',
                'product'      => '',
                'subscription' => null,
                'instanceId'   => $instanceId,
            ];
        }
        ksort($byNode);

        return array_values($byNode);
    }

    /**
     * Zerlegt den Instanznamen einer betriebsbereiten Matter-Annonce:
     * "<Compressed Fabric ID>-<Node ID>._matter._tcp.local", beide 16-stellig hex.
     *
     * Node bleibt bewusst als Hex-String erhalten — 64-Bit-Node-IDs wie
     * FFFFFFEFFFFFFFFF überschreiten PHP_INT_MAX und würden als Float ungenau.
     *
     * @return array{fabric: string, node: string, reserved: bool}|null
     */
    public static function parseOperationalName(string $instance): ?array
    {
        if (preg_match('/^([0-9A-Fa-f]{16})-([0-9A-Fa-f]{16})\./', $instance, $match) !== 1) {
            return null;
        }
        $node = strtoupper($match[2]);

        return [
            'fabric'   => strtoupper($match[1]),
            'node'     => $node,
            'reserved' => strcmp($node, self::NODE_RESERVED_FROM) >= 0,
        ];
    }

    /** Node-ID aus Symcon (Integer) in die 16-stellige Hex-Schreibweise der Annonce. */
    public static function nodeHex(int $nodeId): string
    {
        return strtoupper(sprintf('%016x', $nodeId));
    }

    /**
     * Gleicht die in Symcon bekannten Geräte gegen die annoncierten Matter-Dienste ab.
     *
     * Ist die eigene Fabric bekannt, zählt nur eine Annonce in genau dieser Fabric.
     * Ohne Fabric-ID (Formular unlesbar) wird über alle Fabrics nach der Node-ID
     * gesucht; taucht dieselbe Node in mehreren Fabrics auf, ist die Zuordnung
     * mehrdeutig und wird als solche gemeldet.
     *
     * @param array<int, array{nodeId: int, name: string, vendor: string, product: string, subscription: ?string, instanceId: int}> $known
     * @param array<int, array{instance: string, host: string, addresses: array<int, string>, source: string}> $operational
     * @return array{
     *     devices: array<int, array{nodeId: int, name: string, vendor: string, product: string, subscription: ?string, instanceId: int, visible: bool, ambiguous: bool}>,
     *     ambiguous: bool,
     *     foreignFabrics: array<string, int>,
     *     ownAnnouncements: int
     * }
     */
    public static function matchDevices(array $known, array $operational, ?string $ownFabric): array
    {
        $ownFabric = $ownFabric === null ? null : strtoupper($ownFabric);

        // Fabric => [Node-Hex => true]; reservierte Node-IDs sind keine Geräte
        $nodesByFabric    = [];
        $foreignFabrics   = [];
        $ownAnnouncements = 0;
        foreach ($operational as $device) {
            $parsed = self::parseOperationalName((string)($device['instance'] ?? ''));
            if ($parsed === null || $parsed['reserved']) {
                continue;
            }
            $nodesByFabric[$parsed['fabric']][$parsed['node']] = true;
            if ($ownFabric !== null && $parsed['fabric'] === $ownFabric) {
                $ownAnnouncements++;
            } else {
                $foreignFabrics[$parsed['fabric']] = ($foreignFabrics[$parsed['fabric']] ?? 0) + 1;
            }
        }
        ksort($foreignFabrics);

        $devices     = [];
        $anyAmbiguous = false;
        foreach ($known as $device) {
            $nodeHex   = self::nodeHex((int)$device['nodeId']);
            $visible   = false;
            $ambiguous = false;

            if ($ownFabric !== null) {
                $visible = isset($nodesByFabric[$ownFabric][$nodeHex]);
            } else {
                $hits = 0;
                foreach ($nodesByFabric as $nodes) {
                    if (isset($nodes[$nodeHex])) {
                        $hits++;
                    }
                }
                $visible   = $hits > 0;
                $ambiguous = $hits > 1;
            }
            $anyAmbiguous = $anyAmbiguous || $ambiguous;

            $devices[] = $device + ['visible' => $visible, 'ambiguous' => $ambiguous];
        }

        return [
            'devices'          => $devices,
            'ambiguous'        => $anyAmbiguous,
            'foreignFabrics'   => $foreignFabrics,
            'ownAnnouncements' => $ownAnnouncements,
        ];
    }

    /**
     * Sucht rekursiv den ersten Knoten (assoziatives Array), auf den das
     * Prädikat zutrifft.
     *
     * @param array<mixed> $node
     * @param callable(array<mixed>): bool $matches
     * @return array<mixed>|null
     */
    private static function findNode(array $node, callable $matches): ?array
    {
        if ($matches($node)) {
            return $node;
        }
        foreach ($node as $child) {
            if (is_array($child)) {
                $found = self::findNode($child, $matches);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
