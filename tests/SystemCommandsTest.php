<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php';
require_once __DIR__ . '/../MatterDiagnose/libs/RouteTable.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php';
require_once __DIR__ . '/../MatterDiagnose/libs/ChangeTracker.php';

/**
 * Systembefehle nur dort, wo das Modul sie kennt (Store-Review der Stable 1.0 #85,
 * 07.10.2026, Release 18921 abgelehnt). Drei Lücken aus dem Review:
 *
 * 1. Alles außer Windows galt als Linux. Auf macOS fehlen `ip` und /proc, und BSD-ping
 *    rechnet `-W` in Millisekunden: aus 2 s Geduld würden 2 ms, jedes Thread-Gerät
 *    „nicht erreichbar“. Jetzt gibt es eine dritte Plattform „Other“ ohne Urteil.
 * 2. shell_exec wurde nie geprüft. Gesperrt (disable_functions) wurde unter Windows aus
 *    der leeren Ausgabe „keine Routen“ und damit der rote Befund, den build 59 für Linux
 *    abgestellt hatte. Jetzt ist execute() dreiwertig und der Windows-Zweig von
 *    fromSystem() ebenso.
 * 3. commandMissing() kannte nur Unix-Wortlaute. cmd.exe meldet ein fehlendes Programm
 *    anders; die Fixture cmd_missing_windows_de.txt ist der Mitschnitt von `cmd /c ipxyz`
 *    (Windows 11 deutsch, 07.10.2026), cmd_missing_windows_en.txt der Wortlaut aus der
 *    Sprachressource C:\Windows\System32\en-US\cmd.exe.mui desselben Rechners.
 *
 * Die Engine meldet den Zustand als einen Hinweis (os_unsupported bzw. shell_disabled)
 * und lässt die Präfix-Befunde weg, sonst stünde dort „Ping-Ausgabe nicht lesbar“ oder
 * „Routentabelle nicht lesbar“, beides eine Falschaussage.
 *
 * Der Code-Review desselben Tages (vor dem Commit) fand in der ersten Fassung vier
 * Fehler, die hier festgehalten sind:
 *
 * a) Der persistente Routenspeicher von Windows ist leer, wenn keine Route dauerhaft
 *    gesetzt ist (Mitschnitt route_windows_persistent_empty.txt). Über fromSystem() wäre
 *    aus „leer“ ein „unbekannt“ geworden und thread_route_not_persistent (build 22) nie
 *    mehr gemeldet worden. Für den Speicher gilt deshalb RouteTable::persistentRoutes().
 * b) Das Zeitbudget entstand vor der Plattformprüfung, mit Ping-Reserve auch dort, wo nie
 *    gepingt wird. Das ist der Fehler aus build 66, nur für den Handlauf.
 * c) Ohne Systembefehle fehlen alle Präfix- und Routenbefunde im Lauf; der ChangeTracker
 *    hätte sie als „Behoben“ gemeldet, so wie es build 64 für den ungetesteten Lauf
 *    abgestellt hat. Der Hinweis selbst ist die einzige Änderung.
 * d) Eine netsh-Ausgabe, die kein fehlendes Programm und keine Tabelle ist (Mitschnitt
 *    netsh_error_windows_de.txt, `netsh interface ipv6 show routex`), ergab unter
 *    Windows ein leeres Array und damit „keine Route“. Eine echte Tabelle enthält immer
 *    Routen; ein leeres Parse-Ergebnis aus nicht leerer Ausgabe ist „unbekannt“.
 */

$fx = static fn(string $name): string => (string)file_get_contents(__DIR__ . '/fixtures/os/' . $name);

// --- Lücke 1: Plattform dreiwertig -------------------------------------------------
assertTrue(defined('OsAdapter::PLATFORM_OTHER'), 'OsAdapter::PLATFORM_OTHER vorhanden');
foreach (['Darwin', 'BSD', 'Solaris', 'Unknown'] as $family) {
    assertSame('Other', OsAdapter::platform($family), "PHP_OS_FAMILY $family ist weder Windows noch Linux");
}
assertSame('Windows', OsAdapter::platform('Windows'), 'PHP_OS_FAMILY Windows');
assertSame('Linux', OsAdapter::platform('Linux'), 'PHP_OS_FAMILY Linux');

