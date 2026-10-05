<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

// Ein Lauf dauert 10–25 s. Seit build 75 kann ihn auch ein Skript oder ein KI-Assistent starten
// (MATD_RunDiagnosis) — ohne Sperre lief er dann neben dem Wächter: Beide hörten auf Port 5353
// die Antworten des anderen mit, lasen dieselbe alte Momentaufnahme und schrieben Changes doppelt
// (Code-Review build 77). Ein zweiter Lauf derselben Instanz wartet nicht (er liefe sonst in den
// 30-s-Timeout des Aufrufers), sondern sagt, dass schon einer läuft.

$instanz = neueInstanz();
$instanz->deutsch = true;
$sperre  = 'MatterDiagnose.' . $instanz->id();

// Ohne Sperre im Code würde der Aufruf unten einen echten Lauf ins Netz starten — dann gar nicht
// erst aufrufen, der Test ist auch so rot.
$modul    = (string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php');
$gesperrt = str_contains($modul, 'IPS_SemaphoreEnter(');
assertTrue($gesperrt, 'module.php: Lauf hinter einer Semaphore');

if ($gesperrt) {
    assertTrue(IPS_SemaphoreEnter($sperre, 0), 'Test belegt die Sperre der Instanz (läuft gerade)');

    $antwort = $instanz->RunDiagnosis(false);
    assertTrue(str_contains($antwort, 'läuft bereits'), 'RunDiagnosis: sagt, dass schon ein Lauf läuft (' . $antwort . ')');
    $antwort = $instanz->RunDiagnosis(true);
    assertTrue(str_contains($antwort, 'läuft bereits'), 'RunDiagnosis mit Ping: ebenso');
    $instanz->RequestAction('Monitor', true);
    $instanz->RequestAction('Diagnosis', true);
    assertSame(0, (int)GetValue(IPS_GetObjectIDByIdent('LastRun', $instanz->id())), 'Kein zweiter Lauf hat Statusvariablen geschrieben');
    assertSame([], $instanz->unuebersetzt, 'Meldung übersetzt');

    // Die fremde Sperre bleibt stehen — der zweite Aufruf gibt sie nicht frei
    assertTrue(!IPS_SemaphoreEnter($sperre, 0), 'Sperre des laufenden Laufs bleibt belegt');
    IPS_SemaphoreLeave($sperre);

    // Eine andere Instanz ist davon nicht betroffen
    assertTrue($sperre !== 'MatterDiagnose.' . neueInstanz()->id(), 'Sperre je Instanz');
}
