<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/DeviceIdentity.php';

/**
 * Wo steht das vermisste Gerät? Adresse und MAC in der Beschriftung (build 71).
 *
 * Anlass (26.09.2026): Zwei Shellys am nuc sagten sich nicht mehr an (Annonce-Ausfall nach
 * ~7 Tagen Laufzeit). Der Befund nannte „Shelly Plug S Gen3 (Id 7)" — im LAN gibt es zwei
 * Plugs dieses Modells, und neu gestartet wurde der falsche: `E4B063E529D0` (.116, gehört zur
 * SymBox) statt `D0CF13CA7430` (.176). Das stumme Gerät fehlte in jeder mDNS-Liste, also blieb
 * dort gerade das andere übrig. Mit Adresse und MAC im Befund ist das nicht mehr zu verwechseln.
 *
 * Werte aus dem Mitschnitt und den Shelly-Antworten vom 26.09.2026.
 */

if (!method_exists(DeviceIdentity::class, 'locationLabel')) {
    assertTrue(false, 'DeviceIdentity::locationLabel fehlt');
} else {
    // Shelly: Matter-Hostname ist die MAC, dazu die IPv4 des letzten Lebenszeichens
    assertSame(
        '192.168.178.176, MAC D0:CF:13:CA:74:30',
        DeviceIdentity::locationLabel('D0CF13CA7430.local', ['192.168.178.176']),
        'Plug am nuc: Adresse und MAC'
    );
    assertSame(
        'MAC E8:F6:0A:7C:97:14',
        DeviceIdentity::locationLabel('E8F60A7C9714.local', []),
        'Ohne gemerkte Adresse: die MAC allein genügt zur Unterscheidung'
    );
    assertSame(
        'MAC E8:F6:0A:7C:97:14',
        DeviceIdentity::locationLabel('e8f60a7c9714.local.', []),
        'Kleinschreibung und abschließender Punkt'
    );
    // Thread-Geräte: 16-stellige Zufallskennung — keine MAC, nichts erfinden
    assertSame('', DeviceIdentity::locationLabel('4E93FA842C50F0F9.local', []), 'Thread-Kennung: keine Angabe');
    // Lokal verwaltete Kennung (Echo Dot, build 50: die echte MAC war DC:54:D7…) ist keine MAC
    assertSame('', DeviceIdentity::locationLabel('3D59C51D251F.local', []), 'Lokal verwaltete Kennung: keine MAC');
    assertSame('192.168.178.69', DeviceIdentity::locationLabel('3D59C51D251F.local', ['192.168.178.69']), 'Dann nur die Adresse');
    // Nur IPv4 wird genannt; ohne Host und Adresse bleibt es leer
    assertSame('MAC D0:CF:13:CA:74:30', DeviceIdentity::locationLabel('D0CF13CA7430.local', ['fe80::d2cf:13ff:feca:7430']), 'Link-Local ist keine Ortsangabe');
    assertSame('', DeviceIdentity::locationLabel(null, []), 'Nichts bekannt: leer');
    assertSame('192.168.178.176', DeviceIdentity::locationLabel(null, ['192.168.178.176', '192.168.178.177']), 'Mehrere Adressen: die erste');
}
