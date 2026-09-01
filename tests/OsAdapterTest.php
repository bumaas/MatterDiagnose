<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php';

$fx = static fn(string $name): string => (string)file_get_contents(__DIR__ . '/fixtures/os/' . $name);

// --- Kommandobau ----------------------------------------------------------
gleich(
    'ping -6 -n 6 -w 3000 fd89:1::1',
    OsAdapter::pingCommand(OsAdapter::PLATTFORM_WINDOWS, 'fd89:1::1', 6, 3000),
    'Ping-Kommando Windows'
);
gleich(
    'ping -6 -c 6 -W 3 fd89:1::1',
    OsAdapter::pingCommand(OsAdapter::PLATTFORM_LINUX, 'fd89:1::1', 6, 3000),
    'Ping-Kommando Linux'
);
gleich(
    'netsh interface ipv6 add route fd89:6b7:bc55::/64 "Ethernet" fe80::2',
    OsAdapter::routeAddCommand(OsAdapter::PLATTFORM_WINDOWS, 'fd89:6b7:bc55::', 'fe80::2'),
    'Routen-Kommando Windows'
);
gleich(
    'ip -6 route add fd89:6b7:bc55::/64 via fe80::2',
    OsAdapter::routeAddCommand(OsAdapter::PLATTFORM_LINUX, 'fd89:6b7:bc55::', 'fe80::2'),
    'Routen-Kommando Linux'
);
pruefe(
    str_contains(OsAdapter::routeAddCommand(OsAdapter::PLATTFORM_WINDOWS, 'fd89::', null), '<Gateway'),
    'Routen-Kommando ohne Gateway nutzt Platzhalter'
);

// --- Ping-Auswertung (echte Ausgaben von heute) ---------------------------
gleich(2, OsAdapter::parsePingEmpfangen($fx('ping_windows_de.txt')), 'Ping deutsch: 2 empfangen');
gleich(0, OsAdapter::parsePingEmpfangen($fx('ping_verloren_de.txt')), 'Ping deutsch: 0 empfangen');
gleich(1, OsAdapter::parsePingEmpfangen($fx('ping_windows_en.txt')), 'Ping englisch: 1 empfangen');
gleich(1, OsAdapter::parsePingEmpfangen($fx('ping_busybox.txt')), 'Ping BusyBox: 1 empfangen');
gleich(null, OsAdapter::parsePingEmpfangen('Zieladresse unerreichbar'), 'Unlesbare Ping-Ausgabe ergibt null');

// --- netstat/tasklist (echte Ausgaben vom nuc) ----------------------------
$pids = OsAdapter::parseNetstat5353($fx('netstat_5353_windows.txt'));
sort($pids);
gleich([3164, 3932, 4528, 12268], $pids, 'netstat: PIDs auf 5353 (5355 und 53530 ausgeblendet)');

$prozesse = OsAdapter::parseTasklistCsv($fx('tasklist_windows.txt'));
gleich('mDNSResponder.exe', $prozesse[4528] ?? '(fehlt)', 'tasklist: PID 4528 ist Bonjour');
gleich(4, count($prozesse), 'tasklist: vier Prozesse erkannt');
