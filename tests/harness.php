<?php

declare(strict_types=1);

/*
 * Gemeinsamer Testrahmen. Jede Testdatei bindet ihn mit
 *     require_once __DIR__ . '/harness.php';
 * ein und ist damit ein eigenständiges Programm: php tests/<Datei>.php, ohne Argumente.
 *
 * - assertTrue/assertSame/assertThrows zählen die Prüfungen,
 * - jede PHP-Warnung/-Notice bricht ab, statt still durchzulaufen,
 * - am Ende steht die Schlusszeile „N Prüfungen, M Fehler", Exit 0 grün / 1 rot
 *   (das Format liest rotgruen.php),
 * - module.php hängt am offiziellen Kernel-Stub (symcon/SymconStubs, Submodul tests/stubs,
 *   gepinnt auf bf2950f); Instanzen für Modultests liefert neueInstanz().
 *
 * tests/run_tests.php lädt alle *Test.php in einem Prozess (bequemer Gesamtlauf); die CI
 * ruft jede Datei einzeln auf.
 */

error_reporting(E_ALL);

// Warnungen und Notices des Moduls sollen Tests abbrechen, nicht still durchlaufen.
// E_USER_NOTICE bleibt außen vor (der Stub meldet so einen unbekannten Ident und liefert
// false — normaler Ablauf beim Registrieren), ebenso E_DEPRECATED.
set_error_handler(static function (int $nr, string $text, string $datei, int $zeile): bool {
    if (!(error_reporting() & $nr)) {
        return false; // mit @ unterdrückt — kein Testfehler
    }
    if ($nr & (E_USER_ERROR | E_USER_WARNING | E_WARNING | E_NOTICE)) {
        throw new ErrorException($text, 0, $nr, $datei, $zeile);
    }

    return false;
});

require_once __DIR__ . '/stubs/autoload.php';
require_once dirname(__DIR__) . '/MatterDiagnose/module.php';

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

// Schlusszeile und Exit-Code — Format ist Pflicht (K2). Endet das Skript mit einem Fatal
// (auch einer ungefangenen Exception), bleibt es bei dessen Exit-Code 255: Eine Schlusszeile
// „0 Fehler" wäre dann gelogen.
register_shutdown_function(static function (): void {
    $last = error_get_last();
    if ($last !== null && ($last['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) !== 0) {
        return;
    }
    printf(
        '%s%d Prüfungen, %d Fehler%s',
        PHP_EOL,
        $GLOBALS['__tests']['total'],
        count($GLOBALS['__tests']['failures']),
        PHP_EOL
    );
    exit($GLOBALS['__tests']['failures'] === [] ? 0 : 1);
});

/**
 * MatterDiagnose am Kernel-Stub. Ein Lauf (RequestAction Diagnosis/Monitor) spricht Netz und
 * Betriebssystem an und gehört nicht in die Unit-Tests — geprüft wird hier der Rahmen:
 * Registrierung, Timer, Formular, Aktionen.
 */
final class MatterDiagnoseHarness extends MatterDiagnose
{
    public const MODULE_ID = '{0C9DD2EC-BEC8-46F7-AFA5-936B313C730A}'; // MatterDiagnose/module.json

    /** @var array<string, int> letztes SetTimerInterval je Timer */
    public array $timer = [];

    public function id(): int
    {
        return $this->InstanceID;
    }

    /** Property setzen und übernehmen, wie Speichern im Formular. */
    public function einstellen(string $name, mixed $wert): void
    {
        IPS_SetProperty($this->InstanceID, $name, $wert);
        IPS_ApplyChanges($this->InstanceID);
    }

    public function attributSetzen(string $name, string $wert): void
    {
        $this->WriteAttributeString($name, $wert);
    }

    /** Variablen der Instanz: Ident → Objekt aus IPS_GetVariable plus Name und Position */
    public function variablen(): array
    {
        $variablen = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
            $objekt = IPS_GetObject($id);
            if ($objekt['ObjectType'] === 2) {
                $variablen[$objekt['ObjectIdent']] = IPS_GetVariable($id) + ['Name' => $objekt['ObjectName'], 'Position' => $objekt['ObjectPosition']];
            }
        }

        return $variablen;
    }

    /* --- Stub-Overrides: Signaturen exakt wie tests/stubs/ModuleStrictStubs.php (nicht ModuleStubs.php — dort andere) --- */

    protected function getTime(): int
    {
        return time(); // RegisterTimer/SetTimerInterval brauchen eine Uhr
    }

    protected function SetTimerInterval(string $Ident, int $Milliseconds): bool
    {
        $this->timer[$Ident] = $Milliseconds;

        return parent::SetTimerInterval($Ident, $Milliseconds);
    }
}

/** Legt eine Instanz im Kernel-Stub an (Create + ApplyChanges laufen in createInstance). */
function neueInstanz(): MatterDiagnoseHarness
{
    static $kernelBereit = false;
    if (!$kernelBereit) {
        IPS\Kernel::reset();
        $kernelBereit = true;
    }
    $id = IPS\ObjectManager::registerObject(1 /* Instance */);
    IPS\InstanceManager::createInstance($id, [
        'ModuleID'   => MatterDiagnoseHarness::MODULE_ID,
        'ModuleName' => 'Matter Diagnose',
        'ModuleType' => 3,
        'Class'      => MatterDiagnoseHarness::class,
    ]);
    $instanz = IPS\InstanceManager::getInstanceInterface($id);
    assert($instanz instanceof MatterDiagnoseHarness);

    return $instanz;
}
