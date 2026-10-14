<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/ForeignText.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ThreadNetwork.php';

/**
 * OpenThread schreibt den Active Timestamp ohne Verschiebung (Boui, t/144417/59–64,
 * Dump per PN t/144641 vom 13.10.2026). Sein SLZB-MR5U und sein HomePod mini „Wohnzimmer“
 * spannen ein einziges, einwandfrei laufendes Netz auf, trotzdem kam thread_dataset_mismatch.
 * Das Debug aus build 91 zeigt die Bytes: Apple `at=000066E89C300000` (Sekunden in den
 * oberen 48 Bit, Ticks und U-Bit in den unteren 16, wie die Spezifikation es will),
 * OpenThread `at=0000000066E89C30` (die Sekunden roh). Derselbe Zeitstempel, um 16 Bit
 * verschoben. Gleich ist nur genau diese Verschiebung; eine Regel „führende Nullbytes
 * weglassen“ hielte auch echt verschiedene Werte für gleich.
 *
 * Fixture: die Zeile „Border Router“ aus seinem Debug, unverändert (nur die HEX-Zeile weggelassen).
 */

$line = (string)file_get_contents(__DIR__ . '/fixtures/debug/boui_border_router_2026-10-13.txt');
preg_match_all('/"([^"\s]+)\._meshcop\._udp\.local .*?\(([^)]*)\)"/u', $line, $matches, PREG_SET_ORDER);
assertSame(2, count($matches), 'Fixture: zwei Border Router in der Debug-Zeile');

$routers = [];
foreach ($matches as $match) {
    $txt = [];
    foreach (explode(' ', $match[2]) as $pair) {
        [$key, $value] = explode('=', $pair, 2);
        $txt[$key] = in_array($key, ['xp', 'pt', 'at', 'sb'], true) ? (string)hex2bin($value) : $value;
    }
    $routers[$match[1]] = ['name' => $match[1], 'addresses' => [], 'txt' => $txt];
}
assertSame('0000000066E89C30', strtoupper(bin2hex($routers['SLZB-MR5U']['txt']['at'] ?? '')), 'Fixture: OpenThread-Zeitstempel roh');
assertSame('000066E89C300000', strtoupper(bin2hex($routers['Wohnzimmer']['txt']['at'] ?? '')), 'Fixture: Apple-Zeitstempel nach Spezifikation');

$networks = ThreadNetwork::assess(array_values($routers))['networks'];
assertSame(1, count($networks), 'Boui: ein Netz');
assertSame(1, count($networks[0]['partitions']), 'Boui: eine Partition');
assertSame(1, count($networks[0]['timestamps']), 'Boui: derselbe Zeitstempel, nur anders kodiert');

$reversed = ThreadNetwork::assess(array_reverse(array_values($routers)))['networks'];
assertSame(1, count($reversed[0]['timestamps']), 'Boui: Reihenfolge der Router egal');

// Gegenproben: Was wirklich abweicht, bleibt verschieden
$timestamps = static function (string $slzbAt, string $appleAt) use ($routers): int {
    $slzb                = $routers['SLZB-MR5U'];
    $slzb['txt']['at']   = (string)hex2bin($slzbAt);
    $apple               = $routers['Wohnzimmer'];
    $apple['txt']['at']  = (string)hex2bin($appleAt);

    return count(ThreadNetwork::assess([$slzb, $apple])['networks'][0]['timestamps']);
};
assertSame(2, $timestamps('0000000066E89C31', '000066E89C300000'), 'Gegenprobe: eine Sekunde später bleibt verschieden');
assertSame(2, $timestamps('00000066E89C3000', '000066E89C300000'), 'Gegenprobe: um 8 Bit verschoben bleibt verschieden');
assertSame(2, $timestamps('0000000066E89C30', '000066E89C300001'), 'Gegenprobe: mit gesetztem U-Bit ist es ein anderer Wert');
assertSame(2, $timestamps('0000000000000001', '0000000000000100'), 'Gegenprobe: 1 und 256 sind verschieden');
assertSame(1, $timestamps('0000000000000001', '0000000000010000'), 'Eine Sekunde in beiden Formen ist gleich');
