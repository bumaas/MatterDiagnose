<?php

declare(strict_types=1);

/**
 * Hält die Doku beim Code (Sperrklinke).
 *
 * Aus Modulen, Formularen und öffentlichen Funktionen wird abgeleitet, was in der Doku stehen
 * muss (README.md, README.en.md, docs/**.md):
 *   - jedes Modul (module.json name, Ordnername oder alias) ist genannt;
 *   - jedes Formularfeld aus form.json (Elemente mit "name") ist beschrieben: Name in Backticks
 *     irgendwo im Text, oder Name, englische Beschriftung bzw. deren deutsche Übersetzung aus
 *     locale.json als ganzes Wort in einem Stichwort (erste Tabellenspalte, Überschrift,
 *     Listenpunkt bis zum Doppelpunkt, Fettdruck) - Fließtext zählt nicht;
 *   - jede öffentliche Skriptfunktion PREFIX_Methode aus module.php steht in der Doku
 *     (nur Symcon-Rückrufe ausgenommen: unter IPSModuleStrict ist jede öffentliche Methode
 *     Skript-API, auch wenn ein Formularknopf sie aufruft);
 *   - kein „IP-Symcon" (in jeder Schreibweise) in der Doku außerhalb von Links und Code sowie in
 *     form.json, locale.json und module.php (Produktname seit 2024 „Symcon").
 *
 * Lücken, die es bei der Einführung schon gab, stehen in tests/readme-bekannt.json. Rot wird der
 * Test nur bei NEUEN Lücken - und bei bekannten, die inzwischen geschlossen sind: Die sind aus der
 * Datei zu streichen, damit die Liste nur schrumpfen kann.
 *
 * Was sich nicht ableiten lässt (Verhaltensänderungen, Migrationshinweise), prüft der Test nicht.
 *
 * Aufruf: php tests/check-readme.php                    Prüfung
 *         php tests/check-readme.php --bekannt-schreiben Liste anlegen bzw. geschlossene Lücken
 *                                                     streichen (ergänzt nie neue, Exit 1)
 *
 * Selbsttest (Muster): T:/modules/HMInventory/tests/check-readme-selbsttest.php
 * Vorlage: ~/.claude/skills/symcon-testsuite/vorlagen/check-readme.php - Änderungen dort pflegen.
 */

const RUECKRUFE = [
    'create', 'destroy', 'applychanges', 'receivedata', 'forwarddata', 'requestaction', 'messagesink',
    'getconfigurationform', 'getcompatibleparents', 'getconfigurationforparent', '__construct', '__destruct',
    'translate', 'processhookdata', 'getvisualizationtile', 'migrate',
];

$wurzel  = dirname(__DIR__);
$bekannt = __DIR__ . '/readme-bekannt.json';

/** Formularfelder (Elemente mit "name"), rekursiv über items/columns/popup. */
function formularfelder(array $elemente): array
{
    $felder = [];
    foreach ($elemente as $e) {
        if (!is_array($e)) {
            continue;
        }
        if (isset($e['name']) && is_string($e['name']) && !in_array($e['type'] ?? '', ['Label', 'Button', 'Image'], true)) {
            $felder[] = ['name' => $e['name'], 'caption' => is_string($e['caption'] ?? null) ? $e['caption'] : ''];
        }
        foreach (['items', 'columns'] as $k) {
            if (isset($e[$k]) && is_array($e[$k])) {
                $felder = array_merge($felder, formularfelder($e[$k]));
            }
        }
        if (isset($e['popup']['items']) && is_array($e['popup']['items'])) {
            $felder = array_merge($felder, formularfelder($e['popup']['items']));
        }
    }
    return $felder;
}

/** Stichworte, unter denen ein Feld beschrieben sein kann (klein, ohne Markdown-Zeichen). */
function stichworte(string $text): array
{
    $worte = [];
    foreach (preg_split('/\R/', $text) as $zeile) {
        if (preg_match('/^\s*\|\s*([^|]*?)\s*\|/', $zeile, $m) && preg_match('/^[\s:-]*$/', $m[1]) !== 1) {
            $worte[] = $m[1];                                   // erste Tabellenspalte
        } elseif (preg_match('/^\s*#{1,6}\s+(.+)$/', $zeile, $m)) {
            $worte[] = $m[1];                                   // Überschrift
        } elseif (preg_match('/^\s*(?:[*+-]|\d+\.)\s+([^:]+)/', $zeile, $m)) {
            $worte[] = $m[1];                                   // Listenpunkt bis zum Doppelpunkt
        }
        if (preg_match_all('/\*\*([^*]+)\*\*/', $zeile, $m)) {
            array_push($worte, ...$m[1]);                       // Fettdruck
        }
    }
    return array_map(static fn (string $w): string => mb_strtolower(str_replace(['`', '*'], '', $w)), $worte);
}

