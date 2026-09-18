<?php

declare(strict_types=1);
// Erzeugt MatterDiagnose/libs/oui.php aus der IEEE-Liste (MA-L), beschränkt auf Hersteller,
// die im Smart Home vorkommen. Vollständig wäre die Liste 3,8 MB — zu viel für ein Modul.
$csv = fopen($argv[1], 'r');
$wanted = '/^(Espressif|Shelly|Allterco|IKEA|Signify|Philips Lighting|Tuya|ITEAD|Sonoff|Nanoleaf|Eve Systems|Lumi United|Aqara|Google|Apple|Amazon Technologies|Samsung Electronics|TP-LINK|TP-Link|Meross|WiZ|Govee|LEDVANCE|Robert Bosch|Bosch|eQ-3|AVM|Ubiquiti|Raspberry Pi|Texas Instruments|Nordic Semiconductor|Silicon Lab|Qorvo|NXP|Realtek|Sonos|Netatmo|Legrand|Somfy|tado|Aeotec|ecobee|Ring LLC|Wyze|Xiaomi|Roborock|Dyson|Miele|BSH Hausger|Husqvarna|Gardena|VELUX|Fibaro|Anker|Yeelight|Leviton|Lutron|Sengled|Nest Labs|Belkin|Arlo|Netgear|Ecovacs|Dreame|Heiman|Innr|OSRAM|Zemismart|Third Reality|Athom|Busch-Jaeger|Gira|Hager|Viessmann|Vaillant|Buderus|Stiebel|Enphase|SMA Solar|Fronius|Kostal|SolarEdge|Homematic|Devolo|Telegärtner|Wago|Loxone|Ubisys|Dresden Elektronik|Mediola|Tasmota|Seeed|Arduino|Particle|Sonoff|Ai-Thinker|Shenzhen Ai-Thinker|Wemos|Insta|Eltako|Theben|Elgato|Logitech|Nuki|Yale|August|Danalock|Schlage|Kwikset|Level Home|SwitchBot|Wonderlabs|Meross|Ledvance|Lifx|LIFX|Hue|Ecoflow|EcoFlow|Anker Innovations|Tesla|Wallbox|Keba|go-e|ABB|Siemens|Schneider Electric|Honeywell|Resideo|Ademco|Daikin|Mitsubishi Electric|Panasonic|LG Electronics)\b/i';
$out = [];
fgetcsv($csv);
while (($row = fgetcsv($csv)) !== false) {
    [$registry, $prefix, $org] = $row;
    if (preg_match($wanted, $org) !== 1) { continue; }
    $name = preg_replace('/[,.]?\s*(Inc\.?|Incorporated|Ltd\.?|Limited|GmbH( & Co\.? KG)?|AG|AB|BV|B\.V\.|Co\.?,? Ltd\.?|Corporation|Corp\.?|LLC|L\.L\.C\.|S\.A\.|SA|SAS|S\.p\.A\.|Pty\.? Ltd\.?|Co\.|Company|Technologies|Technology|Electronics|Holdings?)\.?\s*$/iu', '', trim($org));
    $name = preg_replace('/[,.]?\s*(Inc\.?|Ltd\.?|Limited|GmbH|AG|AB|BV|Corporation|Corp\.?|LLC|Co\.)\.?\s*$/iu', '', $name);
    $name = trim($name, ' ,.');
    $kurz = ['/^Apple$/i'=>'Apple','/^Samsung/i'=>'Samsung','/^Google/i'=>'Google','/^Amazon/i'=>'Amazon','/^Xiaomi/i'=>'Xiaomi','/^TP-?Link/i'=>'TP-Link','/^Texas Instruments/i'=>'Texas Instruments','/^AVM/i'=>'AVM','/^Tuya/i'=>'Tuya','/^NXP/i'=>'NXP','/^Nordic/i'=>'Nordic Semiconductor','/^Silicon Lab/i'=>'Silicon Labs','/^Ubiquiti/i'=>'Ubiquiti','/^NETGEAR/i'=>'Netgear','/^Realtek/i'=>'Realtek','/^Espressif/i'=>'Espressif','/^Sonos/i'=>'Sonos','/^Robert Bosch|^Bosch/i'=>'Bosch','/^Signify|^Philips Lighting/i'=>'Signify (Philips Hue)','/^IKEA/i'=>'IKEA','/^Shelly|^Allterco/i'=>'Shelly','/^Lumi United|^Aqara/i'=>'Aqara','/^Legrand/i'=>'Legrand','/^Netatmo/i'=>'Netatmo','/^eQ-3/i'=>'eQ-3 (Homematic)','/^Raspberry Pi/i'=>'Raspberry Pi','/^Logitech/i'=>'Logitech','/^Honeywell|^Resideo/i'=>'Honeywell/Resideo','/^Siemens/i'=>'Siemens','/^ABB/i'=>'ABB','/^Schneider/i'=>'Schneider Electric','/^Panasonic/i'=>'Panasonic','/^Daikin/i'=>'Daikin','/^Mitsubishi/i'=>'Mitsubishi Electric','/^Tesla/i'=>'Tesla','/^Anker/i'=>'Anker','/^Meross/i'=>'Meross','/^Sengled/i'=>'Sengled','/^LEDVANCE|^OSRAM/i'=>'Ledvance/Osram','/^Miele/i'=>'Miele','/^BSH/i'=>'BSH (Bosch/Siemens)','/^Ecovacs/i'=>'Ecovacs','/^Roborock/i'=>'Roborock','/^Dyson/i'=>'Dyson','/^SMA Solar/i'=>'SMA','/^Fronius/i'=>'Fronius'];
    foreach ($kurz as $re => $k) { if (preg_match($re, $name) === 1) { $name = $k; break; } }
    $out[strtoupper($prefix)] = $name;
}
ksort($out);
$php = "<?php\n\ndeclare(strict_types=1);\n\n/*\n * Hersteller-Präfixe (OUI, erste sechs Hex-Stellen der MAC-Adresse) — Auszug aus der IEEE-MA-L-Liste\n * (standards-oui.ieee.org/oui/oui.csv, Stand " . date('d.m.Y') . "), beschränkt auf Hersteller, die im Smart Home\n * vorkommen. Die vollständige Liste hätte 3,8 MB; ein unbekanntes Präfix heißt also nur „nicht in\n * der Auswahl\", nicht „unbekannter Hersteller\". Neu erzeugen: tests/gen_oui.php <oui.csv>.\n */\n\nreturn [\n";
foreach ($out as $p => $n) { $php .= "    '" . $p . "' => '" . str_replace("'", "\'", $n) . "',\n"; }
$php .= "];\n";
file_put_contents(__DIR__ . '/../MatterDiagnose/libs/oui.php', $php);
echo count($out), ' Präfixe, ', strlen($php), " Bytes\n";
$names = array_count_values($out); arsort($names); foreach (array_slice($names, 0, 25, true) as $n => $c) echo "  $c  $n\n";
