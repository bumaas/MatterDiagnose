<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceInventory.php';

/**
 * Eine Ansage ohne aufgelösten Host ist keine Gerätezeile (build 82).
 *
 * Bei Loerdy blieben in einem Lauf 35 Ansagen hinter dem Apple TV ohne SRV, also ohne
 * Hostnamen und Adresse. Ohne Host fehlte der Schlüssel, der die Ansagen eines Geräts in
 * mehreren Systemen zusammenführt: Jede stand als eigene Zeile „?“ in der Liste, ein
 * Fenstergriff in drei Systemen dreimal (PN t/144583/2, Screenshot 05.10.2026 19:32).
 *
 * Instanznamen aus seinem Debug-Auszug des folgenden Laufs (matter_dump.txt, 20:14); ohne
 * Host und Adressen ist das der Stand, den eine PTR-Antwort ohne SRV hinterlässt.
 */

$borderRouters = [
    ['name' => 'Loerdy-TV', 'source' => '192.168.29.181', 'txt' => ['vn' => 'Apple']],
];
$aufgeloest = ['instance' => '3628602A9BDB6A74-000000000000001A._matter._tcp.local', 'host' => 'B6C381539EE5DA1C.local', 'port' => 5540, 'addresses' => ['fd03:8db1:5779:1:d40:bd7f:d4e5:989f'], 'source' => '192.168.29.181', 'sleepy' => null];
$ohneHost   = [
    ['instance' => '882E969DEEF0B04E-00000000554F869F._matter._tcp.local', 'host' => '', 'addresses' => [], 'source' => '192.168.29.181', 'sleepy' => null],
    ['instance' => 'AEE5CCA00477E768-000000000F249719._matter._tcp.local', 'host' => '', 'addresses' => [], 'source' => '192.168.29.181', 'sleepy' => null],
    ['instance' => '470DBE3477759BC0-0000000000000024._matter._tcp.local', 'host' => '', 'addresses' => [], 'source' => '192.168.29.181', 'sleepy' => null],
];
$known = [['nodeId' => 26, 'name' => 'Smart window handle', 'sleepy' => false]];

$rows = DeviceInventory::build(array_merge([$aufgeloest], $ohneHost), $borderRouters, $known, ['3628602A9BDB6A74']);
assertSame(1, count($rows), 'Nur der aufgelöste Fenstergriff steht in der Liste, keine Zeile „?“');
assertSame('Smart window handle', $rows[0]['name'] ?? null, 'Name aus Symcon');

// Eine eigene Ansage ohne Host behält ihre Zeile: Symcon kennt den Namen
$eigeneOhneHost = ['instance' => '3628602A9BDB6A74-000000000000001A._matter._tcp.local', 'host' => '', 'addresses' => [], 'source' => '192.168.29.181', 'sleepy' => null];
$rows           = DeviceInventory::build(array_merge([$eigeneOhneHost], $ohneHost), $borderRouters, $known, ['3628602A9BDB6A74']);
assertSame(1, count($rows), 'Eigenes Gerät ohne Host bleibt sichtbar, fremde ohne Host nicht');
assertSame('Smart window handle', $rows[0]['name'] ?? null, 'Eigenes Gerät mit Symcon-Namen');
