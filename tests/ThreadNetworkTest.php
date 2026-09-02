<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';

/**
 * Thread-Netz-Gesundheit aus den _meshcop-TXT-Records der Border Router.
 *
 * Fixtures sind echte Antworten aus dem nuc-LAN vom 02.09.2026: Apple TV
 * „Wohnzimmer" (Thread 1.3.0) und IKEA DIRIGERA (Thread 1.4.0) im selben Netz
 * „MyHome2081938520". Bemerkenswert: Die Partition-ID steht bei den beiden
 * Herstellern byte-verdreht (C3F6CB75 vs. 75CBF6C3) — ein naiver Vergleich
 * würde ein zerfallenes Netz melden, das keines ist.
 */

$libFile = __DIR__ . '/../MatterDiagnose/libs/ThreadNetwork.php';
if (!is_file($libFile)) {
    assertTrue(false, 'libs/ThreadNetwork.php fehlt');

    return;
}
require_once $libFile;

$meshcopTxt = static function (string $fixture): array {
    $message = MdnsCodec::decodeMessage((string)file_get_contents(__DIR__ . '/fixtures/mdns/' . $fixture));
    foreach ($message['records'] as $record) {
        if ($record['type'] === MdnsCodec::TYPE_TXT && str_contains($record['name'], '_meshcop')) {
            return $record['txt'];
        }
    }

    return [];
};

// --- Parsen der echten TXT-Records ------------------------------------------
$apple    = ThreadNetwork::parseMeshcop($meshcopTxt('meshcop_apple_wohnzimmer.bin'));
$dirigera = ThreadNetwork::parseMeshcop($meshcopTxt('meshcop_dirigera.bin'));

assertSame('BAE59F9A96C840D4', $apple['xp'], 'Apple: Extended PAN ID als Hex');
assertSame('BAE59F9A96C840D4', $dirigera['xp'], 'DIRIGERA: gleiche Extended PAN ID');
assertSame('MyHome2081938520', $apple['nn'], 'Apple: Netzname');
assertSame('Apple', $apple['vn'], 'Apple: Hersteller');
assertSame('IKEA', $dirigera['vn'], 'DIRIGERA: Hersteller');
assertSame('1.3.0', $apple['tv'], 'Apple: Thread-Version');
assertSame('1.4.0', $dirigera['tv'], 'DIRIGERA: Thread-Version');
assertSame('C3F6CB75', $apple['pt'], 'Apple: Partition-ID roh');
assertSame('75CBF6C3', $dirigera['pt'], 'DIRIGERA: Partition-ID roh (byte-verdreht)');
assertSame($apple['partitionKey'], $dirigera['partitionKey'], 'Partition-IDs beider Router gelten normalisiert als gleich');
assertSame('000066F0D68A0000', $apple['at'], 'Apple: aktiver Zeitstempel');
assertSame($apple['at'], $dirigera['at'], 'Beide Router: gleicher aktiver Datensatz');
assertSame(true, $apple['bbrActive'], 'Apple: Backbone Router aktiv (sb Bit 7)');
assertSame(true, $apple['bbrPrimary'], 'Apple: primärer Backbone Router (sb Bit 8)');
assertSame(true, $dirigera['bbrActive'], 'DIRIGERA: Backbone Router aktiv');
assertSame(false, $dirigera['bbrPrimary'], 'DIRIGERA: nicht primär');
assertSame(null, $apple['omr'], 'Apple annonciert kein OMR-Präfix');
assertSame(['prefix' => 'fd89:6b7:bc55::', 'length' => 64], $dirigera['omr'], 'DIRIGERA: OMR-Präfix fd89:6b7:bc55::/64');