if (!method_exists(OsAdapter::class, 'systemCommandsUnavailable')) {
    assertTrue(false, 'OsAdapter::systemCommandsUnavailable fehlt');
} else {
    assertSame('platform', OsAdapter::systemCommandsUnavailable('Other', true), 'fremde Plattform: Grund platform');
    assertSame('shell', OsAdapter::systemCommandsUnavailable('Windows', false), 'shell_exec gesperrt: Grund shell');
    assertSame('platform', OsAdapter::systemCommandsUnavailable('Other', false), 'beides: die Plattform geht vor');
    assertSame(null, OsAdapter::systemCommandsUnavailable('Linux', true), 'Linux mit Shell: kein Grund');
    assertSame(null, OsAdapter::systemCommandsUnavailable('Windows', true), 'Windows mit Shell: kein Grund');
}

// Der Name des Systems im Befundtext: PHP_OS_FAMILY kennt Windows, BSD, Darwin, Solaris,
// Linux und Unknown. „Unknown“ ist kein Betriebssystem; dafür liefert osLabel() null, und
// das Modul setzt einen übersetzten Platzhalter ein (Code-Review 07.10.2026).
if (!method_exists(OsAdapter::class, 'osLabel')) {
    assertTrue(false, 'OsAdapter::osLabel fehlt');
} else {
    assertSame('macOS', OsAdapter::osLabel('Darwin'), 'Darwin heißt im Befund macOS');
    assertSame('BSD', OsAdapter::osLabel('BSD'), 'BSD bleibt BSD');
    assertSame('Solaris', OsAdapter::osLabel('Solaris'), 'Solaris bleibt Solaris');
    assertSame(null, OsAdapter::osLabel('Unknown'), '„Unknown“ ist kein Systemname');
    assertSame(null, OsAdapter::osLabel(''), 'leere Familie ist kein Systemname');
}

// --- Lücke 2: shell_exec geprüft ------------------------------------------------------
// Echter Lauf mit gesperrtem shell_exec: ein PHP-Unterprozess mit -d disable_functions.
// Ohne Prüfung endete der im Fatal „Call to undefined function shell_exec()“. PHP 8
// entfernt eine gesperrte Funktion ganz, function_exists() genügt als Prüfung (die
// erste Fassung las zusätzlich disable_functions, Code-Review 07.10.2026).
// Das Skript liegt in einer Datei: escapeshellarg ersetzt unter Windows jedes " durch
// ein Leerzeichen, ein -r-Einzeiler käme verstümmelt an.
$script = sprintf(
    "<?php\nrequire %s;\necho json_encode([OsAdapter::shellAvailable(), OsAdapter::execute('echo x')]);\n",
    var_export(str_replace('\\', '/', __DIR__ . '/../MatterDiagnose/libs/OsAdapter.php'), true)
);
$scriptBase = (string)tempnam(sys_get_temp_dir(), 'matd');
$scriptFile = $scriptBase . '.php';
file_put_contents($scriptFile, $script);
$command = sprintf(
    '%s -n -d disable_functions=shell_exec -d display_errors=0 -f %s 2>&1',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($scriptFile)
);
exec($command, $lines, $exit);
@unlink($scriptFile);
@unlink($scriptBase);
assertTrue(!file_exists($scriptBase) && !file_exists($scriptFile), 'Unterprozess-Skript hinterlässt keine Datei (tempnam legt die Basisdatei selbst an)');
$output = trim(implode("\n", $lines));
assertSame(0, $exit, 'Unterprozess ohne shell_exec läuft durch (Ausgabe: ' . $output . ')');
assertSame('[false,null]', $output, 'shellAvailable() false und execute() null bei gesperrtem shell_exec');

