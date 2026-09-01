<?php

declare(strict_types=1);

/**
 * Minimaler Testrunner ohne Fremdabhängigkeiten (kein Composer/PHPUnit nötig).
 * Aufruf: php tests/run_tests.php
 */

error_reporting(E_ALL);

$GLOBALS['__tests'] = ['gesamt' => 0, 'fehler' => []];

function pruefe(bool $bedingung, string $name): void
{
    $GLOBALS['__tests']['gesamt']++;
    if (!$bedingung) {
        $GLOBALS['__tests']['fehler'][] = $name;
        echo 'FEHLER: ', $name, PHP_EOL;
    }
}

function gleich(mixed $erwartet, mixed $ist, string $name): void
{
    pruefe(
        $erwartet === $ist,
        sprintf('%s (erwartet %s, erhalten %s)', $name, var_export($erwartet, true), var_export($ist, true))
    );
}

function wirft(callable $fn, string $name): void
{
    try {
        $fn();
        pruefe(false, $name . ' (keine Exception geworfen)');
    } catch (InvalidArgumentException) {
        pruefe(true, $name);
    }
}

foreach (glob(__DIR__ . '/*Test.php') ?: [] as $testDatei) {
    echo '— ', basename($testDatei), PHP_EOL;
    require $testDatei;
}

echo PHP_EOL, $GLOBALS['__tests']['gesamt'], ' Prüfungen, ',
    count($GLOBALS['__tests']['fehler']), ' Fehler', PHP_EOL;
exit($GLOBALS['__tests']['fehler'] === [] ? 0 : 1);
