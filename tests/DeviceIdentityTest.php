<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceIdentity.php';

/**
 * Hersteller und Modell hinter einer Nummer — Fixtures sind echte Antworten aus dem LAN
 * (18.09.2026, tests/fixtures/mdns/services_*.bin): Shellys, Hue Bridge, Cast-Geräte, ESPHome.
 */

$manifest  = json_decode((string)file_get_contents(__DIR__ . '/fixtures/mdns/services_manifest.json'), true);
$responses = [];
foreach ($manifest as $entry) {
    $raw         = (string)file_get_contents(__DIR__ . '/fixtures/mdns/' . $entry['file']);
    $responses[] = ['from' => $entry['source'], 'message' => MdnsCodec::decodeMessage($raw), 'raw' => $raw];
}

$identities = DeviceIdentity::fromResponses($responses);
$byHost     = [];
foreach ($identities as $identity) {
    $byHost[strtolower($identity['host'])] = $identity;
}
assertTrue(count($identities) >= 12, 'Mindestens zwölf Dienstinstanzen aus den Fixtures (' . count($identities) . ')');

$shelly = $byHost['shellyplugsg3-e4b063e529d0.local'] ?? [];
assertSame('Shelly', $shelly['vendor'] ?? null, 'Shelly: Hersteller aus dem Dienst');
assertSame('PlugSG3 Gen 3', $shelly['model'] ?? null, 'Shelly: Modell aus app und gen');
assertTrue(in_array('192.168.178.116', $shelly['addresses'] ?? [], true), 'Shelly: IPv4 des SRV-Hosts gesammelt');

$hue = $byHost['ecb5fab05408.local'] ?? [];
assertSame('Philips Hue', $hue['vendor'] ?? null, 'Hue Bridge: Hersteller');
assertSame('Bridge BSB002', $hue['model'] ?? null, 'Hue Bridge: Modell aus modelid');

$cast = $byHost['f5b7d5c5-984f-7fc3-b291-c59b1df35573.local'] ?? [];
assertSame('BRAVIA 4K GB', $cast['model'] ?? null, 'Cast: Modell aus md');
assertSame('', $cast['vendor'] ?? null, 'Cast: kein Hersteller im TXT');

$esphome = $byHost['nfc-reader.local'] ?? [];
assertSame('ESPHome', $esphome['vendor'] ?? null, 'ESPHome: Hersteller');
assertSame('ESP8266 d1_mini', $esphome['model'] ?? null, 'ESPHome: Plattform und Board');

// HomeKit-Geräte gab es im LAN nicht — der Schlüssel md ist in der HAP-Spezifikation festgelegt.
assertSame(['vendor' => '', 'model' => 'Eve Door'], DeviceIdentity::describe('_hap._tcp.local', ['md' => 'Eve Door', 'ci' => '1'], 'Eve Door._hap._tcp.local'), 'HomeKit: Modell aus md');
assertSame(['vendor' => '', 'model' => ''], DeviceIdentity::describe('_matter._tcp.local', ['SII' => '500'], 'x'), 'Unbekannter Dienst: nichts');

// OUI: Hersteller aus dem Hostnamen, wenn der eine MAC ist
assertSame('Espressif', DeviceIdentity::ouiVendor('E4B063E529D0.local'), 'OUI E4B063 = Espressif');
assertSame('Signify (Philips Hue)', DeviceIdentity::ouiVendor('ecb5fab05408.local'), 'OUI ECB5FA = Signify, Kleinschreibung egal');
assertSame('IKEA', DeviceIdentity::ouiVendor('68EC8A0BE88A.local.'), 'OUI 68EC8A = IKEA, Punkt am Ende egal');
assertSame(null, DeviceIdentity::ouiVendor('3D59C51D251F.local'), 'Erstes Byte mit Multicast-Bit: keine Herstellerkennung');
assertSame(null, DeviceIdentity::ouiVendor('CA1ACE989841CBEB.local'), 'Thread-Kennung (16-stellig): kein Hersteller');
assertSame(null, DeviceIdentity::ouiVendor('SymBox.local'), 'Klarname: kein Hersteller');
assertSame(null, DeviceIdentity::ouiVendor(''), 'Leer: kein Hersteller');

// Zuordnung zu Matter-Geräten (Formen aus dem nuc-Debug-Auszug)
assertSame(
    ['vendor' => 'Shelly', 'model' => 'PlugSG3 Gen 3'],
    DeviceIdentity::identify('E4B063E529D0.local', ['fd86:6fd:53ed:0:e6b0:63ff:fee5:29d0', '192.168.178.116'], $identities),
    'Shelly: über MAC im Hostnamen und gemeinsame Adresse gefunden'
);
assertSame(
    ['vendor' => 'Philips Hue', 'model' => 'Bridge BSB002'],
    DeviceIdentity::identify('ecb5fab05408.local', ['fd86:6fd:53ed:0:eeb5:faff:feb0:5408'], $identities),
    'Hue Bridge: gleicher Host wie die Matter-Annonce'
);
assertSame(
    ['vendor' => 'IKEA', 'model' => ''],
    DeviceIdentity::identify('68EC8A0BE88A.local', ['fd86:6fd:53ed:0:6aec:8aff:fe0b:e88a'], $identities),
    'Ohne Dienst bleibt der Hersteller aus der MAC'
);
assertSame(
    ['vendor' => '', 'model' => ''],
    DeviceIdentity::identify('12C590FFD09CEF22.local', ['fd89:6b7:bc55:0:6efe:107f:c87e:36e2'], $identities),
    'Thread-Gerät: nichts zu holen'
);
assertSame(
    ['vendor' => '', 'model' => 'BRAVIA 4K GB'],
    DeviceIdentity::identify('', ['192.168.178.21'], $identities),
    'Ohne Host: Zuordnung allein über die Adresse'
);
assertSame(['vendor' => 'Espressif', 'model' => ''], DeviceIdentity::identify('D0CF13CA7430.local', [], []), 'Ohne Identitäten: nur OUI');

