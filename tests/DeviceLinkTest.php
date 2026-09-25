<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceInventory.php';

/**
 * Anbindung: Wer selbst antwortet, hängt im LAN/WLAN — auch ohne IPv4 (build 69).
 *
 * Alexandros Govee-Stehlampe H16B0 (Forum t/144417/51–55) ist Matter über WLAN, stand im
 * Bericht aber als „Thread", weil sie keine IPv4 ansagt. Ein Thread-Gerät kann im LAN nie
 * selbst antworten; seine Einträge sagt ein Border Router stellvertretend an.
 *
 * Die Einträge stammen wörtlich aus seinem Debug-Auszug mit 0.8 #68 (dump18.19.txt,
 * 25.09.2026 18:18, Abschnitte „_matter._tcp (144)" und „Border Router").
 */

$borderRouters = [
    ['name' => 'Aqara HubM3 #B681', 'source' => '192.168.10.32', 'txt' => ['vn' => 'Aqara']],
    ['name' => 'Wohnzimmer', 'source' => '192.168.10.91', 'txt' => ['vn' => 'Apple']],
];
$h16b0Adressen = ['fe80::d613:68ff:fe1a:616a', 'fda0:bbb3:5ecf:0:d613:68ff:fe1a:616a', 'fd45:635c:3aa9:4e33:d613:68ff:fe1a:616a'];
$operational   = [
    // H16B0: vier Systeme, antwortet selbst von ihrer Link-Local, keine IPv4
    ['instance' => '791A6C70F25D4788-0000000068FFFB0B._matter._tcp.local', 'host' => 'D413681A616A.local', 'addresses' => $h16b0Adressen, 'source' => 'fe80::d613:68ff:fe1a:616a', 'sleepy' => null],
    ['instance' => '2F2974448B8B3FE1-00000000A06BACA2._matter._tcp.local', 'host' => 'D413681A616A.local', 'addresses' => $h16b0Adressen, 'source' => 'fe80::d613:68ff:fe1a:616a', 'sleepy' => null],
    ['instance' => '5D27ECA641088A00-000000000000002F._matter._tcp.local', 'host' => 'D413681A616A.local', 'addresses' => $h16b0Adressen, 'source' => 'fe80::d613:68ff:fe1a:616a', 'sleepy' => null],
    ['instance' => '78634DF3064AF467-000000000000002A._matter._tcp.local', 'host' => 'D413681A616A.local', 'addresses' => $h16b0Adressen, 'source' => 'fe80::d613:68ff:fe1a:616a', 'sleepy' => null],
    // Thread-Gerät, das der Apple TV stellvertretend ansagt
    ['instance' => '78634DF3064AF467-0000000000000015._matter._tcp.local', 'host' => '5E8B8B9BB1F6EFC7.local', 'addresses' => ['fd24:eaa2:e48b:1:8159:711b:fbb4:dc0f'], 'source' => '192.168.10.91', 'sleepy' => null],
    // LAN-Gerät mit IPv4, antwortet selbst
    ['instance' => '78634DF3064AF467-000000000001B669._matter._tcp.local', 'host' => '027A78CD64980000.local', 'addresses' => ['fe80::d950:5f4f:87cb:6344', 'fda0:bbb3:5ecf:0:a0f:c64d:bb3:b68', 'fd45:635c:3aa9:4e33:1fe9:832d:d258:3e75', '192.168.10.35'], 'source' => '192.168.10.35', 'sleepy' => false],
];
$known = [['nodeId' => 47, 'name' => 'H16B0', 'sleepy' => false]];

$rows   = DeviceInventory::build($operational, $borderRouters, $known, ['5D27ECA641088A00']);
$byHost = [];
foreach ($rows as $row) {
    $byHost[strtolower($row['host'])] = $row;
}

$h16b0 = $byHost['d413681a616a.local'] ?? [];
assertSame(DeviceInventory::LINK_LAN, $h16b0['link'] ?? null, 'H16B0 antwortet selbst — LAN/WLAN, auch ohne IPv4');
assertSame(DeviceInventory::VIA_SELF, $h16b0['via'] ?? null, 'H16B0: Quelle ist das Gerät selbst');
assertSame(4, $h16b0['fabrics'] ?? null, 'H16B0 in vier Systemen');
assertSame(DeviceInventory::POWER_MAINS, $h16b0['power'] ?? null, 'Als LAN-Gerät am Strom');

assertSame(DeviceInventory::LINK_THREAD, $byHost['5e8b8b9bb1f6efc7.local']['link'] ?? null, 'Vom Apple TV angesagt, nur Thread-Adresse: bleibt Thread');
assertSame(DeviceInventory::LINK_LAN, $byHost['027a78cd64980000.local']['link'] ?? null, 'Mit IPv4: LAN wie bisher');
