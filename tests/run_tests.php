<?php

declare(strict_types=1);

/**
 * Minimaler Testrunner ohne Fremdabhängigkeiten (kein Composer/PHPUnit nötig).
 * Aufruf: php tests/run_tests.php
 */

error_reporting(E_ALL);

$GLOBALS['__tests'] = ['total' => 0, 'failures' => []];

function assertTrue(bool $condition, string $name): void
{
    $GLOBALS['__tests']['total']++;
    if (!$condition) {
        $GLOBALS['__tests']['failures'][] = $name;
        echo 'FEHLER: ', $name, PHP_EOL;
    }
}

function assertSame(mixed $expected, mixed $actual, string $name): void
{
    assertTrue(
        $expected === $actual,
        sprintf('%s (erwartet %s, erhalten %s)', $name, var_export($expected, true), var_export($actual, true))
    );
}

function assertThrows(callable $fn, string $name): void
{
    try {
        $fn();
        assertTrue(false, $name . ' (keine Exception geworfen)');
    } catch (InvalidArgumentException) {
        assertTrue(true, $name);
    }
}

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $testFile) {
    echo '— ', basename($testFile), PHP_EOL;
    require $testFile;
}

echo PHP_EOL, $GLOBALS['__tests']['total'], ' Prüfungen, ',
    count($GLOBALS['__tests']['failures']), ' Fehler', PHP_EOL;
exit($GLOBALS['__tests']['failures'] === [] ? 0 : 1);
