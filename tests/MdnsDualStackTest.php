<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ThreadNetwork.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MatterDiscovery.php';
require_once __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php';
if (is_file(__DIR__ . '/../MatterDiagnose/libs/MdnsResponses.php')) {
    require_once __DIR__ . '/../MatterDiagnose/libs/MdnsResponses.php';
}

/**
 * mDNS wie ein normaler Teilnehmer: von Port 5353, über IPv4 und IPv6 (build 67).
 *
 * Anlass: Alexandros Apple TV (Forum t/144417/46–47) antwortet weder auf IPv4-Multicast vom
 * freien Port noch von Port 5353 — nur auf IPv6-Multicast an ff02::fb. Avahi fragt auf
 * beiden Familien, deshalb sah Symcon ihn, das Modul nicht.
 *
 * Fixtures: tests/fixtures/mdns/dualstack — die Fragen der ersten Runde (meshcop, matter,
 * matterc) von Port 5353 über beide Familien, alles, was in 4 s ankam (nuc 27 Pakete,
 * Testbox 26, 25.09.2026), samt fremdem Verkehr (Sonos, Siemens, AirPlay …).
 */

$dir      = __DIR__ . '/fixtures/mdns/dualstack';
$manifest = json_decode((string)file_get_contents($dir . '/manifest.json'), true);
$lade     = static function (string $anlage, ?callable $filter = null) use ($dir, $manifest): array {
    $responses = [];
    foreach ($manifest as $m) {
        if ($m['anlage'] !== $anlage || ($filter !== null && !$filter($m))) {
            continue;
        }
        $raw         = (string)file_get_contents($dir . '/' . $m['file']);
        $from        = $m['family'] === 'v6' ? '[' . $m['from'] . ']:' . $m['port'] : $m['from'] . ':' . $m['port'];
        $responses[] = ['from' => $from, 'message' => MdnsCodec::decodeMessage($raw), 'raw' => $raw];
    }

    return $responses;
};
$fragen = [
    ['name' => '_meshcop._udp.local', 'type' => MdnsCodec::TYPE_PTR],
    ['name' => '_matter._tcp.local', 'type' => MdnsCodec::TYPE_PTR],
    ['name' => '_matterc._udp.local', 'type' => MdnsCodec::TYPE_PTR],
];
$router = static function (array $survey): array {
    $r = [];
    foreach ($survey['borderRouters'] as $br) {
        $r[$br['name']] = $br['source'];
    }
    ksort($r);

    return $r;
};

