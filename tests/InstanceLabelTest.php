<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

// Befunde nennen Geräte mit der Symcon-Instanz (build 76, Blindtest 05.10.2026): Der Agent fand
// „Shelly Plug S Gen3 (Id 7, 192.168.178.176, MAC …)" — welche Instanz das ist, musste er selbst
// über die NodeId herleiten, und am nuc heißt Knoten 7 „Velux Gateway (#10)", leicht mit „Id 10"
// zu verwechseln. Die Instanz-ID steht deshalb in der Beschriftung, sprachneutral als #ID.
// Die Kurzform der Momentaufnahme (plainLabel, „Name (Id 7)") bleibt, sonst griffe
// ChangeTracker::withoutCoveredDevices nicht mehr.

$eingabe = [
    'ipv6Addresses'         => ['fd86:6fd:53ed::1', 'fe80::1'],
    'mdnsResponses'         => true,
    'mdnsProbeResponders'   => 0,
    'borderRouters'         => [],
    'operationalDevices'    => [],
    'commissionableDevices' => [],
    'threadPrefixes'        => [],
    'platform'              => 'Windows',
    'controllerPresent'     => true,
    'ownFabricId'           => '90B99E147F5D9954',
    'devicesAmbiguous'      => false,
    'threadNetworks'        => null,
    'routeAssessment'       => null,
];
// Werte wie am nuc (ALPSTUGA, Knoten 13, Instanz #54334, fünf Systeme)
$alpstuga = ['nodeId' => 13, 'name' => 'ALPSTUGA air quality monitor', 'subscription' => 'OK', 'visible' => true, 'ambiguous' => false, 'endpointNames' => ['Luftqualitätssensor'], 'fabrics' => 5];

$voll = static function (array $geraet) use ($eingabe): ?array {
    foreach (DiagnosisEngine::evaluate($eingabe + ['knownDevices' => [$geraet]]) as $befund) {
        if ($befund['id'] === 'device_fabrics_full') {
            return $befund;
        }
    }

    return null;
};

$mitInstanz = $voll($alpstuga + ['instanceId' => 54334]);
assertSame('ALPSTUGA air quality monitor (Id 13, #54334) [Luftqualitätssensor]', $mitInstanz['params']['devices'] ?? null, 'Kein Platz mehr frei: Befundtext nennt die Instanz');
assertSame(['ALPSTUGA air quality monitor (Id 13)'], $mitInstanz['devices'] ?? null, 'Kein Platz mehr frei: Momentaufnahme behält die Kurzform');

$ohneInstanz = $voll($alpstuga);
assertSame('ALPSTUGA air quality monitor (Id 13) [Luftqualitätssensor]', $ohneInstanz['params']['devices'] ?? null, 'Ohne bekannte Instanz bleibt die Beschriftung wie bisher');

// Die Beschriftung des Moduls (eigene Geräte: nicht erreichbar, melden sich nicht an …)
$instanz     = neueInstanz();
$beschriften = static fn (array $geraet): string => (new ReflectionMethod(MatterDiagnose::class, 'deviceLabel'))->invoke($instanz, $geraet);

assertSame(
    'Shelly Dimmer Gen4 (Id 10, #20390)',
    $beschriften(['nodeId' => 10, 'name' => 'Shelly Dimmer Gen4', 'visible' => true, 'instanceId' => 20390]),
    'Sichtbares eigenes Gerät: Knoten und Instanz'
);
assertSame(
    'Shelly Plug S Gen3 (Id 7, #' . $instanz->id() . ', 192.168.178.176, MAC D0:CF:13:CA:74:30)',
    $beschriften([
        'nodeId'         => 7,
        'name'           => 'Shelly Plug S Gen3',
        'visible'        => false,
        'instanceId'     => $instanz->id(), // eine Instanz, die der Stub kennt; ihre Variablen sind nie aktualisiert
        'host'           => 'D0CF13CA7430.local',
        'aliveAddresses' => ['192.168.178.176'],
    ]),
    'Vermisstes eigenes Gerät: Knoten, Instanz, dann Adresse und MAC'
);
assertSame(
    'Gerät (Id 3)',
    $beschriften(['nodeId' => 3, 'name' => 'Gerät', 'visible' => true]),
    'Ohne Instanz bleibt die Beschriftung wie bisher'
);
