<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/SymconInventory.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';

/**
 * Geräte, die nur auf die Frage nach ihrem Namen angesagt werden (build 98).
 *
 * CrushedBonez (Discord #matter, 09./10.11.2026, Dumps per DM): Der Matter-Konfigurator
 * führte alle 12 Thread-Geräte als „Nicht gefunden“, die Diagnose meldete „Alle gekoppelten
 * Geräte melden sich (12)“. Auf die Suche nach `_matter._tcp` sagte niemand seine
 * Thread-Geräte an, es kamen nur die Echos und die DIRIGERA selbst (9 Ansagen). Erst die
 * namentliche Nachfrage des Moduls brachte alle 12, sämtlich von einer Quelle
 * (`fe80::82f1:b2ff:feaa:1001`). `avahi-browse` und `ZC_QueryServiceType` auf der SymBox
 * zeigten sie nicht. Nach dem Tausch eines Border Routers sagte die DIRIGERA alle 12 schon
 * in der ersten Runde an, das Abo stand wieder auf OK.
 *
 * Das Modul sah die Geräte also auf einem Weg, den sonst niemand geht, und wertete die
 * Abo-Spalte sichtbarer Geräte nicht aus. Seither merkt es sich, wer erst auf die Frage
 * nach dem Namen sichtbar wurde, und meldet das, wenn Symcon das Gerät nicht findet.
 *
 * Fixtures: die Zeilen „Namentliche Nachfrage“, „_matter._tcp (n)“ und „Symcon Geräte“
 * aus seinen beiden Debug-Dumps (11:22 rot, 11:52 grün). Anonymisiert sind das öffentliche
 * IPv6-Präfix (jetzt 2001:db8:0:…), die gerätespezifischen drei Bytes jeder MAC (in den
 * Adressen und den zwölfstelligen Hostnamen) und drei selbst vergebene Gerätenamen; Aufbau,
 * Fabrics, Knoten, Quellen und Abo-Zustände sind die des Mitschnitts.
 */

$ansagen = static function (string $line): array {
    preg_match_all('/"([^"\s]+) ← ([^,]+), Host ([^,]+), ([^,"]*)/u', $line, $found, PREG_SET_ORDER);

    return array_map(static function (array $match): array {
        [$host, $port] = array_pad(explode(':', $match[3], 2), 2, '');

        return [
            'instance'  => $match[1],
            'source'    => $match[2],
            'host'      => $host,
            'port'      => (int)$port,
            'addresses' => $match[4] === 'keine Adresse' ? [] : explode(' ', $match[4]),
        ];
    }, $found);
};
$geraete = static function (string $line): array {
    preg_match_all('/"(.+?) \(Id (\d+)\) sichtbar=(ja|nein) schläft=(ja|nein|\?) Abo=([^"]*)"/u', $line, $found, PREG_SET_ORDER);

    return array_map(static fn(array $match): array => [
        'nodeId'       => (int)$match[2],
        'name'         => $match[1],
        'vendor'       => '',
        'product'      => '',
        'subscription' => $match[5],
        'instanceId'   => 0,
    ], $found);
};
$zeile = static function (array $lines, string $titel): string {
    foreach ($lines as $line) {
        if (preg_match('/^TXT: [^|]+\|\s+' . $titel . ' \|/u', $line) === 1) {
            return $line;
        }
    }

    return '';
};
$nurNamentlich = static fn(array $vorher, array $nachher, array $operational, array $fabrics): ?array => method_exists(SymconInventory::class, 'foundByName')
    ? SymconInventory::foundByName($vorher, $nachher, $operational, $fabrics)
    : null;

$eingabe = [
    'ipv6Addresses'         => ['fd25:0:7:1:e65f:1ff:feaa:2001', '2001:db8:0:2107:e65f:1ff:feaa:2001', 'fe80::e65f:1ff:feaa:2001'],
    'mdnsResponses'         => true,
    'mdnsProbeResponders'   => 0,
    'borderRouters'         => [],
    'commissionableDevices' => [],
    'threadPrefixes'        => [],
    'platform'              => 'Linux',
    'controllerPresent'     => true,
    'devicesAmbiguous'      => false,
    'threadNetworks'        => null,
    'routeAssessment'       => null,
];
$befunde = static function (array $operational, array $known, string $fabric) use ($eingabe): array {
    $liste = [];
    foreach (DiagnosisEngine::evaluate($eingabe + ['operationalDevices' => $operational, 'knownDevices' => $known, 'ownFabricId' => $fabric]) as $befund) {
        $liste[$befund['id']] = $befund;
    }

    return $liste;
};

