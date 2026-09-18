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
    'netsh interface ipv6 add route fd89:6b7:bc55::/64 "Ethernet" fe80::2 store=persistent',
    OsAdapter::routeAddCommand(OsAdapter::PLATFORM_WINDOWS, 'fd89:6b7:bc55::', 'fe80::2'),
    'Routen-Kommando Windows ohne Index: Platzhalter "Ethernet", aber persistent'
);
assertSame(
    'netsh interface ipv6 add route fd89:6b7:bc55::/64 12 fe80::2 store=persistent',
    OsAdapter::routeAddCommand(OsAdapter::PLATFORM_WINDOWS, 'fd89:6b7:bc55::', 'fe80::2', '12'),
    'Routen-Kommando Windows mit Schnittstellenindex'
);
if (!method_exists(OsAdapter::class, 'routeDeleteCommand')) {
    assertTrue(false, 'OsAdapter::routeDeleteCommand/routePersistCommand/routeShowPersistentCommand fehlen');
} else {
    assertSame(
        'netsh interface ipv6 delete route fd89:6b7:bc55::/64 12 fe80::2',
        OsAdapter::routeDeleteCommand(OsAdapter::PLATFORM_WINDOWS, 'fd89:6b7:bc55::', 64, 'fe80::2', '12'),
        'Lösch-Kommando Windows'
    );
    assertSame(
        'ip -6 route del fd89:6b7:bc55::/64 via fe80::2',
        OsAdapter::routeDeleteCommand(OsAdapter::PLATFORM_LINUX, 'fd89:6b7:bc55::', 64, 'fe80::2', null),
        'Lösch-Kommando Linux ohne bekannte Schnittstelle'
    );
    assertSame(
        'netsh interface ipv6 delete route fd89:6b7:bc55::/64 12 fe80::2 && netsh interface ipv6 add route fd89:6b7:bc55::/64 12 fe80::2 store=persistent',
        OsAdapter::routePersistCommand('fd89:6b7:bc55::', 64, 'fe80::2', '12'),
        'Persistenz-Kommando Windows: löschen und persistent neu anlegen'
    );
    assertSame('netsh interface ipv6 show route store=persistent', OsAdapter::routeShowPersistentCommand(), 'Anzeige des persistenten Speichers');
}
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
// Ob eine Route ins Thread-Netz führt, bewertet seit build 36 RouteTable::hasRouteFor
// (RouteTableTest) — die Textsuche hier übersah kürzere, abdeckende Routen.

// --- Eigene Adressen ohne VPN-Interfaces (echte net_get_interfaces()-Ausgabe der SymBox Neustadt, 02.09.2026) ---
// Dort hat eth0 nur eine Link-Local-IPv6; die einzige globale IPv6 gehört zu tailscale0.
// Die Diagnose meldete deshalb fälschlich "IPv6 ist vorhanden" mit der VPN-Adresse als Beleg.
$symbox = json_decode($fx('net_get_interfaces_symbox_tailscale.json'), true, 16, JSON_THROW_ON_ERROR);
if (!method_exists(OsAdapter::class, 'ipv6AddressesFromInterfaces')) {
    assertTrue(false, 'OsAdapter::ipv6AddressesFromInterfaces fehlt');
    assertTrue(false, 'OsAdapter::ipv4AddressesFromInterfaces fehlt');
} else {
    assertSame(
        ['fe80::ba27:ebff:fe4c:aec1'],
        OsAdapter::ipv6AddressesFromInterfaces($symbox),
        'SymBox mit Tailscale: nur die IPv6 von eth0 zählt, die Tailscale-ULA nicht'
    );
    assertSame(
        ['192.168.10.34'],
        OsAdapter::ipv4AddressesFromInterfaces($symbox),
        'SymBox mit Tailscale: nur die IPv4 von eth0 zählt, die CGNAT-Adresse 100.x nicht'
    );

    // Windows liefert GUID-Schlüssel und den Adapternamen unter "description"
    $windows = [
        '{AAAA}' => ['description' => 'Realtek PCIe GBE Family Controller', 'up' => true, 'unicast' => [
            ['family' => 2, 'address' => '192.168.178.81', 'netmask' => '255.255.255.0'],
            ['family' => 23, 'address' => 'fd86:6fd:53ed:0:52b1:1695:7c54:eff5', 'netmask' => 'ffff:ffff:ffff:ffff::'],
            ['family' => 23, 'address' => 'fe80::a7e0:8d3f:39bd:e6ba', 'netmask' => 'ffff:ffff:ffff:ffff::'],
        ]],
        '{BBBB}' => ['description' => 'Tailscale Tunnel', 'up' => true, 'unicast' => [
            ['family' => 2, 'address' => '100.101.102.103', 'netmask' => '255.255.255.255'],
            ['family' => 23, 'address' => 'fd7a:115c:a1e0::1234:5678', 'netmask' => 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
        ]],
        '{CCCC}' => ['description' => 'WireGuard Tunnel', 'up' => true, 'unicast' => [
            ['family' => 2, 'address' => '10.8.0.2', 'netmask' => '255.255.255.255'],
            ['family' => 23, 'address' => 'fd00:8::2', 'netmask' => 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
        ]],
    ];
    assertSame(
        ['fd86:6fd:53ed:0:52b1:1695:7c54:eff5', 'fe80::a7e0:8d3f:39bd:e6ba'],
        OsAdapter::ipv6AddressesFromInterfaces($windows),
        'Windows: Tailscale- und WireGuard-Adapter werden übersprungen'
    );
    assertSame(['192.168.178.81'], OsAdapter::ipv4AddressesFromInterfaces($windows), 'Windows: IPv4 nur vom LAN-Adapter');
}

// --- Review 17.09.2026 -------------------------------------------------------
// Linux verlangt bei einem Link-Local-Gateway die Schnittstelle („dev").
assertSame(
    'ip -6 route add fd89:6b7:bc55::/64 via fe80::2 dev eth0',
    OsAdapter::routeAddCommand(OsAdapter::PLATFORM_LINUX, 'fd89:6b7:bc55::', 'fe80::2', 'eth0'),
    'Routen-Kommando Linux mit Schnittstelle'
);
assertSame(
    'ip -6 route del fd89:6b7:bc55::/64 via fe80::2 dev eth0',
    OsAdapter::routeDeleteCommand(OsAdapter::PLATFORM_LINUX, 'fd89:6b7:bc55::', 64, 'fe80::2', 'eth0'),
    'Lösch-Kommando Linux mit Schnittstelle'
);

// „Zielnetz nicht erreichbar" zählt Windows nicht als empfangen — echter Mitschnitt
// vom 17.09.2026 (Antwort der FRITZ!Box für ein ungenutztes Teilnetz, anonymisiert).
assertSame(0, OsAdapter::parsePingReceived($fx('ping_unreachable_de.txt')), 'Ping „Zielnetz nicht erreichbar": 0 empfangen');

// Zeitbudget: so viele Ping-Versuche, wie ohne Antwort in die Restzeit passen
$attempts = static function (float $remaining): mixed {
    try {
        return OsAdapter::pingAttempts($remaining, 2000, 5);
    } catch (Throwable $e) {
        return 'Ausnahme: ' . get_class($e);
    }
};
assertSame(5, $attempts(20.0), 'Ping-Versuche: reichlich Zeit ergibt das Maximum');
assertSame(3, $attempts(7.5), 'Ping-Versuche: 7,5 s Rest ergeben drei Versuche zu 2 s');
assertSame(0, $attempts(4.9), 'Ping-Versuche: unter zwei vollen Versuchen wird nicht gepingt');
