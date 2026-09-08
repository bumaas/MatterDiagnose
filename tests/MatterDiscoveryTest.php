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

// --- Kopplungsmodus aus dem TXT-Record (Lehrgeld 08.09.2026) -----------------
// Shelly annonciert `_matterc._udp` nach jedem Boot rund 15 Minuten lang mit
// "CM=0" (Extended Discovery) — das Gerät ist dabei NICHT koppelbereit
// (Matter.GetStatus → commissionable: false). Der Mitschnitt stammt vom
// Shelly Dimmer Gen4 im Boot-Fenster; die Apple-Proxy-PTRs aus dem Manifest
// kommen ohne TXT und bleiben damit "unbekannt".
$shellyRaw    = (string)file_get_contents(__DIR__ . '/fixtures/mdns/matterc_shelly_dimmer_cm0.bin');
$surveyShelly = MatterDiscovery::collect(
    [['from' => '192.168.178.67:5353', 'message' => MdnsCodec::decodeMessage($shellyRaw)]],
    []
);
assertSame(1, count($surveyShelly['commissionableDevices']), 'Shelly-Annonce als _matterc-Eintrag erkannt');
assertSame('E8F60A7C9714.local', $surveyShelly['commissionableDevices'][0]['host'] ?? '', 'Shelly-Hostname aus dem SRV-Record');
assertSame(0, $surveyShelly['commissionableDevices'][0]['commissioningMode'] ?? null, 'Shelly meldet CM=0 — kein offenes Kopplungsfenster');
assertSame([], $surveyShelly['missingTxt'], 'Shelly liefert das TXT gleich mit — nichts nachzufragen');

foreach ($survey['commissionableDevices'] as $device) {
    assertTrue(array_key_exists('commissioningMode', $device) && $device['commissioningMode'] === null, 'Ohne TXT bleibt der Kopplungsmodus unbekannt (' . $device['instance'] . ')');
}
assertSame(
    array_map(static fn(array $d): string => $d['instance'], $survey['commissionableDevices']),
    $survey['missingTxt'],
    'Alle _matterc-Einträge ohne TXT stehen zur Nachfrage an'
);

// --- Border Router nur mit IPv4-Adresse → AAAA nachfragen (Lehrgeld 08.09.2026) ---
// Auf die kombinierte Abfrage (_meshcop + _matter + _matterc) antwortet der Apple TV
// „Wohnzimmer" mit 29 Records: PTR/SRV/TXT für meshcop, dazu nur ein A-Record für
// Wohnzimmer-2.local — kein einziges AAAA. Weil der Host damit „eine Adresse hatte",
// fragte das Modul nie nach, kannte die Link-Local des Border Routers nicht und
// erklärte die bewusst gesetzte Thread-Route für „führt zu einem unbekannten Gerät".
$appleRaw    = (string)file_get_contents(__DIR__ . '/fixtures/mdns/meshcop_apple_combined_a_only.bin');
$surveyApple = MatterDiscovery::collect(
    [['from' => '192.168.178.63:5353', 'message' => MdnsCodec::decodeMessage($appleRaw)]],
    []
);
$wohnzimmer = null;
foreach ($surveyApple['borderRouters'] as $br) {
    if ($br['name'] === 'Wohnzimmer') {
        $wohnzimmer = $br;
    }
}
assertTrue($wohnzimmer !== null, 'Apple TV „Wohnzimmer" als Border Router erkannt');
assertSame(['192.168.178.63'], $wohnzimmer['addresses'] ?? [], 'Der Mitschnitt enthält nur die IPv4-Adresse des Apple TV');
assertTrue(
    in_array('Wohnzimmer-2.local', $surveyApple['missingAddresses'], true),
    'Ein Host ohne IPv6-Adresse steht zur AAAA-Nachfrage an, auch wenn ein A-Record da ist'
);

