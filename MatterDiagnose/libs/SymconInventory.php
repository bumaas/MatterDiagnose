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
     * Die beiden Ebenen tragen verschiedene Namensarten: Die Knotenzeile heißt
     * nach dem Produkt ("KLIPPBOK water leak sensor"), die vom Anwender
     * vergebenen Symcon-Namen ("Wasserleck Sensor") stehen an den
     * Endpunkt-Unterzeilen. Beide werden gebraucht — der Produktname, um die
     * Zeile im Konfigurator wiederzufinden, die Symcon-Namen, um das Gerät
     * überhaupt zu erkennen. Sie kommen als "endpointNames" mit.
     *
     * @param array<mixed> $form dekodiertes IPS_GetConfigurationForm des Konfigurators
     * @return array<int, array{nodeId: int, name: string, vendor: string, product: string, subscription: ?string, instanceId: int, endpointNames: array<int, string>, endpointInstances: array<int, int>}>
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

        // Endpunktnamen je Knotenzeile einsammeln ("parent" verweist auf die Id
        // der Knotenzeile, nicht auf die Node-ID).
        // Dazu die Instanz-IDs der Endpunkte: An ihnen hängen die Variablen des
        // PowerSource-Clusters, aus denen sich Batteriebetrieb ablesen lässt — auch
        // dann, wenn das Gerät sich gerade nicht annonciert (Forum t/144417).
        $endpointsByRowId  = [];
        $instancesByRowId  = [];
        foreach ($list['values'] as $row) {
            if (!is_array($row) || !isset($row['parent'])) {
                continue;
            }
            $name = (string)($row['Name'] ?? '');
            if ($name !== '') {
                $endpointsByRowId[(string)$row['parent']][] = $name;
            }
            $endpointInstance = (int)($row['instanceID'] ?? 0);
            if ($endpointInstance > 0) {
                $instancesByRowId[(string)$row['parent']][] = $endpointInstance;
            }
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
                'nodeId'        => (int)$nodeId,
                'name'          => (string)($row['Name'] ?? ''),
                'vendor'        => (string)($row['VendorName'] ?? ''),
                'product'       => (string)($row['ProductName'] ?? ''),
                'subscription'  => is_string($subscription) && $subscription !== '' ? $subscription : null,
                'instanceId'    => (int)($row['instanceID'] ?? 0),
                'endpointNames'     => $endpointsByRowId[(string)($row['Id'] ?? '')] ?? [],
                'endpointInstances' => $instancesByRowId[(string)($row['Id'] ?? '')] ?? [],
            ];
        }

        return $devices;
    }

    /**
     * Zählt je Gerät, in wie vielen Matter-Fabrics es steckt.
     *
     * Warum das zählt: Die Fabric-Tabelle eines Geräts ist begrenzt (der
     * Standard verlangt mindestens fünf Plätze, die meisten Geräte haben genau
     * fünf). Ist sie voll, schlägt jede weitere Kopplung fehl, ohne dass die
     * Fehlermeldung auf die Ursache zeigt.
     *
     * Verbindendes Merkmal über Fabrics hinweg ist der SRV-Host der Annonce —
     * die Node-ID wird je Fabric neu vergeben und taugt dafür nicht. Ohne eigene
     * Fabric-ID lässt sich das eigene Gerät nicht identifizieren; ohne SRV-Host
     * (Annonce ohne SRV-Datensatz) bleibt die Belegung unbekannt.
     *
     * Der gelieferte Wert ist eine Untergrenze: gezählt wird, was gerade
     * annonciert wird. Das ist für eine Warnung die richtige Richtung — sie
     * schlägt nie zu früh an.
     *
     * @param array<int, array{nodeId: int, ...}> $known
     * @param array<int, array{instance: string, host?: string, ...}> $operational
     * @return array<int, int|null> Node-ID => Zahl der Fabrics (null = unbekannt)
     */
    public static function fabricUsage(array $known, array $operational, ?string $ownFabric): array
    {
        if ($ownFabric === null) {
            return [];
        }
        $ownFabric = strtoupper($ownFabric);

        // Node-Hex der eigenen Fabric => Host, und Host => Menge der Fabrics
        $ownHostByNode  = [];
        $fabricsByHost  = [];
        foreach ($operational as $announcement) {
            $parsed = self::parseOperationalName((string)($announcement['instance'] ?? ''));
            if ($parsed === null) {
                continue;
            }
            $host = strtolower(trim((string)($announcement['host'] ?? '')));
            if ($host === '') {
                continue;
            }
            $fabricsByHost[$host][$parsed['fabric']] = true;
            if ($parsed['fabric'] === $ownFabric) {
                $ownHostByNode[$parsed['node']] ??= $host;
            }
        }

        $usage = [];
        foreach ($known as $device) {
            $nodeId = (int)($device['nodeId'] ?? 0);
            $host   = $ownHostByNode[self::nodeHex($nodeId)] ?? null;
            $usage[$nodeId] = $host === null ? null : count($fabricsByHost[$host]);
        }

        return $usage;
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
     * Instanznamen der eigenen, sichtbaren Geräte ohne Schlafangabe — für eine gezielte
     * TXT-Nachfrage. MYGGBETT und KLIPPBOK kamen im nuc-Dump (18.09.2026) ohne TXT an, und
     * Batteriewerte führt Symcon für sie nicht; nur die Annonce sagt, dass sie schlafen.
     *
     * @param array<int, array{nodeId: int, visible?: bool, sleepy?: bool|null}> $known
     * @param array<int, array{instance: string, sleepy?: bool|null}> $operational
     * @param array<int, string> $ownFabrics Compressed Fabric IDs der eigenen Controller
     * @return array<int, string>
     */
    public static function instancesWithoutSleepInfo(array $known, array $operational, array $ownFabrics): array
    {
        $fabrics = array_map('strtoupper', $ownFabrics);
        if ($fabrics === []) {
            return [];
        }
        $wanted = [];
        foreach ($known as $device) {
            if (($device['visible'] ?? false) === true && ($device['sleepy'] ?? null) === null) {
                $wanted[self::nodeHex((int)$device['nodeId'])] = true;
            }
        }
        $result = [];
        foreach ($operational as $announcement) {
            $parsed = self::parseOperationalName((string)($announcement['instance'] ?? ''));
            if ($parsed === null || !in_array($parsed['fabric'], $fabrics, true) || !isset($wanted[$parsed['node']])) {
                continue;
            }
            if (($announcement['sleepy'] ?? null) === null) {
                $result[] = (string)$announcement['instance'];
            }
        }

        return array_values(array_unique($result));
    }

    /**
     * Läuft das Gerät auf Batterie? Erkennbar an den Variablen des
     * PowerSource-Clusters: Ein Batteriegerät hat `PowerSource_Bat…`-Werte
     * (Ladezustand, Restkapazität, Wechsel nötig), eine Steckdose nicht.
     *
     * Das ist die belastbarere Quelle als die mDNS-Annonce, denn sie steht in
     * Symcon und gilt auch für ein Gerät, das sich gerade nicht meldet.
     *
     * @param array<int, string> $idents Idents der Variablen aller Endpunkte des Geräts
     */
    public static function batteryFromVariables(array $idents): bool
    {
        foreach ($idents as $ident) {
            if (stripos($ident, 'PowerSource_Bat') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fasst Geräte zusammen, die mehrfach gelistet sind — etwa weil zwei
     * Konfiguratoren am selben Controller hängen. Die Zeile, zu der eine Instanz
     * angelegt ist, gewinnt (sie liefert das Alter der letzten Daten); sortiert
     * nach Node-ID, damit das Ergebnis nicht von der Reihenfolge abhängt.
     *
     * @param array<int, array{nodeId: int, instanceId?: int}> $devices
     * @return array<int, array{nodeId: int, instanceId?: int}>
     */
    public static function uniqueDevices(array $devices): array
    {
        $byNode = [];
        foreach ($devices as $device) {
            $nodeId = (int)$device['nodeId'];
            if (!isset($byNode[$nodeId]) || ((int)($byNode[$nodeId]['instanceId'] ?? 0) === 0 && (int)($device['instanceId'] ?? 0) > 0)) {
                $byNode[$nodeId] = $device;
            }
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
     * @param array<int, array{instance: string, host: string, addresses: array<int, string>, source: string, sleepy?: bool|null}> $operational
     * @return array{
     *     devices: array<int, array{nodeId: int, name: string, vendor: string, product: string, subscription: ?string, instanceId: int, visible: bool, ambiguous: bool, sleepy: bool|null}>,
     *     ambiguous: bool
     * }
     */
    public static function matchDevices(array $known, array $operational, ?string $ownFabric): array
    {
        $ownFabric = $ownFabric === null ? null : strtoupper($ownFabric);

        // Fabric => [Node-Hex => Schlafangabe]; reservierte Node-IDs sind keine Geräte.
        // Die Schlafangabe (aus SII/SAI/ICD der Annonce) wandert mit ans bekannte Gerät:
        // Fehlt es später, ist nur noch sie die Auskunft darüber, ob es stumm sein darf.
        $nodesByFabric = [];
        foreach ($operational as $device) {
            $parsed = self::parseOperationalName((string)($device['instance'] ?? ''));
            if ($parsed === null || $parsed['reserved']) {
                continue;
            }
            $nodesByFabric[$parsed['fabric']][$parsed['node']] = $device['sleepy'] ?? null;
        }

        $devices     = [];
        $anyAmbiguous = false;
        foreach ($known as $device) {
            $nodeHex   = self::nodeHex((int)$device['nodeId']);
            $visible   = false;
            $ambiguous = false;

            $sleepy = null;
            if ($ownFabric !== null) {
                $visible = array_key_exists($nodeHex, $nodesByFabric[$ownFabric] ?? []);
                $sleepy  = $nodesByFabric[$ownFabric][$nodeHex] ?? null;
            } else {
                $hits = 0;
                foreach ($nodesByFabric as $nodes) {
                    if (array_key_exists($nodeHex, $nodes)) {
                        $hits++;
                        $sleepy ??= $nodes[$nodeHex];
                    }
                }
                $visible   = $hits > 0;
                $ambiguous = $hits > 1;
            }
            $anyAmbiguous = $anyAmbiguous || $ambiguous;

            $devices[] = $device + ['visible' => $visible, 'ambiguous' => $ambiguous, 'sleepy' => $sleepy];
        }

        return [
            'devices'   => $devices,
            'ambiguous' => $anyAmbiguous,
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
