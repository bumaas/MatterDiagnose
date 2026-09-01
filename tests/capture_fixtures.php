<?php

declare(strict_types=1);

/**
 * Hilfswerkzeug: sammelt echte mDNS-Antworten aus dem LAN ein und legt sie
 * als Binär-Fixtures für die Unit-Tests ab (tests/fixtures/mdns/).
 *
 * Aufruf: php tests/capture_fixtures.php
 */

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsBrowser.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MatterErhebung.php';

$browser   = new MdnsBrowser();
$antworten = $browser->query(
    [
        ['name' => MatterErhebung::DIENST_MESHCOP, 'type' => MdnsCodec::TYPE_PTR],
        ['name' => MatterErhebung::DIENST_MATTER, 'type' => MdnsCodec::TYPE_PTR],
        ['name' => MatterErhebung::DIENST_KOPPELBEREIT, 'type' => MdnsCodec::TYPE_PTR],
    ],
    4.0
);

$zielDir = __DIR__ . '/fixtures/mdns';
if (!is_dir($zielDir) && !mkdir($zielDir, 0777, true)) {
    fwrite(STDERR, "Fixture-Verzeichnis nicht anlegbar\n");
    exit(1);
}

$manifest = [];
foreach ($antworten as $i => $antwort) {
    $datei = sprintf('antwort_%02d.bin', $i);
    file_put_contents($zielDir . '/' . $datei, $antwort['raw']);
    $typen = array_count_values(array_map(
        static fn(array $r): string => (string)$r['type'],
        $antwort['message']['records']
    ));
    $manifest[] = [
        'datei'   => $datei,
        'quelle'  => $antwort['from'],
        'records' => count($antwort['message']['records']),
        'typen'   => $typen,
    ];
    echo $datei, '  von ', $antwort['from'], '  Records: ', count($antwort['message']['records']), PHP_EOL;
}
file_put_contents(
    $zielDir . '/manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

echo PHP_EOL, count($antworten), " Antworten gesichert.", PHP_EOL;

// Gleich als Rauchtest die Verdichtung laufen lassen:
$lage = MatterErhebung::sammeln($antworten, []);
echo 'Border Router: ', count($lage['borderRouter']),
    ', Geräte in Betrieb: ', count($lage['geraeteBetrieb']),
    ', koppelbereit: ', count($lage['geraeteKoppelbereit']), PHP_EOL;
