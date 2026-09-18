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
    ['name' => 'Wohnzimmer', 'source' => '192.168.178.63', 'txt' => ['vn' => 'Apple Inc.']],
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
// Build 44 (Burkhard): dieselbe Beschriftung wie im Befund „Thread Border Router gefunden" —
// ein Gerätename wie „Wohnzimmer" sagt allein nicht, welches Gerät im Haus gemeint ist.
assertSame('Wohnzimmer (Apple Inc.)', $klippbok['via'] ?? null, 'KLIPPBOK: annonciert über den Apple TV, beschriftet wie im Befund (Name mit Hersteller)');

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

// Build 40: eine Spalte je System. Die Zeile kennt ihre Fabric-IDs, fabricColumns()
// ordnet die Spalten — Symcon zuerst, dann fremde Systeme nach Zahl ihrer Geräte.
assertSame(['35FA3C0EA8A2346D', '39E99BD14DFBBCD1', 'A5AC1650B5C2EE16', 'B0E451B717784CDF'], $klippbok['fabricIds'] ?? null, 'KLIPPBOK: vier Fabric-IDs, sortiert und in Großschreibung');
assertSame(['35FA3C0EA8A2346D'], $ohneHost['fabricIds'] ?? null, 'Annonce ohne Host: eine Fabric-ID');

$columns = DeviceInventory::fabricColumns($rows, ['A5AC1650B5C2EE16']);
assertSame(['A5AC1650B5C2EE16', '35FA3C0EA8A2346D', 'B0E451B717784CDF', '39E99BD14DFBBCD1'], array_column($columns, 'id'), 'Spalten: Symcon zuerst, dann fremde Systeme nach Gerätezahl (4, 3, 1)');
assertSame(['Symcon', 'A', 'B', 'C'], array_column($columns, 'label'), 'Spaltenbeschriftung: Symcon, dann A, B, C');
assertSame([true, false, false, false], array_column($columns, 'own'), 'Nur die erste Spalte ist die eigene');
assertSame([3, 4, 3, 1], array_column($columns, 'count'), 'Gerätezahl je System');

$ohneEigene = DeviceInventory::fabricColumns($rows, ['DEADBEEF00000000']);
assertSame('DEADBEEF00000000', $ohneEigene[0]['id'] ?? null, 'Die eigene Fabric bekommt auch ohne annonciertes Gerät eine Spalte');
assertSame(0, $ohneEigene[0]['count'] ?? null, '… mit null Geräten');
assertSame(5, count($ohneEigene), 'Vier fremde Systeme dahinter');

$zweiEigene = DeviceInventory::fabricColumns($rows, ['A5AC1650B5C2EE16', 'B0E451B717784CDF']);
assertSame(['Symcon 1', 'Symcon 2', 'A', 'B'], array_column($zweiEigene, 'label'), 'Zwei eigene Controller: Symcon 1 und 2, fremde ab A');
assertSame([], DeviceInventory::fabricColumns([], []), 'Ohne Geräte und ohne eigene Fabric keine Spalten');

// Build 41: Benennung der Systeme durch den Anwender — benannte tragen den Namen, die
// übrigen weiter Buchstaben (ohne Lücke), die Reihenfolge bleibt die nach Gerätezahl.
$benannt = DeviceInventory::fabricColumns($rows, ['A5AC1650B5C2EE16'], ['35FA3C0EA8A2346D' => 'Apple Home', 'b0e451b717784cdf' => ' Home Assistant ', '39E99BD14DFBBCD1' => '']);
// Build 42 (Burkhard): Die Buchstaben bleiben fest — ein benanntes System behält seinen
// Buchstaben, sonst rückt „C" nach dem Benennen von A und B zu „A" auf und nichts passt mehr.
assertSame(['Symcon', 'Apple Home', 'Home Assistant', 'C'], array_column($benannt, 'label'), 'Benannte Systeme heißen wie eingetragen (Kennung in beliebiger Schreibweise, Name getrimmt), leere Namen zählen nicht, Buchstaben rücken nicht auf');
assertSame(['', 'A', 'B', 'C'], array_column($benannt, 'letter'), 'Jedes fremde System behält seinen Buchstaben, benannt oder nicht');
assertSame([false, true, true, false], array_column($benannt, 'named'), 'named markiert die benannten Spalten (Symcon nicht)');

