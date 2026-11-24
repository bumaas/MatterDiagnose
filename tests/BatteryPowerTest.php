<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MdnsResponses.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ThreadNetwork.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MatterDiscovery.php';
require_once __DIR__ . '/../MatterDiagnose/libs/SymconInventory.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceInventory.php';

/**
 * Spalte „Betrieb“ der Geräteliste: Batterie, sobald Symcon Batteriewerte führt.
 *
 * Anlass Forum t/144417/78 (bgersmann, 24.11.2026): Ein Aqara-Türschloss U200 Lite stand als
 * „Netz“ in der Liste, obwohl Symcon am Gerät Batteriestand 100 % zeigt. Drei Ursachen:
 *
 * 1. Das Gesamtgerät (Matter Composite Device) stellt jedem Ident die Endpunktnummer voran,
 *    `E2_PowerSource_Bat…` beim Schloss, `E5_…` beim FP300 am nuc. Die Erkennung verlangte
 *    `PowerSource_Bat` am Anfang und fand bei Gesamtgeräten nie etwas.
 * 2. Hat ein Gesamtgerät zusätzlich Endpunkt-Instanzen (BILRESA am nuc: die beiden Tasten),
 *    wurden nur diese gelesen, die Batteriewerte am Gesamtgerät selbst nicht.
 * 3. Das Schloss schläft nicht (es muss sofort auf Befehle reagieren). Die Spalte folgte allein
 *    dem Schlafzustand und zeigte deshalb „Netz“.
 *
 * Eine Bridge (DIRIGERA) führt die Batteriewerte ihrer gebrückten Geräte, hängt selbst aber im
 * LAN. Für sie bleibt es bei „Netz“.
 *
 * Fixtures: tests/fixtures/symcon/battery_sources_nuc.json (Konfigurator und Idents vom nuc,
 * 24.11.2026), tests/fixtures/mdns/dualstack (nuc, 25.09.2026).
 */

$quelle = json_decode((string)file_get_contents(__DIR__ . '/fixtures/symcon/battery_sources_nuc.json'), true);
$identsJeInstanz = $quelle['idents'];

// --- 1. Idents mit Endpunkt-Präfix ---------------------------------------------------
assertSame(true, SymconInventory::batteryFromVariables($identsJeInstanz['40734']), 'FP300 (Gesamtgerät, E5_PowerSource_Bat…): Batterie erkannt');
assertSame(true, SymconInventory::batteryFromVariables($identsJeInstanz['46298']), 'BILRESA (Gesamtgerät, E0_PowerSource_Bat…): Batterie erkannt');
assertSame(false, SymconInventory::batteryFromVariables($identsJeInstanz['29743']), 'BILRESA Taste 1 (Endpunkt-Instanz): keine Batteriewerte');
// Testbox, Einzelgeräte ohne Präfix (Mitschnitt 24.11.2026, Stromquelle des FP300 #16670)
assertSame(true, SymconInventory::batteryFromVariables(['PowerSource_BatChargeLevel', 'PowerSource_BatPercentRemaining']), 'Endpunkt-Instanz ohne Präfix: weiter erkannt');
assertSame(false, SymconInventory::batteryFromVariables(['E1_Switch_LastEvent', 'E12_OnOff_Status']), 'Präfix ohne PowerSource: keine Batterie');
assertSame(false, SymconInventory::batteryFromVariables(['XE0_PowerSource_BatChargeLevel']), 'Nur das Endpunkt-Präfix zählt, kein beliebiger Vorsatz');

// --- 2. Welche Instanzen gehören zum Gerät -------------------------------------------
$geraete = [];
foreach (SymconInventory::devicesFromConfiguratorForm($quelle['form']) as $device) {
    $geraete[$device['nodeId']] = $device;
}
$instanzen = static function (array $device): mixed {
    return method_exists(SymconInventory::class, 'batteryInstances')
        ? SymconInventory::batteryInstances($device)
        : '(batteryInstances fehlt)';
};
assertSame([46298, 29743, 33088], $instanzen($geraete[29] ?? []), 'BILRESA: Gesamtgerät und beide Tasten');
assertSame([40734], $instanzen($geraete[15] ?? []), 'FP300: nur das Gesamtgerät (Endpunktzeilen ohne Instanz)');
assertSame([], $instanzen($geraete[12] ?? []), 'DIRIGERA am nuc: keine Instanz, nichts zu lesen');
assertSame([31001, 31002], $instanzen(['instanceId' => 0, 'endpointInstances' => [31001, 31002, 31001]]), 'Testbox-Form (Einzelgeräte): Endpunkte ohne Doppel');

