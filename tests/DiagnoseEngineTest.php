<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/DiagnoseEngine.php';

/**
 * Tabellengetriebene Szenario-Tests: jedes JSON unter fixtures/szenarien/
 * beschreibt eine Eingabe (nur die Abweichungen vom Standard) und die
 * erwarteten Befunde. Jedes Szenario entspricht einem real erlebten Fall.
 */

$standard = [
    'ipv6Adressen'        => ['fd86:6fd:53ed::1', 'fe80::1'],
    'mdnsAntworten'       => true,
    'borderRouter'        => [
        ['name' => 'DIRIGERA #666D', 'host' => 'gw2.local', 'adressen' => ['fe80::2'], 'quelle' => '192.168.178.186', 'txt' => []],
    ],
    'geraeteBetrieb'      => [
        ['instanz' => 'A-1._matter._tcp.local', 'host' => 'a.local', 'adressen' => ['fd89:1::1'], 'quelle' => '192.168.178.63'],
    ],
    'geraeteKoppelbereit' => [
        ['instanz' => 'B._matterc._udp.local', 'host' => 'b.local', 'adressen' => ['fd89:1::2'], 'quelle' => '192.168.178.63'],
    ],
    'threadPraefixe'      => [
        'fd89:1::' => ['erreichbar' => true, 'testAdresse' => 'fd89:1::1', 'gateway' => 'fe80::2'],
    ],
    'eigeneAnkuendigung'  => true,
    'plattform'           => 'Windows',
    'port5353Belegung'    => [],
];

foreach (glob(__DIR__ . '/fixtures/szenarien/*.json') ?: [] as $datei) {
    $szenario = json_decode((string)file_get_contents($datei), true, 32, JSON_THROW_ON_ERROR);
    $name     = $szenario['name'];
    $eingabe  = array_merge($standard, $szenario['eingabe']);

    $befunde = DiagnoseEngine::auswerten($eingabe);
    $stufen  = [];
    foreach ($befunde as $b) {
        $stufen[$b['id']] = $b['stufe'];
    }

    foreach ($szenario['erwartet']['stufen'] ?? [] as $id => $stufe) {
        gleich($stufe, $stufen[$id] ?? '(fehlt)', $name . ': Befund ' . $id);
    }
    foreach ($szenario['erwartet']['nichtEnthalten'] ?? [] as $id) {
        pruefe(!isset($stufen[$id]), $name . ': Befund ' . $id . ' darf nicht auftauchen');
    }
    if (isset($szenario['erwartet']['ersterBefund'])) {
        gleich(
            $szenario['erwartet']['ersterBefund'],
            $befunde[0]['id'] ?? '(leer)',
            $name . ': Sortierung — Blocker zuerst'
        );
    }
    foreach ($szenario['erwartet']['paramsEnthalten'] ?? [] as $id => $teilstrings) {
        $params = null;
        foreach ($befunde as $b) {
            if ($b['id'] === $id) {
                $params = implode(' ', $b['params']);
                break;
            }
        }
        foreach ($teilstrings as $teil) {
            pruefe(
                $params !== null && str_contains($params, $teil),
                $name . ': ' . $id . ' enthält "' . $teil . '" (Params: ' . var_export($params, true) . ')'
            );
        }
    }
}