// --- Nachfragen priorisieren und über Runden hinweg merken (Build 21 → 23) -----------
// Der Apple-Proxy annonciert 29 _matter-Instanzen ohne SRV; deren Nachfragen füllten die
// auf 20 gekappte Liste, die AAAA-Nachfrage für den Border Router fiel hinten runter (21).
// Umgekehrt verhungerten danach die SRV-Nachfragen, weil reine IPv4-Hosts jede Runde
// erneut nach AAAA gefragt wurden, obwohl sie nie antworten (Review 08.09.2026). Deshalb:
// Border Router → TXT → SRV → übrige AAAA, und bereits gestellte Fragen kommen nicht wieder.
assertTrue(method_exists(MatterDiscovery::class, 'followUpQuestions'), 'MatterDiscovery::followUpQuestions vorhanden');
if (method_exists(MatterDiscovery::class, 'followUpQuestions')) {
    $manySrv = [];
    for ($i = 0; $i < 29; $i++) {
        $manySrv[] = sprintf('%016X-%016X._matter._tcp.local', 0x1234, $i);
    }
    $survey29 = [
        'borderRouters'    => [
            ['name' => 'Wohnzimmer', 'host' => 'Wohnzimmer-2.local', 'addresses' => ['192.168.178.63'], 'source' => '192.168.178.63', 'txt' => []],
            ['name' => 'DIRIGERA #666D', 'host' => 'gw2.local', 'addresses' => ['fe80::2'], 'source' => '192.168.178.186', 'txt' => []],
        ],
        'missingSrv'       => $manySrv,
        'missingAddresses' => ['plug.local', 'Wohnzimmer-2.local', 'dimmer.local', 'plug2.local', 'plug3.local', 'plug4.local'],
        'missingTxt'       => ['09450185B198E091._matterc._udp.local', 'A._matterc._udp.local', 'B._matterc._udp.local'],
    ];
    $round1 = MatterDiscovery::followUpQuestions($survey29, 20);
    assertSame(20, count($round1), 'Runde 1: Liste auf das Limit gekappt');
    assertSame(['name' => 'Wohnzimmer-2.local', 'type' => MdnsCodec::TYPE_AAAA], $round1[0], 'AAAA des Border Routers ohne IPv6 steht ganz vorn');
    assertSame(MdnsCodec::TYPE_TXT, $round1[1]['type'], 'TXT der _matterc-Annoncen folgt');
    assertSame(MdnsCodec::TYPE_SRV, $round1[4]['type'], 'SRV-Nachfragen kommen vor den übrigen AAAA');
    $srvRound1 = count(array_filter($round1, static fn(array $q): bool => $q['type'] === MdnsCodec::TYPE_SRV));
    assertSame(16, $srvRound1, 'Runde 1: 16 SRV-Nachfragen (20 − 1 Border Router − 3 TXT)');
    $names = array_map(static fn(array $q): string => $q['name'], $round1);
    assertSame(count($names), count(array_unique($names)), 'keine doppelten Nachfragen');

    // Runde 2: nichts aus Runde 1 wiederholen, auch wenn es unbeantwortet blieb
    $asked  = $round1;
    $round2 = MatterDiscovery::followUpQuestions($survey29, 20, $asked);
    foreach ($round2 as $q) {
        assertTrue(!in_array($q, $round1, true), 'Runde 2 wiederholt keine Frage aus Runde 1 (' . $q['name'] . ')');
    }
    $srvRound2 = count(array_filter($round2, static fn(array $q): bool => $q['type'] === MdnsCodec::TYPE_SRV));
    assertSame(13, $srvRound2, 'Runde 2: die restlichen 13 SRV-Nachfragen');
    assertSame(18, count($round2), 'Runde 2: 13 SRV + 5 übrige AAAA');

    // Runde 3: alles gefragt → leer (die Schleife im Modul bricht dann ab)
    $round3 = MatterDiscovery::followUpQuestions($survey29, 20, array_merge($asked, $round2));
    assertSame([], $round3, 'Runde 3: nichts Neues mehr zu fragen');
}

