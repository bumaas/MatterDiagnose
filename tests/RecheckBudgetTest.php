<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/RunBudget.php';

/**
 * Das Zeitbudget darf die Nachfrage vor „nicht mehr erreichbar" nicht verhindern (build 66).
 *
 * Debug am nuc, 25.09.2026 15:43 (IPS_EnableDebugFile): Die Identitätsrunde verpasste den
 * Shelly Dimmer; die Nachfrage kam bei 16,7 s von 24 s und meldete „kein Budget", weil
 * phaseAllowed 7 s Reserve für den Ping freihält und nur bis 16,5 s neue Schritte erlaubt.
 * Die Nachfrage lief so seit build 63 nie — auch nicht im Wächterlauf, der gar nicht pingt.
 *
 * Seither: Der Wächterlauf hält keine Ping-Reserve (RunBudget::forRun), und die erste
 * Nachfragerunde darf die Reserve anbrechen (judgementAllowed), weitere Runden nicht.
 */

$total   = 24.0;
$reserve = 7.0;
$start   = 1000.0;
$bei     = $start + 16.7; // gemessener Zeitpunkt der Nachfrage
$direkt  = 0.5;           // BUDGET_DIRECT
$pause   = 2.5;           // RECHECK_PAUSE

$alt = new RunBudget($total, $reserve, $start);
assertSame(false, $alt->phaseAllowed($bei, $direkt), 'Ausgangslage: phaseAllowed verbietet die Nachfrage bei 16,7 s');

if (!method_exists(RunBudget::class, 'forRun') || !method_exists(RunBudget::class, 'judgementAllowed')) {
    assertTrue(false, 'RunBudget::forRun oder RunBudget::judgementAllowed fehlt');
} else {
    // --- Wächterlauf: kein Ping, keine Reserve -----------------------------------
    $waechter = RunBudget::forRun($total, $reserve, $start, false);
    assertSame(true, $waechter->judgementAllowed($bei, $direkt, true), 'Wächterlauf: erste Runde bei 16,7 s erlaubt');
    $runde2 = $bei + 0.3 + $pause;
    assertSame(true, $waechter->judgementAllowed($runde2, $direkt, false), 'Wächterlauf: zweite Runde (19,5 s) erlaubt');
    $runde3 = $runde2 + $direkt + $pause;
    assertSame(true, $waechter->judgementAllowed($runde3, $direkt, false), 'Wächterlauf: dritte Runde (22,5 s) erlaubt');
    assertSame(false, $waechter->judgementAllowed($start + 23.8, $direkt, false), 'Auch der Wächterlauf überzieht das Gesamtbudget nicht');
    assertSame(true, $waechter->phaseAllowed($bei, $direkt), 'Wächterlauf: auch andere Schritte ohne Reserve');

    // --- Handlauf: Ping-Reserve bleibt, nur die erste Runde darf daran --------------
    $hand = RunBudget::forRun($total, $reserve, $start, true);
    assertSame(true, $hand->judgementAllowed($bei, $direkt, true), 'Handlauf: erste Runde darf an die Reserve (0,5 s)');
    assertSame(false, $hand->judgementAllowed($bei + 0.3, $pause + $direkt, false), 'Handlauf: weitere Runden nur ohne Griff in die Reserve');
    assertSame(true, $hand->judgementAllowed($start + 10.1, $pause + $direkt, false), 'Handlauf früh im Lauf: weitere Runden erlaubt');
    assertSame(false, $hand->judgementAllowed($start + 23.8, $direkt, true), 'Auch die erste Runde nicht über das Gesamtbudget hinaus');
    assertSame(false, $hand->phaseAllowed($bei, $direkt), 'Handlauf: übrige Schritte halten die Reserve weiter frei');
}
