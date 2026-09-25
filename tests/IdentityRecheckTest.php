<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceIdentity.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ChangeTracker.php';

/**
 * Gezielte Nachfrage, bevor ein Gerät als „nicht mehr erreichbar" gilt (build 63).
 *
 * Am nuc pendelten Shelly Plug S Gen3 (Id 7) und Dimmer Gen4 (Id 10) seit dem
 * 20.09.2026 stündlich zwischen „meldet sich nicht an, ist aber im Netz" und „nicht
 * mehr erreichbar". Beide haben keine Matter-Ansage mehr (Abo „Nicht gefunden"); ob sie
 * leben, belegt allein ihre `_shelly`-Antwort in der Identitätsrunde — einer einzigen
 * Frage mit 1 s Wartezeit. Zehn Wiederholungen am 25.09.2026: in einer fehlten drei von
 * fünf Shellys. Ein verlorenes Paket machte so eine Stunde lang einen roten Befund.
 *
 * Die Fixtures sind echte Antworten derselben Geräte auf die unicast an sie gerichtete
 * Identitätsfrage (nuc, 25.09.2026, je rund 265 ms): 192.168.178.176 = Plug S Gen3,
 * 192.168.178.67 = Dimmer Gen4.
 */

$direct = static function (string $file, string $from): array {
    $raw = (string)file_get_contents(__DIR__ . '/fixtures/mdns/' . $file);

    return ['from' => $from, 'message' => MdnsCodec::decodeMessage($raw), 'raw' => $raw];
};

// So stehen die beiden Shellys in Symcon: unsichtbar, Abo nicht OK, Host aus dem Vorlauf.
$plug   = ['nodeId' => 7, 'name' => 'Shelly Plug S Gen3', 'visible' => false, 'sleepy' => false, 'host' => 'D0CF13CA7430.local', 'subscription' => 'Nicht gefunden', 'aliveService' => ''];
$dimmer = ['nodeId' => 10, 'name' => 'Shelly Dimmer Gen4', 'visible' => false, 'sleepy' => false, 'host' => 'E8F60A7C9714.local', 'subscription' => 'Nicht gefunden', 'aliveService' => ''];

// --- Die direkte Antwort belegt das Gerät -------------------------------------
$identities = DeviceIdentity::fromResponses([$direct('identity_direct_plugsg3.bin', '192.168.178.176:5353')]);
$beleg      = DeviceIdentity::alive('D0CF13CA7430.local', [], $identities);
assertTrue($beleg !== null, 'Direkte Antwort des Plug S Gen3 belegt, dass er lebt');
assertSame('_shelly._tcp.local', $beleg['service'] ?? null, 'Beleg über den Shelly-Dienst');
assertSame(['192.168.178.176'], $beleg['addresses'] ?? null, 'Der Beleg nennt die Adresse, unter der das Gerät geantwortet hat');

$identities = DeviceIdentity::fromResponses([$direct('identity_direct_dimmerg4.bin', '192.168.178.67:5353')]);
assertSame(['192.168.178.67'], DeviceIdentity::alive('E8F60A7C9714.local', [], $identities)['addresses'] ?? null, 'Dimmer Gen4: Adresse im Beleg');
assertSame(null, DeviceIdentity::alive('D0CF13CA7430.local', [], $identities), 'Die Antwort des Dimmers belegt nicht den Plug');

// --- Die Adresse wandert in die Momentaufnahme --------------------------------
if (!method_exists(ChangeTracker::class, 'aliveAddressesByNode')) {
    assertTrue(false, 'ChangeTracker::aliveAddressesByNode fehlt');
} else {
    $snapshot = ChangeTracker::snapshot(
        [
            $plug + ['aliveAddresses' => ['192.168.178.176']],
            $dimmer + ['aliveAddresses' => []],
            ['nodeId' => 6, 'name' => 'Sensor', 'visible' => true],
        ],
        [],
        [],
        1000
    );
    assertSame([7 => ['192.168.178.176']], ChangeTracker::aliveAddressesByNode($snapshot), 'Nur belegte Adressen werden gemerkt');
    assertSame([], ChangeTracker::aliveAddressesByNode(null), 'Ohne Vorlauf keine Adressen');
    assertSame([], ChangeTracker::aliveAddressesByNode(['devices' => [['nodeId' => 7, 'name' => 'x', 'visible' => false]]]), 'Alte Momentaufnahme ohne Feld: keine Adressen');

    // Eine andere Adresse ist keine Änderung, die gemeldet werden müsste.
    $moved = ChangeTracker::snapshot([$plug + ['aliveAddresses' => ['192.168.178.99']], $dimmer, ['nodeId' => 6, 'name' => 'Sensor', 'visible' => true]], [], [], 2000);
    assertSame([], ChangeTracker::diff($snapshot, $moved), 'Adresswechsel allein meldet nichts');
}

// --- Wen fragt das Modul nach? ------------------------------------------------
if (!method_exists(DeviceIdentity::class, 'recheckTargets')) {
    assertTrue(false, 'DeviceIdentity::recheckTargets fehlt');
} else {
    $remembered = [7 => ['192.168.178.176'], 10 => ['192.168.178.67']];

    assertSame(
        ['192.168.178.176' => [7], '192.168.178.67' => [10]],
        DeviceIdentity::recheckTargets([$plug, $dimmer], $remembered),
        'Beide Shellys ohne Beleg werden direkt nachgefragt'
    );
    assertSame(
        ['192.168.178.67' => [10]],
        DeviceIdentity::recheckTargets([['aliveService' => '_shelly._tcp.local'] + $plug, $dimmer], $remembered),
        'Wer schon belegt ist, wird nicht nachgefragt'
    );
    assertSame([], DeviceIdentity::recheckTargets([['visible' => true] + $plug], $remembered), 'Sichtbare Geräte nicht');
    assertSame([], DeviceIdentity::recheckTargets([['subscription' => 'OK!'] + $plug], $remembered), 'Abo OK: kein Urteil „nicht erreichbar", keine Nachfrage');
    assertSame([], DeviceIdentity::recheckTargets([['subscription' => 'OK (ICD)'] + $plug], $remembered), 'Abo OK (ICD) ebenso');
    assertSame([], DeviceIdentity::recheckTargets([['subscription' => null] + $plug], $remembered), 'Ohne Abo-Angabe keine Nachfrage');
    assertSame([], DeviceIdentity::recheckTargets([$plug], [10 => ['192.168.178.67']]), 'Ohne gemerkte Adresse keine Nachfrage');
    assertSame(
        [],
        DeviceIdentity::recheckTargets([$plug], [7 => ['fd89:6b7:bc55:0:3a31:e5b3:b3a2:5d3d']]),
        'Nur IPv4: die Direktabfrage geht an <IPv4>:5353'
    );
    assertSame(
        ['192.168.178.176' => [7, 10]],
        DeviceIdentity::recheckTargets([$plug, $dimmer], [7 => ['192.168.178.176'], 10 => ['192.168.178.176']]),
        'Zwei Geräte hinter einer Adresse: eine Frage'
    );

    $many = [];
    $mem  = [];
    for ($i = 1; $i <= 6; $i++) {
        $many[]   = ['nodeId' => 100 + $i] + $plug;
        $mem[100 + $i] = ['192.168.178.' . (200 + $i)];
    }
    assertSame(4, count(DeviceIdentity::recheckTargets($many, $mem)), 'Höchstens vier Adressen je Lauf');
    assertSame(2, count(DeviceIdentity::recheckTargets($many, $mem, 2)), 'Obergrenze einstellbar');
}
