<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/MatterDiscovery.php';

// --- Lagebild aus den echten Paketmitschnitten ----------------------------
$manifest  = json_decode((string)file_get_contents(__DIR__ . '/fixtures/mdns/manifest.json'), true);
$responses = [];
foreach ($manifest as $entry) {
    $raw         = (string)file_get_contents(__DIR__ . '/fixtures/mdns/' . $entry['file']);
    $responses[] = [
        'from'    => $entry['source'],
        'message' => MdnsCodec::decodeMessage($raw),
    ];
}

$survey = MatterDiscovery::collect($responses, []);

assertSame(2, count($survey['borderRouters']), 'Zwei Border Router im Mitschnitt');
$brNames = array_map(static fn(array $br): string => $br['name'], $survey['borderRouters']);
assertTrue(
    count(array_filter($brNames, static fn(string $n): bool => str_starts_with($n, 'DIRIGERA'))) === 1,
    'DIRIGERA als Border Router erkannt (' . implode(', ', $brNames) . ')'
);
foreach ($survey['borderRouters'] as $br) {
    assertTrue($br['source'] !== '', 'Border Router ' . $br['name'] . ' hat eine Quell-IP');
}

assertTrue(count($survey['operationalDevices']) >= 30, 'Mindestens 30 betriebsbereite Matter-Annoncen');
assertTrue(count($survey['commissionableDevices']) >= 1, 'Mindestens ein koppelbereites Gerät');
assertSame(false, $survey['ownAnnouncement'], 'Ohne eigene Adressen keine Eigen-Annonce');

// Die SymBox (192.168.178.172) annonciert sich im Mitschnitt selbst:
$surveySymBox = MatterDiscovery::collect($responses, ['192.168.178.172']);
assertSame(true, $surveySymBox['ownAnnouncement'], 'SymBox-Annonce wird als eigene erkannt');

// --- Thread-Präfixe (synthetisch, deterministisch) ------------------------
$prefixes = DiagnosisEngine::threadPrefixes(
    [
        'fd89:6b7:bc55:0:82ad:18fc:bbce:114c',  // Thread-OMR — soll gefunden werden
        'fd89:6b7:bc55:0:b09d:2243:c5eb:b1cb',  // gleiches Präfix — kein Duplikat
        'fd86:6fd:53ed:0:e65f:1ff:fed4:f4c1',   // eigenes LAN-ULA — ausblenden
        '2003:de:371b:9400:e65f:1ff:fed4:f4c1', // GUA — kein ULA, ausblenden
        'fe80::1',                              // Link-Local — kein ULA
    ],
    ['fd86:6fd:53ed:0:19a4:151a:deaa:af71', '2003:de:371b:9400:19a4:151a:deaa:af71']
);
assertSame(1, count($prefixes), 'Genau ein Thread-Präfix erkannt');
assertTrue(isset($prefixes['fd89:6b7:bc55::']), 'Thread-Präfix kanonisch (fd89:6b7:bc55::)');

// --- Gateway-Zuordnung ----------------------------------------------------
$gateways = MatterDiscovery::prefixGateways(
    $prefixes,
    [
        [
            'instance'  => 'X._matterc._udp.local',
            'host'      => 'X.local',
            'addresses' => ['fd89:6b7:bc55:0:82ad:18fc:bbce:114c'],
            'source'    => '192.168.178.63',
        ],
    ],
    [
        [
            'name'      => 'DIRIGERA #666D',
            'host'      => 'gw2.local',
            'addresses' => ['fd86:6fd:53ed:0:6aec:8aff:fe0b:e88a', 'fe80::9d0e:6b3e:e991:9e09'],
            'source'    => '192.168.178.186',
        ],
        [
            'name'      => 'Wohnzimmer',
            'host'      => 'Wohnzimmer-2.local',
            'addresses' => ['fd86:6fd:53ed:0:c4a:b7a3:7ae0:78b1', 'fe80::8f7:24ce:93c4:8920'],
            'source'    => '192.168.178.63',
        ],
    ]
);
assertSame(
    'fe80::8f7:24ce:93c4:8920',
    $gateways['fd89:6b7:bc55::']['gateway'],
    'Gateway ist die Link-Local des annoncierenden Border Routers'
);
assertSame(
    'fd89:6b7:bc55:0:82ad:18fc:bbce:114c',
    $gateways['fd89:6b7:bc55::']['testAddress'],
    'Testadresse aus dem Präfix übernommen'
);
