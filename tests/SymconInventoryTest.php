<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/SymconInventory.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MatterDiscovery.php';

/**
 * Abgleich zwischen Symcon-Wissen und Netz-Annoncen. Die Formular-Fixtures unter
 * fixtures/symcon/ sind echte Mitschnitte von IPS_GetConfigurationForm (nuc,
 * 02.09.2026) — der Aufbau dieser Formulare ist nicht dokumentiert und kann
 * sich ändern, deshalb wird gegen das Original geprüft.
 */

$loadForm = static fn(string $file): array => json_decode(
    (string)file_get_contents(__DIR__ . '/fixtures/symcon/' . $file),
    true,
    64,
    JSON_THROW_ON_ERROR
);

// --- Compressed Fabric ID aus dem Controller-Formular ---------------------
assertSame(
    'A5AC1650B5C2EE16',
    SymconInventory::fabricIdFromControllerForm($loadForm('controller_form_nuc.json')),
    'Fabric-ID aus dem Controller-Formular des nuc'
);
assertSame(
    null,
    SymconInventory::fabricIdFromControllerForm($loadForm('controller_form_without_fabric.json')),
    'Formular ohne CompressedFabric-Label liefert null'
);
assertSame(
    'A5AC1650B5C2EE16',
    SymconInventory::fabricIdFromControllerForm(
        ['actions' => [['type' => 'Label', 'name' => 'CompressedFabric', 'caption' => 'Compressed Fabric ID: a5ac1650b5c2ee16']]]
    ),
    'Kleinbuchstaben werden normalisiert'
);
assertSame(
    null,
    SymconInventory::fabricIdFromControllerForm(
        ['actions' => [['type' => 'Label', 'name' => 'CompressedFabric', 'caption' => 'Compressed Fabric ID: unbekannt']]]
    ),
    'Label ohne Hex-Wert liefert null'
);

// --- Geräte aus dem Konfigurator-Formular ---------------------------------
$devices = SymconInventory::devicesFromConfiguratorForm($loadForm('configurator_form_nuc.json'));
assertSame(1, count($devices), 'Ein Gerät im Konfigurator des nuc (Endpunkt-Unterzeile 6.1 ignoriert)');
assertSame(6, $devices[0]['nodeId'], 'Node-ID aus create.configuration.NodeId');
assertSame('MYGGBETT door/window sensor', $devices[0]['name'], 'Gerätename');
assertSame('IKEA of Sweden', $devices[0]['vendor'], 'Hersteller');
assertSame('OK (ICD)', $devices[0]['subscription'], 'Abonnement-Status');
assertSame(23721, $devices[0]['instanceId'], 'Instanz-ID des angelegten Geräts');

assertSame(
    [],
    SymconInventory::devicesFromConfiguratorForm(['actions' => [['type' => 'Configurator', 'name' => 'Configurator']]]),
    'Konfigurator ohne values liefert eine leere Liste'
);
assertSame([], SymconInventory::devicesFromConfiguratorForm(['actions' => []]), 'Formular ohne Konfigurator liefert eine leere Liste');

$withoutSubscription = SymconInventory::devicesFromConfiguratorForm([
    'actions' => [[
        'type'   => 'Configurator',
        'name'   => 'Configurator',
        'values' => [
            ['Id' => '7', 'Name' => 'Steckdose', 'instanceID' => 0, 'create' => ['configuration' => ['NodeId' => 7]]],
        ],
    ]],
]);
assertSame(null, $withoutSubscription[0]['subscription'], 'Fehlendes Abonnement wird zu null');
assertSame(0, $withoutSubscription[0]['instanceId'], 'Gekoppeltes Gerät ohne Instanz hat instanceId 0');

// --- Rückfallweg über die Instanzen ---------------------------------------
$fallback = SymconInventory::devicesFromInstances($loadForm('instances_fallback.json'));
assertSame(2, count($fallback), 'Zwei Geräte — beide Endpunkt-Instanzen der Node 6 zusammengefasst');
assertSame(6, $fallback[0]['nodeId'], 'Node 6 zuerst (nach Node-ID sortiert)');
assertSame(23721, $fallback[0]['instanceId'], 'Die kleinste Instanz-ID gewinnt');
assertSame('MYGGBETT door/window sensor', $fallback[0]['name'], 'Name aus der gewählten Instanz');
assertSame(null, $fallback[0]['subscription'], 'Über die Instanzen ist kein Abonnement-Status bekannt');
assertSame(9, $fallback[1]['nodeId'], 'Node 9 als zweites Gerät');