// Die Fabric von Symcon: die einzige, in der die Knoten 1 bis 13 aus dem Konfigurator stehen
$fabric = 'C05FD5EBE2805976';
$quelle = 'fe80::82f1:b2ff:feaa:1001';

// --- Lauf 11:22: Konfigurator rot ---
$rot = file(__DIR__ . '/fixtures/debug/crushedbonez_rot_2026-11-10.txt', FILE_IGNORE_NEW_LINES) ?: [];
assertSame(3, count($rot), 'Fixture rot: drei Debug-Zeilen');
$nachfrage = $zeile($rot, 'Namentliche Nachfrage');
assertTrue(str_contains($nachfrage, '12 vermisste(s) Gerät(e)') && str_contains($nachfrage, $quelle . ' ×1') && str_contains($nachfrage, '9 → 21 betriebsbereite Annoncen'), 'Fixture rot: 12 vermisst, eine Quelle antwortet, 9 → 21 Ansagen');

$alle   = $ansagen($zeile($rot, '_matter\._tcp \(21\)'));
$symcon = $geraete($zeile($rot, 'Symcon Geräte'));
assertSame(21, count($alle), 'Fixture rot: 21 Ansagen nach der Nachfrage');
assertSame(12, count($symcon), 'Fixture rot: 12 Geräte in Symcon');
assertSame(['Nicht gefunden'], array_values(array_unique(array_column($symcon, 'subscription'))), 'Fixture rot: jedes Abo steht auf „Nicht gefunden“');

// Vor der Nachfrage gab es nur, was nicht von der einen Quelle kam
$ersteRunde = array_values(array_filter($alle, static fn(array $a): bool => $a['source'] !== $quelle));
assertSame(9, count($ersteRunde), 'Fixture rot: die erste Runde brachte 9 Ansagen');
assertSame([], array_values(array_filter($ersteRunde, static fn(array $a): bool => str_starts_with($a['instance'], $fabric))), 'Fixture rot: keine davon in der Fabric von Symcon');

$vorher  = SymconInventory::matchDevices($symcon, $ersteRunde, $fabric)['devices'];
$nachher = SymconInventory::matchDevices($symcon, $alle, $fabric)['devices'];
assertSame(0, count(array_filter($vorher, static fn(array $d): bool => $d['visible'])), 'Nach der ersten Runde ist kein Gerät sichtbar');
assertSame(12, count(array_filter($nachher, static fn(array $d): bool => $d['visible'])), 'Nach der Nachfrage sind alle 12 sichtbar');

$gefunden = $nurNamentlich($vorher, $nachher, $alle, [$fabric]);
assertSame([1, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13], array_keys($gefunden ?? []), 'Alle 12 wurden erst auf die Frage nach dem Namen sichtbar');
assertSame([$quelle], array_values(array_unique($gefunden ?? [])), 'Und zwar alle über dieselbe Quelle');

$mitQuelle = array_map(static fn(array $d): array => $d + ['foundByName' => $gefunden[$d['nodeId']] ?? ''], $nachher);
$ergebnis  = $befunde($alle, $mitQuelle, $fabric);
assertTrue(isset($ergebnis['own_devices_found_by_name']), 'Rot: der Befund erscheint');
assertSame('notice', $ergebnis['own_devices_found_by_name']['severity'] ?? null, 'Rot: als Hinweis');
assertSame('12', $ergebnis['own_devices_found_by_name']['params']['count'] ?? null, 'Rot: 12 Geräte');
assertSame($quelle, $ergebnis['own_devices_found_by_name']['params']['sources'] ?? null, 'Rot: der Befund nennt die Quelle der Antwort');
assertSame('Nicht gefunden', $ergebnis['own_devices_found_by_name']['params']['states'] ?? null, 'Rot: und den Zustand des Abos');
assertTrue(str_contains((string)($ergebnis['own_devices_found_by_name']['params']['devices'] ?? ''), 'GRILLPLATS Plug (Id 3)'), 'Rot: die Geräte stehen beim Namen im Befund');
assertSame('Steckdose A (Id 1)', $ergebnis['own_devices_found_by_name']['devices'][0] ?? null, 'Rot: Kurzform für die Momentaufnahme');
assertTrue(!isset($ergebnis['own_devices_visible']), 'Rot: „Alle gekoppelten Geräte melden sich“ entfällt');

