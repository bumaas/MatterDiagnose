<?php

declare(strict_types=1);

/**
 * Zeitzügel der Namensauflösung (Cloud-Review 20.09.2026).
 *
 * `gethostbyaddr` kennt keinen Zeitschalter: Ein Resolver ohne passende Einträge lässt
 * jede Anfrage in den Timeout laufen. Der Lauf hat dafür 1,5 s — und weil
 * `runDiagnosis()` vorher `set_time_limit(0)` setzt, fängt PHP ein Überziehen nicht ab.
 *
 * Gemessen wurde die Dauer bisher erst, nachdem ein Gerät vollständig abgefragt war. Ein
 * Gerät ohne IPv4 — genau der Fall, für den die Runde gebaut ist — fragt aber jede seiner
 * IPv6-Adressen einzeln ab. Bei vier Adressen und einem Resolver im Timeout waren das
 * vier Timeouts am Stück, ehe der Abbruch überhaupt zum Zuge kam; die Höchstzahl an
 * Abfragen galt ebenfalls nur zwischen zwei Geräten.
 *
 * Deshalb hier eine Uhr-Attrappe: Jede Abfrage stellt sie um ihre Kosten vor, das Netz
 * bleibt aus dem Spiel, und die Zahl der Abfragen ist der Prüfstein.
 */

$libFile = __DIR__ . '/../MatterDiagnose/libs/ReverseLookup.php';
if (!is_file($libFile)) {
    assertTrue(false, 'libs/ReverseLookup.php fehlt');

    return;
}
require_once $libFile;

// Zahlen wie im Modul: 1,5 s für die ganze Runde, höchstens 8 Abfragen, ab 0,4 s gilt
// eine Antwort als zu langsam.
$frist   = 1.5;
$max     = 8;
$langsam = 0.4;

$uhr   = 1000.0;
$clock = static function () use (&$uhr): float {
    return $uhr;
};

/** Ein Auflöser, der die Uhr um seine Kosten vorstellt und jede Frage protokolliert. */
$aufloeser = static function (array $antworten, float $kosten, array &$protokoll) use (&$uhr): callable {
    return static function (string $frage) use ($antworten, $kosten, &$protokoll, &$uhr): ?string {
        $protokoll[] = $frage;
        $uhr        += $kosten;

        return $antworten[$frage] ?? null;
    };
};

$geraet = static fn(array $addresses, ?int $nodeId = null): array => [
    'host' => '3D59C51D251F.local', 'name' => '3D59C51D251F', 'nodeId' => $nodeId, 'addresses' => $addresses,
];

// --- Der Resolver hängt: nach der ersten Antwort ist Schluss ------------------
// Echo Dot ohne A-Record, vier IPv6-Adressen. Jede Anfrage läuft 5 s in den Timeout.
$uhr       = 1000.0;
$protokoll = [];
$haengt    = $aufloeser([], 5.0, $protokoll);
$ergebnis  = ReverseLookup::collect(
    [$geraet(['fd86:6fd:53ed:0:de54:d7ff:fe14:dd72', 'fd86:6fd:53ed::2', 'fe80::de54:d7ff:fe14:dd72', 'fd86:6fd:53ed::4'])],
    $haengt,
    $haengt,
    $clock,
    1000.0 + $frist,
    $max,
    $langsam
);
assertSame(1, $ergebnis['queries'], 'Hängender Resolver: nur eine Abfrage, dann Abbruch');
assertSame(1, count($protokoll), 'Hängender Resolver: die übrigen Adressen werden nicht mehr gefragt');
assertSame('slow', $ergebnis['stop'], 'Hängender Resolver: Abbruchgrund ist die zu langsame Antwort');
assertSame([], $ergebnis['names'], 'Hängender Resolver: keine Namen');
assertSame(1005.0, $uhr, 'Hängender Resolver: ein Timeout statt vier');

// --- Die Höchstzahl gilt auch innerhalb eines Geräts --------------------------
// Ein Gerät mit vielen Adressen darf die Runde nicht allein leerfahren.
$uhr       = 1000.0;
$protokoll = [];
$schnell   = $aufloeser([], 0.01, $protokoll);
$viele     = [];
for ($i = 1; $i <= 20; $i++) {
    $viele[] = sprintf('fd86:6fd:53ed::%d', $i);
}
$ergebnis = ReverseLookup::collect([$geraet($viele)], $schnell, $schnell, $clock, 1000.0 + $frist, $max, $langsam);
assertSame($max, $ergebnis['queries'], 'Höchstzahl greift auch innerhalb eines Geräts');
assertSame('limit', $ergebnis['stop'], 'Abbruchgrund ist die Höchstzahl');

