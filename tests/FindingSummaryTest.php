<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

// Klartext der Befunde für die Variable „Befunde", MATD_RunSelfTest und MATD_RunDiagnosis
// (MCP-Regeln 5 und 9): kurz, eine Zeile je Befund mit Handlungsbedarf, die übrigen als Zahl.

$labels = [
    'blocker' => 'Problem',
    'notice'  => 'Hinweis',
    'ok'      => '%d Prüfung(en) ohne Befund.',
    'allOk'   => 'Keine Befunde, alles in Ordnung.',
    'advice'  => 'Abhilfe',
];
$befunde = [
    ['severity' => DiagnosisEngine::SEVERITY_OK, 'title' => 'IPv6 ist vorhanden', 'advice' => ''],
    ['severity' => DiagnosisEngine::SEVERITY_NOTICE, 'title' => 'Kein Platz mehr frei', 'advice' => "Ein System entfernen.\nDann neu koppeln."],
    ['severity' => DiagnosisEngine::SEVERITY_BLOCKER, 'title' => '2 Geräte nicht erreichbar', 'advice' => 'Geräte neu starten.'],
    ['severity' => DiagnosisEngine::SEVERITY_OK, 'title' => 'mDNS funktioniert', 'advice' => ''],
];

$text = FindingSummary::plainText($befunde, 'Diagnose vom 05.10.2026 12:00 (vollständiger Lauf)', $labels);
assertSame(
    "Diagnose vom 05.10.2026 12:00 (vollständiger Lauf)\n"
    . "Problem: 2 Geräte nicht erreichbar — Abhilfe: Geräte neu starten.\n"
    . "Hinweis: Kein Platz mehr frei — Abhilfe: Ein System entfernen. Dann neu koppeln.\n"
    . '2 Prüfung(en) ohne Befund.',
    $text,
    'Blocker zuerst, dann Hinweise, Gutbefunde nur gezählt, Abhilfe einzeilig'
);

$gut = FindingSummary::plainText([$befunde[0], $befunde[3]], 'Kopf', $labels);
assertSame("Kopf\nKeine Befunde, alles in Ordnung.\n2 Prüfung(en) ohne Befund.", $gut, 'Nur Gutbefunde: ausdrücklich „alles in Ordnung"');

// Welche Geräte gemeint sind, gehört in die Zeile — sonst muss die KI den HTML-Bericht lesen
// (am nuc 05.10.2026: „2 gekoppelte Gerät(e) sind nicht mehr erreichbar" ohne Namen)
$mitGeraeten = FindingSummary::plainText(
    [['severity' => DiagnosisEngine::SEVERITY_BLOCKER, 'title' => '2 Geräte nicht erreichbar', 'advice' => 'Neu starten.', 'devices' => 'Shelly Plug S Gen3 (Id 7), Dimmer (Id 10)']],
    'Kopf',
    $labels + ['devices' => 'Geräte']
);
assertSame("Kopf\nProblem: 2 Geräte nicht erreichbar — Geräte: Shelly Plug S Gen3 (Id 7), Dimmer (Id 10) — Abhilfe: Neu starten.", $mitGeraeten, 'Geräteliste des Befunds steht in der Zeile');

$ohneAbhilfe =FindingSummary::plainText([['severity' => DiagnosisEngine::SEVERITY_NOTICE, 'title' => 'Titel', 'advice' => '']], 'Kopf', $labels);
assertSame("Kopf\nHinweis: Titel", $ohneAbhilfe, 'Ohne Abhilfe kein leerer Zusatz, ohne Gutbefunde keine Nullzeile');
