<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php';
require_once __DIR__ . '/../MatterDiagnose/libs/RouteTable.php';

/**
 * Symcon im Container (build 59, Forum t/142087/1140): reblades Docker-Container auf
 * einer Synology hat weder `ip` noch `ping`, und der Kernel kennt keine Route
 * Information (RFC 4191). Das Modul las daraus eine leere Routentabelle — im
 * Wächterlauf wurde „keine Route" zum roten „Thread-Netz nicht erreichbar" —, schob
 * den ausgefallenen Ping aufs Zeitbudget und schwieg zur fehlenden Einstellung.
 *
 * Fixtures: route_linux_proc_testbox.txt und route_linux_ip_testbox.txt sind dieselbe
 * Tabelle der Testbox, im selben Lauf gelesen (21.09.2026). Die cmd_missing_*.txt
 * sind echte Meldungen: dash (reblades Debug-Auszug), BusyBox-sh (Testbox), bash (PC).
 */

$fx = static fn(string $name): string => (string)file_get_contents(__DIR__ . '/fixtures/os/' . $name);

// --- Fehlendes Programm erkennen ---------------------------------------------
if (!method_exists(OsAdapter::class, 'commandMissing')) {
    assertTrue(false, 'OsAdapter::commandMissing fehlt');
} else {
    assertTrue(OsAdapter::commandMissing($fx('cmd_missing_dash.txt')), 'dash: „sh: 1: ip: not found" heißt: Programm fehlt');
    assertTrue(OsAdapter::commandMissing($fx('cmd_missing_busybox.txt')), 'BusyBox: „sh: iplos: not found" heißt: Programm fehlt');
    assertTrue(OsAdapter::commandMissing($fx('cmd_missing_bash.txt')), 'bash: „command not found" heißt: Programm fehlt');
    foreach (['route_linux.txt', 'route_linux_ip_testbox.txt', 'route_linux_symbox_busybox.txt', 'ping_busybox.txt', 'ping_unreachable_de.txt', 'route_windows_active_nuc.txt'] as $normal) {
        assertTrue(!OsAdapter::commandMissing($fx($normal)), 'Gewöhnliche Ausgabe ' . $normal . ' ist kein fehlendes Programm');
    }
    assertTrue(!OsAdapter::commandMissing(''), 'Leere Ausgabe beweist kein fehlendes Programm');
}

// --- Routentabelle aus /proc/net/ipv6_route ------------------------------------
if (!method_exists(RouteTable::class, 'parseProcIpv6Route')) {
    assertTrue(false, 'RouteTable::parseProcIpv6Route fehlt');
} else {
    $proc = RouteTable::parseProcIpv6Route($fx('route_linux_proc_testbox.txt'));
    $ip   = RouteTable::parse(OsAdapter::PLATFORM_LINUX, $fx('route_linux_ip_testbox.txt'));
    assertSame(7, count($ip), 'Testbox: ip -6 route liefert 7 Routen (ohne default)');
    assertSame($ip, $proc, '/proc/net/ipv6_route ergibt dieselben Routen wie ip -6 route');

    $thread = array_values(array_filter(
        $proc,
        static fn(array $r): bool => $r['prefix'] === 'fd89:6b7:bc55::' && $r['gateway'] === 'fe80::8f7:24ce:93c4:8920'
    ));
    assertSame(1, count($thread), '/proc: Thread-Route fd89:6b7:bc55::/64 über den Border Router gefunden');
    assertSame(64, $thread[0]['length'] ?? null, '/proc: Präfixlänge aus dem Hex-Feld');
    assertSame('eth0', $thread[0]['interface'] ?? null, '/proc: Schnittstelle aus der letzten Spalte');
    assertSame(true, $thread[0]['learned'] ?? null, '/proc: per Router Advertisement gelernt (RTF_ROUTEINFO)');

    $prefixes = array_map(static fn(array $r): string => $r['prefix'] . '/' . $r['length'], $proc);
    assertTrue(!in_array('::1/128', $prefixes, true), '/proc: lokale Adressen sind keine Routen');
    assertTrue(!in_array('ff00::/8', $prefixes, true), '/proc: Multicast gehört nicht zur Routentabelle');
    assertTrue(RouteTable::hasRouteFor($proc, 'fd89:6b7:bc55::'), '/proc: Route ins Thread-Netz wird erkannt');
    assertSame([], RouteTable::parseProcIpv6Route(''), '/proc leer: keine Routen');
}

