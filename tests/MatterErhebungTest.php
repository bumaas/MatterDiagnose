<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/MatterErhebung.php';

// --- Lagebild aus den echten Paketmitschnitten ----------------------------
$manifest  = json_decode((string)file_get_contents(__DIR__ . '/fixtures/mdns/manifest.json'), true);
$antworten = [];
foreach ($manifest as $eintrag) {
    $raw         = (string)file_get_contents(__DIR__ . '/fixtures/mdns/' . $eintrag['datei']);
    $antworten[] = [
        'from'    => $eintrag['quelle'],
        'message' => MdnsCodec::decodeMessage($raw),
    ];
}

$lage = MatterErhebung::sammeln($antworten, []);

gleich(2, count($lage['borderRouter']), 'Zwei Border Router im Mitschnitt');
$brNamen = array_map(static fn(array $br): string => $br['name'], $lage['borderRouter']);
pruefe(
    count(array_filter($brNamen, static fn(string $n): bool => str_starts_with($n, 'DIRIGERA'))) === 1,
    'DIRIGERA als Border Router erkannt (' . implode(', ', $brNamen) . ')'
);
foreach ($lage['borderRouter'] as $br) {
    pruefe($br['quelle'] !== '', 'Border Router ' . $br['name'] . ' hat eine Quell-IP');
}

pruefe(count($lage['geraeteBetrieb']) >= 30, 'Mindestens 30 betriebsbereite Matter-Annoncen');
pruefe(count($lage['geraeteKoppelbereit']) >= 1, 'Mindestens ein koppelbereites Gerät');
gleich(false, $lage['eigeneAnkuendigung'], 'Ohne eigene Adressen keine Eigen-Annonce');

// Die SymBox (192.168.178.172) annonciert sich im Mitschnitt selbst:
$lageSymBox = MatterErhebung::sammeln($antworten, ['192.168.178.172']);
gleich(true, $lageSymBox['eigeneAnkuendigung'], 'SymBox-Annonce wird als eigene erkannt');

// --- Thread-Präfixe (synthetisch, deterministisch) ------------------------
$praefixe = DiagnoseEngine::threadPraefixe(
    [
        'fd89:6b7:bc55:0:82ad:18fc:bbce:114c',  // Thread-OMR — soll gefunden werden
        'fd89:6b7:bc55:0:b09d:2243:c5eb:b1cb',  // gleiches Präfix — kein Duplikat
        'fd86:6fd:53ed:0:e65f:1ff:fed4:f4c1',   // eigenes LAN-ULA — ausblenden
        '2003:de:371b:9400:e65f:1ff:fed4:f4c1', // GUA — kein ULA, ausblenden
        'fe80::1',                              // Link-Local — kein ULA
    ],
    ['fd86:6fd:53ed:0:19a4:151a:deaa:af71', '2003:de:371b:9400:19a4:151a:deaa:af71']
);
gleich(1, count($praefixe), 'Genau ein Thread-Präfix erkannt');
pruefe(isset($praefixe['fd89:6b7:bc55::']), 'Thread-Präfix kanonisch (fd89:6b7:bc55::)');

// --- Gateway-Zuordnung ----------------------------------------------------
$gateways = MatterErhebung::praefixGateways(
    $praefixe,
    [
        [
            'instanz'  => 'X._matterc._udp.local',
            'host'     => 'X.local',
            'adressen' => ['fd89:6b7:bc55:0:82ad:18fc:bbce:114c'],
            'quelle'   => '192.168.178.63',
        ],
    ],
    [
        [
            'name'     => 'DIRIGERA #666D',
            'host'     => 'gw2.local',
            'adressen' => ['fd86:6fd:53ed:0:6aec:8aff:fe0b:e88a', 'fe80::9d0e:6b3e:e991:9e09'],
            'quelle'   => '192.168.178.186',
        ],
        [
            'name'     => 'Wohnzimmer',
            'host'     => 'Wohnzimmer-2.local',
            'adressen' => ['fd86:6fd:53ed:0:c4a:b7a3:7ae0:78b1', 'fe80::8f7:24ce:93c4:8920'],
            'quelle'   => '192.168.178.63',
        ],
    ]
);
gleich(
    'fe80::8f7:24ce:93c4:8920',
    $gateways['fd89:6b7:bc55::']['gateway'],
    'Gateway ist die Link-Local des annoncierenden Border Routers'
);
gleich(
    'fd89:6b7:bc55:0:82ad:18fc:bbce:114c',
    $gateways['fd89:6b7:bc55::']['testAdresse'],
    'Testadresse aus dem Präfix übernommen'
);
