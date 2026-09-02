<?php

declare(strict_types=1);

/**
 * Statische Prüfung der Formular-Aktionen (Anlass: 01.09.2026 — der Button rief
 * die globale RequestAction(VariablenID, Wert) mit drei Parametern auf, was in
 * der Konsole mit "Wrong parameter count for RequestAction()" crashte).
 *
 * Regeln:
 *  1. onClick-Skripte lösen Instanz-Aktionen über IPS_RequestAction($id, '<Ident>', ...) aus —
 *     nie über die globale RequestAction(), die eine Variablen-ID erwartet.
 *  2. Jeder in einem onClick verwendete Ident muss in module.php behandelt werden.
 */

$moduleDir = dirname(__DIR__) . '/MatterDiagnose';
$form      = json_decode((string)file_get_contents($moduleDir . '/form.json'), true, 64, JSON_THROW_ON_ERROR);
$modulePhp = (string)file_get_contents($moduleDir . '/module.php');

$onClickScripts = [];
$collect = static function (array $node, string $path) use (&$collect, &$onClickScripts): void {
    foreach ($node as $key => $value) {
        if ($key === 'onClick' && is_string($value)) {
            $onClickScripts[$path] = $value;
        } elseif (is_array($value)) {
            $collect($value, $path . '.' . $key);
        }
    }
};
$collect($form, 'form');

assertTrue($onClickScripts !== [], 'form.json enthält mindestens ein onClick');

foreach ($onClickScripts as $path => $script) {
    assertTrue(
        preg_match('/(?<![A-Za-z_])RequestAction\s*\(\s*\$id/', $script) !== 1,
        $path . ': globale RequestAction() mit $id ist falsch — IPS_RequestAction($id, ...) verwenden'
    );
    assertTrue(
        preg_match('/IPS_RequestAction\s*\(\s*\$id\s*,\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $script, $m) === 1,
        $path . ': onClick löst eine Instanz-Aktion über IPS_RequestAction($id, ...) aus'
    );
    if (isset($m[1])) {
        assertTrue(
            str_contains($modulePhp, "'" . $m[1] . "'"),
            $path . ': Ident "' . $m[1] . '" wird in module.php behandelt'
        );
    }
}

/**
 * Dieselbe Regel gilt für die Skripttexte der Timer: Ein Timer, dessen Ident in
 * RequestAction nicht behandelt wird, feuert und wirft dann jedes Mal eine
 * Exception ins Meldungsprotokoll.
 */
assertTrue(
    preg_match_all('/RegisterTimer\s*\((.*?)\);\s*$/ms', $modulePhp, $timerCalls) >= 1,
    'module.php registriert mindestens einen Timer'
);
foreach ($timerCalls[1] ?? [] as $index => $arguments) {
    assertTrue(
        preg_match('/(?<![A-Za-z_])RequestAction\s*\(\s*\$id/', $arguments) !== 1,
        'Timer ' . $index . ': globale RequestAction() ist falsch — IPS_RequestAction() verwenden'
    );
    assertTrue(
        preg_match('/IPS_RequestAction\s*\([^,]+,\s*[\'"]([A-Za-z0-9_]+)[\'"]/', $arguments, $timerIdent) === 1,
        'Timer ' . $index . ': Skripttext löst eine Instanz-Aktion über IPS_RequestAction() aus'
    );
    if (isset($timerIdent[1])) {
        assertTrue(
            preg_match('/\$Ident\s*===\s*[\'"]' . preg_quote($timerIdent[1], '/') . '[\'"]/', $modulePhp) === 1,
            'Timer ' . $index . ': Ident "' . $timerIdent[1] . '" wird in RequestAction behandelt'
        );
    }
}

/**
 * Wächterbetrieb ab 0.4: Vorgabe 60 Minuten, Eingabe in Minuten (Suffix "min").
 * Anlass: 0.3 lieferte 0 = aus als Vorgabe, Anwender bekamen die Überwachung
 * nicht automatisch (Feldtest Neustadt 02.09.2026).
 */
assertTrue(
    preg_match('/RegisterPropertyInteger\(\s*self::PROP_MONITOR_INTERVAL\s*,\s*60\s*\)/', $modulePhp) === 1,
    'MonitorInterval hat die Vorgabe 60 (Minuten)'
);
$intervalElement = null;
foreach ($form['elements'] ?? [] as $element) {
    if (($element['name'] ?? '') === 'MonitorInterval') {
        $intervalElement = $element;
    }
}
assertTrue($intervalElement !== null, 'form.json enthält das Element MonitorInterval');
assertSame('min', $intervalElement['suffix'] ?? null, 'MonitorInterval wird in Minuten eingegeben');
assertSame(0, $intervalElement['minimum'] ?? null, 'MonitorInterval erlaubt 0 = aus');
