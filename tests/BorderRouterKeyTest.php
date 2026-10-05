<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

// Der Name eines Border Routers ist zugleich sein Schlüssel in der Momentaufnahme
// (border_router_gone/new, missesSomethingKnown). Seit build 75 wird der Name als fremder Text
// gekürzt (MCP-Regel 17) — als Schlüssel hätte das jeden Router mit mehr als 40 Zeichen nach dem
// Update einmal als verschwunden und neu gemeldet, und zwei Router mit gleichem Anfang wären zu
// einem verschmolzen (Code-Review build 77). Der Schlüssel bleibt deshalb ungekürzt; gekürzt wird
// erst die Anzeige.
//
// Fixture: echter Mitschnitt (tests/fixtures/mdns), darin die Routernamen ersetzt.

$manifest  = json_decode((string)file_get_contents(__DIR__ . '/fixtures/mdns/manifest.json'), true);
$responses = [];
foreach ($manifest as $entry) {
    $responses[] = [
        'from'    => $entry['source'],
        'message' => MdnsCodec::decodeMessage((string)file_get_contents(__DIR__ . '/fixtures/mdns/' . $entry['file'])),
    ];
}

$schluessel = static fn(array $router): array => method_exists(MatterDiscovery::class, 'borderRouterKeys') ? MatterDiscovery::borderRouterKeys($router) : [];

// Ohne Sonderfall ist der Schlüssel der bisherige Name — alte Momentaufnahmen passen weiter
$router = MatterDiscovery::collect($responses, [])['borderRouters'];
assertSame(2, count($router), 'Mitschnitt: zwei Border Router');
foreach ($router as $eintrag) {
    assertSame(explode('.', $eintrag['instance'])[0], $eintrag['key'] ?? null, 'Schlüssel = Instanzname wie vor build 75: ' . $eintrag['instance']);
}
assertSame(array_map(static fn(array $r): string => (string)($r['key'] ?? ''), $router), $schluessel($router), 'borderRouterKeys liefert die Schlüssel');

// Zwei lange Namen mit gleichem Anfang
$alt      = array_map(static fn(array $r): string => explode('.', $r['instance'])[0], $router);
$lang     = ['HomePod mini Schlafzimmer Erdgeschoss links', 'HomePod mini Schlafzimmer Erdgeschoss rechts'];
$umbenannt = $responses;
array_walk_recursive($umbenannt, static function (mixed &$wert) use ($alt, $lang): void {
    if (is_string($wert)) {
        $wert = str_replace($alt, $lang, $wert);
    }
});
$router = MatterDiscovery::collect($umbenannt, [])['borderRouters'];
$keys   = $schluessel($router);
sort($keys);
assertSame($lang, $keys, 'Lange Namen: Schlüssel ungekürzt und verschieden');
foreach ($router as $eintrag) {
    assertTrue(mb_strlen($eintrag['name']) <= ForeignText::MAX_LENGTH, 'Anzeigename bleibt begrenzt: ' . $eintrag['name']);
}

// Verschwindet einer, nennt die Änderung ihn begrenzt
$vorher  = ChangeTracker::snapshot([], $lang, [], 1000);
$nachher = ChangeTracker::snapshot([], [$lang[1]], [], 2000);
$wechsel = ChangeTracker::diff($vorher, $nachher);
$weg     = array_values(array_filter($wechsel, static fn(array $c): bool => $c['id'] === 'border_router_gone'));
assertSame(1, count($weg), 'Nur der linke Router ist verschwunden');
assertTrue(mb_strlen((string)($weg[0]['params']['name'] ?? '')) <= ForeignText::MAX_LENGTH, 'Änderung nennt den Router in Anzeigelänge');
assertTrue(str_starts_with((string)($weg[0]['params']['name'] ?? ''), 'HomePod mini Schlafzimmer'), 'Änderung nennt den Router erkennbar');

// Der Kleber nimmt den Schlüssel, nicht den Anzeigenamen
$modul = (string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php');
assertSame(2, substr_count($modul, "MatterDiscovery::borderRouterKeys(\$survey['borderRouters'])"), 'module.php: Momentaufnahme und Abgleich über borderRouterKeys');
