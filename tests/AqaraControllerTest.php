<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ThreadNetwork.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MatterDiscovery.php';
require_once __DIR__ . '/../MatterDiagnose/libs/SymconInventory.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceInventory.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceIdentity.php';

/**
 * Der zweite Knoten des Aqara Hub M3 ist sein Controller (build 85).
 *
 * In Alexandros Lauf (PN t/144583/7) stand der Hub zweimal in der Geräteliste. Er sagt
 * sich als Gerät an (`54EF44803311.local:5540`, Systeme A und B) und ein zweites Mal unter
 * anderem Hostnamen auf einem anderen Port (`54EF448033110000.local:5552`), mit genau
 * denselben Adressen, nur in System D. In D stehen außerdem das FP300 (Id 49) und weitere
 * Thread-Geräte mit Aqara-typischen Knotennummern (`03003BFE3B91D400` …). D ist also das
 * Matter-System der Aqara-App, und der zweite Knoten ist der Hub in seiner Rolle als
 * Controller dieses Systems, wie bei der DIRIGERA auf Port 5541 (build 70). Die Erkennung
 * verlangte bisher denselben Hostnamen.
 *
 * Fixture: tests/fixtures/debug/alexandro_2026-10-06.json, aus seinem Debug-Auszug gezogen.
 */

$lauf          = json_decode((string)file_get_contents(__DIR__ . '/fixtures/debug/alexandro_2026-10-06.json'), true, 512, JSON_THROW_ON_ERROR);
$borderRouters = $lauf['borderRouters'];
$operational   = MatterDiscovery::markControllers($lauf['operational'], $borderRouters);

// Wert eines Schlüssels, null bleibt null; fehlt der Schlüssel, „(fehlt)“
$wert = static fn(array $a, string $k): ?string => array_key_exists($k, $a) ? $a[$k] : '(fehlt)';

$rolle = [];
foreach ($operational as $device) {
    $rolle[strtoupper((string)$device['instance'])] = $device['controller'] ?? null;
}
$hubController = 'FF5B69A22FEB6649-14908A0AB124F000._MATTER._TCP.LOCAL';

assertSame('second_node', $wert($rolle, $hubController), 'Hub auf Port 5552: Controller des Aqara-Systems');
assertSame(null, $wert($rolle, '791A6C70F25D4788-000000006B04903D._MATTER._TCP.LOCAL'), 'Hub auf Port 5540: bleibt ein Gerät');
assertSame(null, $wert($rolle, 'FF5B69A22FEB6649-03003BFE3B91D400._MATTER._TCP.LOCAL'), 'FP300 im Aqara-System: ein Gerät');
assertSame('sdk_default', $wert($rolle, '78634DF3064AF467-000000000001B669._MATTER._TCP.LOCAL'), 'Matter-Server auf .35 bleibt Controller');

// Ohne Border-Router-Beleg kein Urteil, wie bei der DIRIGERA
foreach (MatterDiscovery::markControllers($lauf['operational'], []) as $device) {
    if (strtoupper((string)$device['instance']) === $hubController) {
        assertSame(null, $device['controller'] ?? null, 'Ohne Border Router: kein Urteil über den zweiten Knoten');
    }
}

// Geräteliste: der Controller-Knoten ist gekennzeichnet, kein gebrücktes Gerät des Hubs
$rows = DeviceInventory::build($operational, $borderRouters, [], ['5D27ECA641088A00']);
$hub  = [];
foreach ($rows as $row) {
    if (str_starts_with(strtolower($row['host']), '54ef4480331')) {
        $hub[strtolower($row['host'])] = $row;
    }
}
assertSame('second_node', $wert($hub['54ef448033110000.local'] ?? [], 'controller'), 'Liste: zweiter Knoten als Controller');
assertSame(null, $wert($hub['54ef44803311.local'] ?? [], 'controller'), 'Liste: Hub selbst bleibt Gerät');
assertSame('', $wert($hub['54ef448033110000.local'] ?? [], 'bridgedBy'), 'Der Controller ist kein gebrücktes Gerät des Hubs');
