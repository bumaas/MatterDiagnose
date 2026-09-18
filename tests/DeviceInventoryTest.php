<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceInventory.php';

/**
 * Geräteliste aus den Annoncen — Formen aus dem nuc-Debug-Auszug vom 18.09.2026:
 * Thread-Geräte, die der Apple TV stellvertretend annonciert, WLAN-Shellys, die sich
 * selbst annoncieren, ein Gerät in mehreren Systemen, ein Gerät ohne aufgelösten Host.
 */

$borderRouters = [
    ['name' => 'Wohnzimmer', 'source' => '192.168.178.63'],
    ['name' => 'DIRIGERA #666D', 'source' => '192.168.178.186'],
];
$known = [
    ['nodeId' => 9, 'name' => 'KLIPPBOK water leak sensor', 'sleepy' => null],
    ['nodeId' => 10, 'name' => 'Shelly Dimmer Gen4', 'sleepy' => false],
    ['nodeId' => 11, 'name' => 'GRILLPLATS Plug', 'sleepy' => null],
    ['nodeId' => 6, 'name' => 'MYGGBETT door/window sensor', 'sleepy' => true],
];
$operational = [
    // KLIPPBOK: vier Systeme, Thread, schläft laut Annonce
    ['instance' => 'B0E451B717784CDF-0000000000000006._matter._tcp.local', 'host' => '12C590FFD09CEF22.local', 'addresses' => ['fd89:6b7:bc55:0:6efe:107f:c87e:36e2'], 'source' => '192.168.178.63', 'sleepy' => true],
    ['instance' => 'A5AC1650B5C2EE16-0000000000000009._matter._tcp.local', 'host' => '12c590ffd09cef22.local', 'addresses' => ['fd89:6b7:bc55:0:6efe:107f:c87e:36e2'], 'source' => '192.168.178.63', 'sleepy' => true],
    ['instance' => '39E99BD14DFBBCD1-EC4C9F8250A65571._matter._tcp.local', 'host' => '12C590FFD09CEF22.local', 'addresses' => ['fd89:6b7:bc55:0:6efe:107f:c87e:36e2'], 'source' => '192.168.178.63', 'sleepy' => true],
    ['instance' => '35FA3C0EA8A2346D-00000000BE171A3D._matter._tcp.local', 'host' => '12C590FFD09CEF22.local', 'addresses' => ['fd89:6b7:bc55:0:6efe:107f:c87e:36e2'], 'source' => '192.168.178.63', 'sleepy' => true],
    // GRILLPLATS: zwei Systeme, Thread, Annonce ohne TXT
    ['instance' => '35FA3C0EA8A2346D-000000007D1FF6DB._matter._tcp.local', 'host' => 'CA1ACE989841CBEB.local', 'addresses' => ['fd89:6b7:bc55:0:3a31:e5b3:b3a2:5d3d'], 'source' => '192.168.178.63', 'sleepy' => null],
    ['instance' => 'A5AC1650B5C2EE16-000000000000000B._matter._tcp.local', 'host' => 'CA1ACE989841CBEB.local', 'addresses' => ['fd89:6b7:bc55:0:3a31:e5b3:b3a2:5d3d'], 'source' => '192.168.178.63', 'sleepy' => null],
    // Shelly Dimmer: WLAN, annonciert sich selbst, drei Systeme
    ['instance' => '35FA3C0EA8A2346D-00000000B6E03F3D._matter._tcp.local', 'host' => 'E8F60A7C9714.local', 'addresses' => ['192.168.178.67', 'fd86:6fd:53ed:0:eaf6:aff:fe7c:9714', '2003:f9:7f09:1400:eaf6:aff:fe7c:9714', 'fe80::eaf6:aff:fe7c:9714'], 'source' => '192.168.178.67', 'sleepy' => false],
    ['instance' => 'A5AC1650B5C2EE16-000000000000000A._matter._tcp.local', 'host' => 'E8F60A7C9714.local', 'addresses' => ['192.168.178.67', 'fd86:6fd:53ed:0:eaf6:aff:fe7c:9714', '2003:f9:7f09:1400:eaf6:aff:fe7c:9714', 'fe80::eaf6:aff:fe7c:9714'], 'source' => '192.168.178.67', 'sleepy' => false],
    ['instance' => 'B0E451B717784CDF-0000000000000019._matter._tcp.local', 'host' => 'E8F60A7C9714.local', 'addresses' => ['192.168.178.67', 'fd86:6fd:53ed:0:eaf6:aff:fe7c:9714', '2003:f9:7f09:1400:eaf6:aff:fe7c:9714', 'fe80::eaf6:aff:fe7c:9714'], 'source' => '192.168.178.67', 'sleepy' => false],
    // Fremdes Thread-Gerät ohne Symcon, annonciert über den Apple TV
    ['instance' => 'B0E451B717784CDF-0000000000000007._matter._tcp.local', 'host' => 'B2EDD5A10FF0C48C.local', 'addresses' => ['fd89:6b7:bc55:0:99fa:ff9d:a248:af4e'], 'source' => '192.168.178.63', 'sleepy' => null],
    // Annonce ohne SRV (Host unbekannt)
    ['instance' => '35FA3C0EA8A2346D-0000000000000099._matter._tcp.local', 'host' => '', 'addresses' => [], 'source' => '192.168.178.63', 'sleepy' => null],
    // Die eigene Controller-Annonce (reservierte Node-ID) gehört nicht in die Liste
    ['instance' => '90B99E147F5D9954-FFFFFFEFFFFFFFFF._matter._tcp.local', 'host' => 'SymBox.local', 'addresses' => ['192.168.178.172'], 'source' => '192.168.178.172', 'sleepy' => false],
];