if (!class_exists('MdnsResponses')) {
    assertTrue(false, 'MdnsResponses fehlt');
} else {
    // --- Absender ------------------------------------------------------------
    assertSame('192.168.178.63', MdnsResponses::address('192.168.178.63:5353'), 'IPv4 mit Port');
    assertSame('fe80::8f7:24ce:93c4:8920', MdnsResponses::address('[fe80::8f7:24ce:93c4:8920]:5353'), 'IPv6 in Klammern mit Port');
    assertSame('fe80::8f7:24ce:93c4:8920', MdnsResponses::address('[fe80::8f7:24ce:93c4:8920%12]:5353'), 'IPv6 mit Zone');
    assertSame('192.168.178.63', MdnsResponses::address('192.168.178.63'), 'Nackte Adresse');

    // --- Relevanz: nur Antworten auf unsere Fragen ------------------------------
    $nuc      = $lade('nuc');
    $relevant = MdnsResponses::relevant($nuc, $fragen);
    assertSame(27, count($nuc), 'nuc: 27 Pakete im Mitschnitt');
    $quellen = array_values(array_unique(array_map(static fn(array $r): string => MdnsResponses::address($r['from']), $relevant)));
    sort($quellen);
    assertTrue(!in_array('192.168.178.38', $quellen, true), 'Sonos-Ansagen fliegen raus');
    assertTrue(!in_array('192.168.178.58', $quellen, true), 'Siemens-Geschirrspüler fliegt raus');
    assertTrue(!in_array('192.168.178.91', $quellen, true), 'Shelly-_http fliegt raus');
    assertTrue(in_array('192.168.178.63', $quellen, true) && in_array('192.168.178.186', $quellen, true), 'Apple TV und DIRIGERA bleiben');
    foreach ($relevant as $r) {
        assertTrue($r['message']['isResponse'], 'Fragen anderer fliegen raus (' . $r['from'] . ')');
    }

    // --- IPv6-Absender auf die IPv4 desselben Hosts -----------------------------
    $bevorzugt = MdnsResponses::preferIpv4($nuc);
    $ersteV6   = null;
    $letzteV4  = null;
    foreach ($bevorzugt as $i => $r) {
        $v6 = str_contains(MdnsResponses::address($r['from']), ':');
        if ($v6 && $ersteV6 === null) {
            $ersteV6 = $i;
        }
        if (!$v6) {
            $letzteV4 = $i;
        }
    }
    assertTrue($ersteV6 === null || $letzteV4 < $ersteV6, 'IPv4-Absender stehen vor den übrigen IPv6-Absendern');
    $apple = array_values(array_filter($bevorzugt, static fn(array $r): bool => ($r['via'] ?? '') === 'fe80::8f7:24ce:93c4:8920'));
    assertTrue($apple !== [], 'Apple-TV-Pakete über IPv6 sind als solche markiert');
    assertSame('192.168.178.63', MdnsResponses::address($apple[0]['from'] ?? ''), 'Apple TV fe80::8f7:… → 192.168.178.63');
    $dirigera = array_values(array_filter($bevorzugt, static fn(array $r): bool => ($r['via'] ?? '') === '2003:f9:7f09:1400:6aec:8aff:fe0b:e88a'));
    assertSame('192.168.178.186', MdnsResponses::address($dirigera[0]['from'] ?? ''), 'DIRIGERA von globaler IPv6 → 192.168.178.186');
    assertSame(27, count($bevorzugt), 'Kein Paket geht verloren');

    // --- Das Lagebild bleibt wie mit IPv4 allein --------------------------------
    $eigene = ['192.168.178.86'];
    $survey = MatterDiscovery::collect(MdnsResponses::relevant(MdnsResponses::preferIpv4($nuc), $fragen), $eigene);
    assertSame(['DIRIGERA #666D' => '192.168.178.186', 'Wohnzimmer' => '192.168.178.63'], $router($survey), 'nuc: beide Border Router mit IPv4-Absender');
    $testbox = MatterDiscovery::collect(MdnsResponses::relevant(MdnsResponses::preferIpv4($lade('testbox')), $fragen), ['192.168.178.172']);
    assertSame(['DIRIGERA #666D' => '192.168.178.186', 'Wohnzimmer' => '192.168.178.63'], $router($testbox), 'Testbox: dasselbe');

    // --- Alexandros Fall: der Apple TV antwortet nur über IPv6 -------------------
    $nurV6 = $lade('nuc', static fn(array $m): bool => !($m['family'] === 'v4' && $m['from'] === '192.168.178.63'));
    $alex  = MatterDiscovery::collect(MdnsResponses::relevant(MdnsResponses::preferIpv4($nurV6), $fragen), $eigene);
    assertSame('192.168.178.63', $router($alex)['Wohnzimmer'] ?? null, 'Nur IPv6: Apple TV gefunden, Absender über seinen A-Eintrag');
    $ohneMapping = MatterDiscovery::collect(MdnsResponses::relevant($lade('nuc', static fn(array $m): bool => $m['family'] === 'v4' && $m['from'] !== '192.168.178.63'), $fragen), $eigene);
    assertTrue(!isset($router($ohneMapping)['Wohnzimmer']), 'Gegenprobe: nur IPv4 ohne den Apple TV — kein Wohnzimmer (so sah es bei Alexandro aus)');
}

// --- Schnittstelle der Standardroute -------------------------------------------
if (!method_exists(OsAdapter::class, 'defaultRouteInterface')) {
    assertTrue(false, 'OsAdapter::defaultRouteInterface fehlt');
} else {
    $win = (string)file_get_contents(__DIR__ . '/fixtures/os/route_windows_active_nuc_ra.txt');
    assertSame(12, OsAdapter::defaultRouteInterface(OsAdapter::PLATFORM_WINDOWS, $win), 'Windows: Index 12 aus ::/0 (nuc)');
    $lin = (string)file_get_contents(__DIR__ . '/fixtures/os/route_linux_ip_testbox.txt');
    assertSame('eth0', OsAdapter::defaultRouteInterface(OsAdapter::PLATFORM_LINUX, $lin), 'Linux: eth0 aus default (Testbox)');
    assertSame(null, OsAdapter::defaultRouteInterface(OsAdapter::PLATFORM_LINUX, ''), 'Ohne Standardroute: unbekannt');
}
