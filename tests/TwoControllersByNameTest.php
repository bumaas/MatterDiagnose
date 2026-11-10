<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MdnsResponses.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ThreadNetwork.php';
require_once __DIR__ . '/../MatterDiagnose/libs/MatterDiscovery.php';
require_once __DIR__ . '/../MatterDiagnose/libs/SymconInventory.php';

/**
 * Dieselbe Node-ID in zwei eigenen Fabrics ist nicht dasselbe Gerät (build 102).
 *
 * Code-Review 10.11.2026 zu build 98: `SymconInventory::foundByName` schlüsselte nur nach
 * Node-ID, die Geräteliste ist aber die Verkettung aller Matter Controller, und Node-IDs
 * wiederholen sich je Fabric. Wurde Knoten 6 des einen Controllers erst auf die Frage nach
 * dem Namen sichtbar, bekam auch Knoten 6 des anderen die Markierung, und die Quelle kam
 * von der ersten Ansage mit dieser Node-ID, gleich aus welcher Fabric.
 *
 * Fixture: tests/fixtures/mdns/dualstack, nuc, 25.09.2026. Im selben LAN stehen die Fabric
 * des nuc (A5AC1650B5C2EE16) und die der Testbox (90B99E147F5D9954), und die Knoten 6, 11
 * und 12 gibt es in beiden. Der Test behandelt beide als Controller einer Anlage; die
 * Ansagen sind die des Mitschnitts, die Gerätelisten nennen nur Node-ID und Abo.
 */

$dir      = __DIR__ . '/fixtures/mdns/dualstack';
$manifest = json_decode((string)file_get_contents($dir . '/manifest.json'), true);
$pakete   = [];
foreach ($manifest as $m) {
    if ($m['anlage'] !== 'nuc') {
        continue;
    }
    $from     = $m['family'] === 'v6' ? '[' . $m['from'] . ']:' . $m['port'] : $m['from'] . ':' . $m['port'];
    $pakete[] = ['from' => $from, 'message' => MdnsCodec::decodeMessage((string)file_get_contents($dir . '/' . $m['file']))];
}
$fragen = [
    ['name' => '_meshcop._udp.local', 'type' => MdnsCodec::TYPE_PTR],
    ['name' => '_matter._tcp.local', 'type' => MdnsCodec::TYPE_PTR],
    ['name' => '_matterc._udp.local', 'type' => MdnsCodec::TYPE_PTR],
];
$alle = MatterDiscovery::collect(MdnsResponses::relevant(MdnsResponses::preferIpv4($pakete), $fragen), ['192.168.178.86'])['operationalDevices'];

$nuc     = 'A5AC1650B5C2EE16';
$testbox = '90B99E147F5D9954';
$ansage  = static function (string $fabric, int $node) use ($alle): ?array {
    foreach ($alle as $a) {
        if (stripos((string)$a['instance'], sprintf('%s-%016X.', $fabric, $node)) === 0) {
            return $a;
        }
    }

    return null;
};
foreach ([$nuc, $testbox] as $fabric) {
    assertTrue($ansage($fabric, 6) !== null, "Fixture: Knoten 6 ist in der Fabric $fabric angesagt");
}
$quelleNuc     = (string)($ansage($nuc, 6)['source'] ?? '');
$quelleTestbox = (string)($ansage($testbox, 6)['source'] ?? '');
assertTrue($quelleNuc !== '' && $quelleTestbox !== '', 'Fixture: beide Ansagen nennen ihre Quelle');

$geraet = static fn(int $node, string $abo): array => ['nodeId' => $node, 'name' => 'Knoten ' . $node, 'vendor' => '', 'product' => '', 'subscription' => $abo, 'instanceId' => 0];
// Beide Controller führen einen Knoten 6; beim nuc steht das Abo auf „Nicht gefunden“
$bekannt = [$nuc => [$geraet(6, 'Nicht gefunden')], $testbox => [$geraet(6, 'OK!')]];
$abgleich = static function (array $operational) use ($bekannt): array {
    $liste = [];
    foreach ($bekannt as $fabric => $known) {
        array_push($liste, ...SymconInventory::matchDevices($known, $operational, $fabric)['devices']);
    }

    return $liste;
};

// Vor der Nachfrage fehlt nur die Ansage des nuc-Knotens 6, die der Testbox ist da
$ohneNuc6 = array_values(array_filter($alle, static fn(array $a): bool => stripos((string)$a['instance'], sprintf('%s-%016X.', $nuc, 6)) !== 0));
$vorher   = $abgleich($ohneNuc6);
$nachher  = $abgleich($alle);
assertSame([false, true], array_column($vorher, 'visible'), 'Vorher: nuc-Knoten 6 fehlt, Testbox-Knoten 6 ist sichtbar');
assertSame([true, true], array_column($nachher, 'visible'), 'Nachher: beide sichtbar');
assertSame([$nuc, $testbox], array_column($nachher, 'fabric'), 'Jedes Gerät trägt die Fabric seines Controllers');

$schluessel = static fn(array $device): ?string => method_exists(SymconInventory::class, 'deviceKey') ? SymconInventory::deviceKey($device) : null;
assertSame($nuc . '-0000000000000006', $schluessel($nachher[0]), 'Schlüssel: Fabric und Knoten');
assertSame($testbox . '-0000000000000006', $schluessel($nachher[1]), 'Schlüssel: derselbe Knoten, andere Fabric');
assertSame('0000000000000006', $schluessel(['nodeId' => 6]), 'Schlüssel ohne Fabric: nur der Knoten');

$gefunden = SymconInventory::foundByName($vorher, $nachher, $alle, [$testbox, $nuc]);
assertSame([$nuc . '-0000000000000006'], array_keys($gefunden), 'Markiert wird nur der Knoten 6 des nuc');
assertSame($quelleNuc, $gefunden[$nuc . '-0000000000000006'] ?? null, 'Die Quelle ist die der nuc-Ansage');

// Gegenrichtung: Fehlte die Ansage der Testbox, trägt nur deren Knoten die Markierung
$ohneTestbox6 = array_values(array_filter($alle, static fn(array $a): bool => stripos((string)$a['instance'], sprintf('%s-%016X.', $testbox, 6)) !== 0));
$gegen        = SymconInventory::foundByName($abgleich($ohneTestbox6), $nachher, $alle, [$nuc, $testbox]);
assertSame([$testbox . '-0000000000000006'], array_keys($gegen), 'Gegenrichtung: nur der Knoten 6 der Testbox');
assertSame($quelleTestbox, $gegen[$testbox . '-0000000000000006'] ?? null, 'Gegenrichtung: mit der Quelle der Testbox-Ansage');

// Das Modul liest die Markierung über denselben Schlüssel
$modulePhp = (string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php');
assertTrue(str_contains($modulePhp, '$nurNamentlich[SymconInventory::deviceKey($device)]'), 'module.php liest die Markierung über Fabric und Knoten');