$rows = DeviceInventory::build($operational, $borderRouters, $known, ['A5AC1650B5C2EE16']);
$byName = [];
foreach ($rows as $row) {
    $byName[$row['name']] = $row;
}

assertSame(5, count($rows), 'Fünf Geräte aus elf Annoncen (Controller-Annonce ausgenommen)');
assertSame(['GRILLPLATS Plug', 'KLIPPBOK water leak sensor', 'Shelly Dimmer Gen4'], array_slice(array_map(static fn(array $r): string => $r['name'], $rows), 0, 3), 'Eigene Geräte zuerst, alphabetisch');

$klippbok = $byName['KLIPPBOK water leak sensor'] ?? [];
assertSame(9, $klippbok['nodeId'] ?? null, 'KLIPPBOK: Symcon-Name und Node-ID aus der eigenen Fabric');
assertSame(DeviceInventory::LINK_THREAD, $klippbok['link'] ?? null, 'KLIPPBOK: Thread (keine IPv4)');
assertSame(DeviceInventory::POWER_BATTERY, $klippbok['power'] ?? null, 'KLIPPBOK: Batterie laut Annonce');
assertSame(4, $klippbok['fabrics'] ?? null, 'KLIPPBOK: vier Systeme (Host in beliebiger Schreibweise)');
assertSame(true, $klippbok['symcon'] ?? null, 'KLIPPBOK: Symcon dabei');
assertSame('Wohnzimmer', $klippbok['via'] ?? null, 'KLIPPBOK: annonciert über den Apple TV');

$grillplats = $byName['GRILLPLATS Plug'] ?? [];
assertSame(DeviceInventory::POWER_UNKNOWN, $grillplats['power'] ?? null, 'GRILLPLATS: Betriebsart unbekannt (kein TXT, keine Batteriewerte)');
assertSame(2, $grillplats['fabrics'] ?? null, 'GRILLPLATS: zwei Systeme');

$dimmer = $byName['Shelly Dimmer Gen4'] ?? [];
assertSame(DeviceInventory::LINK_LAN, $dimmer['link'] ?? null, 'Shelly: LAN/WLAN (IPv4 vorhanden)');
assertSame(DeviceInventory::POWER_MAINS, $dimmer['power'] ?? null, 'Shelly: Netz');
assertSame(DeviceInventory::VIA_SELF, $dimmer['via'] ?? null, 'Shelly annonciert sich selbst');
assertSame('fd86:6fd:53ed:0:eaf6:aff:fe7c:9714', $dimmer['addresses'][0] ?? null, 'Adressen: ULA zuerst');
assertSame('192.168.178.67', end($dimmer['addresses']) ?: null, 'Adressen: IPv4 zuletzt');

$fremd = $byName['B2EDD5A10FF0C48C'] ?? [];
assertSame(false, $fremd['symcon'] ?? null, 'Fremdes Gerät: kein Symcon');
assertTrue(array_key_exists('nodeId', $fremd) && $fremd['nodeId'] === null, 'Fremdes Gerät: keine Node-ID');
assertSame(DeviceInventory::POWER_UNKNOWN, $fremd['power'] ?? null, 'Fremdes Thread-Gerät ohne TXT: unbekannt');

$ohneHost = $byName['?'] ?? [];
assertSame(1, $ohneHost['fabrics'] ?? null, 'Annonce ohne Host bleibt als eigenes Gerät mit einem System');

// Ohne bekannte Fabric: alles fremd, Namen sind Hostnamen
$ohneFabric = DeviceInventory::build($operational, $borderRouters, $known, []);
assertSame(0, count(array_filter($ohneFabric, static fn(array $r): bool => $r['symcon'])), 'Ohne eigene Fabric ist kein Gerät als Symcon markiert');
