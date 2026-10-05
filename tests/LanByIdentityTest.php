<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MatterDiscovery.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceInventory.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceIdentity.php';

/**
 * Ein WLAN-Gerät, dessen Matter-Ansage nur IPv6 trägt, ist kein Thread-Gerät (build 82).
 *
 * Loerdys Shelly Plug S Gen3 (Id 5, `8CBFEA966280`) kam über einen Spiegel
 * (`fdb2:3abb:80f6:1::`) nur mit seiner IPv6 aus dem IoT-Segment an. Ohne IPv4 galt er als
 * Thread-Gerät, und sein Segment `fdb2:3abb:80f6:2::` stand wieder als „Thread-Funknetz“
 * im Bericht — derselbe Fehler, den build 37 für Ansagen mit IPv4 behoben hatte. Sein
 * `_shelly`-Dienst nennt im selben Lauf die IPv4 zur selben IPv6.
 *
 * Die Einträge stammen wörtlich aus seinem Debug-Auszug mit 0.9 #81 (matter_dump.txt,
 * 05.10.2026 20:14, Abschnitte „Eigene Adressen“, „Border Router“, „_matter._tcp (73)“ und
 * „Identitäten“; PM t/144583/5).
 */

$ownAddresses  = ['fd9f:c09c:867a:4ba7:e65f:1ff:fed4:fda4', 'fdb2:3abb:80f6:1:e65f:1ff:fed4:fda4', 'fe80::e65f:1ff:fed4:fda4', '192.168.29.5'];
$borderRouters = [
    ['name' => 'Loerdy-TV', 'source' => '192.168.29.181', 'txt' => ['vn' => 'Apple']],
];
$shellyAnsage = ['instance' => '3628602A9BDB6A74-0000000000000005._matter._tcp.local', 'host' => '8CBFEA966280.local', 'port' => 5540, 'addresses' => ['fdb2:3abb:80f6:2:8ebf:eaff:fe96:6280'], 'source' => 'fdb2:3abb:80f6:1::', 'sleepy' => false];
$threadAnsage = ['instance' => '3628602A9BDB6A74-000000000000001A._matter._tcp.local', 'host' => 'B6C381539EE5DA1C.local', 'port' => 5540, 'addresses' => ['fd03:8db1:5779:1:d40:bd7f:d4e5:989f'], 'source' => '192.168.29.181', 'sleepy' => null];
$operational  = [$shellyAnsage, $threadAnsage];
$identities   = [
    ['service' => '_shelly._tcp.local', 'instance' => 'ShellyPlugSG3-8CBFEA966280', 'host' => 'ShellyPlugSG3-8CBFEA966280.local', 'addresses' => ['192.168.30.29', 'fdb2:3abb:80f6:2:8ebf:eaff:fe96:6280'], 'vendor' => 'Shelly', 'model' => 'PlugSG3 Gen 3'],
];

// --- Thread-Kandidaten und Thread-Präfixe ---
$kandidaten = MatterDiscovery::threadCandidateAddresses($operational, $identities);
assertTrue(!in_array('fdb2:3abb:80f6:2:8ebf:eaff:fe96:6280', $kandidaten, true), 'Shelly: IPv4 aus dem Identitätsdienst — kein Thread-Kandidat');
assertTrue(in_array('fd03:8db1:5779:1:d40:bd7f:d4e5:989f', $kandidaten, true), 'Thread-Gerät hinter dem Apple TV bleibt Kandidat');

$praefixe = DiagnosisEngine::threadPrefixes($kandidaten, $ownAddresses, MatterDiscovery::proxiedPrefixes($operational, $borderRouters, $identities));
assertTrue(!isset($praefixe['fdb2:3abb:80f6:2::']), 'Das IoT-Segment ist kein Thread-Netz');
assertTrue(isset($praefixe['fd03:8db1:5779:1::']), 'Das Thread-Netz bleibt erkannt');

// Ohne Identität bleibt es beim bisherigen Urteil — ohne Beleg kein Umschwung
$ohneIdentitaet = MatterDiscovery::threadCandidateAddresses($operational);
assertTrue(in_array('fdb2:3abb:80f6:2:8ebf:eaff:fe96:6280', $ohneIdentitaet, true), 'Ohne Identitätsdienst: kein Beleg, Kandidat wie bisher');

// --- Geräteliste ---
$known  = [['nodeId' => 5, 'name' => 'Shelly Plug S Gen3', 'sleepy' => false], ['nodeId' => 26, 'name' => 'Smart window handle', 'sleepy' => false]];
$rows   = DeviceInventory::build($operational, $borderRouters, $known, ['3628602A9BDB6A74'], $identities);
$byHost = [];
foreach ($rows as $row) {
    $byHost[strtolower($row['host'])] = $row;
}
assertSame(DeviceInventory::LINK_LAN, $byHost['8cbfea966280.local']['link'] ?? null, 'Shelly Id 5 hängt im WLAN, nicht im Thread-Netz');
assertSame(DeviceInventory::LINK_THREAD, $byHost['b6c381539ee5da1c.local']['link'] ?? null, 'Fenstergriff bleibt Thread');

// Eine Ansage mit IPv4 zur selben IPv6 belegt es ebenso (zweite Fabric desselben Shellys)
$zweiteAnsage = ['instance' => '882E969DEEF0B04E-00000000B007ADE4._matter._tcp.local', 'host' => '8CBFEA966280.local', 'port' => 5540, 'addresses' => ['192.168.30.29', 'fdb2:3abb:80f6:2:8ebf:eaff:fe96:6280'], 'source' => '192.168.29.1', 'sleepy' => false];
$kandidaten   = MatterDiscovery::threadCandidateAddresses([$shellyAnsage, $zweiteAnsage, $threadAnsage]);
assertTrue(!in_array('fdb2:3abb:80f6:2:8ebf:eaff:fe96:6280', $kandidaten, true), 'Andere Ansage mit IPv4 zur selben Adresse: kein Thread-Kandidat');
