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

// --- Forum t/144417: Der Befund muss die Folge nennen (Frage Burkhard, 17.09.2026) ---
// „Keine Störung" war die halbe Wahrheit: Die Ansage ist das, womit Symcon ein Gerät
// wiederfindet. Ohne sie scheitert der nächste Verbindungsaufbau — nach einem Neustart
// von Symcon oder sobald das Gerät eine neue Adresse bekommt. Beide Befundtexte müssen
// das sagen, und der Batterie-Befund zusätzlich den Unterschied zum Gerät am Stromnetz.
// Gelesen wird der Katalog wie oben aus dem Quelltext, das Modul selbst läuft ohne Symcon nicht.
foreach (['own_devices_missing', 'own_devices_missing_battery'] as $id) {
    $anfang = strpos($catalogSource, "'" . $id . "' => [");
    assertTrue($anfang !== false, 'Katalogeintrag ' . $id . ' gefunden');
    if ($anfang === false) {
        continue;
    }
    $eintrag = substr($catalogSource, $anfang, (int)strpos($catalogSource, '],', $anfang) - $anfang);
    assertTrue(
        str_contains($eintrag, 'restart of Symcon') || str_contains($eintrag, 'new address'),
        $id . ': nennt die Folge (Neustart von Symcon oder neue Adresse)'
    );
    assertTrue(
        str_contains($eintrag, 'restarting the device') || str_contains($eintrag, 'restart the device'),
        $id . ': nennt den Neustart des Geräts als Abhilfe'
    );
}
$batterieAnfang  = (int)strpos($catalogSource, "'own_devices_missing_battery' => [");
$batterieEintrag = substr($catalogSource, $batterieAnfang, (int)strpos($catalogSource, '],', $batterieAnfang) - $batterieAnfang);
assertTrue(str_contains($batterieEintrag, '🔋'), 'Der Batterie-Befund erklärt das Zeichen');
assertTrue(
    str_contains($batterieEintrag, 'by itself'),
    'Der Batterie-Befund sagt, dass die Ansage bei einem Batteriegerät von selbst zurückkommt'
);
