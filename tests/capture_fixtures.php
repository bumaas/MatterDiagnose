<?php

declare(strict_types=1);

/**
 * Hilfswerkzeug: sammelt echte mDNS-Antworten aus dem LAN ein und legt sie
 * als Binär-Fixtures für die Unit-Tests ab (tests/fixtures/mdns/).
 *
 * Aufruf: php tests/capture_fixtures.php
 */

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsBrowser.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MatterDiscovery.php';

$browser   = new MdnsBrowser();
$responses = $browser->query(
    [
        ['name' => MatterDiscovery::SERVICE_MESHCOP, 'type' => MdnsCodec::TYPE_PTR],
        ['name' => MatterDiscovery::SERVICE_MATTER, 'type' => MdnsCodec::TYPE_PTR],
        ['name' => MatterDiscovery::SERVICE_COMMISSIONABLE, 'type' => MdnsCodec::TYPE_PTR],
    ],
    4.0
);

$targetDir = __DIR__ . '/fixtures/mdns';
if (!is_dir($targetDir) && !mkdir($targetDir, 0777, true)) {
    fwrite(STDERR, "Fixture-Verzeichnis nicht anlegbar\n");
    exit(1);
}

$manifest = [];
foreach ($responses as $index => $response) {
    $file = sprintf('response_%02d.bin', $index);
    file_put_contents($targetDir . '/' . $file, $response['raw']);
    $types = array_count_values(array_map(
        static fn(array $record): string => (string)$record['type'],
        $response['message']['records']
    ));
    $manifest[] = [
        'file'    => $file,
        'source'  => $response['from'],
        'records' => count($response['message']['records']),
        'types'   => $types,
    ];
    echo $file, '  von ', $response['from'], '  Records: ', count($response['message']['records']), PHP_EOL;
}
file_put_contents(
    $targetDir . '/manifest.json',
    json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
);

echo PHP_EOL, count($responses), " Antworten gesichert.", PHP_EOL;

// Gleich als Rauchtest die Verdichtung laufen lassen:
$survey = MatterDiscovery::collect($responses, []);
echo 'Border Router: ', count($survey['borderRouters']),
    ', Geräte in Betrieb: ', count($survey['operationalDevices']),
    ', koppelbereit: ', count($survey['commissionableDevices']), PHP_EOL;