// --- Instanznamen der Annoncen zerlegen -----------------------------------
$parsed = SymconInventory::parseOperationalName('90B99E147F5D9954-0000000000000006._matter._tcp.local');
assertSame('90B99E147F5D9954', $parsed['fabric'] ?? '', 'Fabric-ID aus dem Instanznamen');
assertSame('0000000000000006', $parsed['node'] ?? '', 'Node-ID als 16-stelliger Hex-String');
assertSame(false, $parsed['reserved'] ?? true, 'Node 6 ist ein reguläres Gerät');

$controller = SymconInventory::parseOperationalName('90B99E147F5D9954-FFFFFFEFFFFFFFFF._matter._tcp.local');
assertSame(true, $controller['reserved'] ?? false, 'Node im reservierten Bereich wird erkannt');
assertSame(null, SymconInventory::parseOperationalName('Wohnzimmer._matter._tcp.local'), 'Fremdes Namensmuster liefert null');
assertSame(null, SymconInventory::parseOperationalName(''), 'Leerer Name liefert null');
assertSame('0000000000000006', SymconInventory::nodeHex(6), 'Node-ID 6 als Hex');
assertSame('00000000DDC6A9DC', SymconInventory::nodeHex(0xDDC6A9DC), 'Große Node-ID als Hex');

// --- Abgleich gegen die echten Paketmitschnitte ---------------------------
$manifest  = json_decode((string)file_get_contents(__DIR__ . '/fixtures/mdns/manifest.json'), true);
$responses = [];
foreach ($manifest as $entry) {
    $responses[] = [
        'from'    => $entry['source'],
        'message' => MdnsCodec::decodeMessage((string)file_get_contents(__DIR__ . '/fixtures/mdns/' . $entry['file'])),
    ];
}
$operational = MatterDiscovery::collect($responses, [])['operationalDevices'];

$known = [
    ['nodeId' => 3, 'name' => 'Lampe', 'vendor' => '', 'product' => '', 'subscription' => 'OK', 'instanceId' => 1],
    ['nodeId' => 5, 'name' => 'Sensor', 'vendor' => '', 'product' => '', 'subscription' => 'OK', 'instanceId' => 2],
    ['nodeId' => 6, 'name' => 'Schalter', 'vendor' => '', 'product' => '', 'subscription' => 'OK', 'instanceId' => 3],
    ['nodeId' => 9, 'name' => 'Vermisst', 'vendor' => '', 'product' => '', 'subscription' => 'OK', 'instanceId' => 4],
];

$match   = SymconInventory::matchDevices($known, $operational, '90B99E147F5D9954');
$visible = [];
$missing = [];
foreach ($match['devices'] as $device) {
    if ($device['visible']) {
        $visible[] = $device['nodeId'];
    } else {
        $missing[] = $device['nodeId'];
    }
}
assertSame([3, 5, 6], $visible, 'Nodes 3, 5 und 6 annoncieren sich in der eigenen Fabric');
assertSame([9], $missing, 'Node 9 fehlt im Netz');
assertSame(false, $match['ambiguous'], 'Mit bekannter Fabric ist nichts mehrdeutig');
assertTrue($match['ownAnnouncements'] >= 3, 'Annoncen der eigenen Fabric gezählt');
assertTrue(!isset($match['foreignFabrics']['90B99E147F5D9954']), 'Die eigene Fabric zählt nicht als fremd');
assertTrue(isset($match['foreignFabrics']['35FA3C0EA8A2346D']), 'Fremde Fabric 35FA3C0EA8A2346D erkannt');
assertTrue(count($match['foreignFabrics']) >= 4, 'Mehrere fremde Fabrics im Mitschnitt');
assertTrue(
    array_sum($match['foreignFabrics']) >= 20,
    'Fremde Annoncen gezählt (' . array_sum($match['foreignFabrics']) . ')'
);

