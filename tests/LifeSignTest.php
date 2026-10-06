<?php

declare(strict_types=1);

require_once __DIR__ . '/harness.php';

require_once __DIR__ . '/../MatterDiagnose/libs/MdnsCodec.php';
require_once __DIR__ . '/../MatterDiagnose/libs/DeviceIdentity.php';
require_once __DIR__ . '/../MatterDiagnose/libs/SymconInventory.php';

/**
 * Lebenszeichen ohne Matter-Ansage (build 84).
 *
 * Am nuc (06.10.2026, 0.9 #83) stand „2 gekoppelte Geräte sind nicht mehr erreichbar“ mit
 * der Abhilfe „Prüfen Sie Stromversorgung und Reichweite“. Beide Shellys hatten keine
 * Matter-Ansage und antworteten auch auf die direkte `_shelly`-Frage nicht. Der Dimmer
 * (#20390) lieferte aber jede Minute seinen Energiezähler an Symcon, zuletzt 09:06, und der
 * Plug (#30402, 192.168.178.176) antwortete auf Ping; sein Schaltzustand ändert sich nur,
 * wenn jemand schaltet.
 *
 * Frische Daten an Symcon belegen, dass das Gerät arbeitet: kein Befund. Ein Ping belegt
 * nur Strom und Netz; Symcon konnte den Plug in diesem Zustand nicht schalten (Velux-Wächter
 * #47496). Er bleibt rot, aber ohne den Rat zu Strom und Reichweite und ohne Vermutung
 * (Burkhard 06.10.2026: „einmal neu starten“ ist spekulativ).
 */

$now = 1791270360; // 06.10.2026 09:06:00, letzte Meldung des Energiezählers am nuc

// --- Frische Daten ---
$frisch = static fn(int $updated): bool => method_exists(SymconInventory::class, 'freshData') && SymconInventory::freshData($updated, $now);
assertTrue($frisch($now), 'Meldung in derselben Minute ist frisch');
assertTrue($frisch($now - 14 * 60), 'Meldung vor 14 Minuten ist frisch');
assertTrue(!$frisch($now - 16 * 60), 'Meldung vor 16 Minuten ist es nicht mehr');
assertTrue(!$frisch(0), 'Ohne Zeitstempel kein Lebenszeichen');
// Plug: letzter Schaltzustand nach dem Neustart des Kernels am Vortag
assertTrue(!$frisch(1791185957), 'Plug #30402 (05.10. 09:39:17) liefert keinen Beleg');
assertTrue(!$frisch($now + 3600), 'Ein Zeitstempel in der Zukunft belegt nichts');

// --- Nachfrageziele: wer frische Daten liefert, braucht keine Nachfrage ---
$plug       = ['nodeId' => 7, 'name' => 'Shelly Plug S Gen3', 'visible' => false, 'sleepy' => false, 'host' => 'D0CF13CA7430.local', 'subscription' => 'Nicht gefunden', 'aliveService' => ''];
$dimmer     = ['nodeId' => 10, 'name' => 'Shelly Dimmer Gen4', 'visible' => false, 'sleepy' => false, 'host' => 'E8F60A7C9714.local', 'subscription' => 'Nicht gefunden', 'aliveService' => '', 'aliveData' => true];
$remembered = [7 => ['192.168.178.176'], 10 => ['192.168.178.67']];
assertSame(['192.168.178.176' => [7]], DeviceIdentity::recheckTargets([$plug, $dimmer], $remembered), 'Nur der Plug wird nachgefragt');

// --- Die Texte vermuten nichts ---
$locale = json_decode((string)file_get_contents(__DIR__ . '/../MatterDiagnose/locale.json'), true, 512, JSON_THROW_ON_ERROR);
$module = (string)file_get_contents(__DIR__ . '/../MatterDiagnose/module.php');
$texte  = [];
foreach (['own_devices_announce_missing', 'own_devices_unsubscribed_ping'] as $id) {
    $gefunden = preg_match("/'" . $id . "' => \\[\\s*'[^']*',\\s*'([^']*)',\\s*'([^']*)'/", $module, $m) === 1;
    assertTrue($gefunden, 'Katalogeintrag ' . $id . ' gefunden');
    if (!$gefunden) {
        continue;
    }
    array_push($texte, $m[1], $m[2]);
    if ($id === 'own_devices_unsubscribed_ping') {
        // Er antwortet auf Ping: Strom und Reichweite sind belegt, dorthin schickt die Abhilfe nicht
        assertTrue(stripos($m[2], 'power') === false && stripos($m[2], 'range') === false, 'Abhilfe bei Ping-Antwort nennt weder Strom noch Reichweite');
    }
}
foreach ($texte as $englisch) {
    $deutsch = (string)($locale['translations']['de'][$englisch] ?? '');
    assertTrue($deutsch !== '', 'Übersetzt: ' . substr($englisch, 0, 50));
    foreach (['restart', 'reboot', 'known fault'] as $wort) {
        assertTrue(stripos($englisch, $wort) === false, 'Englischer Text ohne „' . $wort . '“');
    }
    foreach (['neu starten', 'Neustart', 'bekannter Fehler'] as $wort) {
        assertTrue(stripos($deutsch, $wort) === false, 'Deutscher Text ohne „' . $wort . '“');
    }
    assertTrue(!str_contains($englisch . $deutsch, '—'), 'Kein langer Gedankenstrich');
}
