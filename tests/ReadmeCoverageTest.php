<?php

declare(strict_types=1);

/**
 * Statische Prüfung: Die Anwenderdoku (README.md deutsch, README.en.md englisch)
 * muss jeden Befund der Engine abdecken und darf keinen nennen, den es nicht mehr
 * gibt. Die Zuordnung steht als HTML-Kommentar unter jeder Befundgruppe:
 *
 *     <!-- findings: no_ipv6 ipv6_ok -->
 *
 * Anlass (09.09.2026): Die README führte zwei Prüfungen, die seit 0.2 zurückgezogen
 * waren, und kannte die Befunde der Builds 16–24 nicht. Doku-Aktualität ist Teil des
 * Releases (Checkliste in der globalen CLAUDE.md) — hier als Test, damit sie nicht
 * wieder vom Merken abhängt. Dazu: Beispielbild vorhanden, Sprachverweis vorhanden.
 */

$root      = dirname(__DIR__);
$enginePhp = (string)file_get_contents($root . '/MatterDiagnose/libs/DiagnosisEngine.php');
preg_match_all('/self::finding\(\s*self::SEVERITY_[A-Z]+\s*,\s*\'([a-z0-9_]+)\'/', $enginePhp, $m);
$engineIds = array_values(array_unique($m[1]));
sort($engineIds);
assertTrue(count($engineIds) >= 10, 'Engine kennt mindestens zehn Befunde (Regex greift)');

foreach (['README.md' => 'deutsch', 'README.en.md' => 'englisch'] as $file => $sprache) {
    $path = $root . '/' . $file;
    assertTrue(is_file($path), $file . ' vorhanden (' . $sprache . ')');
    if (!is_file($path)) {
        continue;
    }
    $readme = (string)file_get_contents($path);

    preg_match_all('/<!--\s*findings:\s*([a-z0-9_\s]+?)\s*-->/', $readme, $mm);
    $documented = [];
    foreach ($mm[1] as $liste) {
        foreach (preg_split('/\s+/', trim($liste)) ?: [] as $id) {
            if ($id !== '') {
                $documented[] = $id;
            }
        }
    }
    $documented = array_values(array_unique($documented));
    sort($documented);

    assertTrue(count($mm[0]) >= 3, $file . ': Befundgruppen tragen findings-Kommentare (' . count($mm[0]) . ')');
    foreach (array_diff($engineIds, $documented) as $id) {
        assertTrue(false, $file . ': Befund "' . $id . '" ist in der Doku nicht abgedeckt');
    }
    foreach (array_diff($documented, $engineIds) as $id) {
        assertTrue(false, $file . ': Doku nennt Befund "' . $id . '", den die Engine nicht (mehr) erzeugt');
    }
    assertSame($engineIds, $documented, $file . ': Befunde der Engine und der Doku sind deckungsgleich');

    // Beispielbild eingebunden und vorhanden
    if (preg_match('/!\[[^\]]*\]\(([^)]+\.png)\)/', $readme, $img) === 1) {
        assertTrue(is_file($root . '/' . $img[1]), $file . ': Beispielbild ' . $img[1] . ' liegt im Repo');
    } else {
        assertTrue(false, $file . ': kein Beispielbild eingebunden');
    }

    // Verweis auf die jeweils andere Sprache
    $other = $file === 'README.md' ? 'README.en.md' : 'README.md';
    assertTrue(str_contains($readme, $other), $file . ': verweist auf ' . $other);

    // Pflichtabschnitte (Checkliste „Anwenderdoku")
    $abschnitte = $file === 'README.md'
        ? ['## Wann brauche ich das?', '## Installation', '## Grenzen', '## Begriffe', '## Anhang']
        : ['## When do I need this?', '## Installation', '## Limits', '## Terms', '## Appendix'];
    foreach ($abschnitte as $h) {
        assertTrue(str_contains($readme, $h), $file . ': Abschnitt „' . $h . '" vorhanden');
    }
}
