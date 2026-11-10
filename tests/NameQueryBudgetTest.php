<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/RunBudget.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';
require_once __DIR__ . '/../MatterDiagnose/libs/SymconInventory.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';

/**
 * Ohne die Frage nach dem Namen gibt es kein Urteil „nicht mehr erreichbar“ (build 99).
 *
 * Code-Review 10.11.2026 zu build 98: Seit die namentliche Nachfrage zwischen dem Hinweis
 * `own_devices_found_by_name` und dem roten `own_devices_unsubscribed` entscheidet, darf sie
 * nicht still entfallen. Sie hing an `phaseAllowed`, also an der vollen Ping-Reserve des
 * Handlaufs, schrieb beim Überspringen nichts ins Debug und fragte höchstens 20 Namen. Wer
 * nicht gefragt wurde, stand rot im Bericht, mit dem Satz „auch nicht auf die namentliche
 * Nachfrage“. Auf einer Anlage wie der von CrushedBonez hätte derselbe Zustand im Handlauf
 * Rot und im Wächterlauf einen Hinweis ergeben.
 *
 * Seither: Die Nachfrage darf wie die Identitätsnachfrage die Reserve anbrechen
 * (`judgementAllowed`), sie fragt bis zu 60 Namen, und ein Gerät, nach dem nicht gefragt
 * wurde, bekommt den Hinweis `own_devices_unasked` statt des roten Urteils.
 *
 * Die Geräte stammen aus dem Mitschnitt von CrushedBonez (FoundByNameTest), die Zeitpunkte
 * sind Rechengrößen des Budgets (24 s, 7 s Reserve, Kosten 1 s), keine Messwerte.
 */

$total   = 24.0;
$reserve = 7.0;
$start   = 1000.0;
$kosten  = 1.0; // BUDGET_DIRECT * 2

// --- Budget: Die Nachfrage darf an die Reserve, der Ping behält seine Mindestzeit ---
foreach (['Linux', 'Windows'] as $plattform) {
    $boden = OsAdapter::pingMinimumSeconds(2000, $plattform) + 0.5;
    $hand  = RunBudget::forRun($total, $reserve, $start, true, $boden);
    $grenzeAlt = $total - $reserve - $kosten;         // bis hierhin erlaubte phaseAllowed
    $grenzeNeu = $total - $boden - $kosten;           // bis hierhin erlaubt judgementAllowed
    assertTrue($grenzeNeu > $grenzeAlt, "$plattform: die neue Grenze liegt später als die alte");
    $dazwischen = $start + ($grenzeAlt + $grenzeNeu) / 2;
    assertSame(false, $hand->phaseAllowed($dazwischen, $kosten), "$plattform: phaseAllowed verbietet die Nachfrage zwischen den Grenzen");
    assertSame(true, $hand->judgementAllowed($dazwischen, $kosten, true), "$plattform: judgementAllowed erlaubt sie dort");
    assertSame(false, $hand->judgementAllowed($start + $grenzeNeu + 0.1, $kosten, true), "$plattform: dahinter bleibt die Zeit dem Ping");
}

