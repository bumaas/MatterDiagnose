<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

// Kopfzeile des Klartexts und Gerätebeschriftung (Code-Review build 79).
//
// Die Kopfzeile nannte jeden Lauf ohne Ping „Wächterlauf" — auch ein MATD_RunDiagnosis(false)
// von Hand oder durch einen KI-Assistenten. Sie beschreibt jetzt, was der Lauf getan hat, und
// das Datum folgt der Sprache (die englische Fassung zeigte „05.10.2026").
//
// „Name (Id N, #I)" stand an drei Stellen (Engine zweimal, module.php einmal); jetzt gibt es
// eine Schreibweise, DiagnosisEngine::nodeLabel.

// --- Kopfzeile -----------------------------------------------------------------
$zeit = mktime(12, 39, 0, 10, 5, 2026);
$kopf = static function (MatterDiagnoseHarness $instanz, bool $ping) use ($zeit): string {
    $methode = new ReflectionMethod($instanz, 'runHeader');

    return (string)$methode->invoke($instanz, $ping, $zeit);
};
$englisch         = neueInstanz();
$deutsch          = neueInstanz();
$deutsch->deutsch = true;
if (method_exists($englisch, 'runHeader')) {
    assertSame('Diagnosis from 2026-10-05 12:39 (without reachability test)', $kopf($englisch, false), 'Kopfzeile englisch ohne Ping');
    assertSame('Diagnosis from 2026-10-05 12:39 (with reachability test)', $kopf($englisch, true), 'Kopfzeile englisch mit Ping');
    assertSame('Diagnose vom 05.10.2026 12:39 (ohne Erreichbarkeitstest)', $kopf($deutsch, false), 'Kopfzeile deutsch ohne Ping');
    assertSame('Diagnose vom 05.10.2026 12:39 (mit Erreichbarkeitstest)', $kopf($deutsch, true), 'Kopfzeile deutsch mit Ping');
    assertSame([], $deutsch->unuebersetzt, 'Kopfzeile vollständig übersetzt');
} else {
    assertTrue(false, 'module.php: runHeader fehlt');
}
assertSame(0, substr_count((string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php'), 'monitoring run without reachability test'), 'Kein „Wächterlauf" mehr für jeden Lauf ohne Ping');

// --- Gerätebeschriftung ----------------------------------------------------------
if (method_exists(DiagnosisEngine::class, 'nodeLabel')) {
    $geraet = ['name' => 'Shelly Plug S Gen3', 'nodeId' => 7, 'instanceId' => 30402];
    assertSame('Shelly Plug S Gen3 (Id 7, #30402)', DiagnosisEngine::nodeLabel($geraet), 'nodeLabel: mit Instanz');
    assertSame('Shelly Plug S Gen3 (Id 7)', DiagnosisEngine::nodeLabel($geraet, false), 'nodeLabel: Kurzform ohne Instanz (Momentaufnahme)');
    assertSame('Shelly Plug S Gen3 (Id 7)', DiagnosisEngine::nodeLabel(['instanceId' => 0] + $geraet), 'nodeLabel: ohne bekannte Instanz');
    assertSame('Shelly Plug S Gen3 (Id 7, #30402, 192.168.178.176)', DiagnosisEngine::nodeLabel($geraet, true, ['192.168.178.176']), 'nodeLabel: Zusätze hinten an');
} else {
    assertTrue(false, 'DiagnosisEngine::nodeLabel fehlt');
}
$quellen = (string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php') . (string)file_get_contents(__DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php');
assertSame(0, preg_match_all("/'%s \\(Id %d(, #%d)?\\)'|'%s \\(%s\\)', \\(string\\)\\\$device\\['name'\\]/", $quellen), 'Keine eigene Schreibweise „Name (Id …)" neben nodeLabel');
