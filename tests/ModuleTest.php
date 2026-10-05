<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

// MatterDiagnose am offiziellen Kernel-Stub: was Create(), ApplyChanges(),
// GetConfigurationForm() und RequestAction() ohne Netz leisten müssen. Ein Diagnoselauf
// selbst braucht mDNS und Systemkommandos und gehört nicht hierher.

// --- Create: Variablen, Darstellungen, Reihenfolge ------------------------
$instanz   = neueInstanz();
$variablen = $instanz->variablen();
$erwartet  = [
    'Healthy'        => [VARIABLETYPE_BOOLEAN, VARIABLE_PRESENTATION_VALUE_PRESENTATION, 10],
    'KnownDevices'   => [VARIABLETYPE_INTEGER, VARIABLE_PRESENTATION_VALUE_PRESENTATION, 20],
    'VisibleDevices' => [VARIABLETYPE_INTEGER, VARIABLE_PRESENTATION_VALUE_PRESENTATION, 30],
    'BorderRouters'  => [VARIABLETYPE_INTEGER, VARIABLE_PRESENTATION_VALUE_PRESENTATION, 40],
    'LastRun'        => [VARIABLETYPE_INTEGER, VARIABLE_PRESENTATION_DATE_TIME, 50],
    'Changes'        => [VARIABLETYPE_STRING, VARIABLE_PRESENTATION_VALUE_PRESENTATION, 60],
    'Report'         => [VARIABLETYPE_STRING, VARIABLE_PRESENTATION_WEB_CONTENT, 70],
];
assertSame(array_keys($erwartet), array_keys($variablen), 'Create: genau die sieben Statusvariablen, in Positionsreihenfolge');
foreach ($erwartet as $ident => [$typ, $darstellung, $position]) {
    $variable = $variablen[$ident] ?? null;
    assertSame($typ, $variable['VariableType'] ?? null, "Create: $ident hat den richtigen Typ");
    assertSame($darstellung, $variable['VariablePresentation']['PRESENTATION'] ?? null, "Create: $ident hat die richtige Darstellung");
    assertSame($position, $variable['Position'] ?? null, "Create: $ident steht an Position $position");
    assertSame('', $variable['VariableProfile'] ?? null, "Create: $ident ohne Legacy-Profil");
}
assertSame(true, $variablen['Changes']['VariablePresentation']['MULTILINE'] ?? null, 'Create: Änderungen mehrzeilig (Forum t/144417, Einträge klebten sonst zusammen)');

$optionen = json_decode((string)($variablen['Healthy']['VariablePresentation']['OPTIONS'] ?? ''), true);
assertSame(2, is_array($optionen) ? count($optionen) : 0, 'Create: Healthy trägt zwei Optionen');
foreach (is_array($optionen) ? $optionen : [] as $option) {
    // Fehlt eines dieser Felder, bricht der Darstellungsdialog der Konsole ab (t/144417/24)
    foreach (['Value', 'Caption', 'IconActive', 'IconValue', 'ColorActive', 'ColorValue', 'ContentColorActive', 'ContentColorValue'] as $feld) {
        assertTrue(array_key_exists($feld, $option), "Create: Healthy-Option hat das Feld $feld");
    }
}

// --- ApplyChanges: Wächter-Intervall ------------------------------------
assertSame(102, IPS_GetInstance($instanz->id())['InstanceStatus'], 'ApplyChanges: Instanz aktiv');
assertSame(60 * 60 * 1000, $instanz->timer['Monitor'] ?? null, 'ApplyChanges: Vorgabe 60 Minuten');
$instanz->einstellen('MonitorInterval', 5);
assertSame(5 * 60 * 1000, $instanz->timer['Monitor'] ?? null, 'ApplyChanges: 5 Minuten');
$instanz->einstellen('MonitorInterval', 0);
assertSame(0, $instanz->timer['Monitor'] ?? null, 'ApplyChanges: 0 = Wächter aus');
$instanz->einstellen('MonitorInterval', -3);
assertSame(0, $instanz->timer['Monitor'] ?? null, 'ApplyChanges: negativer Wert schaltet aus statt zu scheitern');

