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