// Gegenprobe: Steht das Abo auf OK, gibt es nichts zu tun, auch wenn erst die Nachfrage half
$abosOk = array_map(static fn(array $d): array => ['subscription' => 'OK!'] + $d, $mitQuelle);
$ok     = $befunde($alle, $abosOk, $fabric);
assertTrue(!isset($ok['own_devices_found_by_name']) && isset($ok['own_devices_visible']), 'Abo OK: kein Befund, alle melden sich');

// --- Lauf 11:52: Konfigurator grün ---
$gruen = file(__DIR__ . '/fixtures/debug/crushedbonez_gruen_2026-11-10.txt', FILE_IGNORE_NEW_LINES) ?: [];
assertSame(2, count($gruen), 'Fixture grün: zwei Debug-Zeilen, eine Nachfrage gab es nicht');
$alleGruen   = $ansagen($zeile($gruen, '_matter\._tcp \(49\)'));
$symconGruen = $geraete($zeile($gruen, 'Symcon Geräte'));
assertSame(49, count($alleGruen), 'Fixture grün: 49 Ansagen');
assertSame(12, count(array_filter($alleGruen, static fn(array $a): bool => str_starts_with($a['instance'], $fabric) && $a['source'] === '192.168.180.101')), 'Fixture grün: die DIRIGERA sagt alle 12 für Symcon an');
assertSame(2, count(array_filter($symconGruen, static fn(array $d): bool => $d['subscription'] === 'Verbinde...')), 'Fixture grün: zwei Abos stehen noch auf „Verbinde...“');

$sichtbar = SymconInventory::matchDevices($symconGruen, $alleGruen, $fabric)['devices'];
assertSame([], $nurNamentlich($sichtbar, $sichtbar, $alleGruen, [$fabric]), 'Grün: niemand brauchte die Nachfrage');
$ergebnisGruen = $befunde($alleGruen, array_map(static fn(array $d): array => $d + ['foundByName' => ''], $sichtbar), $fabric);
assertTrue(!isset($ergebnisGruen['own_devices_found_by_name']), 'Grün: kein Befund, auch nicht für „Verbinde...“');
assertTrue(isset($ergebnisGruen['own_devices_visible']), 'Grün: alle gekoppelten Geräte melden sich');

// --- Ein Abo im Aufbau oder ohne Instanz ist kein „nicht gefunden“ (build 100) ---
// Code-Review 10.11.2026: Der Befund kam bei jedem Abo, das nicht mit „OK“ beginnt. Nach
// einem Neustart von Symcon stehen die Abos für Minuten auf „Verbinde...“, und „Unbekannt“
// heißt nur, dass zum Knoten keine Instanz angelegt ist (nuc, DIRIGERA, 26.09.2026).
$abo = static fn(string $zustand): ?string => method_exists(SymconInventory::class, 'subscriptionState') ? SymconInventory::subscriptionState($zustand) : null;
foreach (['OK!' => 'ok', 'OK (ICD)' => 'ok', 'OK' => 'ok', 'Verbinde...' => 'pending', 'Connecting...' => 'pending', 'Unbekannt' => 'none', 'Nicht gefunden' => 'failed', 'Not found' => 'failed', 'Error' => 'failed'] as $zustand => $erwartet) {
    assertSame($erwartet, $abo($zustand), 'Abo „' . $zustand . '“ zählt als ' . $erwartet);
}
assertSame('none', $abo(''), 'Ein leerer Zustand sagt nichts');

// Die zwei Geräte, die im grünen Lauf noch auf „Verbinde...“ standen, einmal so, als wären
// sie erst auf die Frage nach dem Namen sichtbar geworden
$imAufbau = array_map(
    static fn(array $d): array => $d + ['foundByName' => $d['subscription'] === 'Verbinde...' ? $quelle : ''],
    $sichtbar
);
assertSame(2, count(array_filter($imAufbau, static fn(array $d): bool => $d['foundByName'] !== '')), 'Zwei Geräte im Aufbau tragen die Quelle');
$ergebnisAufbau = $befunde($alleGruen, $imAufbau, $fabric);
assertTrue(!isset($ergebnisAufbau['own_devices_found_by_name']), '„Verbinde...“: kein Befund, das Abo ist im Aufbau');
assertTrue(isset($ergebnisAufbau['own_devices_visible']), '„Verbinde...“: alle gekoppelten Geräte melden sich');

$ohneInstanz = $befunde($alle, array_map(static fn(array $d): array => ['subscription' => 'Unbekannt'] + $d, $mitQuelle), $fabric);
assertTrue(!isset($ohneInstanz['own_devices_found_by_name']), '„Unbekannt“: kein Befund, es gibt keine Instanz');

