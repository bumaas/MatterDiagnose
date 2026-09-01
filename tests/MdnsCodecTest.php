<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';

// --- Roundtrip: Query bauen und wieder zerlegen ---------------------------
$query = MdnsCodec::encodeQuery([
    ['name' => '_meshcop._udp.local', 'type' => MdnsCodec::TYPE_PTR, 'unicast' => true],
    ['name' => '_matter._tcp.local', 'type' => MdnsCodec::TYPE_PTR, 'unicast' => false],
]);
$decoded = MdnsCodec::decodeMessage($query);
gleich(2, count($decoded['questions']), 'Roundtrip: zwei Fragen');
gleich('_meshcop._udp.local', $decoded['questions'][0]['name'], 'Roundtrip: Name der ersten Frage');
gleich(MdnsCodec::TYPE_PTR, $decoded['questions'][0]['type'], 'Roundtrip: Typ der ersten Frage');
pruefe(($decoded['questions'][0]['class'] & 0x8000) !== 0, 'Roundtrip: QU-Bit gesetzt');
pruefe(($decoded['questions'][1]['class'] & 0x8000) === 0, 'Roundtrip: QU-Bit nicht gesetzt');
gleich(false, $decoded['isResponse'], 'Roundtrip: Query ist keine Antwort');

// --- Alle Fixtures müssen sauber dekodieren -------------------------------
$fixtures = glob(__DIR__ . '/fixtures/mdns/*.bin') ?: [];
pruefe(count($fixtures) >= 5, 'Fixtures vorhanden (mindestens 5)');

$typenGesamt = [];
foreach ($fixtures as $datei) {
    $raw = (string)file_get_contents($datei);
    try {
        $message = MdnsCodec::decodeMessage($raw);
    } catch (InvalidArgumentException $e) {
        pruefe(false, basename($datei) . ' dekodierbar (' . $e->getMessage() . ')');
        continue;
    }
    pruefe($message['isResponse'], basename($datei) . ' ist eine Antwort');
    pruefe($message['records'] !== [], basename($datei) . ' enthält Records');
    foreach ($message['records'] as $record) {
        $typenGesamt[$record['type']] = true;
        if ($record['type'] === MdnsCodec::TYPE_AAAA) {
            pruefe(
                filter_var($record['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
                basename($datei) . ': AAAA-Adresse gültig (' . $record['address'] . ')'
            );
        }
        if ($record['type'] === MdnsCodec::TYPE_SRV) {
            pruefe($record['target'] !== '', basename($datei) . ': SRV-Ziel nicht leer');
        }
    }
}
foreach ([MdnsCodec::TYPE_PTR, MdnsCodec::TYPE_SRV, MdnsCodec::TYPE_TXT, MdnsCodec::TYPE_AAAA] as $typ) {
    pruefe(isset($typenGesamt[$typ]), 'Fixtures decken Record-Typ ' . $typ . ' ab');
}

// --- Kaputte Pakete werden abgewiesen, nicht falsch gedeutet --------------
wirft(static fn() => MdnsCodec::decodeMessage('kurz'), 'Zu kurzes Paket wird abgewiesen');

// Antwort-Header (1 Answer), dann ein Name mit Kompressionszeiger auf sich selbst
$schleife = pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0) . chr(0xC0) . chr(12);
wirft(static fn() => MdnsCodec::decodeMessage($schleife), 'Kompressionsschleife wird abgewiesen');

// Label reicht über das Paketende hinaus
$abgeschnitten = pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0) . chr(60) . 'nur-kurz';
wirft(static fn() => MdnsCodec::decodeMessage($abgeschnitten), 'Abgeschnittenes Label wird abgewiesen');

// Ungültige Namen beim Kodieren
wirft(static fn() => MdnsCodec::encodeName('a..b'), 'Leeres Label wird abgewiesen');
wirft(static fn() => MdnsCodec::encodeName(str_repeat('x', 64) . '.local'), 'Überlanges Label wird abgewiesen');