$modulePhp = (string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php');
assertTrue(
    preg_match('/\$vermisst !== \[\] && \$budget->judgementAllowed\(microtime\(true\), self::BUDGET_DIRECT \* 2, true\)/', $modulePhp) === 1,
    'module.php: die namentliche Nachfrage prüft mit judgementAllowed'
);
assertTrue(
    preg_match("/debug\\('Namentliche Nachfrage', sprintf\\('kein Budget/", $modulePhp) === 1,
    'module.php: eine übersprungene Nachfrage steht im Debug'
);
assertTrue(preg_match("/\\\$device\\['nameAsked'\\]\\s*=/", $modulePhp) === 1, 'module.php trägt am Gerät ein, ob nach ihm gefragt wurde');

// --- Die Grenze: mehr als 20 vermisste Geräte ---
$fabric   = 'C05FD5EBE2805976';
$viele    = array_map(static fn(int $node): array => ['nodeId' => $node, 'visible' => false], range(1, 25));
$namen    = SymconInventory::instanceNamesForMissing($viele, [$fabric]);
assertSame(25, count($namen), '25 vermisste Geräte: nach allen wird gefragt');
$fragen   = array_map(static fn(string $name): array => ['name' => $name, 'type' => MdnsCodec::TYPE_SRV], SymconInventory::instanceNamesForMissing(
    array_map(static fn(int $node): array => ['nodeId' => $node, 'visible' => false], range(1, 80)),
    [$fabric]
));
// Seit build 104 ohne Obergrenze: Sonst gäbe es neben der Zeit einen zweiten Grund, aus dem
// nicht gefragt wird, und der Befundtext nennt nur einen.
assertSame(80, count($fragen), '80 vermisste Geräte: nach allen wird gefragt');
assertTrue(count(MdnsCodec::encodeQueries($fragen)) > 1, '80 Fragen verteilen sich auf mehrere Pakete');
foreach (MdnsCodec::encodeQueries($fragen) as $paket) {
    assertTrue(strlen($paket) <= MdnsCodec::MAX_QUERY_BYTES, 'Jedes Fragepaket bleibt unter der Fragmentierungsgrenze');
}

$gefragt = method_exists(SymconInventory::class, 'nodesAskedByName') ? SymconInventory::nodesAskedByName($namen) : null;
assertSame(range(1, 25), $gefragt, 'Aus den Fragen lässt sich lesen, nach welchen Geräten gefragt wurde');
assertSame([], method_exists(SymconInventory::class, 'nodesAskedByName') ? SymconInventory::nodesAskedByName([]) : null, 'Ohne Frage niemand');

// --- Bewertung: nicht gefragt ist nicht „nicht erreichbar“ ---
$zeile = '';
foreach (file(__DIR__ . '/fixtures/debug/crushedbonez_rot_2026-11-10.txt', FILE_IGNORE_NEW_LINES) ?: [] as $line) {
    if (preg_match('/^TXT: [^|]+\|\s+Symcon Geräte \|/u', $line) === 1) {
        $zeile = $line;
    }
}
preg_match_all('/"(.+?) \(Id (\d+)\) sichtbar=(ja|nein) schläft=(ja|nein|\?) Abo=([^"]*)"/u', $zeile, $found, PREG_SET_ORDER);
$symcon = array_map(static fn(array $match): array => [
    'nodeId'       => (int)$match[2],
    'name'         => $match[1],
    'vendor'       => '',
    'product'      => '',
    'subscription' => $match[5],
    'instanceId'   => 0,
    'visible'      => false,
], $found);
assertSame(12, count($symcon), 'Fixture: 12 Geräte, alle mit Abo „Nicht gefunden“');

$eingabe = [
    'ipv6Addresses'         => ['fd25:0:7:1:e65f:1ff:feaa:2001', 'fe80::e65f:1ff:feaa:2001'],
    'mdnsResponses'         => true,
    'mdnsProbeResponders'   => 0,
    'borderRouters'         => [],
    'commissionableDevices' => [],
    'operationalDevices'    => [],
    'threadPrefixes'        => [],
    'platform'              => 'Linux',
    'controllerPresent'     => true,
    'devicesAmbiguous'      => false,
    'threadNetworks'        => null,
    'routeAssessment'       => null,
    'ownFabricId'           => $fabric,
];
$befunde = static function (array $known) use ($eingabe): array {
    $liste = [];
    foreach (DiagnosisEngine::evaluate($eingabe + ['knownDevices' => $known]) as $befund) {
        $liste[$befund['id']] = $befund;
    }

    return $liste;
};

// Die Nachfrage fiel aus: kein rotes Urteil, sondern der Hinweis mit dem wahren Grund
$ohneFrage = $befunde(array_map(static fn(array $d): array => $d + ['nameAsked' => false], $symcon));
assertTrue(!isset($ohneFrage['own_devices_unsubscribed']), 'Nicht gefragt: kein „nicht mehr erreichbar“');
assertSame('notice', $ohneFrage['own_devices_unasked']['severity'] ?? null, 'Nicht gefragt: Hinweis own_devices_unasked');
assertSame('12', $ohneFrage['own_devices_unasked']['params']['count'] ?? null, 'Nicht gefragt: alle 12 Geräte');
assertSame('Nicht gefunden', $ohneFrage['own_devices_unasked']['params']['states'] ?? null, 'Nicht gefragt: der Zustand des Abos steht dabei');
assertSame('Steckdose A (Id 1)', $ohneFrage['own_devices_unasked']['devices'][0] ?? null, 'Nicht gefragt: Kurzform für die Momentaufnahme');
assertTrue(!isset($ohneFrage['own_devices_visible']), 'Nicht gefragt: kein „Alle melden sich“');

// Gefragt und keine Antwort: Das rote Urteil bleibt, wie es war
$mitFrage = $befunde(array_map(static fn(array $d): array => $d + ['nameAsked' => true], $symcon));
assertSame('blocker', $mitFrage['own_devices_unsubscribed']['severity'] ?? null, 'Gefragt ohne Antwort: weiter rot');
assertTrue(!isset($mitFrage['own_devices_unasked']), 'Gefragt ohne Antwort: kein Hinweis auf eine fehlende Frage');

// Ohne die Angabe (ältere Aufrufer) gilt das Gerät als gefragt
$ohneAngabe = $befunde($symcon);
assertTrue(isset($ohneAngabe['own_devices_unsubscribed']) && !isset($ohneAngabe['own_devices_unasked']), 'Ohne Angabe: wie bisher rot');

// Ein Lebenszeichen geht vor: Wer Daten liefert, braucht die Frage nicht
$mitDaten = $befunde(array_map(static fn(array $d): array => $d + ['nameAsked' => false, 'aliveData' => true], $symcon));
assertTrue(!isset($mitDaten['own_devices_unasked']) && isset($mitDaten['own_devices_visible_data']), 'Frische Daten: kein Hinweis, nur der Gutbefund');

// Gemischt: 2 von 12 nicht gefragt
$gemischt = $befunde(array_map(static fn(array $d): array => $d + ['nameAsked' => $d['nodeId'] > 3], $symcon));
assertSame('2', $gemischt['own_devices_unasked']['params']['count'] ?? null, 'Gemischt: 2 Geräte ohne Frage');
assertSame('10', $gemischt['own_devices_unsubscribed']['params']['count'] ?? null, 'Gemischt: 10 Geräte gefragt und stumm');

// --- Der Text sagt, was fehlt, und was zu tun ist ---
$locale    = json_decode((string)file_get_contents(__DIR__ . '/../MatterDiagnose/locale.json'), true, 512, JSON_THROW_ON_ERROR);
$imKatalog = preg_match("/'own_devices_unasked' => \\[\\s*'([^']*)',\\s*'([^']*)',\\s*'([^']*)'/", $modulePhp, $m) === 1;
assertTrue($imKatalog, 'Katalogeintrag own_devices_unasked gefunden');
if ($imKatalog) {
    assertTrue(str_contains($m[1], '%count%'), 'Titel nennt die Zahl');
    foreach (['%devices%', '%states%'] as $platzhalter) {
        assertTrue(str_contains($m[2], $platzhalter), 'Text nennt ' . $platzhalter);
    }
    assertTrue($m[3] !== '', 'Es gibt eine Abhilfe');
    // build 104 (Burkhard 10.11.2026): Der Titel sagt, was geschah, der Text nennt die Zeit
    // als Grund, und die Abhilfe ist dieselbe wie bei thread_prefix_untested_budget.
    $de = static fn(string $en): string => (string)($locale['translations']['de'][$en] ?? '');
    assertTrue(str_contains($m[1], 'they were not asked for by name') && str_contains($de($m[1]), 'nach dem Namen wurde nicht gefragt'), 'Titel: es wurde nicht gefragt');
    assertTrue(!str_contains($m[1], 'still open') && !str_contains($de($m[1]), 'steht noch aus'), 'Titel stellt nichts in Aussicht');
    assertTrue(str_contains($m[2], 'no time left') && str_contains($de($m[2]), 'keine Zeit mehr'), 'Text nennt die Zeit als Grund');
    $budget = preg_match("/'thread_prefix_untested_budget' => \\[\\s*'[^']*',\\s*'[^']*',\\s*'([^']*)'/", $modulePhp, $b) === 1 ? $b[1] : '';
    assertSame($budget, $m[3], 'Abhilfe wie beim Zeitbudget-Befund des Thread-Netzes');
    assertTrue(!str_contains($m[3], 'reachability test'), 'Abhilfe verweist auf keinen Lauf, für den es keinen Knopf gibt');
    foreach ([$m[1], $m[2], $m[3]] as $englisch) {
        $deutsch = (string)($locale['translations']['de'][$englisch] ?? '');
        assertTrue($deutsch !== '', 'Übersetzt: ' . substr($englisch, 0, 50));
        assertTrue(!str_contains($englisch . $deutsch, '—') && !str_contains($englisch . $deutsch, '–'), 'Kein langer Gedankenstrich');
    }
}