// --- Die Frist endet die Runde ------------------------------------------------
// Jede Antwort kommt in 0,3 s — einzeln nicht zu langsam, in Summe aber zu viel.
$uhr       = 1000.0;
$protokoll = [];
$zaeh      = $aufloeser([], 0.3, $protokoll);
$ergebnis  = ReverseLookup::collect(
    [$geraet(['fd86::1']), $geraet(['fd86::2']), $geraet(['fd86::3']), $geraet(['fd86::4']), $geraet(['fd86::5'])],
    $zaeh,
    $zaeh,
    $clock,
    1000.0 + 1.0,
    $max,
    $langsam
);
assertSame(4, $ergebnis['queries'], 'Nach Ablauf der Frist wird nicht mehr gefragt');
assertSame('deadline', $ergebnis['stop'], 'Abbruchgrund ist die abgelaufene Frist');
assertTrue($uhr <= 1000.0 + 1.0 + 0.3, 'Die Runde überzieht höchstens um eine laufende Abfrage');

// --- Frist schon abgelaufen: gar keine Abfrage --------------------------------
$uhr       = 1000.0;
$protokoll = [];
$schnell   = $aufloeser([], 0.01, $protokoll);
$ergebnis  = ReverseLookup::collect([$geraet(['192.168.178.69'])], $schnell, $schnell, $clock, 999.0, $max, $langsam);
assertSame(0, $ergebnis['queries'], 'Abgelaufene Frist: keine Abfrage mehr');
assertSame('deadline', $ergebnis['stop'], 'Abgelaufene Frist: Abbruchgrund');

// --- Normalfall: mit IPv4 genügt eine Abfrage ---------------------------------
$uhr       = 1000.0;
$protokoll = [];
$mitIpv4   = $aufloeser(['192.168.178.69' => 'EchoDot-Kueche.fritz.box'], 0.02, $protokoll);
$ergebnis  = ReverseLookup::collect(
    [$geraet(['fd86:6fd:53ed::9', '192.168.178.69'])],
    $mitIpv4,
    $mitIpv4,
    $clock,
    1000.0 + $frist,
    $max,
    $langsam
);
assertSame(1, $ergebnis['queries'], 'Mit IPv4 genügt eine Abfrage');
assertSame(['192.168.178.69' => 'EchoDot-Kueche.fritz.box'], $ergebnis['names'], 'Der Klarname kommt direkt');
assertSame(null, $ergebnis['stop'], 'Regulär zu Ende');

// --- Der Umweg über zwei Ecken (ohne A-Record) --------------------------------
// Auf die IPv6 nennt die FRITZ!Box nur den mDNS-Namen; erst dessen IPv4 führt zum
// Klarnamen (build 51/52).
$uhr        = 1000.0;
$protokoll  = [];
$rueckwaerts = $aufloeser([
    'fd86:6fd:53ed:0:de54:d7ff:fe14:dd72' => '3D59C51D251F.fritz.box',
    '192.168.178.69'                      => 'EchoDot-Kueche.fritz.box',
], 0.02, $protokoll);
$vorwaerts = $aufloeser(['3D59C51D251F.fritz.box' => '192.168.178.69'], 0.02, $protokoll);
$ergebnis  = ReverseLookup::collect(
    [$geraet(['fd86:6fd:53ed:0:de54:d7ff:fe14:dd72'])],
    $rueckwaerts,
    $vorwaerts,
    $clock,
    1000.0 + $frist,
    $max,
    $langsam
);
assertSame(
    ['fd86:6fd:53ed:0:de54:d7ff:fe14:dd72' => 'EchoDot-Kueche.fritz.box'],
    $ergebnis['names'],
    'Über zwei Ecken zum Klarnamen'
);
assertSame(3, $ergebnis['queries'], 'Drei Abfragen — jede zählt gegen die Höchstzahl');

// --- Gekoppelte Geräte werden nicht gefragt -----------------------------------
$uhr       = 1000.0;
$protokoll = [];
$schnell   = $aufloeser(['192.168.178.69' => 'EchoDot-Kueche.fritz.box'], 0.01, $protokoll);
$ergebnis  = ReverseLookup::collect([$geraet(['192.168.178.69'], 42)], $schnell, $schnell, $clock, 1000.0 + $frist, $max, $langsam);
assertSame(0, $ergebnis['queries'], 'Ein in Symcon gekoppeltes Gerät braucht keinen Klarnamen');
assertSame([], $ergebnis['names'], 'Und liefert keinen Eintrag');

// --- Mehrere Geräte, alle schnell ---------------------------------------------
$uhr       = 1000.0;
$protokoll = [];
$schnell   = $aufloeser([
    '192.168.178.69' => 'EchoDot-Kueche.fritz.box',
    '192.168.178.70' => 'Drucker.fritz.box',
], 0.01, $protokoll);
$ergebnis = ReverseLookup::collect(
    [$geraet(['192.168.178.69']), $geraet(['192.168.178.70']), $geraet(['fd86::99'])],
    $schnell,
    $schnell,
    $clock,
    1000.0 + $frist,
    $max,
    $langsam
);
assertSame(3, $ergebnis['queries'], 'Alle drei Geräte werden gefragt');
assertSame(2, count($ergebnis['names']), 'Zwei Treffer, das dritte Gerät kennt der Router nicht');
assertSame(null, $ergebnis['stop'], 'Kein Abbruch bei schnellen Antworten');
