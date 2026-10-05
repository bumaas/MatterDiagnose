<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

// Geräte mit Abo „Nicht gefunden" stehen im Matter-Konfigurator OHNE instanceID (und ohne
// create) — obwohl ihre Instanz existiert. Gefunden im Blindtest 05.10.2026: Die vermissten
// Shellys standen ohne Symcon-Instanz und ohne „letzte Daten vor …" im Befund, also genau dort,
// wo die Angabe am meisten nützt.
//
// Die Instanz fehlt aber nicht, sie steht eine Zeile tiefer: Der Konfigurator hängt die
// vorhandene Instanz als Unterzeile OHNE "Id" an (Name = Objektpfad, "7_0"). Der Parser übernimmt
// sie als Instanz des Knotens (Code-Review build 77). Der Umweg über alle Instanzen am Controller
// (build 76) lief am nuc in jedem Lauf, weil die DIRIGERA als Bridge nie eine instanceID hat, und
// gab einer Bridge die Instanz eines gebrückten Endpunkts.
//
// Fixture: echter Mitschnitt vom nuc (05.10.2026 16:19): Formular des Konfigurators #58999 und
// die Geräteinstanzen am Controller #56638, wie module.php::deviceInstances sie liefert.

$mitschnitt = json_decode((string)file_get_contents(__DIR__ . '/fixtures/symcon/not_found_without_instance_nuc.json'), true, 512, JSON_THROW_ON_ERROR);
$bekannt    = [];
foreach (SymconInventory::devicesFromConfiguratorForm($mitschnitt['form']) as $geraet) {
    $bekannt[$geraet['nodeId']] = $geraet;
}

// Die Instanz kommt aus der Unterzeile ohne Id
assertSame(30402, $bekannt[7]['instanceId'] ?? null, 'Plug (Knoten 7, Nicht gefunden): Instanz aus Unterzeile 7_0');
assertSame(20390, $bekannt[10]['instanceId'] ?? null, 'Dimmer (Knoten 10, Nicht gefunden): Instanz aus Unterzeile 10_0');
assertSame(54334, $bekannt[13]['instanceId'] ?? null, 'ALPSTUGA (Knoten 13, OK): instanceID der Knotenzeile bleibt');

// Der Objektpfad der Unterzeile ist kein Endpunktname — er stünde sonst im Befundtext
assertSame([], $bekannt[7]['endpointNames'] ?? null, 'Plug: kein Objektpfad als Endpunktname');
assertSame([], $bekannt[10]['endpointNames'] ?? null, 'Dimmer: kein Objektpfad als Endpunktname');
assertSame(['Ein/Aus Steckdose', 'Elektrischer Sensor'], $bekannt[11]['endpointNames'] ?? null, 'GRILLPLATS: echte Endpunktnamen bleiben');

// Die Bridge hat keine eigene Instanz und bekommt keine
assertSame(0, $bekannt[12]['instanceId'] ?? null, 'DIRIGERA (Bridge): keine Instanz');
assertSame(['Warmwasserpumpe', 'Bewegungsmelder Gast', 'Hue Aurelle'], $bekannt[12]['endpointNames'] ?? null, 'DIRIGERA: gebrückte Endpunkte bleiben Endpunktnamen');

// Gegenprobe am Mitschnitt: jede Instanz am Controller ist genau ihrem Knoten zugeordnet
foreach ($mitschnitt['instances'] as $instanz) {
    assertSame($instanz['instanceId'], $bekannt[$instanz['nodeId']]['instanceId'] ?? null, 'Instanz #' . $instanz['instanceId'] . ' an Knoten ' . $instanz['nodeId']);
}

// Der Umweg über den Instanz-Scan ist entfallen
assertSame(false, method_exists(SymconInventory::class, 'withInstanceIds'), 'withInstanceIds entfällt');
assertSame(0, substr_count((string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php'), 'withInstanceIds'), 'module.php scannt die Instanzen nicht mehr bei jedem Lauf');
