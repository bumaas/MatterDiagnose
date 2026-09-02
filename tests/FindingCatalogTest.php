<?php

declare(strict_types=1);

/**
 * Statische Prüfung: Die Befund-IDs, die DiagnosisEngine erzeugen kann, und die
 * Katalogeinträge in module.php (findingTexts) müssen deckungsgleich sein.
 *
 * Anlass (02.09.2026): Nach Rücksprache mit paresy entfielen die Befunde zur
 * Portkonkurrenz auf 5353 und zur eigenen Controller-Annonce. Ohne diesen Test
 * blieben ihre Texte samt Übersetzungen unbemerkt als Leichen im Katalog —
 * und umgekehrt fiele ein neuer Engine-Befund ohne Text erst im Formular auf
 * (dort erschiene dann nur die nackte ID).
 */

$moduleDir = dirname(__DIR__) . '/MatterDiagnose';
$enginePhp = (string)file_get_contents($moduleDir . '/libs/DiagnosisEngine.php');
$modulePhp = (string)file_get_contents($moduleDir . '/module.php');

preg_match_all('/self::finding\(\s*self::SEVERITY_[A-Z]+\s*,\s*\'([a-z0-9_]+)\'/', $enginePhp, $m);
$engineIds = array_values(array_unique($m[1]));
sort($engineIds);

$catalogStart = strpos($modulePhp, '$catalog = [');
assertTrue($catalogStart !== false, 'module.php enthält den Befundkatalog ($catalog = [)');
$catalogSource = substr($modulePhp, (int)$catalogStart);
preg_match_all('/^\s{12}\'([a-z0-9_]+)\'\s*=>\s*\[/m', $catalogSource, $m);
$catalogIds = array_values(array_unique($m[1]));
sort($catalogIds);

assertTrue(count($engineIds) >= 10, 'Engine kennt mindestens zehn Befunde (Regex greift)');

foreach (array_diff($engineIds, $catalogIds) as $id) {
    assertTrue(false, 'Befund "' . $id . '" hat keinen Text im Katalog von module.php');
}
foreach (array_diff($catalogIds, $engineIds) as $id) {
    assertTrue(false, 'Katalogeintrag "' . $id . '" wird von der Engine nie erzeugt (Leiche)');
}
assertSame($engineIds, $catalogIds, 'Befund-IDs der Engine und Katalog in module.php sind deckungsgleich');
