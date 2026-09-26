<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MdnsResponses.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ThreadNetwork.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MatterDiscovery.php';
require_once __DIR__ . '/../MatterDiagnose/libs/SymconInventory.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceInventory.php';

/**
 * Controller sind keine Geräte, und ein Knoten ist Host plus Port (build 70).
 *
 * Anlass (Burkhard, 26.09.2026): Symcon zeigt für die DIRIGERA „Verbundene Systeme (4 von
 * 10)", das Modul meldete fünf und damit `device_fabrics_full`. Die fünfte Ansage
 * (`39E99BD14DFBBCD1-63E2D34A47E6F9E2`) kommt zwar von der DIRIGERA selbst, aber auf
 * Port 5541 statt 5540 und mit eigenem TXT — ein zweiter Matter-Knoten auf demselben Host:
 * ihr Controller in der IKEA-Fabric, in der auch die IKEA-Thread-Sensoren stehen. Das Modul
 * fasste nach Host zusammen und zählte ihn als Platz der Bridge.
 *
 * Zweitens stand `haos-pi4` als Matter-Gerät in der Liste: `B0E451B717784CDF-000000000001B669`
 * ist der Matter-Server von Home Assistant in seiner eigenen Fabric, Node-ID 0x1B669 = 112233,
 * die Standard-Controller-ID des Matter-SDK (bei Alexandro ebenso, siehe DeviceLinkTest).
 *
 * Gegenprobe im selben Mitschnitt: Der Echo Dot (`D87EBFBB213D`) läuft als einziger Knoten
 * seines Hosts auf 5541 — ein Gerät. Der Port allein sagt nichts.
 *
 * Fixture: tests/fixtures/mdns/dualstack, nuc, 25.09.2026 (erste Runde, beide Familien).
 */

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

$dirigeraController = '39e99bd14dfbbcd1-63e2d34a47e6f9e2._matter._tcp.local';
$haController       = 'b0e451b717784cdf-000000000001b669._matter._tcp.local';
$ownFabric          = 'A5AC1650B5C2EE16';

// Werte der Kennzeichnung — als Literal, damit der Test auch gegen den Stand ohne Fix zählt
$SECOND_NODE = 'second_node';
$SDK_DEFAULT = 'sdk_default';
assertSame($SECOND_NODE, defined('MatterDiscovery::CONTROLLER_SECOND_NODE') ? MatterDiscovery::CONTROLLER_SECOND_NODE : null, 'Konstante CONTROLLER_SECOND_NODE');
assertSame($SDK_DEFAULT, defined('MatterDiscovery::CONTROLLER_SDK_DEFAULT') ? MatterDiscovery::CONTROLLER_SDK_DEFAULT : null, 'Konstante CONTROLLER_SDK_DEFAULT');

// --- Erkennung in der Erhebung ----------------------------------------------------
$rollen = [];
foreach ($survey['operationalDevices'] as $device) {
    $rollen[strtolower($device['instance'])] = array_key_exists('controller', $device) ? $device['controller'] : '(kein Feld)';
}
assertSame(32, count($rollen), 'nuc: 32 Ansagen im Mitschnitt');
assertSame($SECOND_NODE, $rollen[$dirigeraController], 'DIRIGERA Port 5541: Controller-Knoten neben der Bridge');
assertSame($SDK_DEFAULT, $rollen[$haController], 'haos-pi4, Node 112233: Controller von Home Assistant');
assertSame(null, $rollen['3475b3b4260655e1-05c32246ce589631._matter._tcp.local'], 'Echo Dot allein auf 5541: ein Gerät');
assertSame(null, $rollen['a5ac1650b5c2ee16-000000000000000c._matter._tcp.local'], 'DIRIGERA-Bridge in der eigenen Fabric: ein Gerät');
assertSame(2, count(array_filter($rollen, static fn(?string $rolle): bool => $rolle !== null && $rolle !== '(kein Feld)')), 'Genau zwei Controller-Ansagen');

// Ohne den Border Router fehlt der Beleg für den zweiten Knoten — dann bleibt er ein Gerät
$ohneRouter = $survey;
$ohneRouter['borderRouters'] = [];
$ohneRouter['operationalDevices'] = method_exists(MatterDiscovery::class, 'markControllers') ? MatterDiscovery::markControllers($survey['operationalDevices'], []) : $survey['operationalDevices'];
$ohne = [];
foreach ($ohneRouter['operationalDevices'] as $device) {
    $ohne[strtolower($device['instance'])] = array_key_exists('controller', $device) ? $device['controller'] : '(kein Feld)';
}
assertSame(null, $ohne[$dirigeraController], 'Ohne Border-Router-Beleg: kein Urteil über den zweiten Knoten');
assertSame($SDK_DEFAULT, $ohne[$haController], 'Node 112233 braucht keinen Router');

