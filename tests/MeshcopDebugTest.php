<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ForeignText.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ThreadNetwork.php';

/**
 * Die Werte der _meshcop-Felder gehören ins Debug (Boui, t/144417/59–62, 12.10.2026).
 * Er bekam thread_dataset_mismatch zwischen einem SLZB-MR5U (OpenThread) und einem
 * HomePod mini, obwohl das Netz einwandfrei lief. Seine `dns-sd -L`-Ausgabe zeigte
 * denselben Active Timestamp um zwei Bytes verschoben, aber nicht jedes Byte (nicht
 * druckbare stehen dort als „?“). Das Debug nannte bis build 90 nur die Schlüssel
 * („TXT rv id vn …“), ein Dump half also nicht. Jetzt stehen die Binärfelder, an denen
 * Befunde hängen, als Hex dabei, damit der Fix an einem echten Mitschnitt entsteht.
 *
 * Fixtures: Apple TV „Wohnzimmer“ und IKEA DIRIGERA aus dem nuc-LAN (02.09.2026).
 */

$meshcopTxt = static function (string $fixture): array {
    $message = MdnsCodec::decodeMessage((string)file_get_contents(__DIR__ . '/fixtures/mdns/' . $fixture));
    foreach ($message['records'] as $record) {
        if ($record['type'] === MdnsCodec::TYPE_TXT && str_contains($record['name'], '_meshcop')) {
            return $record['txt'];
        }
    }

    return [];
};

if (!method_exists(ThreadNetwork::class, 'debugFields')) {
    assertTrue(false, 'ThreadNetwork::debugFields fehlt');

    return;
}

$apple    = ThreadNetwork::debugFields($meshcopTxt('meshcop_apple_wohnzimmer.bin'));
$dirigera = ThreadNetwork::debugFields($meshcopTxt('meshcop_dirigera.bin'));

assertTrue(str_contains($apple, 'at=000066F0D68A0000'), "Apple: Active Timestamp als Hex ($apple)");
assertTrue(str_contains($apple, 'pt=C3F6CB75'), "Apple: Partition als Hex ($apple)");
assertTrue(str_contains($apple, 'xp=BAE59F9A96C840D4'), "Apple: Extended PAN ID als Hex ($apple)");
assertTrue(str_contains($dirigera, 'at=000066F0D68A0000'), "DIRIGERA: Active Timestamp als Hex ($dirigera)");
assertTrue(str_contains($dirigera, 'pt=75CBF6C3'), "DIRIGERA: Partition als Hex ($dirigera)");
assertTrue(str_contains($dirigera, 'vn=IKEA'), "DIRIGERA: Hersteller als Text ($dirigera)");
assertTrue(preg_match('/^[\x20-\x7e]+$/', $apple) === 1, 'Apple: nur druckbare Zeichen im Debug');
assertTrue(preg_match('/^[\x20-\x7e]+$/', $dirigera) === 1, 'DIRIGERA: nur druckbare Zeichen im Debug');

// Fehlende Felder bleiben weg, ein leerer TXT-Datensatz ergibt nichts
assertSame('', ThreadNetwork::debugFields([]), 'ohne TXT kein Text');
assertSame('vn=OpenThread', ThreadNetwork::debugFields(['vn' => 'OpenThread']), 'nur vorhandene Felder');

// module.php nutzt die Bibliothek für die Debug-Zeile „Border Router“
$modulePhp = (string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php');
assertTrue(str_contains($modulePhp, 'ThreadNetwork::debugFields('), 'module.php schreibt die Feldwerte ins Debug');
