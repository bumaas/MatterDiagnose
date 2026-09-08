<?php

declare(strict_types=1);

require_once __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php';

/**
 * Routentabelle lesen und bewerten: Persistenz (Windows), veraltete Routen
 * nach Präfixwechsel, Routen auf verschwundene Border Router.
 *
 * Fixture route_windows_active_nuc.txt ist die echte netsh-Ausgabe des nuc vom
 * 02.09.2026 — in Codepage 850, deshalb stehen in den Kopfzeilen Byte-Reste
 * statt Umlaute. Der persistente Speicher desselben Rechners war leer: die
 * Thread-Route fd89:6b7:bc55::/64 überlebt dort keinen Neustart.
 */

$libFile = __DIR__ . '/../MatterDiagnose/libs/RouteTable.php';
if (!is_file($libFile)) {
    assertTrue(false, 'libs/RouteTable.php fehlt');

    return;
}
require_once $libFile;

$fx = static fn(string $name): string => (string)file_get_contents(__DIR__ . '/fixtures/os/' . $name);

// --- Parsen: Windows (echte nuc-Tabelle, CP850) -------------------------------
$active = RouteTable::parse(OsAdapter::PLATFORM_WINDOWS, $fx('route_windows_active_nuc.txt'));
assertSame(17, count($active), 'nuc: 17 aktive IPv6-Routen gelesen (Kopfzeilen verworfen)');

$thread = null;
$onLink = null;
foreach ($active as $route) {
    if ($route['prefix'] === 'fd89:6b7:bc55::' && $route['length'] === 64) {
        $thread = $route;
    }
    if ($route['prefix'] === 'fd86:6fd:53ed::' && $route['length'] === 64 && $route['gateway'] === null) {
        $onLink = $route;
    }
}
assertTrue($thread !== null, 'nuc: Thread-Route fd89:6b7:bc55::/64 gefunden');
assertSame('fe80::8f7:24ce:93c4:8920', $thread['gateway'] ?? null, 'nuc: Gateway ist die Link-Local des Apple TV');
assertSame('12', $thread['interface'] ?? null, 'nuc: Schnittstellenindex 12');
assertSame('Manuell', $thread['type'] ?? null, 'nuc: Typ „Manuell"');
assertTrue($onLink !== null, 'nuc: On-Link-Route des eigenen ULA-Präfixes ohne Gateway erkannt');

assertSame([], RouteTable::parse(OsAdapter::PLATFORM_WINDOWS, $fx('route_windows_persistent_empty.txt')), 'Leerer persistenter Speicher ergibt leere Liste');
$persistent = RouteTable::parse(OsAdapter::PLATFORM_WINDOWS, $fx('route_windows_persistent_with_thread.txt'));
assertSame(1, count($persistent), 'Persistenter Speicher mit Thread-Route: eine Route');

// --- Parsen: Linux -----------------------------------------------------------
$linux = RouteTable::parse(OsAdapter::PLATFORM_LINUX, $fx('route_linux.txt'));
assertSame(3, count($linux), 'Linux: drei Präfix-Routen, default übersprungen');
$linuxThread = null;
foreach ($linux as $route) {
    if ($route['prefix'] === 'fd89:6b7:bc55::') {
        $linuxThread = $route;
    }
}
assertSame('fe80::8f7:24ce:93c4:8920', $linuxThread['gateway'] ?? null, 'Linux: Gateway aus „via"');
assertSame('eth0', $linuxThread['interface'] ?? null, 'Linux: Schnittstelle aus „dev"');
assertSame('ra', $linuxThread['type'] ?? null, 'Linux: Protokoll ra');

// --- Die Metrik-Spalte ist nicht immer eine Zahl -----------------------------
// Fehlalarm-Ursache vom 02.09.2026: Im persistenten Speicher schreibt netsh dort
// "Standard" bzw. "Default". Wurde die Spalte als \d+ gelesen, fiel die ganze
// Zeile durch das Raster — und das Modul warnte vor fehlender Dauerhaftigkeit,
// obwohl die Route soeben persistent gesetzt worden war.
foreach (['Standard', 'Default', '256', '0'] as $metrik) {
    $zeile  = sprintf('Nein     Andere    %s  fd89:6b7:bc55::/64         12  fe80::8f7:24ce:93c4:8920', $metrik);
    $routen = RouteTable::parse(OsAdapter::PLATFORM_WINDOWS, $zeile);
    assertSame(1, count($routen), 'Metrik "' . $metrik . '" wird gelesen');
    assertSame('fd89:6b7:bc55::', $routen[0]['prefix'] ?? null, 'Präfix bei Metrik "' . $metrik . '"');
    assertSame('fe80::8f7:24ce:93c4:8920', $routen[0]['gateway'] ?? null, 'Gateway bei Metrik "' . $metrik . '"');
}

