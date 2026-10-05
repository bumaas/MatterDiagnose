<?php

declare(strict_types=1);

/**
 * Gesamtlauf aller *Test.php — bequem beim Entwickeln.
 * Aufruf: php tests/run_tests.php
 *
 * Jede Testdatei läuft in einem eigenen Prozess, genau wie in der CI. In einem gemeinsamen
 * Prozess wanderten Variablen und Stub-Zustand von Test zu Test; ein Test konnte hier grün
 * und in der CI rot sein (Code-Review build 79). Die Schlusszeile summiert die Läufe im
 * Format der harness.php („N Prüfungen, M Fehler", Exit 0/1).
 */

$total    = 0;
$failures = 0;
foreach (glob(__DIR__ . '/*Test.php') ?: [] as $testFile) {
    echo '— ', basename($testFile), PHP_EOL;
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($testFile) . ' 2>&1', $output, $exitCode);
    $summary = null;
    foreach ($output as $line) {
        if (preg_match('/^(\d+) Prüfungen, (\d+) Fehler$/', $line, $match) === 1) {
            $summary = $match;
        } elseif (trim($line) !== '') {
            echo '  ', $line, PHP_EOL;
        }
    }
    if ($summary === null) {
        // Fatal ohne Schlusszeile: zählt als ein Fehler, sonst fiele er in der Summe weg
        echo '  ABBRUCH ohne Schlusszeile (Exit ', $exitCode, ')', PHP_EOL;
        $failures++;
        continue;
    }
    $total    += (int)$summary[1];
    $failures += (int)$summary[2];
    if ((int)$summary[2] === 0 && $exitCode !== 0) {
        echo '  Exit ', $exitCode, ' trotz 0 Fehlern', PHP_EOL;
        $failures++;
    }
}

printf('%s%d Prüfungen, %d Fehler%s', PHP_EOL, $total, $failures, PHP_EOL);
exit($failures === 0 ? 0 : 1);