// Build 41: Hersteller und Modell — aus Symcon (eigene Geräte), aus anderen Diensten
// desselben Geräts (Shelly, Hue …) oder aus der MAC-Adresse im Hostnamen.
$identities = [
    ['service' => '_shelly._tcp.local', 'instance' => 'shellydimmerg4-e8f60a7c9714._shelly._tcp.local', 'host' => 'ShellyDimmerG4-E8F60A7C9714.local', 'addresses' => ['192.168.178.67'], 'vendor' => 'Shelly', 'model' => 'DimmerG4 Gen 4'],
];
$knownMitHersteller = $known;
$knownMitHersteller[0] += ['vendor' => 'IKEA of Sweden', 'product' => 'KLIPPBOK water leak sensor'];
$mitIdentitaet = [];
foreach (DeviceInventory::build($operational, $borderRouters, $knownMitHersteller, ['A5AC1650B5C2EE16'], $identities) as $row) {
    $mitIdentitaet[$row['name']] = $row;
}
assertSame('IKEA of Sweden', $mitIdentitaet['KLIPPBOK water leak sensor']['vendor'] ?? null, 'Eigenes Gerät: Hersteller aus Symcon');
assertSame('', $mitIdentitaet['KLIPPBOK water leak sensor']['model'] ?? null, 'Eigenes Gerät: Produktname gleich Name → kein doppeltes Modell');
assertSame('Shelly', $mitIdentitaet['Shelly Dimmer Gen4']['vendor'] ?? null, 'Eigenes Gerät ohne Symcon-Hersteller: aus dem Shelly-Dienst');
assertSame('DimmerG4 Gen 4', $mitIdentitaet['Shelly Dimmer Gen4']['model'] ?? null, 'Modell aus dem Shelly-Dienst');
assertSame('', $mitIdentitaet['B2EDD5A10FF0C48C']['vendor'] ?? null, 'Fremdes Thread-Gerät: kein Hersteller');
assertSame('', $mitIdentitaet['GRILLPLATS Plug']['vendor'] ?? null, 'Ohne Angabe in Symcon und ohne Dienst: leer');
$ohneIdentitaet = DeviceInventory::build($operational, $borderRouters, $known, ['A5AC1650B5C2EE16']);
assertSame('Espressif', $ohneIdentitaet[2]['vendor'] ?? null, 'Ohne Dienste bleibt der Hersteller aus der MAC (Shelly = Espressif-Chip)');

// --- Build 45: Symcons Urteil über Batterie/Netz schlägt die Annonce -------------------
$wallSwitchOperational = [
    ['instance' => 'A5AC1650B5C2EE16-0000000000000006._matter._tcp.local', 'host' => '1A6346D0166841C0.local', 'addresses' => ['2a02:6d40:3025:a9ff:70f:f5d1:c300:b859'], 'source' => '192.168.10.56', 'sleepy' => true],
];
$wallSwitchRows = DeviceInventory::build($wallSwitchOperational, [], [['nodeId' => 6, 'name' => 'Aqara Smart Wall Switch', 'sleepy' => false]], ['A5AC1650B5C2EE16']);
assertSame(DeviceInventory::POWER_MAINS, $wallSwitchRows[0]['power'] ?? null, 'Symcon sagt „kein ICD": Netz, obwohl die Annonce SII/SAI trägt');
$ohneUrteil = DeviceInventory::build($wallSwitchOperational, [], [['nodeId' => 6, 'name' => 'Aqara Smart Wall Switch', 'sleepy' => null]], ['A5AC1650B5C2EE16']);
assertSame(DeviceInventory::POWER_BATTERY, $ohneUrteil[0]['power'] ?? null, 'Ohne Urteil von Symcon entscheidet die Annonce');

