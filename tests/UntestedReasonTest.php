<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

// „Der Erreichbarkeitstest wurde übersprungen (Zeitbudget) oder war nicht eindeutig" — mit dem
// Oder wusste niemand, woran er war (Burkhard, 05.10.2026: „diese Meldung bekomme ich immer").
// Am nuc war es jedes Mal das Zeitbudget: Der Lauf kam bei 17,4 von 24 s am Test an, las danach
// noch 1 s Routen, und für zwei Ping-Versuche reichte der Rest nicht — gepingt wurde nie.
// Jede Ursache hat jetzt ihren eigenen Befund (Szenarien untested_*.json); hier: die Texte
// nennen genau eine Ursache, und jede Variante wird wie das alte „ungetestet" behandelt.

$varianten = ['thread_prefix_untested', 'thread_prefix_untested_budget', 'thread_prefix_untested_no_device', 'thread_prefix_untested_quick'];

// --- Texte: eine Ursache je Befund, deutsch und englisch ----------------------------
foreach ([false, true] as $deutsch) {
    $instanz          = neueInstanz();
    $instanz->deutsch = $deutsch;
    $texte            = new ReflectionMethod($instanz, 'findingTexts');
    foreach ($varianten as $id) {
        $text = $texte->invoke($instanz, $id, ['prefix' => 'MyHome2081938520 (fd89:6b7:bc55::)']);
        assertTrue(($text['title'] ?? $id) !== $id, "$id: Katalogeintrag vorhanden");
        $alles = ($text['title'] ?? '') . ' ' . ($text['text'] ?? '');
        assertTrue(preg_match($deutsch ? '/\boder\b/u' : '/\bor\b/u', $alles) !== 1, "$id (" . ($deutsch ? 'de' : 'en') . '): nennt eine Ursache, kein „oder": ' . $alles);
    }
    assertSame([], $instanz->unuebersetzt, 'Texte übersetzt (' . ($deutsch ? 'de' : 'en') . ')');
}

// --- Momentaufnahme: jede Variante übernimmt die Aussage des Vorlaufs (build 64) ------
$prefix  = 'fd89:6b7:bc55::';
$befund  = static fn(string $id, string $severity): array => ['severity' => $severity, 'id' => $id, 'params' => [], 'title' => $id, 'subject' => $prefix];
$vorlauf = ChangeTracker::snapshot([], ['Wohnzimmer'], [$befund('thread_prefix_route_ok', 'ok')], 1000);
foreach ($varianten as $id) {
    $lauf      = ChangeTracker::snapshot([], ['Wohnzimmer'], [$befund($id, 'notice')], 2000);
    $gespeichert = ChangeTracker::carryOver($vorlauf, $lauf);
    assertSame([], ChangeTracker::diff($vorlauf, $gespeichert), "$id: kein „Neuer Befund\" nach einem getesteten Vorlauf");
    assertSame('ok', $gespeichert['findings']['thread_prefix_route_ok@' . $prefix] ?? null, "$id: die letzte Aussage bleibt gespeichert");
}