$batterieJeGeraet = [];
foreach ([29, 15] as $node) {
    $idents = [];
    foreach ((array)$instanzen($geraete[$node] ?? []) as $instanceId) {
        array_push($idents, ...($identsJeInstanz[(string)$instanceId] ?? []));
    }
    $batterieJeGeraet[$node] = SymconInventory::batteryFromVariables($idents);
}
assertSame([29 => true, 15 => true], $batterieJeGeraet, 'BILRESA und FP300 gelten als Batteriegeräte');

// --- 3. Spalte „Betrieb“ -----------------------------------------------------------
$dir      = __DIR__ . '/fixtures/mdns/dualstack';
$manifest = json_decode((string)file_get_contents($dir . '/manifest.json'), true);
$pakete   = [];
foreach ($manifest as $m) {
    if ($m['anlage'] !== 'nuc') {
        continue;
    }
    $from     = $m['family'] === 'v6' ? '[' . $m['from'] . ']:' . $m['port'] : $m['from'] . ':' . $m['port'];
    $pakete[] = ['from' => $from, 'message' => MdnsCodec::decodeMessage((string)file_get_contents($dir . '/' . $m['file']))];
}
$fragen = [
    ['name' => '_meshcop._udp.local', 'type' => MdnsCodec::TYPE_PTR],
    ['name' => '_matter._tcp.local', 'type' => MdnsCodec::TYPE_PTR],
    ['name' => '_matterc._udp.local', 'type' => MdnsCodec::TYPE_PTR],
];
$survey = MatterDiscovery::collect(MdnsResponses::relevant(MdnsResponses::preferIpv4($pakete), $fragen), ['192.168.178.86']);

// Knoten 11 ist im Mitschnitt ein Thread-Gerät, das nicht schläft (GRILLPLATS). Mit
// Batteriewerten steht es für das Schloss: wach, Thread, Symcon führt den Batteriestand.
$known = [
    ['nodeId' => 9, 'name' => 'KLIPPBOK water leak sensor', 'sleepy' => true, 'batteryVariables' => true],
    ['nodeId' => 11, 'name' => 'Smart lock U200 Lite', 'sleepy' => false, 'batteryVariables' => true],
    ['nodeId' => 12, 'name' => 'DIRIGERA', 'sleepy' => false, 'batteryVariables' => true],
];
$betrieb = [];
foreach (DeviceInventory::build($survey['operationalDevices'], $survey['borderRouters'], $known, ['A5AC1650B5C2EE16']) as $row) {
    if ($row['nodeId'] !== null) {
        $betrieb[$row['name']] = [$row['link'], $row['power']];
    }
}
assertSame([DeviceInventory::LINK_THREAD, DeviceInventory::POWER_BATTERY], $betrieb['Smart lock U200 Lite'] ?? null, 'Wach, Thread, mit Batteriewerten: Batterie');
assertSame([DeviceInventory::LINK_LAN, DeviceInventory::POWER_MAINS], $betrieb['DIRIGERA'] ?? null, 'Bridge im LAN mit Batteriewerten gebrückter Geräte: Netz');
assertSame([DeviceInventory::LINK_THREAD, DeviceInventory::POWER_BATTERY], $betrieb['KLIPPBOK water leak sensor'] ?? null, 'Schlafendes Batteriegerät: unverändert Batterie');

// Gegenprobe: ohne Batteriewerte bleibt das wache Thread-Gerät am Netz
$known[1]['batteryVariables'] = false;
$ohne = [];
foreach (DeviceInventory::build($survey['operationalDevices'], $survey['borderRouters'], $known, ['A5AC1650B5C2EE16']) as $row) {
    $ohne[$row['name']] = $row['power'];
}
assertSame(DeviceInventory::POWER_MAINS, $ohne['Smart lock U200 Lite'] ?? null, 'Ohne Batteriewerte: Netz wie bisher');