$fehler = $befunde($alle, array_map(static fn(array $d): array => ['subscription' => 'Error'] + $d, $mitQuelle), $fabric);
assertSame('12', $fehler['own_devices_found_by_name']['params']['count'] ?? null, '„Error“: der Befund bleibt');

// --- Frische Daten nehmen den Hinweis nicht zurück (Absicht, Burkhard 10.11.2026) ---
// Bei CrushedBonez liefen die Werte weiter, während der Konfigurator alle Geräte als nicht
// gefunden zeigte. Anders als bei unsichtbaren Geräten (build 84) bleibt der Hinweis deshalb
// auch mit frischen Daten stehen, und er bleibt ein Hinweis, nie ein Blocker.
$mitDaten = $befunde($alle, array_map(static fn(array $d): array => $d + ['aliveData' => true], $mitQuelle), $fabric);
assertSame('notice', $mitDaten['own_devices_found_by_name']['severity'] ?? null, 'Frische Daten: der Hinweis bleibt, als Hinweis');
assertSame('12', $mitDaten['own_devices_found_by_name']['params']['count'] ?? null, 'Frische Daten: für alle 12 Geräte');
assertTrue(!isset($mitDaten['own_devices_visible']), 'Frische Daten: kein „Alle gekoppelten Geräte melden sich“');

// --- Das Modul reicht die Auskunft durch ---
$modulePhp = (string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php');
assertTrue(str_contains($modulePhp, 'SymconInventory::foundByName('), 'module.php merkt sich, wer erst auf die Nachfrage sichtbar wurde');
assertTrue(preg_match("/\\\$device\\['foundByName'\\]\\s*=/", $modulePhp) === 1, 'module.php trägt es am Gerät ein');

// --- Der Text nennt Geräte, Zustand und Quelle und rät nichts Unbelegtes ---
$locale   = json_decode((string)file_get_contents(__DIR__ . '/../MatterDiagnose/locale.json'), true, 512, JSON_THROW_ON_ERROR);
$imKatalog = preg_match("/'own_devices_found_by_name' => \\[\\s*'([^']*)',\\s*'([^']*)',\\s*'([^']*)'/", $modulePhp, $m) === 1;
assertTrue($imKatalog, 'Katalogeintrag own_devices_found_by_name gefunden');
if ($imKatalog) {
    assertTrue(str_contains($m[1], '%count%'), 'Titel nennt die Zahl');
    foreach (['%devices%', '%states%', '%sources%'] as $platzhalter) {
        assertTrue(str_contains($m[2] . $m[3], $platzhalter), 'Text nennt ' . $platzhalter);
    }
    // Ein Lauf ohne Antwort auf die Suche ist kein Urteil über das Gerät (build 101,
    // Code-Review 10.11.2026): RFC 6762 schreibt Schweigen im Einzelfall sogar vor (§6
    // Sekundenregel, §7.1 Known-Answer Suppression). Der Text sagt deshalb, was dieser Lauf
    // sah, und rät erst zum Prüfen, wenn es so bleibt.
    $abhilfeDe = (string)($locale['translations']['de'][$m[3]] ?? '');
    assertTrue(!str_contains($m[3], 'It answers the question for a single name, but not the general search.'), 'Abhilfe (en): keine Tatsachenbehauptung über die allgemeine Suche');
    assertTrue(!str_contains($abhilfeDe, 'Es beantwortet die Frage nach einem einzelnen Namen, die allgemeine Suche aber nicht.'), 'Abhilfe (de): keine Tatsachenbehauptung über die allgemeine Suche');
    assertTrue(str_contains($m[3], 'In this run') && str_contains($m[3], 'over several runs'), 'Abhilfe (en): nennt den Lauf und rät erst bei Wiederholung');
    assertTrue(str_contains($abhilfeDe, 'In diesem Lauf') && str_contains($abhilfeDe, 'über mehrere Läufe'), 'Abhilfe (de): nennt den Lauf und rät erst bei Wiederholung');
    foreach ([$m[1], $m[2], $m[3]] as $englisch) {
        $deutsch = (string)($locale['translations']['de'][$englisch] ?? '');
        assertTrue($deutsch !== '', 'Übersetzt: ' . substr($englisch, 0, 50));
        assertTrue(!str_contains($englisch . $deutsch, '—') && !str_contains($englisch . $deutsch, '–'), 'Kein langer Gedankenstrich');
    }
}
