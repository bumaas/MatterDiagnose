<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';

/**
 * Tabellengetriebene Szenario-Tests: jedes JSON unter fixtures/scenarios/
 * beschreibt eine Eingabe (nur die Abweichungen vom Standard) und die
 * erwarteten Befunde. Jedes Szenario entspricht einem real erlebten Fall.
 */

$defaults = [
    'ipv6Addresses'         => ['fd86:6fd:53ed::1', 'fe80::1'],
    'mdnsResponses'         => true,
    'mdnsProbeResponders'   => 0,
    'borderRouters'         => [
        ['name' => 'DIRIGERA #666D', 'host' => 'gw2.local', 'addresses' => ['fe80::2'], 'source' => '192.168.178.186', 'txt' => []],
    ],
    'operationalDevices'    => [
        ['instance' => 'A-1._matter._tcp.local', 'host' => 'a.local', 'addresses' => ['fd89:1::1'], 'source' => '192.168.178.63'],
    ],
    'commissionableDevices' => [
        ['instance' => 'B._matterc._udp.local', 'host' => 'b.local', 'addresses' => ['fd89:1::2'], 'source' => '192.168.178.63'],
    ],
    'threadPrefixes'        => [
        'fd89:1::' => ['reachable' => true, 'testAddress' => 'fd89:1::1', 'gateway' => 'fe80::2', 'routeExists' => true],
    ],
    'platform'              => 'Windows',
    // Abgleich mit den in Symcon gekoppelten Geräten (ab 0.3): Standard ist ein
    // vorhandener Controller mit lesbarer Fabric-ID und ohne gekoppelte Geräte.
    'controllerPresent'     => true,
    'ownFabricId'           => '90B99E147F5D9954',
    'knownDevices'          => [],
    'devicesAmbiguous'      => false,
    // Thread-Netz-Gesundheit und Routenbewertung (ab 0.4): ohne Angaben bleiben
    // beide Abschnitte still, damit die älteren Szenarien unverändert gelten.
    'threadNetworks'        => null,
    'routeAssessment'       => null,
];

// Zurückgezogene Befunde (paresy, 02.09.2026): Symcon annonciert sich als Controller
// nicht, und Bonjour/Avahi sind Symcons eigener mDNS-Unterbau, keine Störer.
// Dazu (02.09.2026) 'foreign_fabrics': Die Zahl fremder Fabrics im Netz beantwortet
// keine Frage des Anwenders — sie ist weder eine Störung noch führt sie zu einer
// Handlung. An ihre Stelle tritt die Fabric-Belegung der eigenen Geräte.
// Kein Szenario darf sie je wieder liefern.
$retiredFindings = ['port5353_competition', 'own_controller_missing', 'own_controller_ok', 'foreign_fabrics'];

