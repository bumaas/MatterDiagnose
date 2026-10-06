<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MatterDiscovery.php';

/**
 * Die Nachfrage reicht für eine große Apple-Installation (build 83).
 *
 * Alexandros Apple TV sagte 150 Matter-Knoten an (35 Geräte in bis zu vier Systemen), für
 * rund 144 fehlte der SRV-Eintrag. Die Nachfrage stellte drei Runden zu je 20 Fragen, und
 * jede wurde beantwortet (62, 56 und 70 Records, drei je Frage). Nach 60 Fragen war Schluss:
 * 84 Ansagen blieben ohne Host, aus 35 sichtbaren Geräten wurden im Bericht 18. Zeit war
 * genug da, der Lauf brauchte 16,9 von 24 s (PN t/144583/7, 0.9 #82).
 *
 * Seither fragt jede Runde mehr, verteilt auf Pakete, die in eine IPv6-Mindest-MTU passen.
 *
 * Fixture: tests/fixtures/debug/alexandro_2026-10-06.json, aus seinem Debug-Auszug gezogen.
 */

$lauf      = json_decode((string)file_get_contents(__DIR__ . '/fixtures/debug/alexandro_2026-10-06.json'), true, 512, JSON_THROW_ON_ERROR);
$instanzen = array_map(static fn(array $a): string => (string)$a['instance'], $lauf['operational']);
assertSame(150, count($instanzen), 'Fixture: 150 Ansagen');

$jeRunde = defined('MatterDiscovery::FOLLOW_UP_QUESTIONS') ? MatterDiscovery::FOLLOW_UP_QUESTIONS : 20;
$runden  = defined('MatterDiscovery::FOLLOW_UP_ROUNDS') ? MatterDiscovery::FOLLOW_UP_ROUNDS : 3;

// Schlimmster Fall: keiner Ansage liegt der SRV bei, dazu die AAAA der Hosts mit Adresse
$hosts  = array_values(array_unique(array_filter(array_map(static fn(array $a): string => (string)$a['host'], $lauf['operational']))));
$survey = [
    'borderRouters'    => [],
    'missingRouterTxt' => [],
    'missingTxt'       => [],
    'missingSrv'       => $instanzen,
    'missingAddresses' => [],
];
$asked = [];
for ($runde = 0; $runde < $runden; $runde++) {
    $fragen = MatterDiscovery::followUpQuestions($survey, $jeRunde, $asked);
    if ($fragen === []) {
        break;
    }
    array_push($asked, ...$fragen);
}
$srvGefragt = array_filter($asked, static fn(array $q): bool => $q['type'] === MdnsCodec::TYPE_SRV);
assertSame(150, count($srvGefragt), 'Alle 150 SRV werden in den Nachfragerunden gestellt');

// Wird ein Teil der SRV in Runde 1 beantwortet, kommen die AAAA ihrer Hosts noch dran
$survey['missingAddresses'] = $hosts;
$asked = [];
for ($runde = 0; $runde < $runden; $runde++) {
    $fragen = MatterDiscovery::followUpQuestions($survey, $jeRunde, $asked);
    if ($fragen === []) {
        break;
    }
    array_push($asked, ...$fragen);
}
$aaaaGefragt = array_filter($asked, static fn(array $q): bool => $q['type'] === MdnsCodec::TYPE_AAAA);
assertSame(count($hosts), count($aaaaGefragt), 'Nach den SRV auch die AAAA aller ' . count($hosts) . ' Hosts');

// --- Pakete: jede Runde passt in Pakete der IPv6-Mindest-MTU ---
$fragen  = array_map(static fn(string $name): array => ['name' => $name, 'type' => MdnsCodec::TYPE_SRV, 'unicast' => false], array_slice($instanzen, 0, $jeRunde));
$pakete  = method_exists(MdnsCodec::class, 'encodeQueries') ? MdnsCodec::encodeQueries($fragen) : [MdnsCodec::encodeQuery($fragen)];
$zurueck = [];
foreach ($pakete as $nr => $paket) {
    assertTrue(strlen($paket) <= 1232, sprintf('Paket %d mit %d Bytes passt in 1232 Bytes', $nr + 1, strlen($paket)));
    foreach (MdnsCodec::decodeMessage($paket)['questions'] as $frage) {
        $zurueck[] = $frage['name'];
    }
}
assertSame(array_column($fragen, 'name'), $zurueck, 'Alle Fragen der Runde stehen in den Paketen, in derselben Reihenfolge');
