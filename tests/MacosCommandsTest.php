<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php';
require_once __DIR__ . '/../MatterDiagnose/libs/RouteTable.php';
require_once __DIR__ . '/../MatterDiagnose/libs/RunBudget.php';

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
 *  - ping6_option_x_macos_reblade.txt: `ping6 -c 3 -X 8` (t/144609/14, ohne die
 *    Prompt-Zeilen): „invalid option -- X“ samt Hilfe.
 *
 * Die Befehle stammen aus Apples Quelltext (network_cmds-741.100.2): ping6 kennt kein
 * Zeitlimit, weder je Antwort (-W und -t sind dort Abfragearten) noch für den ganzen Lauf
 * (-X steht nur als toter Zweig im Code). Ohne Antwort wartet es eine Sekunde nach dem
 * letzten Paket fest 10 s. `ping` kann nur IPv4 und kennt -W in Millisekunden. Das Flag S
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
// Beta 1.2 #89 schickte ping6 -c n -X <s>. reblades ping6 lehnte -X ab (t/144609/14,
// ping6_option_x_macos_reblade.txt): In Apples Quelltext steht zwar ein Zweig case 'X',
// aber das X fehlt in der Optionsliste von getopt_long, in jeder Version seit 2010 bis
// network_cmds-741.100.2. Jede Option, die das Modul an ping6 übergibt, muss deshalb in
// der Hilfe stehen, die sein ping6 ausgibt.
$usage   = $fx('ping6_option_x_macos_reblade.txt');
$allowed = [];
foreach (preg_match_all('/\[-([A-Za-z]+)\]/', $usage, $flags) > 0 ? $flags[1] : [] as $group) {
    $allowed = array_merge($allowed, str_split($group));
}
foreach (preg_match_all('/\[-([A-Za-z]) \w+\]/', $usage, $options) > 0 ? $options[1] : [] as $option) {
    $allowed[] = $option;
}
assertTrue(in_array('c', $allowed, true) && !in_array('X', $allowed, true), 'Fixture: ping6 kennt -c, aber kein -X');
for ($n = 1; $n <= 3; $n++) {
    $command = OsAdapter::pingCommand($mac, '2003:f5:1f30:d3fe:8cb7:7140:1f0:54ba', $n, 2000);
    preg_match_all('/(?<=\s)-([A-Za-z])\b/', $command, $used);
    assertSame([], array_values(array_diff($used[1], $allowed)), "macOS: $command nutzt nur Optionen, die ping6 kennt");
}
assertSame(
    'ping6 -c 1 2003:f5:1f30:d3fe:8cb7:7140:1f0:54ba',
    OsAdapter::pingCommand($mac, '2003:f5:1f30:d3fe:8cb7:7140:1f0:54ba', 1, 2000),
    'macOS: ping6 mit einem Paket, ohne Zeitlimit'
);
assertSame(
    'ping -c 1 -W 1000 192.168.2.125',
    OsAdapter::pingCommand($mac, '192.168.2.125', 1, 1000),
    'macOS: IPv4-ping mit -W in Millisekunden'
);
assertSame(0, OsAdapter::parsePingReceived($fx('ping6_lost_macos_reblade.txt')), 'macOS: ping6 ohne Antwort');
assertSame(3, OsAdapter::parsePingReceived($fx('ping6_macos_reblade.txt')), 'macOS: ping6 mit drei Antworten');
// Ohne Zeitlimit läuft ping6 -c n so (ping6.c, Hauptschleife): ein Paket je Sekunde, eine
// Sekunde nach dem letzten wartet es fest MAXWAIT = 10 s; kommen alle Antworten, endet es
// sofort. Ohne Antwort also n + 10 s. Das schlafende Gerät beantwortete im Mitschnitt alle
// Pakete nach 4954 ms, wenn es beim Parent nachfragte; ein einziges Paket reicht, und es
// fällt in die Wartezeit.
preg_match_all('/time=([\d.]+) ms/', $fx('ping6_macos_reblade.txt'), $rtt);
$lastReply = max(array_map('floatval', $rtt[1])) / 1000;
assertTrue($lastReply > 4.9 && $lastReply < 1 + 10, 'macOS: die Antwort nach rund 5 s fällt in die Wartezeit eines einzelnen Pakets');
assertSame(12.0, OsAdapter::pingMinimumSeconds(2000, $mac), 'macOS: ein Paket kostet ohne Antwort 11 s plus 1 s Start');
assertSame(1, OsAdapter::pingAttempts(12.0, 2000, 5, $mac), 'macOS: mit 12 s Rest ein Paket');
assertSame(0, OsAdapter::pingAttempts(11.9, 2000, 5, $mac), 'macOS: mit weniger kein Ping');
for ($remaining = 4; $remaining <= 20; $remaining++) {
    $n = OsAdapter::pingAttempts((float)$remaining, 2000, 5, $mac);
    if ($n > 0) {
        assertTrue($n + 10 <= $remaining - 1, "macOS: bei $remaining s Rest passen $n Pakete ohne Antwort ins Budget");
    }
}

// Das Budget des Handlaufs wächst um das, was der Ping der Plattform über die Reserve
// hinaus braucht (24 s und 7 s Reserve aus module.php, 0,5 s Luft wie dort). Windows und
// Linux bleiben bei 24 s, der Wächterlauf pingt nicht und bleibt überall bei 24 s.
$budgetFor = static fn(string $platform, bool $pings): RunBudget => RunBudget::forRun(24.0, 7.0, 0.0, $pings, OsAdapter::pingMinimumSeconds(2000, $platform) + 0.5);
assertSame(29.5, $budgetFor($mac, true)->remaining(0.0), 'macOS: Handlauf 29,5 s');
assertTrue($budgetFor($mac, true)->phaseAllowed(0.0, 17.0) && !$budgetFor($mac, true)->phaseAllowed(0.0, 17.1), 'macOS: der Erhebung bleiben wie bisher 17 s, dem Ping 12,5 s');
assertSame(24.0, $budgetFor($mac, false)->remaining(0.0), 'macOS: Wächterlauf bleibt bei 24 s');
foreach ([OsAdapter::PLATFORM_WINDOWS, OsAdapter::PLATFORM_LINUX] as $platform) {
    assertSame(24.0, $budgetFor($platform, true)->remaining(0.0), "$platform: Handlauf bleibt bei 24 s");
    assertTrue($budgetFor($platform, true)->phaseAllowed(0.0, 17.0) && !$budgetFor($platform, true)->phaseAllowed(0.0, 17.1), "$platform: Reserve bleibt 7 s");
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
