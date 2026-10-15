<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/ForeignText.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MatterDiscovery.php';

/**
 * Ein Border Router, der seine Thread-Geräte ansagt, sich selbst aber nicht (build 95).
 *
 * Boui (t/144417/69–73, Dump per PN t/144641 vom 15.10.2026): Sein SLZB-MR5U (OpenThread,
 * 192.168.178.180) antwortete in jedem Lauf auf die gemeinsame Frage nach `_meshcop`,
 * `_matter` und `_matterc`, aber nur mit den `_matter`-Ansagen der Thread-Geräte, die er
 * stellvertretend ansagt. Seine eigene `_meshcop`-Ansage fehlte, der Bericht meldete
 * „Nur ein Thread Border Router“. `dns-sd -B _meshcop._udp` auf dem Mac sah ihn sofort.
 * Wer Geräte ohne LAN-Adresse ansagt und nicht selbst als Border Router bekannt ist, wird
 * deshalb direkt nach `_meshcop` gefragt.
 *
 * Fixture: die Zeilen „Border Router“ und „_matter._tcp (124)“ aus seinem Debug, unverändert.
 */

$lines = file(__DIR__ . '/fixtures/debug/boui_survey_2026-10-15.txt', FILE_IGNORE_NEW_LINES) ?: [];
assertSame(2, count($lines), 'Fixture: zwei Debug-Zeilen');

$entries = static function (string $line): array {
    preg_match_all('/"([^"\s]+) ← ([^,]+), Host ([^,]+), ([^,"]*)/u', $line, $found, PREG_SET_ORDER);

    return array_map(static function (array $match): array {
        [$host, $port] = array_pad(explode(':', $match[3], 2), 2, '');

        return [
            'instance'  => $match[1],
            'source'    => $match[2],
            'host'      => $host,
            'port'      => (int)$port,
            'addresses' => $match[4] === 'keine Adresse' ? [] : explode(' ', $match[4]),
        ];
    }, $found);
};
$borderRouters = $entries($lines[0]);
$devices       = $entries($lines[1]);
assertSame(1, count($borderRouters), 'Fixture: nur der HomePod als Border Router');
assertSame('192.168.178.148', $borderRouters[0]['source'] ?? null, 'Fixture: HomePod Wohnzimmer');
assertSame(124, count($devices), 'Fixture: 124 _matter-Ansagen');
assertSame(17, count(array_filter($devices, static fn(array $d): bool => $d['source'] === '192.168.178.180')), 'Fixture: der SLZB sagt 17 Ansagen an (5 Thread-Geräte)');

$hidden = static fn(array $devices, array $borderRouters): ?array => method_exists(MatterDiscovery::class, 'hiddenBorderRouters')
    ? MatterDiscovery::hiddenBorderRouters($devices, $borderRouters)
    : null;

assertSame(['192.168.178.180'], $hidden($devices, $borderRouters), 'Boui: der SLZB-MR5U wird direkt gefragt, sonst niemand');

// Gegenproben
$slzb = ['instance' => 'SLZB-MR5U._meshcop._udp.local', 'source' => '192.168.178.180', 'host' => 'SLZB-MR5U.local', 'port' => 0, 'addresses' => []];
assertSame([], $hidden($devices, [...$borderRouters, $slzb]), 'Ist der SLZB als Border Router bekannt, fragt niemand nach');
assertSame([], $hidden(array_values(array_filter($devices, static fn(array $d): bool => $d['source'] !== '192.168.178.180')), $borderRouters), 'Ohne seine Ansagen kein Verdacht');

// Ein Gerät, das selbst mit seiner IPv4 antwortet (Shelly, HA-Controller), ist kein Proxy
$self = array_values(array_filter($devices, static fn(array $d): bool => in_array($d['source'], $d['addresses'], true)));
assertTrue($self !== [], 'Fixture: es gibt Geräte, die selbst antworten');
assertSame([], $hidden($self, $borderRouters), 'Wer sich selbst ansagt, wird nicht gefragt');

// Eine IPv6-Quelle lässt sich nicht direkt fragen (Direktabfragen gehen an die IPv4)
$v6 = array_map(static fn(array $d): array => ['source' => 'fe80::e8f6:aff:fed2:8a00'] + $d, array_values(array_filter($devices, static fn(array $d): bool => $d['source'] === '192.168.178.180')));
assertSame([], $hidden($v6, $borderRouters), 'Nur IPv4-Quellen werden direkt gefragt');

// module.php fragt diese Quellen gezielt nach _meshcop
$modulePhp = (string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php');
assertTrue(str_contains($modulePhp, 'MatterDiscovery::hiddenBorderRouters('), 'module.php sucht versteckte Border Router');
assertTrue(preg_match('/hiddenBorderRouters\(.*?SERVICE_MESHCOP.*?\$target/s', $modulePhp) === 1, 'module.php fragt sie direkt nach _meshcop');
