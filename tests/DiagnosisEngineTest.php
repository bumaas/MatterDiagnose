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
    'foreignFabrics'        => [],
    // Thread-Netz-Gesundheit und Routenbewertung (ab 0.4): ohne Angaben bleiben
    // beide Abschnitte still, damit die älteren Szenarien unverändert gelten.
    'threadNetworks'        => null,
    'routeAssessment'       => null,
];

// Zurückgezogene Befunde (paresy, 02.09.2026): Symcon annonciert sich als Controller
// nicht, und Bonjour/Avahi sind Symcons eigener mDNS-Unterbau, keine Störer.
// Kein Szenario darf sie je wieder liefern.
$retiredFindings = ['port5353_competition', 'own_controller_missing', 'own_controller_ok'];

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
}