// Reservierte Node-IDs (Controller-Annonce) fließen nicht in die Zählung ein
$reservedOnly = SymconInventory::matchDevices(
    [],
    [['instance' => 'AAAABBBBCCCCDDDD-FFFFFFEFFFFFFFFF._matter._tcp.local', 'host' => '', 'addresses' => [], 'source' => '']],
    '90B99E147F5D9954'
);
assertSame([], $reservedOnly['foreignFabrics'], 'Reservierte Node-IDs zählen nicht als fremdes Gerät');

// --- Abgleich ohne bekannte Fabric-ID -------------------------------------
$withoutFabric = SymconInventory::matchDevices($known, $operational, null);
$byNode        = [];
foreach ($withoutFabric['devices'] as $device) {
    $byNode[$device['nodeId']] = $device;
}
assertSame(true, $withoutFabric['ambiguous'], 'Ohne Fabric-ID ist die Zuordnung mehrdeutig');
assertSame(true, $byNode[6]['visible'], 'Node 6 wird auch ohne Fabric-ID gefunden');
assertSame(true, $byNode[6]['ambiguous'], 'Node 6 existiert in mehreren Fabrics');
assertSame(false, $byNode[9]['visible'], 'Node 9 fehlt auch ohne Fabric-ID');
assertSame(false, $byNode[9]['ambiguous'], 'Ein nicht gefundenes Gerät ist nicht mehrdeutig');

// --- Fabric-Belegung je Gerät ---------------------------------------------
// Ein Matter-Gerät hat eine begrenzte Fabric-Tabelle (Standard: mindestens 5
// Plätze). Ist sie voll, schlägt jede weitere Kopplung fehl — mit einer
// Fehlermeldung, die nicht auf die Ursache zeigt. Wie viele Plätze belegt sind,
// verrät das Netz: dasselbe Gerät annonciert sich in jeder Fabric einmal, immer
// unter demselben SRV-Host, aber mit fabric-eigener Node-ID.
//
// Die Annoncen unten sind ein Mitschnitt vom 02.09.2026 (nuc, 28 Instanzen):
// 68EC8A0BE88A.local steckte in vier Fabrics, 0AFBFBC93A714D87.local in dreien.
$announcements = [
    ['instance' => '90B99E147F5D9954-0000000000000003._matter._tcp.local', 'host' => '68EC8A0BE88A.local'],
    ['instance' => '39E99BD14DFBBCD1-63E2D34A47E6F9E2._matter._tcp.local', 'host' => '68EC8A0BE88A.local'],
    ['instance' => '71C2EE4C5CD7A3B4-0000000000000001._matter._tcp.local', 'host' => '68EC8A0BE88A.local'],
    ['instance' => 'B0E451B717784CDF-0000000000000001._matter._tcp.local', 'host' => '68EC8A0BE88A.local'],
    ['instance' => '90B99E147F5D9954-0000000000000008._matter._tcp.local', 'host' => '0AFBFBC93A714D87.local'],
    ['instance' => '39E99BD14DFBBCD1-CD9493D7BA73FA87._matter._tcp.local', 'host' => '0AFBFBC93A714D87.local'],
    ['instance' => '35FA3C0EA8A2346D-0000000012AC01DF._matter._tcp.local', 'host' => '0AFBFBC93A714D87.local'],
    ['instance' => '90B99E147F5D9954-000000000000000C._matter._tcp.local', 'host' => 'ecb5fab05408.local'],
    // Ohne SRV-Host lässt sich nichts zuordnen — der Fall muss null liefern.
    ['instance' => '90B99E147F5D9954-0000000000000006._matter._tcp.local', 'host' => ''],
];

$usage = SymconInventory::fabricUsage(
    [
        ['nodeId' => 3,  'name' => 'DIRIGERA'],
        ['nodeId' => 8,  'name' => 'KLIPPBOK water leak sensor'],
        ['nodeId' => 12, 'name' => 'BILRESA dual button'],
        ['nodeId' => 6,  'name' => 'ALPSTUGA air quality monitor'],
        ['nodeId' => 99, 'name' => 'Gerät ohne Annonce'],
    ],
    $announcements,
    '90B99E147F5D9954'
);