foreach (glob(__DIR__ . '/fixtures/scenarios/*.json') ?: [] as $file) {
    $scenario = json_decode((string)file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    $name     = $scenario['name'];
    $input    = array_merge($defaults, $scenario['input']);

    $findings   = DiagnosisEngine::evaluate($input);
    $severities = [];
    foreach ($findings as $finding) {
        $severities[$finding['id']] = $finding['severity'];
    }

    foreach ($retiredFindings as $id) {
        assertTrue(!isset($severities[$id]), $name . ': zurückgezogener Befund ' . $id . ' darf nicht mehr auftauchen');
    }
    foreach ($scenario['expected']['severities'] ?? [] as $id => $severity) {
        assertSame($severity, $severities[$id] ?? '(fehlt)', $name . ': Befund ' . $id);
    }
    foreach ($scenario['expected']['absent'] ?? [] as $id) {
        assertTrue(!isset($severities[$id]), $name . ': Befund ' . $id . ' darf nicht auftauchen');
    }
    if (isset($scenario['expected']['firstFinding'])) {
        assertSame(
            $scenario['expected']['firstFinding'],
            $findings[0]['id'] ?? '(leer)',
            $name . ': Sortierung — Blocker zuerst'
        );
    }
    foreach ($scenario['expected']['paramsContain'] ?? [] as $id => $substrings) {
        $params = null;
        foreach ($findings as $finding) {
            if ($finding['id'] === $id) {
                $params = implode(' ', $finding['params']);
                break;
            }
        }
        foreach ($substrings as $substring) {
            assertTrue(
                $params !== null && str_contains($params, $substring),
                $name . ': ' . $id . ' enthält "' . $substring . '" (Params: ' . var_export($params, true) . ')'
            );
        }
    }
    foreach ($scenario['expected']['paramsAbsent'] ?? [] as $id => $substrings) {
        $params = '';
        foreach ($findings as $finding) {
            if ($finding['id'] === $id) {
                $params = implode(' ', $finding['params']);
                break;
            }
        }
        foreach ($substrings as $substring) {
            assertTrue(
                !str_contains($params, $substring),
                $name . ': ' . $id . ' enthält nicht "' . $substring . '" (Params: ' . var_export($params, true) . ')'
            );
        }
    }
    // Build 62: Die Geräte eines Befunds als Liste, schlicht „Name (Id n)" — für die
    // Änderungsmeldung, die sie nach dem Lauf noch nennen soll (Burkhard 25.09.2026).
    foreach ($scenario['expected']['devices'] ?? [] as $id => $expectedDevices) {
        $devices = '(Befund fehlt)';
        foreach ($findings as $finding) {
            if ($finding['id'] === $id) {
                $devices = $finding['devices'] ?? '(keine Liste)';
                break;
            }
        }
        assertSame($expectedDevices, $devices, $name . ': Geräteliste von ' . $id);
    }
}

// --- Review 17.09.2026 -------------------------------------------------------
// Befunde, die je Präfix oder Route auftreten, tragen ihr Präfix als „subject" —
// sonst verschmelzen sie in der Änderungserkennung zu einem Eintrag.
$subjects     = [];
$subjectInput = array_merge($defaults, [
    'threadPrefixes'  => [
        'fd89:1::' => ['reachable' => false, 'testAddress' => 'fd89:1::1', 'gateway' => 'fe80::2', 'routeExists' => false],
        'fd89:2::' => ['reachable' => false, 'testAddress' => 'fd89:2::1', 'gateway' => 'fe80::2', 'routeExists' => false],
    ],
    'routeAssessment' => [
        'notPersistent'  => [],
        'stale'          => [
            ['prefix' => 'fd99:1::', 'length' => 64, 'gateway' => 'fe80::9', 'interface' => '12'],
            ['prefix' => 'fd99:2::', 'length' => 64, 'gateway' => 'fe80::9', 'interface' => '12'],
        ],
        'gatewayUnknown' => [],
    ],
]);
foreach (DiagnosisEngine::evaluate($subjectInput) as $finding) {
    if (in_array($finding['id'], ['thread_prefix_unreachable', 'thread_route_stale'], true)) {
        $subjects[] = $finding['id'] . '@' . ($finding['subject'] ?? '(fehlt)');
    }
}
sort($subjects);
assertSame(
    ['thread_prefix_unreachable@fd89:1::', 'thread_prefix_unreachable@fd89:2::', 'thread_route_stale@fd99:1::/64 via fe80::9', 'thread_route_stale@fd99:2::/64 via fe80::9'],
    $subjects,
    'Präfix-Befunde tragen ihr Präfix, Routenbefunde ihre Route als subject'
);

// own_devices_visible: der ungenutzte Parameter „visible" entfällt
$visibleParams = null;
foreach (DiagnosisEngine::evaluate(array_merge($defaults, ['knownDevices' => [['nodeId' => 1, 'name' => 'A', 'subscription' => 'OK', 'visible' => true, 'ambiguous' => false]]])) as $finding) {
    if ($finding['id'] === 'own_devices_visible') {
        $visibleParams = array_keys($finding['params']);
    }
}
assertSame(['total'], $visibleParams, 'own_devices_visible trägt nur noch „total"');

// --- Forum t/144417: Batteriezeichen zurück, Erklärung nur wenn nötig ----------------
// Das Symbol ist in der Konsole gut lesbar (Hardcopy Burkhard, 17.09.2026) — es bleibt
// an der Geräteliste. Die Erklärung dazu steht nur im Befund, wenn wirklich ein Gerät
// gekennzeichnet ist; Loerdys Bericht erklärte ein Zeichen, das nirgends auftauchte.
$mitBatterie = null;
$ohneMarke   = null;
foreach (DiagnosisEngine::evaluate(array_merge($defaults, ['knownDevices' => [
    ['nodeId' => 18, 'name' => 'Smart Lock Go', 'subscription' => 'OK', 'visible' => false, 'ambiguous' => false, 'sleepy' => true],
    ['nodeId' => 28, 'name' => 'GRILLPLATS Plug', 'subscription' => 'OK', 'visible' => false, 'ambiguous' => false, 'sleepy' => false],
]])) as $finding) {
    if ($finding['id'] === 'own_devices_missing_battery') {
        $mitBatterie = $finding;
    }
    if ($finding['id'] === 'own_devices_missing') {
        $ohneMarke = $finding;
    }
}
assertSame(null, $ohneMarke, 'Mit Batteriegerät nur der Befund mit Erklärung');
assertSame('Smart Lock Go (Id 18) 🔋, GRILLPLATS Plug (Id 28)', $mitBatterie['params']['devices'] ?? '(fehlt)', 'Batteriegerät gekennzeichnet, Steckdose nicht');
assertSame(['count', 'devices', 'states'], array_keys($mitBatterie['params'] ?? []), 'Keine zusätzliche Batterieliste mehr');

$nurUnbekannt = null;
foreach (DiagnosisEngine::evaluate(array_merge($defaults, ['knownDevices' => [
    ['nodeId' => 28, 'name' => 'GRILLPLATS Plug', 'subscription' => 'OK', 'visible' => false, 'ambiguous' => false, 'sleepy' => null],
]])) as $finding) {
    if ($finding['id'] === 'own_devices_missing') {
        $nurUnbekannt = $finding;
    }
}
assertSame('GRILLPLATS Plug (Id 28)', $nurUnbekannt['params']['devices'] ?? '(fehlt)', 'Ohne Angabe kein Zeichen');

// --- nuc-Dump 18.09.2026: „37 Matter-Geräte" waren 13 Geräte in 6 Systemen -------------
// Jedes Gerät annonciert sich einmal je System (Fabric), dem es angehört. Der Befund zählte
// Ansagen und nannte sie Geräte — Burkhard: „so viele Geräte habe ich doch gar nicht".
// Gezählt werden jetzt Geräte (je Host) und Systeme (je Fabric); die Ansagen bleiben als Zahl.
$mehrfach = [
    ['instance' => '90B99E147F5D9954-0000000000000005._matter._tcp.local', 'host' => 'E4B063E529D0.local', 'addresses' => ['192.168.178.116'], 'source' => '192.168.178.116'],
    ['instance' => 'A5AC1650B5C2EE16-0000000000000006._matter._tcp.local', 'host' => '4E93FA842C50F0F9.local', 'addresses' => ['fd89:6b7:bc55::1'], 'source' => '192.168.178.63'],
    ['instance' => '35FA3C0EA8A2346D-000000005A7F1DB6._matter._tcp.local', 'host' => '4E93FA842C50F0F9.local', 'addresses' => ['fd89:6b7:bc55::1'], 'source' => '192.168.178.63'],
    ['instance' => 'B0E451B717784CDF-0000000000000018._matter._tcp.local', 'host' => '4e93fa842c50f0f9.local', 'addresses' => ['fd89:6b7:bc55::1'], 'source' => '192.168.178.63'],
    ['instance' => 'B0E451B717784CDF-0000000000000002._matter._tcp.local', 'host' => '', 'addresses' => [], 'source' => '192.168.178.63'],
    // Der Controller-Datensatz einer SymBox (reservierte Node-ID, eigene Fabric) ist kein Gerät und
    // kein System — build 39 zählte auf dem nuc „14 Geräte in 8 Systemen", die Liste zeigte 13.
    ['instance' => '1234567890ABCDEF-FFFFFFEFFFFFFFFF._matter._tcp.local', 'host' => 'SymBox.local', 'addresses' => ['192.168.178.172'], 'source' => '192.168.178.172'],
];
$zaehlung = null;
foreach (DiagnosisEngine::evaluate(array_merge($defaults, ['operationalDevices' => $mehrfach])) as $finding) {
    if ($finding['id'] === 'operational_found') {
        $zaehlung = $finding['params'];
    }
}
assertSame('3', $zaehlung['count'] ?? '(fehlt)', 'Geräte: drei Hosts (Groß-/Kleinschreibung egal, ohne Host zählt die Ansage)');
assertSame('5', $zaehlung['announcements'] ?? '(fehlt)', 'Ansagen: fünf');
assertSame('4', $zaehlung['systems'] ?? '(fehlt)', 'Systeme: vier Fabrics');

// --- Build 43: „meldet sich für andere Systeme, aber nicht für Symcon" ---
// Loerdys GRILLPLATS (t/144417): lebt, Apple Home und HA sehen sie, nur die Ansage für
// Symcon fehlt. Bisher ging sie in „melden sich nicht" unter — der Befund konnte nicht
// sagen, ob das Gerät tot ist oder nur die Symcon-Kopplung hakt. Jetzt ein eigener Befund;
// das Gerät fehlt in der Liste der Stummen.
$fuerAndere = null;
$stumm      = null;
foreach (DiagnosisEngine::evaluate(array_merge($defaults, ['knownDevices' => [
    ['nodeId' => 28, 'name' => 'GRILLPLATS Plug', 'subscription' => 'OK', 'visible' => false, 'ambiguous' => false, 'sleepy' => false, 'announcedElsewhere' => 2],
    ['nodeId' => 18, 'name' => 'Smart Lock Go', 'subscription' => 'OK', 'visible' => false, 'ambiguous' => false, 'sleepy' => true, 'announcedElsewhere' => 0],
    ['nodeId' => 30, 'name' => 'Lampe', 'subscription' => 'OK', 'visible' => true, 'ambiguous' => false],
]])) as $finding) {
    if ($finding['id'] === 'own_devices_silent_for_symcon') {
        $fuerAndere = $finding;
    }
    if ($finding['id'] === 'own_devices_missing_battery') {
        $stumm = $finding;
    }
}
assertSame(DiagnosisEngine::SEVERITY_NOTICE, $fuerAndere['severity'] ?? null, 'Eigener Hinweis für Geräte, die sich nur für andere melden');
assertSame('GRILLPLATS Plug (Id 28)', $fuerAndere['params']['devices'] ?? '(fehlt)', 'GRILLPLATS steht im neuen Befund');
assertSame('1', $fuerAndere['params']['count'] ?? '(fehlt)', 'Zahl der Geräte');
assertSame('OK', $fuerAndere['params']['states'] ?? '(fehlt)', 'Abo-Status bleibt Teil des Befunds');
assertSame('Smart Lock Go (Id 18) 🔋', $stumm['params']['devices'] ?? '(fehlt)', 'Die Stummen enthalten GRILLPLATS nicht mehr');

$nurFuerAndere = [];
foreach (DiagnosisEngine::evaluate(array_merge($defaults, ['knownDevices' => [
    ['nodeId' => 28, 'name' => 'GRILLPLATS Plug', 'subscription' => 'OK', 'visible' => false, 'ambiguous' => false, 'sleepy' => false, 'announcedElsewhere' => 1],
]])) as $finding) {
    $nurFuerAndere[] = $finding['id'];
}
assertTrue(!in_array('own_devices_missing', $nurFuerAndere, true) && !in_array('own_devices_visible', $nurFuerAndere, true), 'Weder „melden sich nicht" noch „alle melden sich", wenn nur dieser Fall vorliegt');
assertTrue(in_array('own_devices_silent_for_symcon', $nurFuerAndere, true), 'Nur der neue Befund');

// --- Build 52: „kein IPv6" ist nur mit Thread ein Blocker (Ralf, Forum t/144417/22) ---
// Seine Anlage führt zwei WLAN-Matter-Geräte, die einwandfrei laufen, hat aber kein IPv6.
// Der rote Blocker forderte eine Handlung, die nichts bewirkt hätte.
$ohneThread = [
    'ipv6Addresses'         => [],
    'mdnsResponses'         => true,
    'borderRouters'         => [],
    'operationalDevices'    => [
        ['instance' => 'A5AC1650B5C2EE16-0000000000000001._matter._tcp.local', 'host' => 'E8F60A7C9714.local', 'addresses' => ['192.168.178.67'], 'source' => '192.168.178.67'],
        ['instance' => 'B0E451B717784CDF-0000000000000002._matter._tcp.local', 'host' => 'E4B063E529D0.local', 'addresses' => ['192.168.178.116'], 'source' => '192.168.178.116'],
    ],
    'commissionableDevices' => [],
    'threadPrefixes'        => [],
    'platform'              => 'windows',
];
$ids = static function (array $input): array {
    $out = [];
    foreach (DiagnosisEngine::evaluate($input) as $f) {
        $out[$f['id']] = $f['severity'];
    }

    return $out;
};

$befundeOhneThread = $ids($ohneThread);
assertSame(false, isset($befundeOhneThread['no_ipv6']), 'Ohne Thread kein Blocker „no_ipv6"');
assertSame(DiagnosisEngine::SEVERITY_NOTICE, $befundeOhneThread['no_ipv6_no_thread'] ?? null, 'Stattdessen ein Hinweis');

// Sobald ein Border Router da ist, bleibt es ein Blocker
$mitRouter = $ohneThread;
$mitRouter['borderRouters'] = [['name' => 'DIRIGERA #666D', 'host' => 'dirigera.local', 'addresses' => ['192.168.178.186'], 'source' => '192.168.178.186', 'txt' => ['vn' => 'IKEA of Sweden']]];
$befundeMitRouter = $ids($mitRouter);
assertSame(DiagnosisEngine::SEVERITY_BLOCKER, $befundeMitRouter['no_ipv6'] ?? null, 'Mit Border Router bleibt es ein Blocker');
assertSame(false, isset($befundeMitRouter['no_ipv6_no_thread']), 'Dann kein Hinweis');

// Ein Gerät, das nur IPv6 annonciert, ist ein Thread-Gerät — ebenfalls Blocker
$mitThreadGeraet = $ohneThread;
$mitThreadGeraet['operationalDevices'][] = ['instance' => 'A5AC1650B5C2EE16-0000000000000009._matter._tcp.local', 'host' => 'CA1ACE989841CBEB.local', 'addresses' => ['fd89:6b7:bc55:0:3a31:e5b3:b3a2:5d3d'], 'source' => '192.168.178.63'];
assertSame(DiagnosisEngine::SEVERITY_BLOCKER, $ids($mitThreadGeraet)['no_ipv6'] ?? null, 'Ein Gerät ohne IPv4 zählt als Thread');
