<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

/**
 * Hinweis „kein Gerät koppelbereit“ mit geschlossenem Fenster (build 86).
 *
 * Bei Loerdy (PN t/144583/15, 1.0 #85) nannte der Hinweis seinen Shelly Plug S Gen3 (Id 5)
 * und riet: „das Gerät gehört vermutlich schon zu einem anderen System (Werksreset nötig)“.
 * Der Plug war in Symcon gekoppelt und lief; er annonciert nur zusätzlich `_matterc` mit
 * CM=0. Gekoppelte Geräte fallen deshalb aus dem Hinweis (Szenarien
 * `commissionable_closed_*`), und der Text nennt nur, was das geschlossene Fenster belegt:
 * keine Vermutung, kein Werksreset.
 */

$locale = json_decode((string)file_get_contents(__DIR__ . '/../MatterDiagnose/locale.json'), true, 512, JSON_THROW_ON_ERROR);
$module = (string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php');

$gefunden = preg_match("/'no_commissionable_closed_only' => \\[\\s*'([^']*)',\\s*'([^']*)',\\s*'([^']*)'/", $module, $m) === 1;
assertTrue($gefunden, 'Katalogeintrag no_commissionable_closed_only gefunden');
if ($gefunden) {
    foreach ([$m[1], $m[2], $m[3]] as $englisch) {
        if ($englisch === '') {
            continue;
        }
        $deutsch = (string)($locale['translations']['de'][$englisch] ?? '');
        assertTrue($deutsch !== '', 'Übersetzt: ' . substr($englisch, 0, 50));
        foreach (['may already', 'factory reset', 'probably'] as $wort) {
            assertTrue(stripos($englisch, $wort) === false, 'Englischer Text ohne „' . $wort . '“');
        }
        foreach (['vermutlich', 'Werksreset'] as $wort) {
            assertTrue(stripos($deutsch, $wort) === false, 'Deutscher Text ohne „' . $wort . '“');
        }
        assertTrue(!str_contains($englisch . $deutsch, '—'), 'Kein langer Gedankenstrich');
    }
}