// --- LAN-Schnittstelle aus eigenen Adressen ----------------------------------
$ownNuc = ['fd86:6fd:53ed:0:5ab9:15a9:ed5d:db8b', 'fe80::3524:11bc:5bd7:121e'];
assertSame('12', RouteTable::interfaceForAddresses($active, $ownNuc), 'nuc: LAN-Schnittstelle ist Index 12');
assertSame('eth0', RouteTable::interfaceForAddresses($linux, ['fd86:6fd:53ed::1']), 'Linux: LAN-Schnittstelle eth0');
assertSame(null, RouteTable::interfaceForAddresses($active, ['2001:db8::1']), 'Unbekannte Adresse ergibt null');

// --- Bewertung ---------------------------------------------------------------
$brLinkLocals = ['fe80::8f7:24ce:93c4:8920'];
$inUse        = ['fd89:6b7:bc55::'];

// a) nuc heute: Route aktiv, persistenter Speicher leer
$a = RouteTable::assess($active, [], $inUse, $brLinkLocals, $ownNuc, OsAdapter::PLATFORM_WINDOWS);
assertSame(1, count($a['notPersistent']), 'nuc: Thread-Route ist nicht persistent');
assertSame('fd89:6b7:bc55::', $a['notPersistent'][0]['prefix'] ?? null, 'nuc: betroffenes Präfix');
assertSame([], $a['stale'], 'nuc: keine veraltete Route (Tailscale- und LAN-ULA-Routen bleiben außen vor)');
assertSame([], $a['gatewayUnknown'], 'nuc: Gateway gehört zum Apple TV');

// b) Route auch im persistenten Speicher
$b = RouteTable::assess($active, $persistent, $inUse, $brLinkLocals, $ownNuc, OsAdapter::PLATFORM_WINDOWS);
assertSame([], $b['notPersistent'], 'Mit persistentem Eintrag kein Befund');

// c) Präfixdrift: niemand nutzt fd89 mehr
$c = RouteTable::assess($active, $persistent, [], $brLinkLocals, $ownNuc, OsAdapter::PLATFORM_WINDOWS);
assertSame(1, count($c['stale']), 'Ungenutztes Thread-Präfix gilt als veraltet');
assertSame('fd89:6b7:bc55::', $c['stale'][0]['prefix'] ?? null, 'Veraltet ist genau die Thread-Route');
assertSame([], $c['notPersistent'], 'Veraltete Route wird nicht zusätzlich als nicht persistent gemeldet');

// d) Border Router ausgetauscht: Gateway unbekannt
$d = RouteTable::assess($active, $persistent, $inUse, ['fe80::aaaa'], $ownNuc, OsAdapter::PLATFORM_WINDOWS);
assertSame(1, count($d['gatewayUnknown']), 'Gateway ist keine Link-Local eines aktuellen Border Routers');
assertSame([], $d['notPersistent'], 'Unbekanntes Gateway wird nicht zusätzlich als nicht persistent gemeldet');

// e) Keine Border Router bekannt: Gateway-Prüfung entfällt
$e = RouteTable::assess($active, $persistent, $inUse, [], $ownNuc, OsAdapter::PLATFORM_WINDOWS);
assertSame([], $e['gatewayUnknown'], 'Ohne bekannte Border Router keine Gateway-Aussage');

// f) Linux: Persistenz wird nicht bewertet (RA erneuert die Route)
$f = RouteTable::assess($linux, null, $inUse, $brLinkLocals, ['fd86:6fd:53ed::1'], OsAdapter::PLATFORM_LINUX);
assertSame([], $f['notPersistent'], 'Linux: keine Persistenzaussage');
assertSame([], $f['stale'], 'Linux: Thread-Route in Gebrauch');

