<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';

// --- Roundtrip: Query bauen und wieder zerlegen ---------------------------
$query = MdnsCodec::encodeQuery([
    ['name' => '_meshcop._udp.local', 'type' => MdnsCodec::TYPE_PTR, 'unicast' => true],
    ['name' => '_matter._tcp.local', 'type' => MdnsCodec::TYPE_PTR, 'unicast' => false],
]);
$decoded = MdnsCodec::decodeMessage($query);
assertSame(2, count($decoded['questions']), 'Roundtrip: zwei Fragen');
assertSame('_meshcop._udp.local', $decoded['questions'][0]['name'], 'Roundtrip: Name der ersten Frage');
assertSame(MdnsCodec::TYPE_PTR, $decoded['questions'][0]['type'], 'Roundtrip: Typ der ersten Frage');
assertTrue(($decoded['questions'][0]['class'] & 0x8000) !== 0, 'Roundtrip: QU-Bit gesetzt');
assertTrue(($decoded['questions'][1]['class'] & 0x8000) === 0, 'Roundtrip: QU-Bit nicht gesetzt');
assertSame(false, $decoded['isResponse'], 'Roundtrip: Query ist keine Antwort');

// --- Alle Fixtures müssen sauber dekodieren -------------------------------
$fixtures = glob(__DIR__ . '/fixtures/mdns/*.bin') ?: [];
assertTrue(count($fixtures) >= 5, 'Fixtures vorhanden (mindestens 5)');

$seenTypes = [];
foreach ($fixtures as $file) {
    $raw = (string)file_get_contents($file);
    try {
        $message = MdnsCodec::decodeMessage($raw);
    } catch (InvalidArgumentException $e) {
        assertTrue(false, basename($file) . ' dekodierbar (' . $e->getMessage() . ')');
        continue;
    }
    assertTrue($message['isResponse'], basename($file) . ' ist eine Antwort');
    assertTrue($message['records'] !== [], basename($file) . ' enthält Records');
    foreach ($message['records'] as $record) {
        $seenTypes[$record['type']] = true;
        if ($record['type'] === MdnsCodec::TYPE_AAAA) {
            assertTrue(
                filter_var($record['address'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
                basename($file) . ': AAAA-Adresse gültig (' . $record['address'] . ')'
            );
        }
        if ($record['type'] === MdnsCodec::TYPE_SRV) {
            assertTrue($record['target'] !== '', basename($file) . ': SRV-Ziel nicht leer');
        }
    }
}
foreach ([MdnsCodec::TYPE_PTR, MdnsCodec::TYPE_SRV, MdnsCodec::TYPE_TXT, MdnsCodec::TYPE_AAAA] as $type) {
    assertTrue(isset($seenTypes[$type]), 'Fixtures decken Record-Typ ' . $type . ' ab');
}

// --- Kaputte Pakete werden abgewiesen, nicht falsch gedeutet --------------
assertThrows(static fn() => MdnsCodec::decodeMessage('kurz'), 'Zu kurzes Paket wird abgewiesen');

// Antwort-Header (1 Answer), dann ein Name mit Kompressionszeiger auf sich selbst
$loop = pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0) . chr(0xC0) . chr(12);
assertThrows(static fn() => MdnsCodec::decodeMessage($loop), 'Kompressionsschleife wird abgewiesen');

// Label reicht über das Paketende hinaus
$truncated = pack('nnnnnn', 0, 0x8400, 0, 1, 0, 0) . chr(60) . 'nur-kurz';
assertThrows(static fn() => MdnsCodec::decodeMessage($truncated), 'Abgeschnittenes Label wird abgewiesen');

// Ungültige Namen beim Kodieren
assertThrows(static fn() => MdnsCodec::encodeName('a..b'), 'Leeres Label wird abgewiesen');
assertThrows(static fn() => MdnsCodec::encodeName(str_repeat('x', 64) . '.local'), 'Überlanges Label wird abgewiesen');