// Windows-Zweig dreiwertig wie der Linux-Zweig (build 59): leer oder Fehlermeldung → unbekannt
$returnsNull = (new ReflectionMethod(RouteTable::class, 'fromSystem'))->getParameters()[1]->allowsNull();
assertTrue($returnsNull, 'fromSystem nimmt null als Kommandoausgabe (execute ohne Shell)');
assertSame(null, $returnsNull ? RouteTable::fromSystem('Windows', null, null) : '(kein null möglich)', 'Windows, keine Shell: Routen unbekannt');
assertSame(null, RouteTable::fromSystem('Windows', '', null), 'Windows, leere Ausgabe: Routen unbekannt, nicht leer');
assertSame(null, RouteTable::fromSystem('Windows', $fx('cmd_missing_windows_de.txt'), null), 'Windows, Programm fehlt: Routen unbekannt');
// d) netsh antwortet, aber nicht mit einer Tabelle (falsche Syntax, fremde Sprache,
// Zugriff verweigert): ebenfalls unbekannt, kein „keine Route“
assertSame(null, RouteTable::fromSystem('Windows', $fx('netsh_error_windows_de.txt'), null), 'Windows, netsh-Fehlertext: Routen unbekannt, nicht leer');
$nuc = RouteTable::fromSystem('Windows', $fx('route_windows_active_nuc.txt'), null);
assertTrue(is_array($nuc) && $nuc !== [], 'Windows, echte Tabelle: weiterhin Routen');
$proc = RouteTable::fromSystem('Linux', '', $fx('route_linux_proc_testbox.txt'));
assertTrue(is_array($proc) && $proc !== [], 'Linux ohne ip: weiterhin Routen aus /proc');

