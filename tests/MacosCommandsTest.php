<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php';
require_once __DIR__ . '/../MatterDiagnose/libs/RouteTable.php';

/**
 * Systembefehle unter macOS (reblade, PN t/144609, 10./11.10.2026). Seit build 87 galt
 * macOS als „Other“ ohne Routen und Ping, weil echte Mitschnitte fehlten. reblade hat sie
 * von seinem Mac mini geliefert:
 *
 *  - route_macos_netstat_reblade.txt: `netstat -rn -f inet6` (t/144609/3). Die Routen ins
 *    Thread-Netz stehen als „UGc“ über ein Link-Local mit Zonenangabe (`fe80::…%en1`),
 *    on-link-Netze als „link#7“, Host-Einträge ohne Präfixlänge, VPN-Defaults über utun
 *    mit dem Flag I (RTF_IFSCOPE).
 *  - ping6_lost_macos_reblade.txt: `ping6 -c 3` auf eine Adresse, deren Präfix es nach
 *    einem Präfixwechsel des Anschlusses nicht mehr gab (t/144609/3).
 *  - ping6_macos_reblade.txt: `ping6 -c 3` auf ein schlafendes Thread-Gerät (t/144609/10),
 *    alle drei Antworten kamen zusammen nach rund 5 s.
 *
 * Die Befehle stammen aus Apples Quelltext (network_cmds-741.100.2): ping6 kennt kein
 * Zeitlimit je Antwort (-W ist dort eine Abfrageart), das Limit für den ganzen Lauf setzt
 * -X <Sekunden> (seit network_cmds-543, in der Manpage nicht beschrieben); ohne -X wartet
 * ping6 nach dem letzten Paket 10 s. Der IPv4-ping kennt -W in Millisekunden. Das Flag S
 * (RTF_STATIC) heißt laut netstat(1) „Manually added“, und `route add` setzt es selbst;
 * eine Route ohne S hat das System selbst angelegt.
 */

$fx = static fn(string $name): string => (string)file_get_contents(__DIR__ . '/fixtures/os/' . $name);

// --- Plattform ---------------------------------------------------------------------
assertTrue(defined('OsAdapter::PLATFORM_MACOS'), 'OsAdapter::PLATFORM_MACOS vorhanden');
$mac = defined('OsAdapter::PLATFORM_MACOS') ? (string)constant('OsAdapter::PLATFORM_MACOS') : 'Darwin';
assertSame($mac, OsAdapter::platform('Darwin'), 'PHP_OS_FAMILY Darwin ist macOS');
assertSame(null, OsAdapter::systemCommandsUnavailable($mac, true), 'macOS mit Shell: Systembefehle verfügbar');
assertSame('shell', OsAdapter::systemCommandsUnavailable($mac, false), 'macOS ohne shell_exec: Grund shell');
foreach (['BSD', 'Solaris', 'Unknown'] as $family) {
    assertSame(OsAdapter::PLATFORM_OTHER, OsAdapter::platform($family), "PHP_OS_FAMILY $family bleibt ohne Befehle");
}

// --- Routentabelle -----------------------------------------------------------------
assertSame('netstat -rn -f inet6', OsAdapter::routeShowCommand($mac), 'macOS: Routen über netstat');

$netstat = $fx('route_macos_netstat_reblade.txt');
$routes  = RouteTable::parse($mac, $netstat);
$find    = static function (array $routes, string $prefix, int $length): array {
    return array_values(array_filter($routes, static fn(array $r): bool => $r['prefix'] === $prefix && $r['length'] === $length));
};

$amazon = $find($routes, 'fd2a:692a:52d3:1::', 64);
assertSame(1, count($amazon), 'macOS: Route ins Thread-Netz des Amazon-Routers gelesen');
assertSame('fe80::8628:59ff:fefe:fc86', $amazon[0]['gateway'] ?? null, 'macOS: Gateway ohne Zonenangabe %en1');
assertSame('en1', $amazon[0]['interface'] ?? null, 'macOS: Schnittstelle aus der Spalte Netif');
assertSame(true, $amazon[0]['learned'] ?? null, 'macOS: Route ohne Flag S hat das System selbst gelernt');
assertSame(null, array_key_exists('validLifetime', $amazon[0] ?? []) ? $amazon[0]['validLifetime'] : 'fehlt', 'macOS: keine Restlaufzeit (Spalte Expire leer)');

$aqara = $find($routes, '2003:f5:1f30:d3fe::', 64);
assertSame(1, count($aqara), 'macOS: globales Thread-Präfix des Aqara Hub M3 gelesen');
assertSame('fe80::1ac2:3cff:fe6d:cf20', $aqara[0]['gateway'] ?? null, 'macOS: Gateway Aqara Hub M3');

$onLink = $find($routes, '2003:f5:1f30:d300::', 64);
assertSame(1, count($onLink), 'macOS: on-link-Netz gelesen');
assertSame(null, array_key_exists('gateway', $onLink[0] ?? []) ? $onLink[0]['gateway'] : 'fehlt', 'macOS: „link#7“ ist kein Gateway');
assertSame(1, count($find($routes, '2003:f5:1f30:d300::', 56)), 'macOS: das /56 über den Heimrouter steht daneben');