// --- Build 48: gebrückte Geräte (Rainers Aqara Hub M3, Forum t/144417/16) --------------
// Der Hub meldet sein über ZigBee angelerntes Gerät unter eigenem Namen, aber mit seiner
// eigenen Adresse. In der Liste stand es als anonyme Nummer ohne Hersteller neben dem Hub.
$hubAdresse    = 'fd1a:29ab:6df7:0:1ac2:3cff:fe7a:c254';
$bruecke       = [
    ['instance' => 'A5AC1650B5C2EE16-0000000000000007._matter._tcp.local', 'host' => '18C23C7AC254.local', 'addresses' => [$hubAdresse, '192.168.10.56'], 'source' => '192.168.10.56', 'sleepy' => false],
    ['instance' => 'CBC9CF8A0E945838-54EF4A5F00700000._matter._tcp.local', 'host' => '54EF4A5F0070000.local', 'addresses' => [$hubAdresse], 'source' => '192.168.10.56', 'sleepy' => null],
];
$bruecken = [];
foreach (DeviceInventory::build($bruecke, [], [['nodeId' => 7, 'name' => 'Aqara Hub M3', 'sleepy' => false, 'vendor' => 'Aqara', 'product' => 'Hub M3']], ['A5AC1650B5C2EE16']) as $row) {
    $bruecken[$row['name']] = $row;
}
assertSame(2, count($bruecken), 'Hub und gebrücktes Gerät bleiben zwei Zeilen — es sind zwei Matter-Knoten');
assertSame('Aqara Hub M3', $bruecken['54EF4A5F0070000']['bridgedBy'] ?? null, 'Gebrücktes Gerät nennt seinen Träger');
assertSame('Aqara', $bruecken['54EF4A5F0070000']['vendor'] ?? null, 'Hersteller vom Träger übernommen');
assertSame('', $bruecken['Aqara Hub M3']['bridgedBy'] ?? null, 'Der Träger selbst ist nicht gebrückt');

// Ohne MAC im Hostnamen entscheidet, wer von beiden in Symcon gekoppelt ist
$bruecke[0]['host'] = 'F1E2D3C4B5A69788.local';
$ohneMac = [];
foreach (DeviceInventory::build($bruecke, [], [['nodeId' => 7, 'name' => 'Aqara Hub M3', 'sleepy' => false]], ['A5AC1650B5C2EE16']) as $row) {
    $ohneMac[$row['name']] = $row;
}
assertSame('Aqara Hub M3', $ohneMac['54EF4A5F0070000']['bridgedBy'] ?? null, 'Rückfall: genau ein Gerät der Gruppe ist in Symcon gekoppelt');

// Keine Richtung ohne Beleg: sind beide gekoppelt, bleibt die Gruppe unberührt
$beideGekoppelt    = $bruecke;
$beideGekoppelt[1]['instance'] = 'A5AC1650B5C2EE16-0000000000000008._matter._tcp.local';
$beideBekannt      = [];
foreach (DeviceInventory::build($beideGekoppelt, [], [['nodeId' => 7, 'name' => 'Aqara Hub M3', 'sleepy' => false], ['nodeId' => 8, 'name' => 'Aqara FP300', 'sleepy' => null]], ['A5AC1650B5C2EE16']) as $row) {
    $beideBekannt[$row['name']] = $row;
}
assertSame('', $beideBekannt['Aqara Hub M3']['bridgedBy'] ?? null, 'Zwei gekoppelte Geräte an einer Adresse: keine Richtung behaupten');
assertSame('', $beideBekannt['Aqara FP300']['bridgedBy'] ?? null, 'Auch das zweite bleibt ohne Träger');

// Getrennte Geräte bleiben getrennt — die Regel greift nur bei geteilter Adresse
foreach (DeviceInventory::build($operational, $borderRouters, $known, ['A5AC1650B5C2EE16']) as $row) {
    assertSame('', $row['bridgedBy'], 'nuc-Lauf: kein Gerät teilt seine Adresse mit einem anderen');
}

// MAC aus der EUI-64 einer IPv6-Adresse (Beleg für die Trägerbestimmung)
assertSame('18C23C7AC254', DeviceIdentity::macFromAddress($hubAdresse), 'MAC aus EUI-64: FF:FE heraus, Bit 1 zurück');
assertSame('E8F60A7C9714', DeviceIdentity::macFromAddress('fd86:6fd:53ed:0:eaf6:aff:fe7c:9714'), 'Shelly-Adresse: MAC wie im Hostnamen');
assertSame(null, DeviceIdentity::macFromAddress('fd89:6b7:bc55:0:6efe:107f:c87e:36e2'), 'Thread-Kennung trägt keine MAC');
assertSame(null, DeviceIdentity::macFromAddress('192.168.178.63'), 'IPv4 trägt keine MAC');

