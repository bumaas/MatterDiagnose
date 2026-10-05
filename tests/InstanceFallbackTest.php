<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

// Geräte mit Abo „Nicht gefunden" stehen im Matter-Konfigurator OHNE instanceID (und ohne
// create) — obwohl ihre Instanz existiert. Gefunden im Blindtest 05.10.2026: Die vermissten
// Shellys standen ohne Symcon-Instanz und ohne „letzte Daten vor …" im Befund, also genau dort,
// wo die Angabe am meisten nützt. Die Instanz wird über die NodeId der Instanzen am selben
// Controller ergänzt (SymconInventory::withInstanceIds).
//
// Fixture: echter Mitschnitt vom nuc (05.10.2026 16:19): Formular des Konfigurators #58999 und
// die Geräteinstanzen am Controller #56638, wie module.php::deviceInstances sie liefert.

$mitschnitt = json_decode((string)file_get_contents(__DIR__ . '/fixtures/symcon/not_found_without_instance_nuc.json'), true, 512, JSON_THROW_ON_ERROR);
$bekannt    = [];
foreach (SymconInventory::devicesFromConfiguratorForm($mitschnitt['form']) as $geraet) {
    $bekannt[$geraet['nodeId']] = $geraet;
}

// Der Ausgangsbefund: Symcon lässt die Instanz bei „Nicht gefunden" weg
assertSame(0, $bekannt[7]['instanceId'] ?? null, 'Mitschnitt: Plug (Knoten 7, Nicht gefunden) ohne instanceID');
assertSame(0, $bekannt[10]['instanceId'] ?? null, 'Mitschnitt: Dimmer (Knoten 10, Nicht gefunden) ohne instanceID');
assertSame(54334, $bekannt[13]['instanceId'] ?? null, 'Mitschnitt: ALPSTUGA (Knoten 13, OK) mit instanceID');

$ergaenzt = [];
foreach (SymconInventory::withInstanceIds(array_values($bekannt), $mitschnitt['instances']) as $geraet) {
    $ergaenzt[$geraet['nodeId']] = $geraet;
}
assertSame(30402, $ergaenzt[7]['instanceId'] ?? null, 'Plug: Instanz über die NodeId ergänzt');
assertSame(20390, $ergaenzt[10]['instanceId'] ?? null, 'Dimmer: Instanz über die NodeId ergänzt');
assertSame(54334, $ergaenzt[13]['instanceId'] ?? null, 'Vorhandene instanceID bleibt');
assertSame(count($bekannt), count($ergaenzt), 'Kein Gerät kommt hinzu oder fällt weg');

// Mehrere Instanzen je Knoten: die Wurzel (EndpointId 0) gewinnt, sonst die kleinste ID —
// abgeleitet vom Mitschnitt, eine zusätzliche Endpunkt-Instanz mit kleinerer ID davor
$mitEndpunkt = array_merge([['instanceId' => 10001, 'name' => 'Endpunkt', 'nodeId' => 7, 'endpointId' => 1]], $mitschnitt['instances']);
$plug        = array_values(array_filter(SymconInventory::withInstanceIds(array_values($bekannt), $mitEndpunkt), static fn (array $g): bool => $g['nodeId'] === 7))[0] ?? [];
assertSame(30402, $plug['instanceId'] ?? null, 'Wurzelinstanz (EndpointId 0) vor kleinerer Endpunkt-ID');
$nurEndpunkte = [['instanceId' => 10002, 'name' => 'B', 'nodeId' => 7, 'endpointId' => 2], ['instanceId' => 10001, 'name' => 'A', 'nodeId' => 7, 'endpointId' => 1]];
$plug         = array_values(array_filter(SymconInventory::withInstanceIds(array_values($bekannt), $nurEndpunkte), static fn (array $g): bool => $g['nodeId'] === 7))[0] ?? [];
assertSame(10001, $plug['instanceId'] ?? null, 'Ohne Wurzelinstanz die kleinste ID');