$linkLocal = $find($routes, 'fe80::', 64);
assertTrue(in_array('en1', array_column($linkLocal, 'interface'), true), 'macOS: fe80::%en1/64 ohne Zone im Präfix gelesen');

$prefixes = array_column($routes, 'prefix');
assertTrue(!in_array('::', $prefixes, true), 'macOS: Default-Routen bleiben draußen');
assertTrue(!in_array('fd0f:2c7b:1ecf:0:2a:3404:47d9:deac', $prefixes, true), 'macOS: Host-Einträge ohne Präfixlänge bleiben draußen');
assertTrue(!in_array('::1', $prefixes, true), 'macOS: ::1 ohne Präfixlänge bleibt draußen');

assertSame(true, RouteTable::hasRouteFor($routes, '2003:f5:1f30:d3fe::'), 'macOS: Route zum aktuellen Thread-Präfix erkannt');
assertSame(true, RouteTable::hasRouteFor($routes, 'fd2a:692a:52d3:1::'), 'macOS: Route zum ULA-Thread-Präfix erkannt');
assertSame(false, RouteTable::hasRouteFor($routes, '2003:f5:1f2d:b5fe::'), 'macOS: das Präfix vor dem Wechsel hat keine Route mehr');

assertSame('en1', RouteTable::interfaceForAddresses($routes, ['2003:f5:1f30:d300:ec99:f1d4:f97a:2746']), 'macOS: eigene Adresse liegt an en1');
// Nur Link-Local: fe80::/64 steht an en6 (iBridge), ap1 und en1. Die eigene Adresse kommt
// ohne Zone an, welche Schnittstelle gemeint ist, lässt sich nicht sagen (Code-Review
// 11.10.2026: vorher kam en6, die erste der Tabelle).
assertSame(null, RouteTable::interfaceForAddresses($routes, ['fe80::402:2e82:5ce7:669f']), 'macOS: Link-Local an drei Schnittstellen ist keine Zuordnung');
foreach (['route_linux_ip_testbox.txt' => [OsAdapter::PLATFORM_LINUX, 'eth0'], 'route_windows_active_nuc.txt' => [OsAdapter::PLATFORM_WINDOWS, '12']] as $file => [$platform, $expected]) {
    assertSame($expected, RouteTable::interfaceForAddresses(RouteTable::parse($platform, $fx($file)), ['fe80::3524:11bc:5bd7:121e']), "$file: Link-Local an einer einzigen Schnittstelle bleibt Rückfall");
}

// Gelernte Routen sind kein Löschfall, auch wenn diese Runde das Gateway nicht kennt
$assessment = RouteTable::assess($routes, null, ['2003:f5:1f30:d3fe::', 'fd2a:692a:52d3:1::'], [], ['2003:f5:1f30:d300:ec99:f1d4:f97a:2746'], $mac);
assertSame([], $assessment['stale'], 'macOS: keine gelernte Route gilt als veraltet');
assertSame([], $assessment['gatewayUnknown'], 'macOS: keine gelernte Route mit unbekanntem Gateway');
assertSame([], $assessment['notPersistent'], 'macOS: Persistenz ist ein Windows-Befund');
assertTrue(count($assessment['learned']) >= 2, 'macOS: Thread-Routen als gelernt bewertet');

// Von Hand gesetzt (Flag S): dann gilt die volle Bewertung wie unter Linux
$manual = RouteTable::parse($mac, "fd2a:692a:52d3:1::/64                   fe80::8628:59ff:fefe:fc86%en1           UGSc                  en1\n");
assertSame(false, $manual[0]['learned'] ?? null, 'macOS: Flag S heißt von Hand gesetzt');

// --- unbekannt statt leer ----------------------------------------------------------
assertTrue(RouteTable::fromSystem($mac, $netstat, null) !== null, 'macOS: echte Tabelle ist bekannt');
assertSame(null, RouteTable::fromSystem($mac, null, null), 'macOS: ohne Shell unbekannt');
assertSame(null, RouteTable::fromSystem($mac, '', null), 'macOS: leere Ausgabe unbekannt');
assertSame(null, RouteTable::fromSystem($mac, "sh: netstat: command not found\n", null), 'macOS: fehlendes netstat unbekannt');
assertSame(null, RouteTable::fromSystem($mac, "netstat: illegal option -- x\n", null), 'macOS: Fehlermeldung ist keine leere Tabelle');

// --- Schnittstelle für mDNS über IPv6 ----------------------------------------------
// Bewusst keine Schnittstelle: mDNS bleibt unter macOS beim IPv4-Weg ohne Bindung, der in
// beiden Dumps lief; für IPv6-Multicast vom Mac gibt es keinen Mitschnitt.
assertSame(null, OsAdapter::defaultRouteInterface($mac, $netstat), 'macOS: mDNS ohne Schnittstelle, wie bis build 88');