// --- TXT-Zuordnung unabhängig von Groß-/Kleinschreibung (Review 08.09.2026) ----------
// mDNS-Namen und TXT-Schlüssel sind case-insensitiv; antworten Gerät und Advertising-Proxy
// in verschiedener Schreibweise, darf der Shelly-Fehlalarm (CM=0) nicht zurückkommen.
$shellyMsg = MdnsCodec::decodeMessage($shellyRaw);
foreach ($shellyMsg['records'] as &$rec) {
    if ($rec['type'] === MdnsCodec::TYPE_TXT) {
        $rec['name'] = strtolower($rec['name']);
        $rec['txt']  = array_change_key_case($rec['txt'], CASE_LOWER);
    }
    if ($rec['type'] === MdnsCodec::TYPE_SRV) {
        $rec['name'] = strtoupper($rec['name']);
    }
}
unset($rec);
$surveyCase = MatterDiscovery::collect([['from' => '192.168.178.67:5353', 'message' => $shellyMsg]], []);
assertSame(0, $surveyCase['commissionableDevices'][0]['commissioningMode'] ?? 'fehlt', 'TXT in anderer Schreibweise: CM=0 trotzdem erkannt');
assertSame('E8F60A7C9714.local', $surveyCase['commissionableDevices'][0]['host'] ?? '', 'SRV in anderer Schreibweise: Host trotzdem aufgelöst');
assertSame([], $surveyCase['missingTxt'], 'TXT in anderer Schreibweise gilt nicht als fehlend');

// --- Unbrauchbarer CM-Wert bleibt „unbekannt", nicht „geschlossen" ------------------------
$shellyMsg2 = MdnsCodec::decodeMessage($shellyRaw);
foreach ($shellyMsg2['records'] as &$rec) {
    if ($rec['type'] === MdnsCodec::TYPE_TXT) {
        $rec['txt']['CM'] = '';
    }
}
unset($rec);
$surveyBadCm = MatterDiscovery::collect([['from' => '192.168.178.67:5353', 'message' => $shellyMsg2]], []);
$dev = $surveyBadCm['commissionableDevices'][0] ?? [];
assertTrue(array_key_exists('commissioningMode', $dev) && $dev['commissioningMode'] === null, 'Leerer CM-Wert wird nicht zu 0 (unbekannt statt geschlossen)');
assertSame([], $surveyBadCm['missingTxt'], 'Ein vorhandenes, aber unbrauchbares TXT wird nicht erneut nachgefragt');

// --- 6a (Review 08.09.2026): kein Gateway-Urteil ohne vollständige Link-Local-Liste --------
// Liefert ein Border Router per mDNS nur GUA/ULA (Avahi/OTBR), fehlt seine fe80 — dann darf
// keine Route als „unbekanntes Gateway" gelten; ein Löschrat ohne Beleg ist teurer als ein
// verpasster Hinweis (am 08.09.2026 selbst erlebt).
assertTrue(method_exists(MatterDiscovery::class, 'borderRouterLinkLocals'), 'MatterDiscovery::borderRouterLinkLocals vorhanden');
if (method_exists(MatterDiscovery::class, 'borderRouterLinkLocals')) {
    $complete = MatterDiscovery::borderRouterLinkLocals([
        ['name' => 'Wohnzimmer', 'host' => 'Wohnzimmer-2.local', 'addresses' => ['192.168.178.63', 'FE80::8F7:24CE:93C4:8920'], 'source' => '192.168.178.63', 'txt' => []],
        ['name' => 'DIRIGERA #666D', 'host' => 'gw2.local', 'addresses' => ['fdba::1', 'fe80::6720:d6cb:b7d2:bed'], 'source' => '192.168.178.186', 'txt' => []],
    ]);
    assertSame(['fe80::8f7:24ce:93c4:8920', 'fe80::6720:d6cb:b7d2:bed'], $complete, 'alle Border Router mit Link-Local: Liste vollständig, kleingeschrieben');
    $incomplete = MatterDiscovery::borderRouterLinkLocals([
        ['name' => 'Wohnzimmer', 'host' => 'Wohnzimmer-2.local', 'addresses' => ['fe80::8f7:24ce:93c4:8920'], 'source' => '192.168.178.63', 'txt' => []],
        ['name' => 'OTBR', 'host' => 'otbr.local', 'addresses' => ['fd86:6fd:53ed::9', '2003:f9::9'], 'source' => '192.168.178.9', 'txt' => []],
    ]);
    assertSame([], $incomplete, 'ein Border Router ohne Link-Local: keine Liste, also kein Gateway-Urteil');
    assertSame([], MatterDiscovery::borderRouterLinkLocals([]), 'ohne Border Router keine Liste');
}