// a) Der persistente Speicher darf leer sein: Das ist der Normalfall ohne dauerhafte Route
// und die Grundlage von thread_route_not_persistent (build 22, nuc 02.09.2026).
if (!method_exists(RouteTable::class, 'persistentRoutes')) {
    assertTrue(false, 'RouteTable::persistentRoutes fehlt (der Speicher ging über fromSystem, leer hieß unbekannt)');
} else {
    assertSame([], RouteTable::persistentRoutes('Windows', $fx('route_windows_persistent_empty.txt')), 'leerer persistenter Speicher: keine dauerhafte Route, nicht „unbekannt“');
    $stored = RouteTable::persistentRoutes('Windows', $fx('route_windows_persistent_with_thread.txt'));
    assertSame(1, is_array($stored) ? count($stored) : -1, 'persistenter Speicher mit Thread-Route: eine Route');
    assertSame(null, RouteTable::persistentRoutes('Windows', null), 'ohne Shell bleibt der Speicher unbekannt');
    assertSame(null, RouteTable::persistentRoutes('Windows', $fx('cmd_missing_windows_de.txt')), 'ohne netsh bleibt der Speicher unbekannt');
}
$modulePhp = (string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php');
assertTrue(
    preg_match('/RouteTable::persistentRoutes\(\s*\$platform,\s*OsAdapter::execute\(OsAdapter::routeShowPersistentCommand\(\)\)\s*\)/', $modulePhp) === 1,
    'module.php liest den persistenten Speicher über RouteTable::persistentRoutes'
);
assertTrue(
    preg_match('/fromSystem\([^;]*routeShowPersistentCommand/s', $modulePhp) !== 1,
    'module.php schickt den persistenten Speicher nicht durch fromSystem'
);

// b) Das Zeitbudget kennt die Plattform: Ohne Systembefehle gibt es keinen Ping und
// damit keine Reserve dafür, sonst verbietet phaseAllowed im Handlauf Nachfragerunden,
// für die reichlich Zeit wäre (build 66 für den Wächterlauf).
$diagnoseStart = strpos($modulePhp, 'private function diagnoseLocked(');
assertTrue($diagnoseStart !== false, 'module.php enthält diagnoseLocked');
$diagnoseSource = substr($modulePhp, (int)$diagnoseStart);
$osChecksAt     = strpos($diagnoseSource, 'OsAdapter::systemCommandsUnavailable(');
$budgetAt       = strpos($diagnoseSource, 'RunBudget::forRun(');
assertTrue($osChecksAt !== false && $budgetAt !== false, 'diagnoseLocked prüft Systembefehle und baut das Budget');
assertTrue($osChecksAt !== false && $budgetAt !== false && $osChecksAt < $budgetAt, 'Die Plattformprüfung steht vor dem Zeitbudget');
assertTrue(
    preg_match('/RunBudget::forRun\((?:[^;]*?)!\$quick\s*&&\s*\$osChecks\s*===\s*null/s', $diagnoseSource) === 1,
    'Das Budget reserviert den Ping nur, wenn der Lauf pingen kann (!$quick && $osChecks === null)'
);

// --- Lücke 3: Wortlaut von cmd.exe ----------------------------------------------------
assertTrue(OsAdapter::commandMissing($fx('cmd_missing_windows_de.txt')), 'cmd.exe deutsch: „ist entweder falsch geschrieben oder konnte nicht gefunden werden“ heißt: Programm fehlt');
assertTrue(OsAdapter::commandMissing($fx('cmd_missing_windows_en.txt')), 'cmd.exe englisch: „is not recognized as an internal or external command“ heißt: Programm fehlt');
foreach (['route_windows_active_nuc.txt', 'route_windows_persistent_empty.txt', 'ping_windows_de.txt', 'ping_windows_en.txt', 'ping_unreachable_de.txt', 'netsh_error_windows_de.txt'] as $normal) {
    assertTrue(!OsAdapter::commandMissing($fx($normal)), 'Gewöhnliche Windows-Ausgabe ' . $normal . ' ist kein fehlendes Programm');
}

// --- Engine: ein Hinweis, keine Präfix-Befunde ---------------------------------------
$router = ['name' => 'Wohnzimmer', 'host' => 'Wohnzimmer-2.local', 'addresses' => ['fe80::8f7:24ce:93c4:8920'], 'source' => '192.168.178.63', 'txt' => []];
$prefix = static fn(?string $reason, bool $quick): array => [
    'fd89:6b7:bc55::' => [
        'reachable'       => null,
        'testAddress'     => 'fd89:6b7:bc55:0:1234::1',
        'gateway'         => 'fe80::8f7:24ce:93c4:8920',
        'routeExists'     => null,
        'pingSkipped'     => $quick,
        'pingUnavailable' => false,
        'pingReason'      => $reason,
    ],
];
$input = static fn(array $extra): array => $extra + [
    'ipv6Addresses'         => ['fd00::10'],
    'mdnsResponses'         => true,
    'borderRouters'         => [$router],
    'operationalDevices'    => [],
    'commissionableDevices' => [],
    'threadPrefixes'        => [],
    'platform'              => 'Other',
];
$ids = static fn(array $findings): array => array_map(static fn(array $f): string => $f['id'], $findings);

foreach ([false, true] as $quick) {
    $findings = DiagnosisEngine::evaluate($input(['systemCommands' => 'platform', 'osName' => 'macOS', 'threadPrefixes' => $prefix(null, $quick)]));
    $os       = array_values(array_filter($findings, static fn(array $f): bool => $f['id'] === 'os_unsupported'));
    $label    = $quick ? 'Wächterlauf' : 'Handlauf';
    assertSame(1, count($os), "$label auf fremder Plattform: genau ein os_unsupported (" . implode(', ', $ids($findings)) . ')');
    assertSame('notice', $os[0]['severity'] ?? '(fehlt)', "$label: os_unsupported ist ein Hinweis");
    assertSame('macOS', $os[0]['params']['os'] ?? '(fehlt)', "$label: os_unsupported nennt das System");
    $prefixFindings = array_filter($ids($findings), static fn(string $id): bool => str_starts_with($id, 'thread_prefix_'));
    assertSame([], array_values($prefixFindings), "$label auf fremder Plattform: kein thread_prefix_-Befund");
}

// Die Aussage „keine Systembefehle“ steht einmal im Eingang (systemCommands); die
// Präfixe tragen sie nicht noch einmal als pingReason. Gleich, was dort steht, entfällt
// der Präfix-Befund, sonst stünden Hinweis und „Routentabelle nicht lesbar“ nebeneinander.
foreach (['no_device', 'budget', null] as $reason) {
    $findings = DiagnosisEngine::evaluate($input(['platform' => 'Windows', 'systemCommands' => 'shell', 'osName' => 'Windows', 'threadPrefixes' => $prefix($reason, false)]));
    assertSame(['shell_disabled'], array_values(array_filter($ids($findings), static fn(string $id): bool => $id === 'shell_disabled')), 'shell_exec gesperrt (pingReason ' . var_export($reason, true) . '): genau ein shell_disabled');
    assertSame([], array_values(array_filter($ids($findings), static fn(string $id): bool => str_starts_with($id, 'thread_prefix_'))), 'shell_exec gesperrt (pingReason ' . var_export($reason, true) . '): kein thread_prefix_-Befund');
}
$enginePhp = (string)file_get_contents(__DIR__ . '/../MatterDiagnose/libs/DiagnosisEngine.php');
assertTrue(!str_contains($enginePhp, "'platform', 'shell'"), 'Die Engine liest den Grund nicht aus pingReason');
assertTrue(!str_contains($enginePhp, 'PHP_OS_FAMILY'), 'Die Engine liest die Umgebung nicht (osName kommt vom Modul)');

// Der Platzhalter für ein unbenanntes System kommt übersetzt vom Modul
$findings = DiagnosisEngine::evaluate($input(['systemCommands' => 'platform', 'osName' => 'diesem Betriebssystem', 'threadPrefixes' => $prefix(null, false)]));
$os       = array_values(array_filter($findings, static fn(array $f): bool => $f['id'] === 'os_unsupported'));
assertSame('diesem Betriebssystem', $os[0]['params']['os'] ?? '(fehlt)', 'os_unsupported übernimmt den Namen, den das Modul liefert');

// Ohne Thread (keine Border Router, keine Präfixe, Geräte nur mit IPv4) gibt es nichts zu tun
$findings = DiagnosisEngine::evaluate($input([
    'systemCommands'     => 'platform',
    'osName'             => 'macOS',
    'borderRouters'      => [],
    'operationalDevices' => [['instance' => 'A', 'host' => 'shelly.local', 'addresses' => ['192.168.178.70'], 'source' => '192.168.178.70']],
]));
assertTrue(!in_array('os_unsupported', $ids($findings), true), 'ohne Thread kein os_unsupported (Befund ohne Handlung)');

// --- c) Momentaufnahme: fehlende Messung ist kein „Behoben“ ----------------------------
$subjectFinding = static fn(string $id, string $severity, string $subject, string $title): array => [
    'id' => $id, 'severity' => $severity, 'params' => [], 'subject' => $subject, 'title' => $title,
];
$plainFinding = static fn(string $id, string $severity, string $title): array => [
    'id' => $id, 'severity' => $severity, 'params' => [], 'title' => $title,
];
$changeIds = static fn(array $changes): array => array_map(
    static fn(array $c): string => $c['id'] . ':' . ($c['params']['finding'] ?? ''),
    $changes
);
$withShell = ChangeTracker::snapshot([], ['Wohnzimmer'], [
    $subjectFinding('thread_prefix_untested_quick', 'notice', 'fd89:6b7:bc55::', 'Thread-Netz nicht getestet'),
    $subjectFinding('thread_route_not_persistent', 'notice', 'fd89:6b7:bc55::/64', 'Route nicht dauerhaft'),
    $plainFinding('ipv6_ok', 'ok', 'IPv6 vorhanden'),
], 1000);
assertTrue(
    isset($withShell['findings']['thread_prefix_untested@fd89:6b7:bc55::'], $withShell['findings']['thread_route_not_persistent@fd89:6b7:bc55::/64']),
    'Vorlauf mit Shell: Präfix- und Routenbefund in der Momentaufnahme (Vorbedingung)'
);
foreach (['shell_disabled', 'os_unsupported'] as $notice) {
    $withoutShell = ChangeTracker::snapshot([], ['Wohnzimmer'], [
        $plainFinding($notice, 'notice', 'Systembefehle fehlen'),
        $plainFinding('ipv6_ok', 'ok', 'IPv6 vorhanden'),
    ], 2000);
    $carried = ChangeTracker::carryOver($withShell, $withoutShell);
    assertSame('notice', $carried['findings']['thread_prefix_untested@fd89:6b7:bc55::'] ?? null, "$notice: Präfix-Befund des Vorlaufs bleibt stehen");
    assertSame('notice', $carried['findings']['thread_route_not_persistent@fd89:6b7:bc55::/64'] ?? null, "$notice: Routenbefund des Vorlaufs bleibt stehen");
    assertSame('Route nicht dauerhaft', $carried['findingTitles']['thread_route_not_persistent@fd89:6b7:bc55::/64'] ?? null, "$notice: Titel wandert mit");
    assertSame(2000, $carried['time'], "$notice: Zeit ist die des neuen Laufs");
    assertSame(["finding_new:$notice"], $changeIds(ChangeTracker::diff($withShell, $carried)), "$notice: einzige Änderung ist der neue Hinweis, kein „Behoben“");
    // Zweiter Lauf ohne Shell: gar nichts Neues
    $again = ChangeTracker::carryOver($carried, ChangeTracker::snapshot([], ['Wohnzimmer'], [
        $plainFinding($notice, 'notice', 'Systembefehle fehlen'),
    ], 3000));
    assertSame([], $changeIds(ChangeTracker::diff($carried, $again)), "$notice: der folgende Lauf ohne Shell meldet nichts");
}
// Ein roter Befund bleibt rot, bis wieder gemessen wird
$redBefore = ChangeTracker::snapshot([], ['Wohnzimmer'], [
    $subjectFinding('thread_prefix_unreachable', 'blocker', 'fd89:6b7:bc55::', 'Thread-Netz nicht erreichbar'),
], 1000);
$redCarried = ChangeTracker::carryOver($redBefore, ChangeTracker::snapshot([], ['Wohnzimmer'], [$plainFinding('shell_disabled', 'notice', 'Systembefehle fehlen')], 2000));
assertSame('blocker', $redCarried['findings']['thread_prefix_unreachable@fd89:6b7:bc55::'] ?? null, 'roter Präfix-Befund bleibt ohne Messung stehen');
// Kehrt die Shell zurück, zählt wieder der Lauf: der alte Befund gilt als behoben, wenn er fehlt
$measuredAgain = ChangeTracker::carryOver($redCarried, ChangeTracker::snapshot([], ['Wohnzimmer'], [
    $subjectFinding('thread_prefix_reachable', 'ok', 'fd89:6b7:bc55::', 'Thread-Netz erreichbar'),
], 3000));
assertSame(['finding_resolved:shell_disabled', 'finding_resolved:thread_prefix_unreachable'], $changeIds(ChangeTracker::diff($redCarried, $measuredAgain)), 'mit Shell wird wieder gemessen: Hinweis und roter Befund behoben');
// Gegenprobe: Ein Lauf mit Shell, dem die Befunde fehlen, meldet sie wie bisher als behoben
$measured = ChangeTracker::carryOver($withShell, ChangeTracker::snapshot([], ['Wohnzimmer'], [$plainFinding('ipv6_ok', 'ok', 'IPv6 vorhanden')], 2000));
assertSame(['finding_resolved:thread_prefix_untested', 'finding_resolved:thread_route_not_persistent'], $changeIds(ChangeTracker::diff($withShell, $measured)), 'mit Shell gilt der Lauf: fehlende Befunde sind behoben');

// --- Texte: Katalog de und en, eine Ursache, das System beim Namen ----------------------
foreach ([false, true] as $deutsch) {
    $instanz          = neueInstanz();
    $instanz->deutsch = $deutsch;
    $texte            = new ReflectionMethod($instanz, 'findingTexts');
    $sprache          = $deutsch ? 'de' : 'en';
    foreach (['os_unsupported' => ['os' => 'macOS'], 'shell_disabled' => []] as $id => $params) {
        $text  = $texte->invoke($instanz, $id, $params);
        $alles = ($text['title'] ?? '') . ' ' . ($text['text'] ?? '');
        assertTrue(($text['title'] ?? $id) !== $id, "$id ($sprache): Katalogeintrag vorhanden");
        assertTrue(str_contains($alles, $id === 'os_unsupported' ? 'macOS' : 'shell_exec'), "$id ($sprache): nennt das System bzw. shell_exec: $alles");
        assertTrue(preg_match($deutsch ? '/\boder\b/u' : '/\bor\b/u', $alles) !== 1, "$id ($sprache): nennt eine Ursache, kein „oder“: $alles");
    }
    // Unbenanntes System: der übersetzte Platzhalter statt „Unknown“
    $platzhalter = $instanz->Translate('this operating system');
    assertSame($deutsch ? 'diesem Betriebssystem' : 'this operating system', $platzhalter, "Platzhalter für ein unbenanntes System übersetzt ($sprache)");
    $text = $texte->invoke($instanz, 'os_unsupported', ['os' => $platzhalter]);
    assertTrue(str_contains((string)$text['title'], $deutsch ? 'unter diesem Betriebssystem' : 'on this operating system'), "os_unsupported ($sprache) ohne Systemnamen liest sich: " . $text['title']);
    assertSame([], $instanz->unuebersetzt, "Texte übersetzt ($sprache)");
}
assertTrue(
    preg_match('/OsAdapter::osLabel\(\)\s*\?\?\s*\$this->Translate\(\'this operating system\'\)/', $modulePhp) === 1,
    'module.php setzt für ein unbenanntes System den übersetzten Platzhalter ein'
);