// --- Welche Quelle gilt? ---------------------------------------------------------
if (!method_exists(RouteTable::class, 'fromSystem')) {
    assertTrue(false, 'RouteTable::fromSystem fehlt');
} else {
    assertSame(
        RouteTable::parse(OsAdapter::PLATFORM_LINUX, $fx('route_linux_ip_testbox.txt')),
        RouteTable::fromSystem(OsAdapter::PLATFORM_LINUX, $fx('route_linux_ip_testbox.txt'), $fx('route_linux_proc_testbox.txt')),
        'Mit ip gilt dessen Ausgabe'
    );
    assertSame(
        RouteTable::parseProcIpv6Route($fx('route_linux_proc_testbox.txt')),
        RouteTable::fromSystem(OsAdapter::PLATFORM_LINUX, $fx('cmd_missing_dash.txt'), $fx('route_linux_proc_testbox.txt')),
        'Ohne ip: Routen aus /proc/net/ipv6_route'
    );
    // Die Lücke, die reblade traf: ohne ip und ohne /proc darf keine leere Tabelle
    // entstehen — „keine Route" wäre dann eine Behauptung, kein Befund.
    assertSame(
        null,
        RouteTable::fromSystem(OsAdapter::PLATFORM_LINUX, $fx('cmd_missing_dash.txt'), null),
        'Ohne ip und ohne /proc: Routentabelle unbekannt (null), nicht leer'
    );
    assertSame(
        RouteTable::parse(OsAdapter::PLATFORM_WINDOWS, $fx('route_windows_active_nuc.txt')),
        RouteTable::fromSystem(OsAdapter::PLATFORM_WINDOWS, $fx('route_windows_active_nuc.txt'), null),
        'Windows: netsh-Ausgabe, /proc spielt keine Rolle'
    );
}

if (!method_exists(OsAdapter::class, 'readProcIpv6Route')) {
    assertTrue(false, 'OsAdapter::readProcIpv6Route fehlt');
} else {
    assertSame(
        $fx('route_linux_proc_testbox.txt'),
        OsAdapter::readProcIpv6Route(__DIR__ . '/fixtures/os/route_linux_proc_testbox.txt'),
        '/proc/net/ipv6_route wird unverändert gelesen'
    );
    assertSame(null, OsAdapter::readProcIpv6Route(__DIR__ . '/fixtures/os/gibt-es-nicht'), 'Ohne die Datei (Windows): null');
}

// --- Kernel ohne Route Information -------------------------------------------------
if (!method_exists(OsAdapter::class, 'ipv6ConfOptionMissing')) {
    assertTrue(false, 'OsAdapter::ipv6ConfOptionMissing fehlt');
} else {
    assertSame(
        true,
        OsAdapter::ipv6ConfOptionMissing('accept_ra_rt_info_max_plen', __DIR__ . '/fixtures/os/ipv6conf_ohne_routeinfo'),
        'reblade: accept_ra_rt_info_max_plen gibt es auf keiner Schnittstelle'
    );
    assertSame(
        false,
        OsAdapter::ipv6ConfOptionMissing('accept_ra_rt_info_max_plen', __DIR__ . '/fixtures/os/ipv6conf'),
        'Testbox: die Einstellung ist vorhanden'
    );
    assertSame(
        false,
        OsAdapter::ipv6ConfOptionMissing('accept_ra', __DIR__ . '/fixtures/os/ipv6conf_ohne_routeinfo'),
        'reblade: accept_ra ist vorhanden'
    );
    assertSame(
        null,
        OsAdapter::ipv6ConfOptionMissing('accept_ra_rt_info_max_plen', __DIR__ . '/fixtures/os/gibt-es-nicht'),
        'Ohne das Verzeichnis (Windows): kein Urteil'
    );
}