// --- Ping --------------------------------------------------------------------------
assertSame(
    'ping6 -c 3 -X 8 2003:f5:1f30:d3fe:8cb7:7140:1f0:54ba',
    OsAdapter::pingCommand($mac, '2003:f5:1f30:d3fe:8cb7:7140:1f0:54ba', 3, 2000),
    'macOS: ping6 mit Gesamtlimit -X in Sekunden'
);
assertSame(
    'ping -c 1 -W 1000 192.168.2.125',
    OsAdapter::pingCommand($mac, '192.168.2.125', 1, 1000),
    'macOS: IPv4-ping mit -W in Millisekunden'
);
assertSame(0, OsAdapter::parsePingReceived($fx('ping6_lost_macos_reblade.txt')), 'macOS: ping6 ohne Antwort');
assertSame(3, OsAdapter::parsePingReceived($fx('ping6_macos_reblade.txt')), 'macOS: ping6 mit drei Antworten');
// Das schlafende Gerät beantwortet alle Pakete erst, wenn es beim Parent nachfragt: im
// Mitschnitt 4954 ms nach dem ersten Paket. -X zählt ab dem Start, also muss jedes Limit,
// das der Lauf vergibt, darüber liegen (Code-Review 11.10.2026: mit (n−1)+T waren es 3
// bzw. 4 s, ping6 brach vor den Antworten ab). Und das Zeitbudget muss das Limit kennen.
preg_match_all('/time=([\d.]+) ms/', $fx('ping6_macos_reblade.txt'), $rtt);
$lastReply = max(array_map('floatval', $rtt[1])) / 1000;
assertTrue($lastReply > 4.9, 'Fixture: letzte Antwort nach rund 5 s');
$limitFor = static function (int $attempts) use ($mac): int {
    preg_match('/-X (\d+)/', OsAdapter::pingCommand($mac, '2003:f5:1f30:d3fe:8cb7:7140:1f0:54ba', $attempts, 2000), $x);

    return (int)($x[1] ?? 0);
};
for ($n = 2; $n <= 5; $n++) {
    assertTrue($limitFor($n) > $lastReply, "macOS: $n Versuche, -X {$limitFor($n)} wartet die Antwort nach $lastReply s ab");
}
for ($remaining = 4; $remaining <= 20; $remaining++) {
    $n = OsAdapter::pingAttempts((float)$remaining, 2000, 5, $mac);
    if ($n > 0) {
        assertTrue($limitFor($n) <= $remaining - 1, "macOS: bei $remaining s Rest passt -X {$limitFor($n)} für $n Versuche ins Budget");
    }
}

// --- Befehle zum Kopieren ----------------------------------------------------------
assertSame(
    'route add -inet6 2003:f5:1f30:d3fe::/64 fe80::1ac2:3cff:fe6d:cf20%en1',
    OsAdapter::routeAddCommand($mac, '2003:f5:1f30:d3fe::', 'fe80::1ac2:3cff:fe6d:cf20', 'en1'),
    'macOS: Route setzen, Link-Local mit Zone'
);
assertSame(
    'route add -inet6 2003:f5:1f30:d3fe::/64 <Gateway-Adresse des Border-Routers>',
    OsAdapter::routeAddCommand($mac, '2003:f5:1f30:d3fe::', null, 'en1'),
    'macOS: ohne Gateway der Platzhalter ohne Zone'
);
// Die Zone gehört nur an ein Link-Local. Hat der Border Router keins, nimmt
// prefixGateways seine ULA; „fd0f:…%en1“ lehnt route als ungültige Adresse ab.
assertSame(
    'route add -inet6 fd2a:692a:52d3:1::/64 fd0f:2c7b:1ecf:0:9a9b:cbff:fec7:d1f3',
    OsAdapter::routeAddCommand($mac, 'fd2a:692a:52d3:1::', 'fd0f:2c7b:1ecf:0:9a9b:cbff:fec7:d1f3', 'en1'),
    'macOS: Route setzen, ULA-Gateway ohne Zone'
);
// Löschen mit Gateway: Ohne trifft route delete die erste Route zum Präfix, das kann die
// funktionierende über den aktuellen Border Router sein (Code-Review 11.10.2026).
assertSame(
    'route delete -inet6 fd2a:692a:52d3:1::/64 fe80::8628:59ff:fefe:fc86%en1',
    OsAdapter::routeDeleteCommand($mac, 'fd2a:692a:52d3:1::', 64, 'fe80::8628:59ff:fefe:fc86', 'en1'),
    'macOS: Route löschen, mit Gateway und Zone'
);
assertSame(
    'route delete -inet6 fd2a:692a:52d3:1::/64 fd0f:2c7b:1ecf:0:9a9b:cbff:fec7:d1f3',
    OsAdapter::routeDeleteCommand($mac, 'fd2a:692a:52d3:1::', 64, 'fd0f:2c7b:1ecf:0:9a9b:cbff:fec7:d1f3', 'en1'),
    'macOS: Route löschen, ULA-Gateway ohne Zone'
);
assertSame(
    'route delete -inet6 fd2a:692a:52d3:1::/64',
    OsAdapter::routeDeleteCommand($mac, 'fd2a:692a:52d3:1::', 64, null, 'en1'),
    'macOS: Route löschen ohne bekanntes Gateway'
);
