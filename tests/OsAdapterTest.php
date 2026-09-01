<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php';

$fx = static fn(string $name): string => (string)file_get_contents(__DIR__ . '/fixtures/os/' . $name);

// --- Kommandobau ----------------------------------------------------------
assertSame(
    'ping -6 -n 6 -w 3000 fd89:1::1',
    OsAdapter::pingCommand(OsAdapter::PLATFORM_WINDOWS, 'fd89:1::1', 6, 3000),
    'Ping-Kommando Windows'
);
assertSame(
    'ping -6 -c 6 -W 3 fd89:1::1',
    OsAdapter::pingCommand(OsAdapter::PLATFORM_LINUX, 'fd89:1::1', 6, 3000),
    'Ping-Kommando Linux'
);
assertSame(
    'netsh interface ipv6 add route fd89:6b7:bc55::/64 "Ethernet" fe80::2',
    OsAdapter::routeAddCommand(OsAdapter::PLATFORM_WINDOWS, 'fd89:6b7:bc55::', 'fe80::2'),
    'Routen-Kommando Windows'
);
assertSame(
    'ip -6 route add fd89:6b7:bc55::/64 via fe80::2',
    OsAdapter::routeAddCommand(OsAdapter::PLATFORM_LINUX, 'fd89:6b7:bc55::', 'fe80::2'),
    'Routen-Kommando Linux'
);
assertTrue(
    str_contains(OsAdapter::routeAddCommand(OsAdapter::PLATFORM_WINDOWS, 'fd89::', null), '<Gateway'),
    'Routen-Kommando ohne Gateway nutzt Platzhalter'
);

// --- Ping-Auswertung (echte Ausgaben vom 01.09.2026) ----------------------
assertSame(2, OsAdapter::parsePingReceived($fx('ping_windows_de.txt')), 'Ping deutsch: 2 empfangen');
assertSame(0, OsAdapter::parsePingReceived($fx('ping_lost_de.txt')), 'Ping deutsch: 0 empfangen');
assertSame(1, OsAdapter::parsePingReceived($fx('ping_windows_en.txt')), 'Ping englisch: 1 empfangen');
assertSame(1, OsAdapter::parsePingReceived($fx('ping_busybox.txt')), 'Ping BusyBox: 1 empfangen');
assertSame(null, OsAdapter::parsePingReceived('Zieladresse unerreichbar'), 'Unlesbare Ping-Ausgabe ergibt null');

// --- Routingtabelle (echte Ausgaben vom nuc bzw. Linux-Format) ------------
assertSame('netsh interface ipv6 show route', OsAdapter::routeShowCommand(OsAdapter::PLATFORM_WINDOWS), 'Routen-Anzeige Windows');
assertSame('ip -6 route', OsAdapter::routeShowCommand(OsAdapter::PLATFORM_LINUX), 'Routen-Anzeige Linux');
assertSame(
    true,
    OsAdapter::parseRouteExists($fx('route_windows_with_thread.txt'), 'fd89:6b7:bc55::'),
    'Windows-Route zum Thread-Präfix erkannt'
);
assertSame(
    false,
    OsAdapter::parseRouteExists($fx('route_windows_without_thread.txt'), 'fd89:6b7:bc55::'),
    'Fehlende Windows-Route erkannt'
);
assertSame(
    true,
    OsAdapter::parseRouteExists($fx('route_linux.txt'), 'fd89:6b7:bc55::'),
    'Linux-Route zum Thread-Präfix erkannt'
);

// --- netstat/tasklist (echte Ausgaben vom nuc) ----------------------------
$pids = OsAdapter::parseNetstat5353($fx('netstat_5353_windows.txt'));
sort($pids);
assertSame([3164, 3932, 4528, 12268], $pids, 'netstat: PIDs auf 5353 (5355 und 53530 ausgeblendet)');

$processes = OsAdapter::parseTasklistCsv($fx('tasklist_windows.txt'));
assertSame('mDNSResponder.exe', $processes[4528] ?? '(fehlt)', 'tasklist: PID 4528 ist Bonjour');
assertSame(4, count($processes), 'tasklist: vier Prozesse erkannt');