// --- Build 49: eine Schreibweise je System (Burkhard, 18.09.2026) ----------------------
// Spaltenkopf und Legende schrieben dasselbe System unterschiedlich, und in der
// Auswahlliste der Benennung stand der Name direkt neben dem Namensfeld noch einmal.
$benanntesSystem = ['id' => '35FA3C0EA8A2346D', 'label' => 'Apple Home', 'letter' => 'A', 'own' => false, 'named' => true, 'count' => 9];
$namenloses      = ['id' => '71C2EE4C5CD7A3B4', 'label' => 'E', 'letter' => 'E', 'own' => false, 'named' => false, 'count' => 2];
$eigenes         = ['id' => 'A5AC1650B5C2EE16', 'label' => 'Symcon', 'letter' => '', 'own' => true, 'named' => false, 'count' => 5];

assertSame('Apple Home (A)', DeviceInventory::columnTitle($benanntesSystem), 'Benanntes System: Name mit Buchstabe — in Spalte wie Legende');
assertSame('E', DeviceInventory::columnTitle($namenloses), 'Ohne Namen bleibt es der Buchstabe allein');
assertSame('Symcon', DeviceInventory::columnTitle($eigenes), 'Die eigene Installation hat keinen Buchstaben');

assertSame('A: 35FA3C0EA8A2346D (9)', DeviceInventory::columnChoice($benanntesSystem), 'Auswahlliste ohne Namen — der steht in der Spalte daneben');
assertSame('E: 71C2EE4C5CD7A3B4 (2)', DeviceInventory::columnChoice($namenloses), 'Auswahlliste unbenannt: Buchstabe, Kennung, Zahl');

// Die Spalten aus fabricColumns müssen zu beiden Funktionen passen
$titelSpalten = DeviceInventory::fabricColumns($rows, ['A5AC1650B5C2EE16'], ['35FA3C0EA8A2346D' => 'Apple Home']);
$titel = array_map(static fn(array $c): string => DeviceInventory::columnTitle($c), $titelSpalten);
assertSame('Symcon', $titel[0] ?? null, 'Erste Spalte ist die eigene Installation');
assertSame(true, in_array('Apple Home (A)', $titel, true), 'Das benannte System trägt überall Name und Buchstabe');

// --- Build 51: Klarnamen aus dem Reverse-Eintrag des Routers ---------------------------
// Der eigene Echo Dot stand als „3D59C51D251F" in der Liste, die FRITZ!Box kennt ihn als
// „EchoDot-Kueche.fritz.box" (18.09.2026).
assertSame('EchoDot-Kueche', DeviceInventory::reverseLabel('EchoDot-Kueche.fritz.box', '3D59C51D251F.local'), 'Domäne fällt weg');
assertSame('EchoDot-Kueche', DeviceInventory::reverseLabel('EchoDot-Kueche', '3D59C51D251F.local'), 'Auch ohne Domäne');
assertSame(null, DeviceInventory::reverseLabel('E4B063E529D0.fritz.box', 'E4B063E529D0.local'), 'Nur die Kennung wiederholt: kein Gewinn');
assertSame(null, DeviceInventory::reverseLabel('192.168.178.69', '3D59C51D251F.local'), 'Die Adresse selbst ist kein Name');
assertSame(null, DeviceInventory::reverseLabel('', '3D59C51D251F.local'), 'Leerer Eintrag');

$echoZeilen = [
    ['host' => '3D59C51D251F.local', 'name' => '3D59C51D251F', 'nodeId' => null, 'addresses' => ['192.168.178.69', 'fd86:6fd:53ed:0:de54:d7ff:fe14:dd72']],
    ['host' => 'E8F60A7C9714.local', 'name' => 'Shelly Dimmer Gen4', 'nodeId' => 10, 'addresses' => ['192.168.178.67']],
];
$benannt = DeviceInventory::applyReverseNames($echoZeilen, ['192.168.178.69' => 'EchoDot-Kueche.fritz.box', '192.168.178.67' => 'shelly-dimmer.fritz.box']);
assertSame('EchoDot-Kueche', $benannt[0]['name'], 'Fremdes Gerät bekommt den Klarnamen');
assertSame('Shelly Dimmer Gen4', $benannt[1]['name'], 'Ein Gerät aus Symcon behält seinen Namen');
assertSame('3D59C51D251F', DeviceInventory::applyReverseNames($echoZeilen, [])[0]['name'], 'Ohne Eintrag bleibt die Kennung stehen');