/** Kommt $wort als ganzes Wort in einem der Stichworte vor? */
function imStichwort(string $wort, array $stichworte): bool
{
    $muster = '/(?<![\p{L}\p{N}_])' . preg_quote(mb_strtolower($wort), '/') . '(?![\p{L}\p{N}_])/u';
    foreach ($stichworte as $s) {
        if (preg_match($muster, $s) === 1) {
            return true;
        }
    }
    return false;
}

// Doku zusammentragen
$doku = '';
foreach (['README.md', 'readme.md', 'Readme.md', 'README.en.md'] as $datei) {
    if (is_file("$wurzel/$datei")) {
        $doku .= "\n" . file_get_contents("$wurzel/$datei");
    }
}
if (is_dir("$wurzel/docs")) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$wurzel/docs", FilesystemIterator::SKIP_DOTS)) as $datei) {
        if (str_ends_with($datei->getFilename(), '.md')) {
            $doku .= "\n" . file_get_contents($datei->getPathname());
        }
    }
}
// Links: URL heraus, Linktext bleibt. HTML-Tags nur innerhalb einer Zeile und nur, wenn auf „<"
// ein Buchstabe folgt - ein einzelnes „<" („RSSI < -90") verschluckt sonst Text bis zum nächsten „>".
$ohneLinks  = preg_replace(['#\]\([^)\n]*\)#', '#https?://\S+#', '#</?[a-zA-Z][^<>\n]*>#'], ['](…)', '', ' '], $doku);
$klein      = mb_strtolower($ohneLinks);
$stichworte = stichworte($ohneLinks);

// Lücken ermitteln
$luecken = [];
foreach (glob("$wurzel/*/module.json") ?: [] as $moduljson) {
    $ordner = dirname($moduljson);
    $modul  = json_decode(file_get_contents($moduljson), true, 512, JSON_THROW_ON_ERROR);
    $name   = $modul['name'] ?? basename($ordner);

    $genannt = false;
    foreach (array_merge([$name, basename($ordner)], $modul['aliases'] ?? []) as $n) {
        if ($n !== '' && str_contains($klein, mb_strtolower($n))) {
            $genannt = true;
        }
    }
    if (!$genannt) {
        $luecken[] = "modul:$name";
    }

    $locale = is_file("$ordner/locale.json")
        ? (json_decode(file_get_contents("$ordner/locale.json"), true, 512, JSON_THROW_ON_ERROR)['translations']['de'] ?? [])
        : [];
    $form = is_file("$ordner/form.json") ? json_decode(file_get_contents("$ordner/form.json"), true, 512, JSON_THROW_ON_ERROR) : [];
    // Ein Klammerzusatz am Ende („(0 = aus, Vorgabe 60)“) wird in der Doku oft anders formuliert und
    // darf beim Vergleich wegfallen - aber nur, wenn der Rest im Modul eindeutig bleibt. Bei
    // „Sonnenrichtung (von)“/„(bis)“ trägt die Klammer die Bedeutung.
    $ohneKlammer = static fn (string $t): string => rtrim(preg_replace('/\s*\([^)]*\)\s*:?\s*$/u', '', $t), ': ');
    $felder      = formularfelder($form['elements'] ?? []);
    // je Sprache zählen: „From Azimuth“/„To Azimuth“ sind englisch eindeutig, deutsch erst mit Klammer
    $kerne = array_count_values(array_merge(
        array_map(static fn (array $f): string => 'en:' . $ohneKlammer($f['caption']), $felder),
        array_map(static fn (array $f): string => 'de:' . $ohneKlammer($locale[$f['caption']] ?? $f['caption']), $felder)
    ));
    foreach ($felder as $feld) {
        $kandidaten = [$feld['name']];
        if ($feld['caption'] !== '') {
            $beschriftungen = ['en' => $feld['caption']];
            if (isset($locale[$feld['caption']])) {
                $beschriftungen['de'] = $locale[$feld['caption']];
            }
            foreach ($beschriftungen as $sprache => $b) {
                $kandidaten[] = rtrim($b, ': ');
                if (($kerne[$sprache . ':' . $ohneKlammer($b)] ?? 0) === 1) {
                    $kandidaten[] = $ohneKlammer($b);
                }
            }
        }
        $beschrieben = str_contains($klein, mb_strtolower('`' . $feld['name'] . '`'));
        foreach ($kandidaten as $k) {
            if (mb_strlen($k) > 2 && imStichwort($k, $stichworte)) {
                $beschrieben = true;
            }
        }
        if (!$beschrieben) {
            $luecken[] = "feld:$name:{$feld['name']}";
        }
    }

    $prefix = $modul['prefix'] ?? '';
    if ($prefix !== '' && is_file("$ordner/module.php")) {
        preg_match_all('/^\s*public\s+function\s+(\w+)\s*\(/m', file_get_contents("$ordner/module.php"), $treffer);
        foreach (array_unique($treffer[1]) as $methode) {
            $funktion = $prefix . '_' . $methode;
            if (in_array(strtolower($methode), RUECKRUFE, true)) {
                continue;
            }
            if (preg_match('/\b' . preg_quote($funktion, '/') . '\b/', $ohneLinks) !== 1) {
                $luecken[] = "funktion:$funktion";
            }
        }
    }
}
sort($luecken);