// --- Build 50: Hersteller aus der MAC in der IPv6-Adresse -----------------------------
// Echter Fall aus dem eigenen Netz (18.09.2026): Ein Amazon Echo annonciert seinen
// Matter-Dienst unter dem Hostnamen 3D59C51D251F — zwölf Hexstellen, aber eine lokal
// verwaltete MAC, also ohne Herstellerkennung. Die Adresse trägt die echte MAC.
$echoAdressen = ['fd86:6fd:53ed:0:de54:d7ff:fe14:dd72', '2003:f9:7f09:1400:de54:d7ff:fe14:dd72', 'fe80::de54:d7ff:fe14:dd72'];
assertSame(null, DeviceIdentity::ouiVendor('3D59C51D251F.local'), 'Lokal verwaltete MAC im Hostnamen: kein Hersteller');
assertSame('Amazon', DeviceIdentity::identify('3D59C51D251F.local', $echoAdressen, [])['vendor'], 'Hersteller aus der EUI-64 der Adresse');

// Der Hostname hat Vorrang, solange er etwas hergibt
assertSame('Espressif', DeviceIdentity::identify('E8F60A7C9714.local', $echoAdressen, [])['vendor'], 'Hostname schlägt Adresse');

// Ein Identitätsdienst schlägt beides
$dienst = [['service' => '_shelly._tcp.local', 'instance' => 'x', 'host' => 'ShellyX.local', 'addresses' => ['fd86:6fd:53ed:0:de54:d7ff:fe14:dd72'], 'vendor' => 'Shelly', 'model' => 'Plug S']];
assertSame('Shelly', DeviceIdentity::identify('3D59C51D251F.local', $echoAdressen, $dienst)['vendor'], 'Ein anderer Dienst desselben Geräts hat Vorrang');

// Thread-Adressen tragen keine MAC — dort bleibt es leer
assertSame('', DeviceIdentity::identify('CA1ACE989841CBEB.local', ['fd89:6b7:bc55:0:3a31:e5b3:b3a2:5d3d'], [])['vendor'], 'Thread-Kennung: kein Hersteller');
assertSame('', DeviceIdentity::identify('B2EDD5A10FF0C48C.local', ['192.168.178.63'], [])['vendor'], 'IPv4 allein: kein Hersteller');

// --- Lebt das Gerät, obwohl seine Matter-Ansage fehlt? -----------------------
// Belegt am 20.09.2026 im eigenen LAN: Der Shelly Dimmer Gen4 annonciert sich unter
// _shelly._tcp (ShellyDimmerG4-E8F60A7C9714.local) und beantwortet HTTP-RPC, sein
// _matter._tcp-Eintrag fehlt aber — auch auf die gezielte Frage. Symcon zeigt ihn
// als „Nicht gefunden", während Werte hereinkommen. Der Beleg, dass das Gerät lebt,
// kommt deshalb aus der anderen Ansage, nicht aus Symcons Daten.
if (!method_exists(DeviceIdentity::class, 'alive')) {
    assertTrue(false, 'DeviceIdentity::alive fehlt');
} else {
    $identitaeten = [
        [
            'service'   => '_shelly._tcp.local',
            'instance'  => 'shellydimmerg4-e8f60a7c9714._shelly._tcp.local',
            'host'      => 'ShellyDimmerG4-E8F60A7C9714.local',
            'addresses' => ['192.168.178.77'],
            'vendor'    => 'Shelly',
            'model'     => 'Shelly Dimmer Gen4',
        ],
    ];

    $treffer = DeviceIdentity::alive('E8F60A7C9714.local', [], $identitaeten);
    assertTrue($treffer !== null, 'Dieselbe MAC im Hostnamen genügt als Beleg');
    assertSame('_shelly._tcp.local', $treffer['service'] ?? '', 'Der Dienst kommt mit');
    assertSame('Shelly Dimmer Gen4', $treffer['model'] ?? '', 'Modell für den Befundtext');

    assertSame(
        '_shelly._tcp.local',
        DeviceIdentity::alive('irgendwas.local', ['192.168.178.77'], $identitaeten)['service'] ?? '',
        'Auch die gemeinsame Adresse belegt es'
    );
    assertSame(
        '_shelly._tcp.local',
        DeviceIdentity::alive('ShellyDimmerG4-E8F60A7C9714.local', [], $identitaeten)['service'] ?? '',
        'Und der gleiche Host'
    );
    assertSame(null, DeviceIdentity::alive('D0CF13CA7430.local', [], $identitaeten), 'Ein fremdes Gerät ist kein Beleg');
    assertSame(null, DeviceIdentity::alive('', [], $identitaeten), 'Ohne Host kein Beleg');
    assertSame(null, DeviceIdentity::alive('E8F60A7C9714.local', [], []), 'Ohne Identitäten kein Beleg');
}