// --- Fabric-Tabelle der eigenen DIRIGERA -------------------------------------------
$known = [
    ['nodeId' => 6, 'name' => 'MYGGBETT door/window sensor', 'sleepy' => true],
    ['nodeId' => 9, 'name' => 'KLIPPBOK water leak sensor', 'sleepy' => true],
    ['nodeId' => 11, 'name' => 'GRILLPLATS Plug', 'sleepy' => false],
    ['nodeId' => 12, 'name' => 'DIRIGERA', 'sleepy' => null],
];
$usage = SymconInventory::fabricUsage($known, $survey['operationalDevices'], $ownFabric);
assertSame(4, $usage[12] ?? null, 'DIRIGERA: 4 Systeme wie in Symcon („4 von 10"), nicht 5');

// --- Geräteliste ---------------------------------------------------------------------
$rows      = DeviceInventory::build($survey['operationalDevices'], $survey['borderRouters'], $known, [$ownFabric]);
$bridge    = null;
$kontrolle = [];
foreach ($rows as $row) {
    if (($row['nodeId'] ?? null) === 12) {
        $bridge = $row;
    }
    if (($row['controller'] ?? null) !== null) {
        $kontrolle[strtolower($row['host'])] = $row;
    }
}
assertSame(4, $bridge['fabrics'] ?? null, 'Geräteliste: DIRIGERA in 4 Systemen');
assertTrue(!in_array('39E99BD14DFBBCD1', $bridge['fabricIds'] ?? ['39E99BD14DFBBCD1'], true), 'Die IKEA-Fabric gehört nicht zur Bridge');
assertSame(['68ec8a0be88a.local', 'dca632c250890000.local'], array_keys($kontrolle), 'Beide Controller stehen als solche in der Liste');
assertSame($SECOND_NODE, $kontrolle['68ec8a0be88a.local']['controller'] ?? null, 'DIRIGERA-Controller gekennzeichnet');
assertSame('', $kontrolle['68ec8a0be88a.local']['bridgedBy'] ?? null, 'Der Controller ist kein gebrücktes Gerät der DIRIGERA');
assertSame($SDK_DEFAULT, $kontrolle['dca632c250890000.local']['controller'] ?? null, 'HA-Controller gekennzeichnet');

// Spalten zählen Geräte — Controller zählen nicht mit
$spalten = [];
foreach (DeviceInventory::fabricColumns($rows, [$ownFabric]) as $spalte) {
    $spalten[$spalte['id']] = $spalte['count'];
}
assertSame(4, $spalten['39E99BD14DFBBCD1'] ?? null, 'IKEA-Fabric: die vier Sensoren, nicht der Controller');
assertSame(7, $spalten['B0E451B717784CDF'] ?? null, 'Home-Assistant-Fabric: sieben Geräte ohne den Matter-Server');

// --- Befund „Matter-Geräte melden sich" --------------------------------------------
$engineInput = [
    'ipv6Addresses'         => ['fd86:6fd:53ed::1', 'fe80::1'],
    'mdnsResponses'         => true,
    'mdnsProbeResponders'   => 0,
    'borderRouters'         => $survey['borderRouters'],
    'operationalDevices'    => $survey['operationalDevices'],
    'commissionableDevices' => [],
    'threadPrefixes'        => [],
    'platform'              => 'Windows',
    'controllerPresent'     => true,
    'ownFabricId'           => $ownFabric,
    'knownDevices'          => [],
    'devicesAmbiguous'      => false,
    'threadNetworks'        => null,
    'routeAssessment'       => null,
];
$gezaehlt = null;
foreach (DiagnosisEngine::evaluate($engineInput) as $finding) {
    if ($finding['id'] === 'operational_found') {
        $gezaehlt = $finding['params'];
    }
}
assertSame('26', $gezaehlt['count'] ?? '(fehlt)', 'Geräte ohne den Matter-Server von Home Assistant');
assertSame('29', $gezaehlt['announcements'] ?? '(fehlt)', 'Ansagen ohne die beiden Controller und den SymBox-Datensatz');
assertSame('6', $gezaehlt['systems'] ?? '(fehlt)', 'Systeme unverändert — beide Fabrics haben echte Geräte');