// --- Normalisierung der Partition-ID ---------------------------------------
assertSame('75CBF6C3', ThreadNetwork::normalizePartition('C3F6CB75'), 'Normalisierung: kleinere Byte-Reihenfolge gewinnt');
assertSame('75CBF6C3', ThreadNetwork::normalizePartition('75CBF6C3'), 'Normalisierung ist idempotent');
assertTrue(
    ThreadNetwork::normalizePartition('11111111') !== ThreadNetwork::normalizePartition('22222222'),
    'Echt verschiedene Partitionen bleiben verschieden'
);

// --- Bewertung der Border Router -------------------------------------------
$br = static fn(string $name, array $txt, string $ll = 'fe80::1'): array => [
    'name' => $name, 'host' => strtolower($name) . '.local', 'addresses' => [$ll], 'source' => '192.168.178.1', 'txt' => $txt,
];
$appleTxt    = $meshcopTxt('meshcop_apple_wohnzimmer.bin');
$dirigeraTxt = $meshcopTxt('meshcop_dirigera.bin');

$healthy = ThreadNetwork::assess([$br('Wohnzimmer', $appleTxt), $br('DIRIGERA #666D', $dirigeraTxt)]);
assertSame(2, $healthy['routers'], 'Zwei Border Router gezählt');
assertSame([], $healthy['unknown'], 'Kein Router ohne TXT');
assertSame(1, count($healthy['networks']), 'Ein gemeinsames Thread-Netz');
$net = $healthy['networks'][0];
assertSame('MyHome2081938520', $net['name'], 'Netzname übernommen');
assertSame(['Wohnzimmer', 'DIRIGERA #666D'], $net['routers'], 'Beide Router im Netz');
assertSame(1, count($net['partitions']), 'Eine Partition trotz byte-verdrehter IDs');
assertSame(1, count($net['timestamps']), 'Ein aktiver Datensatz');
assertSame(['1.3.0', '1.4.0'], $net['versions'], 'Thread-Versionen sortiert');
assertSame(['Apple', 'IKEA'], $net['vendors'], 'Hersteller sortiert');
assertSame(['fd89:6b7:bc55::'], $net['omrPrefixes'], 'OMR-Präfix aus dem DIRIGERA');
assertSame('Wohnzimmer', $net['primaryBbr'], 'Apple TV ist primärer Backbone Router');

$single = ThreadNetwork::assess([$br('DIRIGERA #666D', $dirigeraTxt)]);
assertSame(1, $single['routers'], 'Einzelner Border Router');
assertSame(null, $single['networks'][0]['primaryBbr'], 'Ohne primären BBR bleibt das Feld null');

$otherNet   = $appleTxt;
$otherNet['xp'] = hex2bin('2222222222222222');
$otherNet['nn'] = 'Fremdnetz';
$split = ThreadNetwork::assess([$br('Wohnzimmer', $appleTxt), $br('Nest', $otherNet)]);
assertSame(2, count($split['networks']), 'Verschiedene Extended PAN IDs ergeben zwei Netze');

$otherPartition = $dirigeraTxt;
$otherPartition['pt'] = hex2bin('11111111');
$partitions = ThreadNetwork::assess([$br('Wohnzimmer', $appleTxt), $br('DIRIGERA #666D', $otherPartition)]);
assertSame(2, count($partitions['networks'][0]['partitions']), 'Echt verschiedene Partition-IDs werden erkannt');

$otherDataset = $dirigeraTxt;
$otherDataset['at'] = hex2bin('000066F0D68B0000');
$dataset = ThreadNetwork::assess([$br('Wohnzimmer', $appleTxt), $br('DIRIGERA #666D', $otherDataset)]);
assertSame(2, count($dataset['networks'][0]['timestamps']), 'Verschiedene aktive Zeitstempel werden erkannt');

$noTxt = ThreadNetwork::assess([$br('Unbekannt', [])]);
assertSame(['Unbekannt'], $noTxt['unknown'], 'Router ohne TXT landet unter unknown');
assertSame([], $noTxt['networks'], 'Router ohne TXT bildet kein Netz');
