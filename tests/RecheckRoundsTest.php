<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceIdentity.php';

/**
 * Die Nachfrage vor „nicht mehr erreichbar" überbrückt Antwortlücken im WLAN (build 66).
 *
 * build 63 fragte einmal nach — am nuc meldete der Wächter um 13:43 trotzdem den Shelly
 * Dimmer Gen4 als nicht erreichbar, obwohl er seit dem 19.09. durchlief. Messung am
 * 25.09.2026 (je Gerät alle 3 s eine direkte Frage, 270 s): Dimmer 6/90 ohne Antwort,
 * Plug 2/90, der Apple TV am LAN 0/90; die Lücken der Shellys kamen etwa alle 30 s und
 * dauerten 1–2 s, bei beiden zur selben Zeit. Ein einzelner Versuch fällt so in die Lücke.
 *
 * DeviceIdentity::recheckRounds fragt deshalb in Runden: jede Runde alle noch stummen
 * Ziele, dazwischen eine Pause, die länger ist als eine Lücke. Die Antwort in den Tests
 * ist die echte Direktantwort des Dimmers (identity_direct_dimmerg4.bin); die Folge
 * „erst stumm, dann Antwort" bildet die gemessene Lücke nach.
 */

$raw     = (string)file_get_contents(__DIR__ . '/fixtures/mdns/identity_direct_dimmerg4.bin');
$antwort = [['from' => '192.168.178.67:5353', 'message' => MdnsCodec::decodeMessage($raw), 'raw' => $raw]];

if (!method_exists(DeviceIdentity::class, 'recheckRounds')) {
    assertTrue(false, 'DeviceIdentity::recheckRounds fehlt');
} else {
    // Ein Ziel, das erst im dritten Versuch antwortet (Lücke über zwei Versuche)
    $calls  = [];
    $pausen = 0;
    $folge  = ['192.168.178.67' => [[], [], $antwort]];
    $ask    = static function (string $address) use (&$calls, &$folge): array {
        $calls[] = $address;

        return array_shift($folge[$address]) ?? [];
    };
    $result = DeviceIdentity::recheckRounds(['192.168.178.67'], $ask, 3, static function () use (&$pausen): void {
        $pausen++;
    }, static fn(): bool => true);
    assertSame(3, count($calls), 'Lücke über zwei Versuche: der dritte bringt die Antwort');
    assertSame(2, $pausen, 'Zwischen den Runden je eine Pause');
    assertSame(1, count($result['responses']), 'Die Antwort kommt zurück');
    assertSame(3, $result['rounds'], 'Drei Runden gebraucht');
    assertSame(['192.168.178.67' => 3], $result['attempts'], 'Versuche je Ziel');
    $identities = DeviceIdentity::fromResponses($result['responses']);
    assertTrue(DeviceIdentity::alive('E8F60A7C9714.local', [], $identities) !== null, 'Damit gilt der Dimmer als lebend');

    // Antwortet sofort: keine Pause, kein zweiter Versuch
    $calls  = [];
    $pausen = 0;
    $folge  = ['192.168.178.67' => [$antwort]];
    $result = DeviceIdentity::recheckRounds(['192.168.178.67'], $ask, 3, static function () use (&$pausen): void {
        $pausen++;
    }, static fn(): bool => true);
    assertSame([1, 0, 1], [count($calls), $pausen, $result['rounds']], 'Sofortige Antwort: ein Versuch, keine Pause');

    // Zwei Ziele: das beantwortete wird in der nächsten Runde nicht mehr gefragt
    $calls  = [];
    $folge  = ['192.168.178.67' => [[], $antwort], '192.168.178.176' => [$antwort]];
    $result = DeviceIdentity::recheckRounds(['192.168.178.67', '192.168.178.176'], $ask, 3, static function (): void {
    }, static fn(): bool => true);
    assertSame(['192.168.178.67', '192.168.178.176', '192.168.178.67'], $calls, 'Runde 2 fragt nur das stumme Ziel');
    assertSame(2, count($result['responses']), 'Beide Antworten zurück');

    // Wirklich stumm: nach der letzten Runde keine Pause mehr
    $calls  = [];
    $pausen = 0;
    $folge  = ['192.168.178.67' => []];
    $result = DeviceIdentity::recheckRounds(['192.168.178.67'], $ask, 3, static function () use (&$pausen): void {
        $pausen++;
    }, static fn(): bool => true);
    assertSame([3, 2, []], [count($calls), $pausen, $result['responses']], 'Stummes Gerät: drei Versuche, zwei Pausen, keine Antwort');

    // Reicht das Zeitbudget nicht für eine weitere Runde, endet die Nachfrage
    $calls  = [];
    $folge  = ['192.168.178.67' => []];
    $result = DeviceIdentity::recheckRounds(['192.168.178.67'], $ask, 3, static function (): void {
    }, static fn(): bool => false);
    assertSame([1, 1], [count($calls), $result['rounds']], 'Ohne Budget bleibt es beim ersten Versuch');

    // Keine Ziele: nichts zu tun
    $calls  = [];
    $result = DeviceIdentity::recheckRounds([], $ask, 3, static function (): void {
    }, static fn(): bool => true);
    assertSame([[], 0], [$calls, $result['rounds']], 'Ohne Ziele keine Frage');
}