if (in_array('--bekannt-schreiben', $argv, true)) {
    // Sperrklinke: Gibt es die Liste schon, wird nur gestrichen - neue Lücken gehören in die Doku.
    $neu = [];
    if (is_file($bekannt)) {
        $vorher  = json_decode(file_get_contents($bekannt), true, 512, JSON_THROW_ON_ERROR)['offen'] ?? [];
        $neu     = array_values(array_diff($luecken, $vorher));
        $luecken = array_values(array_intersect($vorher, $luecken));
    }
    file_put_contents($bekannt, json_encode([
        'hinweis' => 'Doku-Lücken bei Einführung von tests/check-readme.php. Nur streichen, nie ergänzen - neue Felder und Funktionen gehören in die Doku.',
        'offen'   => $luecken,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    printf("%d bekannte Lücken nach %s geschrieben\n", count($luecken), basename($bekannt));
    if ($neu !== []) {
        echo 'Nicht aufgenommen, bitte dokumentieren: ' . implode(', ', $neu) . "\n";
        exit(1);
    }
    exit(0);
}

// Prüfen
$pruefungen = 0;
$fehler     = 0;
function pruefe(bool $ok, string $text): void
{
    global $pruefungen, $fehler;
    $pruefungen++;
    if (!$ok) {
        $fehler++;
    }
    echo ($ok ? '  ok   ' : '  FEHL ') . $text . "\n";
}

$offen = is_file($bekannt) ? (json_decode(file_get_contents($bekannt), true, 512, JSON_THROW_ON_ERROR)['offen'] ?? []) : [];

pruefe(trim($doku) !== '', 'Doku vorhanden (README.md)');
// Doku: Links samt Linktext (Titel fremder Seiten) und Code (Pfade, Zitate) zählen nicht
$ipSymcon = preg_match_all('/ip-symcon/i', preg_replace(['/!?\[[^\]\n]*\]\([^)\n]*\)/', '#https?://\S+#', '/```.*?```/s', '/`[^`\n]*`/'], '', $doku));
pruefe($ipSymcon === 0, "kein „IP-Symcon“ in der Doku ($ipSymcon)");
$imModul = [];
foreach (['form.json', 'locale.json', 'module.php'] as $muster) {
    foreach (glob("$wurzel/*/$muster") ?: [] as $datei) {
        if (preg_match('/ip-symcon/i', preg_replace('#https?://\S+#', '', file_get_contents($datei))) === 1) { // URLs bleiben
            $imModul[] = basename(dirname($datei)) . '/' . basename($datei);
        }
    }
}
pruefe($imModul === [], 'kein „IP-Symcon“ in Formular, Übersetzung und Modulcode' . ($imModul !== [] ? ': ' . implode(', ', $imModul) : ''));

$neu = array_values(array_diff($luecken, $offen));
pruefe($neu === [], 'keine neuen Doku-Lücken' . ($neu !== [] ? ': ' . implode(', ', $neu) : ''));
$erledigt = array_values(array_diff($offen, $luecken));
pruefe($erledigt === [], 'readme-bekannt.json aktuell' . ($erledigt !== [] ? ' - geschlossen, bitte streichen: ' . implode(', ', $erledigt) : ''));
echo '  (noch ' . count($offen) . " bekannte Lücken)\n";

echo "\n$pruefungen Prüfungen, $fehler Fehler\n";
exit($fehler === 0 ? 0 : 1);