// --- Lebensdauer: RA-gelernte Routen erkennen (Lehrgeld 08.09.2026) ---------------
// Windows lernt die Thread-Routen der Border Router inzwischen selbst per RIO. In der
// Tabellenansicht sehen sie wie von Hand gesetzte aus (Typ „Manuell"), im persistenten
// Speicher fehlen sie — und das Modul verlangte, sie dauerhaft zu machen. Verrat ist
// allein die Lebensdauer aus "show route level=verbose": RA-Routen laufen ab (hier 1730 s,
// wie die Default-Route der FRITZ!Box), manuelle stehen auf „Unendlich". Fixtures sind
// die echten nuc-Ausgaben vom 08.09.2026, 23:19 Uhr (Codepage 850 → UTF-8).
assertTrue(method_exists(RouteTable::class, 'parseLifetimes'), 'RouteTable::parseLifetimes vorhanden');
assertTrue(method_exists(RouteTable::class, 'annotateLifetimes'), 'RouteTable::annotateLifetimes vorhanden');
if (method_exists(RouteTable::class, 'parseLifetimes') && method_exists(RouteTable::class, 'annotateLifetimes')) {
    $lifetimes = RouteTable::parseLifetimes($fx('route_windows_verbose_active_nuc.txt'));
    assertTrue(count($lifetimes) >= 25, 'verbose: alle Routenblöcke gelesen (' . count($lifetimes) . ')');
    assertSame(1730, $lifetimes['fd89:6b7:bc55::/64|fe80::8f7:24ce:93c4:8920|12'] ?? 'fehlt', 'Thread-Route über Apple TV: 1730 s Restlaufzeit');
    assertSame(1651, $lifetimes['fd89:6b7:bc55::/64|fe80::6720:d6cb:b7d2:bed|12'] ?? 'fehlt', 'Thread-Route über DIRIGERA: 1651 s Restlaufzeit');
    assertSame(1650, $lifetimes['::/0|fe80::62b5:8dff:fe64:7890|12'] ?? 'fehlt', 'Default-Route der FRITZ!Box: ebenfalls endlich (RA)');
    assertTrue(array_key_exists('::1/128||1', $lifetimes) && $lifetimes['::1/128||1'] === null, 'Loopback: „Unendlich" wird zu null');

    $persistentLifetimes = RouteTable::parseLifetimes($fx('route_windows_verbose_persistent_nuc.txt'));
    assertSame(1, count($persistentLifetimes), 'verbose persistent: ein Block');
    assertTrue(array_key_exists('fd89:6b7:bc55::/64|fe80::8f7:24ce:93c4:8920|12', $persistentLifetimes) && $persistentLifetimes['fd89:6b7:bc55::/64|fe80::8f7:24ce:93c4:8920|12'] === null, 'Persistente Route: unendlich');

    $raRoutes = RouteTable::annotateLifetimes(
        RouteTable::parse(OsAdapter::PLATFORM_WINDOWS, $fx('route_windows_active_nuc_ra.txt')),
        $lifetimes
    );
    $learnedThread = [];
    $loopback      = null;
    foreach ($raRoutes as $route) {
        if ($route['prefix'] === 'fd89:6b7:bc55::' && $route['length'] === 64) {
            $learnedThread[$route['gateway']] = $route;
        }
        if ($route['prefix'] === '::1' && $route['length'] === 128) {
            $loopback = $route;
        }
    }
    assertSame(2, count($learnedThread), 'RA-Tabelle: zwei Thread-Routen');
    assertSame(true, $learnedThread['fe80::8f7:24ce:93c4:8920']['learned'] ?? null, 'Thread-Route über Apple TV als RA-gelernt markiert');
    assertSame(1730, $learnedThread['fe80::8f7:24ce:93c4:8920']['validLifetime'] ?? null, 'Restlaufzeit übernommen');
    assertSame(true, $learnedThread['fe80::6720:d6cb:b7d2:bed']['learned'] ?? null, 'Thread-Route über DIRIGERA als RA-gelernt markiert');
    assertSame(false, $loopback['learned'] ?? null, 'Loopback: nicht gelernt');

    // Bewertung: gelernte Routen sind kein Persistenz-Befund mehr, sondern ein eigener Befund
    $linkLocals = ['fe80::8f7:24ce:93c4:8920', 'fe80::6720:d6cb:b7d2:bed'];
    $own        = ['fd86:6fd:53ed:0:5ab9:15a9:ed5d:db8b', '2003:f9:7f09:1400:c2af:8d03:fc68:9e68'];
    $assessRa   = RouteTable::assess($raRoutes, [], ['fd89:6b7:bc55::'], $linkLocals, $own, OsAdapter::PLATFORM_WINDOWS);
    assertSame([], $assessRa['notPersistent'], 'RA-gelernte Routen ohne persistenten Eintrag: kein „nicht persistent"');
    assertSame(2, count($assessRa['learned'] ?? []), 'beide RA-gelernten Thread-Routen unter „learned"');
    assertSame([], $assessRa['gatewayUnknown'], 'Gateways sind die aktuellen Border Router');

    // Kontrolle: ohne Lebensdauer-Information bleibt das alte Verhalten
    $plainRoutes = RouteTable::parse(OsAdapter::PLATFORM_WINDOWS, $fx('route_windows_active_nuc_ra.txt'));
    $assessPlain = RouteTable::assess($plainRoutes, [], ['fd89:6b7:bc55::'], $linkLocals, $own, OsAdapter::PLATFORM_WINDOWS);
    assertSame(2, count($assessPlain['notPersistent']), 'ohne Lebensdauer-Information weiterhin „nicht persistent"');
    assertSame([], $assessPlain['learned'] ?? [], 'ohne Lebensdauer-Information nichts als gelernt');
}
// --- Gelernte Routen sind weder „veraltet" noch „unbekanntes Gateway" (Review 08.09.2026) ---
// Hat Windows die Route aus einem Router Advertisement, ist ihr Gateway per Definition ein
// aktueller Router — auch wenn dessen Link-Local in dieser mDNS-Runde nicht ankam. Und ein
// Präfix, das noch per RA verteilt wird, ist nicht veraltet. Sonst empfiehlt das Modul ein
// Löschen, das Windows beim nächsten RA rückgängig macht, und der Befund flackert.
if (method_exists(RouteTable::class, 'annotateLifetimes')) {
    $raRoutes2 = RouteTable::annotateLifetimes(
        RouteTable::parse(OsAdapter::PLATFORM_WINDOWS, $fx('route_windows_active_nuc_ra.txt')),
        RouteTable::parseLifetimes($fx('route_windows_verbose_active_nuc.txt'))
    );
    $own2 = ['fd86:6fd:53ed:0:5ab9:15a9:ed5d:db8b'];
    $onlyDirigera = RouteTable::assess($raRoutes2, [], ['fd89:6b7:bc55::'], ['fe80::6720:d6cb:b7d2:bed'], $own2, OsAdapter::PLATFORM_WINDOWS);
    assertSame([], $onlyDirigera['gatewayUnknown'], 'RA-gelernte Route über den (diesmal nicht aufgelösten) Apple TV ist kein unbekanntes Gateway');
    assertSame(2, count($onlyDirigera['learned']), 'beide RA-Routen bleiben „gelernt"');
    $noPrefix = RouteTable::assess($raRoutes2, [], [], ['fe80::8f7:24ce:93c4:8920', 'fe80::6720:d6cb:b7d2:bed'], $own2, OsAdapter::PLATFORM_WINDOWS);
    assertSame([], $noPrefix['stale'], 'RA-gelernte Routen sind nicht veraltet, auch wenn kein Gerät das Präfix nutzt');
    assertSame(2, count($noPrefix['learned']), 'auch dann beide als gelernt geführt');

    // Schnittstellenindex gehört in den Schlüssel: zweiter Block gleiches Präfix/Gateway,
    // anderer Index, manuell (Unendlich) — darf die RA-Route auf Index 12 nicht überschreiben.
    $verbose = $fx('route_windows_verbose_active_nuc.txt');
    $block   = "\n\nZielpräfix:             fd89:6b7:bc55::/64\nQuellpräfix:            ::/0\nSchnittstellenindex:    5\n"
             . "Gateway/Schnittst.-name:fe80::8f7:24ce:93c4:8920\nVeröffentlichen:        Nein\nTyp:                    Manuell\n"
             . "Metrik:                 256\nStandortpräfixlänge     0\nGültigkeitsdauer        Unendlich\nBevorzugte Gültigkeitsd.Unendlich\n";
    $lifetimesTwoIf = RouteTable::parseLifetimes(rtrim($verbose) . $block);
    $routesTwoIf    = RouteTable::annotateLifetimes(
        RouteTable::parse(OsAdapter::PLATFORM_WINDOWS, $fx('route_windows_active_nuc_ra.txt')),
        $lifetimesTwoIf
    );
    $if12 = null;
    foreach ($routesTwoIf as $route) {
        if ($route['prefix'] === 'fd89:6b7:bc55::' && $route['gateway'] === 'fe80::8f7:24ce:93c4:8920' && $route['interface'] === '12') {
            $if12 = $route;
        }
    }
    assertSame(true, $if12['learned'] ?? null, 'Gleiche Route auf anderer Schnittstelle überschreibt die Lebensdauer nicht');
    assertSame(1730, $if12['validLifetime'] ?? null, 'Index 12 behält seine 1730 s');

    // Beschriftungsvarianten: englisch in einem Wort, und unbekannte Sprache (Rückfall Zeile 9)
    $en = str_replace('Gültigkeitsdauer', 'ValidLifetime', $verbose);
    $lt = RouteTable::parseLifetimes($en);
    assertSame(1730, $lt['fd89:6b7:bc55::/64|fe80::8f7:24ce:93c4:8920|12'] ?? $lt['fd89:6b7:bc55::/64|fe80::8f7:24ce:93c4:8920'] ?? 'fehlt', 'Label „ValidLifetime" (ein Wort) wird gelesen');
    $fr = str_replace('Gültigkeitsdauer', 'Durée de validité', $verbose);
    $lt = RouteTable::parseLifetimes($fr);
    assertSame(1730, $lt['fd89:6b7:bc55::/64|fe80::8f7:24ce:93c4:8920|12'] ?? $lt['fd89:6b7:bc55::/64|fe80::8f7:24ce:93c4:8920'] ?? 'fehlt', 'Unbekannte Beschriftung: Rückfall auf Zeile 9');
}
// --- 4a (Review 08.09.2026): gelernte Route mit zusätzlichem persistenten Eintrag ----------
// Der Befund „wird automatisch gelernt" soll den dauerhaften Eintrag als Reserve nennen,
// statt ihn zu verschweigen — wer ihn auf „nicht nötig" hin löscht, hat nach einem Neustart
// bis zum ersten Router Advertisement keinen Weg. Beide Fixtures stammen aus derselben
// Minute auf dem nuc (verbose aktiv: 1730 s; persistent: Unendlich).
if (method_exists(RouteTable::class, 'annotateLifetimes')) {
    $raRoutes3 = RouteTable::annotateLifetimes(
        RouteTable::parse(OsAdapter::PLATFORM_WINDOWS, $fx('route_windows_active_nuc_ra.txt')),
        RouteTable::parseLifetimes($fx('route_windows_verbose_active_nuc.txt'))
    );
    $persistent3 = RouteTable::parse(OsAdapter::PLATFORM_WINDOWS, $fx('route_windows_persistent_with_thread.txt'));
    $withPersistent = RouteTable::assess($raRoutes3, $persistent3, ['fd89:6b7:bc55::'], ['fe80::8f7:24ce:93c4:8920', 'fe80::6720:d6cb:b7d2:bed'], ['fd86:6fd:53ed::1'], OsAdapter::PLATFORM_WINDOWS);
    assertSame(2, count($withPersistent['learned']), 'mit persistentem Speicher: beide Routen weiterhin gelernt');
    foreach ($withPersistent['learned'] as $route) {
        assertSame(true, $route['persistent'] ?? null, 'gelernte Route trägt persistent=true, wenn ein dauerhafter Eintrag für das Präfix existiert (' . $route['gateway'] . ')');
    }
    $withoutPersistent = RouteTable::assess($raRoutes3, [], ['fd89:6b7:bc55::'], [], ['fd86:6fd:53ed::1'], OsAdapter::PLATFORM_WINDOWS);
    foreach ($withoutPersistent['learned'] as $route) {
        assertSame(false, $route['persistent'] ?? null, 'ohne dauerhaften Eintrag persistent=false (' . $route['gateway'] . ')');
    }
}