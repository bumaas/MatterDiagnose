<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php';
require_once __DIR__ . '/../MatterDiagnose/libs/RouteTable.php';

/**
 * Linux-Multipath-Route (Alexandro, PN t/144583/17, 09.10.2026): Zwei Border Router
 * (Apple TV und Aqara Hub M3) sagen dasselbe Thread-Präfix an, der Kernel führt eine
 * Route mit zwei Next Hops. Die Kopfzeile trägt kein „dev“, Gateway und Schnittstelle
 * stehen erst in den eingerückten nexthop-Zeilen. Der Parser verwarf die Route, der
 * Wächterlauf meldete thread_prefix_unreachable samt Routenbefehl, obwohl die Route per
 * RA gelernt war und der Ping im selben Lauf 5 von 5 Antworten bekam.
 *
 * Fixture: die Routentabelle aus seinem Debug-Auszug (Lauf 11:00:20), <LF>/<HT> zurück
 * in Zeilenumbruch und Tab.
 */

$output = (string)file_get_contents(__DIR__ . '/fixtures/os/route_linux_multipath_alexandro.txt');
$routes = RouteTable::parse(OsAdapter::PLATFORM_LINUX, $output);

$thread = array_values(array_filter($routes, static fn(array $r): bool => $r['prefix'] === 'fd24:eaa2:e48b:1::' && $r['length'] === 64));
assertSame(2, count($thread), 'Multipath: je Next Hop eine Route zum Thread-Präfix');
assertSame(
    ['fe80::c1e:55c2:5bec:80f5', 'fe80::1ac2:3cff:fe5b:1f9c'],
    array_column($thread, 'gateway'),
    'Multipath: beide Gateways aus den nexthop-Zeilen'
);
assertSame(['eth0', 'eth0'], array_column($thread, 'interface'), 'Multipath: Schnittstelle aus den nexthop-Zeilen');
assertSame([true, true], array_column($thread, 'learned'), 'Multipath: „proto ra“ der Kopfzeile gilt für jeden Next Hop');
assertSame([1667, 1667], array_column($thread, 'validLifetime'), 'Multipath: Ablaufzeit der Kopfzeile gilt für jeden Next Hop');

// Die übrigen Routen bleiben, wie sie waren: 2 ULA, 7 × fe80::/64, die Default-Route nicht
assertSame(11, count($routes), 'Multipath: 2 Thread-Routen + 9 einfache Routen');
assertSame('fd45:635c:3aa9:4e33::', $routes[2]['prefix'], 'Multipath: die Zeile nach den Next Hops wird wieder als eigene Route gelesen');

assertSame(true, RouteTable::hasRouteFor($routes, 'fd24:eaa2:e48b:1::'), 'Multipath: Route zum Thread-Präfix erkannt');

$assessment = RouteTable::assess(
    $routes,
    null,
    ['fd24:eaa2:e48b:1::'],
    ['fe80::c1e:55c2:5bec:80f5', 'fe80::1ac2:3cff:fe5b:1f9c'],
    ['fd45:635c:3aa9:4e33:be24:11ff:fee4:a82', 'fda0:bbb3:5ecf:0:be24:11ff:fee4:a82'],
    OsAdapter::PLATFORM_LINUX
);
assertSame(2, count($assessment['learned']), 'Multipath: beide Wege als gelernt bewertet');
assertSame([], $assessment['stale'], 'Multipath: kein Löschrat');
assertSame([], $assessment['gatewayUnknown'], 'Multipath: kein unbekanntes Gateway');

// Eine nexthop-Zeile ohne vorausgehende Kopfzeile ergibt keine Route
assertSame([], RouteTable::parse(OsAdapter::PLATFORM_LINUX, "\tnexthop via fe80::1 dev eth0 weight 1\n"), 'nexthop ohne Kopfzeile: keine Route');
// Die Default-Route als Multipath bleibt draußen wie die einfache
assertSame([], RouteTable::parse(OsAdapter::PLATFORM_LINUX, "default proto ra metric 1024\n\tnexthop via fe80::1 dev eth0 weight 1\n\tnexthop via fe80::2 dev eth0 weight 1\n"), 'Multipath-Default-Route: keine Route');
