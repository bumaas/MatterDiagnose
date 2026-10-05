<?php

declare(strict_types=1);

/**
 * Gesamtlauf aller *Test.php in einem Prozess — bequem beim Entwickeln.
 * Aufruf: php tests/run_tests.php
 *
 * Jede Testdatei läuft auch allein (php tests/<Datei>.php); so ruft sie die CI auf.
 * Asserts, Fehlerstrenge und Schlusszeile kommen aus tests/harness.php.
 */

require_once __DIR__ . '/harness.php';

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $testFile) {
    echo '— ', basename($testFile), PHP_EOL;
    require $testFile;
}