// --- GetConfigurationForm: ohne und mit Geräteliste ----------------------
$formular = static function (MatterDiagnoseHarness $instanz): array {
    return json_decode($instanz->GetConfigurationForm(), true, 64, JSON_THROW_ON_ERROR);
};
$element = static function (array $liste, string $name): ?array {
    foreach ($liste as $eintrag) {
        if (($eintrag['name'] ?? '') === $name) {
            return $eintrag;
        }
    }

    return null;
};

$leer = $formular(neueInstanz());
$vorlage = json_decode((string)file_get_contents(dirname(__DIR__) . '/MatterDiagnose/form.json'), true, 64, JSON_THROW_ON_ERROR);
assertSame($vorlage, $leer, 'Formular ohne Lauf: unverändert form.json');

$mitGeraeten = neueInstanz();
$mitGeraeten->einstellen('FabricNames', json_encode([
    ['Id' => '35fa0000aaaa0001', 'Name' => 'Apple Home'],
    ['Id' => 'DEADBEEF00000000', 'Name' => 'Gerade nicht zu sehen'],
], JSON_THROW_ON_ERROR));
$mitGeraeten->attributSetzen('Devices', json_encode([
    'columns' => [
        ['id' => 'AAAA000000000001', 'label' => 'Symcon', 'letter' => '', 'named' => false, 'own' => true, 'count' => 3],
        ['id' => '35FA0000AAAA0001', 'label' => 'Apple Home', 'letter' => 'A', 'named' => true, 'own' => false, 'count' => 9],
        ['id' => '77770000BBBB0002', 'label' => 'B', 'letter' => 'B', 'named' => false, 'own' => false, 'count' => 2],
    ],
    'rows' => [
        ['Name' => 'Steckdose', 'Vendor' => 'Shelly', 'Link' => 'WLAN', 'Power' => 'Netz', 'F0' => '✓', 'F1' => '✓', 'F2' => '', 'Via' => '', 'Address' => '192.0.2.7'],
    ],
], JSON_THROW_ON_ERROR));
$voll = $formular($mitGeraeten);

$geraete = $element($voll['actions'], 'Devices');
$spalten = array_column($geraete['columns'] ?? [], 'name');
assertSame(['Name', 'Vendor', 'Link', 'Power', 'F0', 'F1', 'F2', 'Via', 'Address'], $spalten, 'Formular: eine Spalte je System zwischen Power und Via');
assertSame('Apple Home (A)', $geraete['columns'][5]['caption'] ?? null, 'Formular: benanntes System mit Buchstabe');
assertSame(1, count($geraete['values'] ?? []), 'Formular: Gerätezeilen aus dem letzten Lauf');
assertSame(1, $geraete['rowCount'] ?? null, 'Formular: Zeilenzahl folgt der Liste');

$legende = $element($voll['actions'], 'FabricLegend');
assertSame(true, $legende['visible'] ?? null, 'Formular: Legende sichtbar, sobald es Systeme gibt');
assertTrue(str_contains((string)($legende['caption'] ?? ''), '35FA0000AAAA0001'), 'Formular: Legende nennt die Kennung des fremden Systems');

$auswahl = [];
foreach ($element($voll['elements'], 'FabricNames')['columns'] ?? [] as $spalte) {
    if ($spalte['name'] === 'Id') {
        $auswahl = array_column($spalte['edit']['options'] ?? [], 'caption', 'value');
    }
}
assertSame(
    ['35FA0000AAAA0001' => 'A: 35FA0000AAAA0001 (9)', '77770000BBBB0002' => 'B: 77770000BBBB0002 (2)', 'DEADBEEF00000000' => 'DEADBEEF00000000'],
    $auswahl,
    'Formular: Benennungsauswahl = fremde Systeme des Laufs plus benannte, die gerade fehlen; das eigene nicht'
);

// --- RequestAction: unbekannte Aktion ------------------------------------
assertThrows(static fn () => $instanz->RequestAction('GibtEsNicht', true), 'RequestAction: unbekannter Ident wirft');
