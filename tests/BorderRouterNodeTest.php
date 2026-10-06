<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MatterDiscovery.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceInventory.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceIdentity.php';

/**
 * Der Matter-Knoten eines Border Routers ist kein Thread-Gerät (build 83).
 *
 * In Alexandros Lauf (PN t/144583/7, 0.9 #82) sagte sich sein Apple TV „Wohnzimmer“ auch
 * selbst als Matter-Knoten an (`56FCC2ECE403.local:54890`, Fabric 791A6C70F25D4788), mit
 * genau seinen eigenen Adressen, aber ohne IPv4. Die stand nur in der `_meshcop`-Ansage
 * daneben, und die zählte nicht als Beleg. So stand der Apple TV als Thread-Gerät in der
 * Liste, und Alexandros beide LAN-Präfixe (`fda0:bbb3:5ecf::`, `fd45:635c:3aa9:4e33::`, in
 * beiden hat Symcon selbst Adressen) standen unter „Belegte Thread-Präfixe“.
 *
 * Fixture: tests/fixtures/debug/alexandro_2026-10-06.json, aus seinem Debug-Auszug gezogen.
 */

$lauf          = json_decode((string)file_get_contents(__DIR__ . '/fixtures/debug/alexandro_2026-10-06.json'), true, 512, JSON_THROW_ON_ERROR);
$ownAddresses  = $lauf['ownAddresses'];
$borderRouters = array_map(static fn(array $router): array => $router + ['txt' => ['vn' => $router['name'] === 'Wohnzimmer' ? 'Apple' : 'Aqara']], $lauf['borderRouters']);
$operational   = $lauf['operational'];

$appleTvKnoten = '56fcc2ece403.local';
$lanPraefixe   = [
    DiagnosisEngine::prefix64('fda0:bbb3:5ecf:0:1ca4:57ea:ae2d:8c45'),
    DiagnosisEngine::prefix64('fd45:635c:3aa9:4e33:4f2:57e1:1dd1:3951'),
];
$threadPraefix = DiagnosisEngine::prefix64('fd24:eaa2:e48b:1:44e1:332f:a74:278f');

// --- Thread-Kandidaten ---
$kandidaten = MatterDiscovery::threadCandidateAddresses($operational, [], $borderRouters);
assertTrue(!in_array('fda0:bbb3:5ecf:0:1ca4:57ea:ae2d:8c45', $kandidaten, true), 'Adresse des Apple TV (aus der _meshcop-Ansage mit IPv4) ist kein Thread-Kandidat');
assertTrue(!in_array('fd45:635c:3aa9:4e33:4f2:57e1:1dd1:3951', $kandidaten, true), 'Zweite Adresse des Apple TV ebenso');
assertTrue(in_array('fd24:eaa2:e48b:1:44e1:332f:a74:278f', $kandidaten, true), 'Thread-Gerät 0EA661286AD90249 bleibt Kandidat');

// --- Belegte Thread-Präfixe ---
$belegt = MatterDiscovery::proxiedPrefixes($operational, $borderRouters);
foreach ($lanPraefixe as $praefix) {
    assertTrue(!in_array($praefix, $belegt, true), 'LAN-Präfix ' . $praefix . ' ist kein belegtes Thread-Präfix');
}
assertTrue(in_array($threadPraefix, $belegt, true), 'Das Thread-Netz fd24:eaa2:e48b:1:: bleibt belegt');

$praefixe = DiagnosisEngine::threadPrefixes($kandidaten, $ownAddresses, $belegt);
assertSame([$threadPraefix], array_keys($praefixe), 'Genau ein Thread-Netz');

// --- Geräteliste ---
$rows   = DeviceInventory::build($operational, $borderRouters, [], ['5D27ECA641088A00']);
$byHost = [];
foreach ($rows as $row) {
    $byHost[strtolower($row['host'])] = $row;
}
assertSame(DeviceInventory::LINK_LAN, $byHost[$appleTvKnoten]['link'] ?? null, 'Der Knoten des Apple TV hängt im LAN, nicht im Thread-Netz');
assertSame(DeviceInventory::LINK_THREAD, $byHost['0ea661286ad90249.local']['link'] ?? null, 'Thread-Gerät 0EA661286AD90249 bleibt Thread');
assertSame(DeviceInventory::LINK_THREAD, $byHost['5e8b8b9bb1f6efc7.local']['link'] ?? null, 'Thread-Gerät hinter dem Apple TV bleibt Thread');

// Ein Border Router ohne IPv4 in seiner Ansage belegt nichts
$ohneIpv4 = $borderRouters;
foreach ($ohneIpv4 as &$router) {
    $router['addresses'] = array_values(array_filter($router['addresses'], static fn(string $a): bool => !str_contains($a, '.')));
}
unset($router);
$kandidaten = MatterDiscovery::threadCandidateAddresses($operational, [], $ohneIpv4);
assertTrue(in_array('fda0:bbb3:5ecf:0:1ca4:57ea:ae2d:8c45', $kandidaten, true), 'Ohne IPv4 in der Router-Ansage: kein Beleg, Kandidat wie bisher');
