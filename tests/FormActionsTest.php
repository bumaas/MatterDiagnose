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
