<?php

declare(strict_types=1);

/**
 * Statische Prüfung der Wertanzeige-Optionen (Anlass: 19.09.2026, erpe im Forum
 * t/144417/24 — beim Öffnen der Variablen "Matter-Netz OK" meldete die Konsole
 * "Ungültiges Formular: Undefined array key IconActive", die Android-Visu
 * "type 'Null' is not a subtype of 'bool'", und das WebFront lud nicht mehr).
 *
 * Ursache: Die Optionen der Darstellung trugen nur die Felder, die das Modul
 * tatsächlich nutzt (Value, Caption, ColorActive, ColorValue). Das Formular der
 * Konsole liest aber alle Felder einer Option, teils ohne isset() — siehe die
 * Fixture fixtures/presentation/value_presentation_form.txt, ein echter Auszug
 * aus valuePresentationForm.php. Fehlt eines, landet eine PHP-Warnung vor dem
 * JSON und das Formular ist unbrauchbar.
 *
 * Regel seither: Jede Option trägt alle Felder, die Symcon liest — mit echten
 * Werten statt "nicht gesetzt".
 */

$moduleDir = dirname(__DIR__) . '/MatterDiagnose';
$modulePhp = (string)file_get_contents($moduleDir . '/module.php');
$fixture   = (string)file_get_contents(__DIR__ . '/fixtures/presentation/value_presentation_form.txt');

// 1. Welche Felder liest Symcon? Antwort aus dem echten Formularskript.
preg_match_all('/\$option\[["\']([A-Za-z_]+)["\']\]/', $fixture, $treffer);
$gelesen = array_values(array_unique($treffer[1]));
sort($gelesen);
// Die *Display-Felder berechnet das Formular selbst, sie werden nur geschrieben.
$erwartet = array_values(array_filter($gelesen, static fn(string $feld): bool => !str_ends_with($feld, 'Display')));

assertSame(
    ['ColorActive', 'ColorValue', 'ContentColorActive', 'ContentColorValue', 'IconActive', 'IconValue'],
    $erwartet,
    'Die Fixture nennt die sechs Felder, die das Darstellungs-Formular je Option liest'
);
// Value und Caption kommen hinzu: ohne sie hätte die Option weder Bezug noch Text.
$pflichtfelder = array_merge(['Value', 'Caption'], $erwartet);

// 2. Die Optionen aus module.php holen.
assertTrue(
    preg_match("/'OPTIONS'\s*=>\s*json_encode\(\[(.*?)\],\s*JSON_THROW_ON_ERROR\)/s", $modulePhp, $block) === 1,
    'module.php definiert die Optionen der Wertanzeige'
);
$optionsBlock = $block[1] ?? '';
$anzahlWerte  = preg_match_all("/'Value'\s*=>/", $optionsBlock);
$anzahl       = preg_match_all("/\[\s*'Value'\s*=>[^\[\]]*\]/", $optionsBlock, $eintraege);

assertTrue($anzahl >= 1, 'Der Optionsblock enthält mindestens eine Option');
assertSame($anzahlWerte, $anzahl, 'Jede Option des Blocks wird vom Test erfasst (eine Option je Eintrag)');

// 3. Jede Option trägt alle Felder — und die Schalter als echten Wahrheitswert,
//    sonst sieht die Visu null statt bool.
foreach ($eintraege[0] as $index => $eintrag) {
    preg_match_all("/'([A-Za-z_]+)'\s*=>\s*([^,\]]+)/", $eintrag, $felder);
    $vorhanden = $felder[1];
    $werte     = array_combine($felder[1], array_map('trim', $felder[2]));

    foreach ($pflichtfelder as $feld) {
        assertTrue(
            in_array($feld, $vorhanden, true),
            'Option ' . $index . ': Feld "' . $feld . '" ist gesetzt'
        );
    }
    foreach (['IconActive', 'ColorActive', 'ContentColorActive'] as $schalter) {
        if (isset($werte[$schalter])) {
            assertTrue(
                in_array($werte[$schalter], ['true', 'false'], true),
                'Option ' . $index . ': "' . $schalter . '" ist true oder false, kein anderer Ausdruck'
            );
        }
    }
}