assertSame(4, $usage[3] ?? null, 'Node 3 (68EC8A0BE88A) steckt in vier Fabrics');
assertSame(3, $usage[8] ?? null, 'Node 8 (0AFBFBC93A714D87) steckt in drei Fabrics');
assertSame(1, $usage[12] ?? null, 'Node 12 (ecb5fab05408) nur in der eigenen Fabric');
// Achtung: "?? " unterscheidet nicht zwischen fehlendem Schlüssel und null —
// hier wird beides getrennt geprüft, denn der Schlüssel muss vorhanden sein.
assertTrue(array_key_exists(6, $usage), 'Auch ein Gerät ohne SRV-Host steht im Ergebnis');
assertSame(null, $usage[6], 'Ohne SRV-Host bleibt die Belegung unbekannt (null)');
assertTrue(array_key_exists(99, $usage), 'Auch ein Gerät ohne Annonce steht im Ergebnis');
assertSame(null, $usage[99], 'Ein Gerät ohne Annonce liefert null, nicht 0');

assertSame(
    [],
    SymconInventory::fabricUsage([['nodeId' => 3, 'name' => 'X']], $announcements, null),
    'Ohne eigene Fabric-ID ist keine Zuordnung möglich'
);

// Die Node-ID unterscheidet sich je Fabric — nur der Host verbindet die Annoncen.
$sameNodeDifferentDevice = SymconInventory::fabricUsage(
    [['nodeId' => 1, 'name' => 'Eigenes Gerät mit Node 1']],
    [
        ['instance' => '90B99E147F5D9954-0000000000000001._matter._tcp.local', 'host' => 'aaaa.local'],
        ['instance' => 'B0E451B717784CDF-0000000000000001._matter._tcp.local', 'host' => 'bbbb.local'],
    ],
    '90B99E147F5D9954'
);
assertSame(
    1,
    $sameNodeDifferentDevice[1] ?? null,
    'Gleiche Node-ID in fremder Fabric, aber anderer Host: zählt nicht mit'
);

// --- Endpunktnamen zum Knoten ---------------------------------------------
// Die Knotenzeile des Konfigurators trägt den Produktnamen ("KLIPPBOK water leak
// sensor"), die Symcon-Namen hängen an den Endpunkt-Unterzeilen (parent = "8").
// Für den Anwender müssen beide im Befund stehen.
$withEndpoints = SymconInventory::devicesFromConfiguratorForm([
    'actions' => [[
        'type'   => 'Configurator',
        'name'   => 'Configurator',
        'values' => [
            ['Id' => '8', 'Name' => 'KLIPPBOK water leak sensor', 'VendorName' => 'IKEA of Sweden',
             'create' => ['configuration' => ['NodeId' => 8]]],
            ['Id' => '8.1', 'Name' => 'Wasserleck Sensor', 'parent' => '8', 'instanceID' => 26374],
            ['Id' => '11', 'Name' => 'Presence Multi-Sensor FP300', 'VendorName' => 'Aqara',
             'create' => ['configuration' => ['NodeId' => 11]]],
            ['Id' => '11.1', 'Name' => 'Anwesenheitssensor', 'parent' => '11', 'instanceID' => 15486],
            ['Id' => '11.2', 'Name' => 'Lichtsensor', 'parent' => '11', 'instanceID' => 22153],
        ],
    ]],
]);
$byNodeId = [];
foreach ($withEndpoints as $device) {
    $byNodeId[$device['nodeId']] = $device;
}
assertSame(['Wasserleck Sensor'], $byNodeId[8]['endpointNames'] ?? null, 'Endpunktname des Wasserleck-Sensors');
assertSame(
    ['Anwesenheitssensor', 'Lichtsensor'],
    $byNodeId[11]['endpointNames'] ?? null,
    'Beide Endpunkte des FP300 in Reihenfolge der Liste'
);
assertSame(
    'KLIPPBOK water leak sensor',
    $byNodeId[8]['name'] ?? null,
    'Der Knotenname bleibt der Produktname aus der Konfigurator-Zeile'
);
