<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/RunBudget.php';

/**
 * Zeitbudget mit Reserve für den Erreichbarkeitstest (Forum t/144417): Auf Loerdys SymBox
 * brauchte allein das Lesen des Matter-Konfigurators 6 s, dazu Nachfrage-, Direkt- und
 * TXT-Runden — am Ende blieb keine Zeit zum Pingen, und der Bericht meldete bei jedem
 * Lauf „konnte nicht getestet werden".
 *
 * Zahlen wie im Modul: 24 s gesamt, 7 s Reserve; Startzeitpunkt hier fest, damit der Test
 * nicht von der Uhr abhängt.
 */

$start  = 10_000.0;
$budget = new RunBudget(24.0, 7.0, $start);

assertSame(0.0, $budget->elapsed($start), 'Am Start ist nichts verbraucht');
assertSame(4.0, $budget->elapsed($start + 4.0), 'Vergangene Zeit');
assertSame(0.0, $budget->elapsed($start - 5.0), 'Eine rückwärts laufende Uhr ergibt keine negative Zeit');
assertSame(24.0, $budget->remaining($start), 'Am Start steht das ganze Budget');
assertSame(10.0, $budget->remaining($start + 14.0), 'Nach 14 s bleiben 10 s');
assertSame(0.0, $budget->remaining($start + 30.0), 'Überzogen ist null, nicht negativ');

// Am Anfang ist alles erlaubt: 4 s mDNS + 7 s Reserve passen in 24 s
assertSame(true, $budget->phaseAllowed($start, 4.0), 'Erste mDNS-Runde ist erlaubt');
assertSame(true, $budget->phaseAllowed($start + 14.0, 2.0), 'Nach 14 s ist eine 2-s-Runde noch drin (2 + 7 = 9 ≤ 10)');
assertSame(true, $budget->phaseAllowed($start + 14.0, 3.0), 'Genau aufgehende Rechnung zählt als erlaubt (3 + 7 = 10)');
assertSame(false, $budget->phaseAllowed($start + 14.0, 4.0), 'Eine 4-s-Runde würde die Reserve anknabbern');
assertSame(false, $budget->phaseAllowed($start + 18.0, 0.5), 'Nach 18 s ist auch eine halbe Sekunde zu viel — die Reserve steht');
assertSame(false, $budget->phaseAllowed($start + 30.0, 0.1), 'Nach Überziehen ist nichts mehr erlaubt');

// Loerdys Lauf in Zahlen: 6 s Konfigurator + 4 s mDNS + 3 Nachfragerunden à 2 s = 16 s.
// Danach hätten Direktabfragen (4 Router à 0,5 s) und die TXT-Runde weitere 3 s gekostet —
// der Ping wäre leer ausgegangen. Mit Reserve ist ab 17 s Schluss.
$loerdy = new RunBudget(24.0, 7.0, $start);
assertSame(true, $loerdy->phaseAllowed($start + 16.0, 0.5), 'Nach 16 s ist eine Direktabfrage noch erlaubt');
assertSame(false, $loerdy->phaseAllowed($start + 17.5, 0.5), 'Nach 17,5 s nicht mehr');
assertSame(6.5, $loerdy->remaining($start + 17.5), 'Für den Erreichbarkeitstest bleiben dann 6,5 s');

// Ohne Reserve verhält es sich wie früher: erlaubt, solange überhaupt Zeit ist
$ohneReserve = new RunBudget(24.0, 0.0, $start);
assertSame(true, $ohneReserve->phaseAllowed($start + 23.0, 1.0), 'Ohne Reserve zählt nur das Gesamtbudget');
assertSame(false, $ohneReserve->phaseAllowed($start + 23.5, 1.0), 'Auch dann nicht über das Budget hinaus');
