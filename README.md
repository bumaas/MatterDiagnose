# Matter Diagnose

[![Checks](https://github.com/bumaas/MatterDiagnose/actions/workflows/check.yml/badge.svg)](https://github.com/bumaas/MatterDiagnose/actions/workflows/check.yml)

🇬🇧 [English version](README.en.md)

Ein Symcon-Modul, das mit einem Klick prüft, warum Matter-Geräte nicht ins Haus
kommen oder plötzlich verstummen — besonders bei **Matter over Thread**. Das
Ergebnis ist eine Ampel-Liste in Klartext: Was ist los, was bedeutet es, und was
ist zu tun. Ausgeführt wird dabei nichts; das Modul liest nur.

![Beispielbericht](docs/bericht.png)

## Wann brauche ich das?

**Die Kopplung endet mit „Fehlgeschlagen" — ohne weitere Angabe.**
Meist scheitert sie in der letzten Phase: Der Symcon-Rechner findet den Weg ins
Thread-Funknetz nicht, hat kein IPv6, oder das Gerät ist schon mit so vielen
Systemen verbunden, dass kein Platz mehr ist. Die Diagnose nennt den Grund und,
wo nötig, den Befehl, der ihn behebt.

**Ein Gerät steht auf „aktiv", liefert aber keine Werte mehr — in der
Hersteller-App oder in Home Assistant läuft es weiter.**
Symcon zeigt den Verlust der Verbindung nicht an der Instanz an: Der Status
bleibt grün, der letzte Wert bleibt stehen. Die Diagnose vergleicht die in Symcon
gekoppelten Geräte mit denen, die sich im Netz tatsächlich melden, und sagt,
welches fehlt.

**Nach einem Neustart oder Update sind Thread-Geräte tot.**
Dann fehlt oft die Route ins Thread-Netz oder der Border Router hat eine neue
Adresse. Die Diagnose sieht beides.

## Installation und erster Lauf

1. Modul installieren — über den Module Store (Beta-Kanal) oder in der
   Modulverwaltung mit `https://github.com/bumaas/MatterDiagnose.git`.
2. Eine Instanz **Matter Diagnose** anlegen (Instanz hinzufügen → Kern-Instanzen).
3. Im Konfigurationsformular **Diagnose starten** klicken. Ein Lauf dauert bis zu
   25 Sekunden; solange steht der Fortschritt im Formular.

Der Bericht erscheint als Liste im Formular und zusätzlich als HTML in der
Variablen **Letzter Bericht**, die sich in jede Visualisierung einbinden lässt.

## So lesen Sie den Bericht

Jeder Befund hat eine Ampel:

| Zeichen | Bedeutung |
|---|---|
| ❌ | **Blocker** — so wird keine Kopplung gelingen und kein Gerät zuverlässig laufen. Steht immer ganz oben. |
| ⚠️ | **Hinweis** — funktioniert gerade, wird aber Ärger machen (etwa nach dem nächsten Neustart), oder es fehlt ein Beleg. |
| ✅ | **In Ordnung** — mit dem Detail, das geprüft wurde, damit Sie es nachvollziehen können. |

Unter jedem Befund steht, was er bedeutet, und — wenn etwas zu tun ist — die
Empfehlung. Befehle, die Administratorrechte brauchen (etwa das Setzen einer
Route), führt das Modul **nie selbst aus**; sie stehen gesammelt im Feld
**Auszuführende Befehle** zum Kopieren. Das ist Absicht: Ein Eingriff in die
Routingtabelle ist eine bewusste Entscheidung.

Ein Lauf ohne Blocker heißt nicht, dass alles perfekt ist — lesen Sie die
Hinweise. Ein Lauf mit Blocker heißt nicht, dass alles kaputt ist: Erst den
Blocker beheben, dann erneut prüfen, oft erledigen sich die Hinweise mit.

## Dauerbetrieb: Wächter und Benachrichtigung

Im Formular steht ein **Prüfintervall in Minuten** (Vorgabe 60, 0 = aus). Mit
eingeschaltetem Wächter wiederholt sich die Prüfung im Hintergrund und schreibt
das Ergebnis in Statusvariablen. Angepingt wird dabei nicht, schlafende
Batteriegeräte bleiben in Ruhe.

> Instanzen, die vor Version 0.4 angelegt wurden, zeigen hier 0 — der Wächter
> ist dann aus, bis Sie einen Wert eintragen.

| Variable | Bedeutung |
|---|---|
| Matter-Netz OK | falsch, sobald ein Befund als Blocker gilt |
| Gekoppelte Geräte / Geräte, die sich annoncieren | Soll- und Ist-Zahl |
| Thread Border Router | Anzahl der gefundenen Border Router |
| Letzte Prüfung | Zeitpunkt des letzten Laufs |
| Letzte Änderungen | Klartext dessen, was sich gegenüber dem Vorlauf geändert hat — **wird nur bei einer echten Änderung beschrieben** |
| Letzter Bericht | vollständiger Bericht als HTML |

**Benachrichtigung in drei Schritten:** Ein Skript anlegen, darunter ein Ereignis
**„Bei Variablenaktualisierung"** auf die Variable **Letzte Änderungen** der
Diagnose-Instanz, und im Skript die Änderung weiterreichen — so, wie Sie
Meldungen sonst verschicken (Push, E-Mail, Telegram …):

```php
<?php
// 12345 durch die ID der Matter-Diagnose-Instanz ersetzen
$ok        = GetValueBoolean(IPS_GetObjectIDByIdent('Healthy', 12345));
$aenderung = GetValueString(IPS_GetObjectIDByIdent('Changes', 12345));
$text      = ($ok ? 'Matter-Netz in Ordnung. ' : 'Matter-Netz gestört! ') . $aenderung;
// hier die eigene Benachrichtigung einsetzen, z. B.
IPS_LogMessage('Matter Diagnose', $text);
```

Das Ereignis feuert genau dann, wenn ein Gerät verschwindet oder zurückkommt, ein
Border Router wegfällt oder ein Befund neu auftritt beziehungsweise sich erledigt
— keine Stundenmeldungen. Der erste Lauf meldet nichts, er legt nur den
Vergleichsstand an; das gilt auch für den ersten Lauf nach dem Update auf
0.4 build 31, weil sich dessen Format geändert hat. Fehlt beim Lauf ein bekanntes
Gerät, fragt das Modul einmal nach, bevor es urteilt; ein einzelnes verlorenes
Paket löst keinen Fehlalarm aus.
Fällt die Gerätesuche einmal ganz aus, meldet es nur diesen Ausfall — nicht jedes
Gerät als verschwunden und jeden Befund als erledigt. Ob gerade ein
Kopplungsfenster offen ist, löst keine Meldung aus.

## Die Befunde im Überblick

**Ist mein Symcon-Rechner richtig eingerichtet?**
<!-- findings: no_ipv6 no_ipv6_no_thread ipv6_ok mdns_silent mdns_ok -->
- IPv6 vorhanden oder nicht (VPN-Adapter wie Tailscale oder WireGuard zählen
  nicht — Matter braucht IPv6 im Heimnetz). Fehlt IPv6, hängt der Schweregrad
  daran, ob Thread im Spiel ist: Mit Border Router oder Thread-Geräten ist es
  ein Blocker, ohne beides nur ein Hinweis — Matter über LAN oder WLAN läuft
  auch ohne IPv6 (ab 0.5 build 52).
- Kommt Multicast an? Antwortet kein einziger Matter-Dienst, prüft das Modul
  mit einer allgemeinen Anfrage, ob das Netz überhaupt Multicast durchlässt
  (typischer Fall: Docker ohne `--network host`). Antworten des eigenen
  Rechners zählen dabei nicht.

**Was ist im Netz zu sehen?**
<!-- findings: no_border_router border_router_found operational_found commissionable_found no_commissionable no_commissionable_closed_only -->
- Thread Border Router — die Geräte, die das Thread-Funknetz mit dem Heimnetz
  verbinden (Apple TV/HomePod, DIRIGERA, Google Nest, Home Assistant mit
  OpenThread …). Ohne Border Router kein Matter over Thread.
- Matter-Geräte, die sich melden, und ob gerade eines **koppelbereit** ist.
  Geräte, die zwar sichtbar sind, deren Kopplungsfenster aber geschlossen ist,
  werden eigens genannt — wer die Kopplungstaste gedrückt hat und das liest,
  weiß: Das Gerät lebt, nur das Fenster ging nicht auf (meist gehört es schon
  zu einem anderen System).

**Welche Geräte gibt es überhaupt?** — die Geräteliste (ab 0.5)
- Unter den Befunden steht eine Zeile je Gerät: Name (bei eigenen Geräten der
  Symcon-Name mit Id, sonst der Hostname), Anbindung (Thread oder LAN/WLAN),
  Betrieb (Batterie, Netz oder unbekannt), je System eine Spalte mit Häkchen —
  „Symcon" ist diese Installation, „A", „B" … sind die anderen Systeme im Netz,
  deren Kennung und Gerätezahl die Zeile darunter nennt —, der Border Router, der
  es annonciert (beschriftet wie im Befund „Thread Border Router gefunden"; ein
  LAN-/WLAN-Gerät meldet sich selbst und hat keinen) und seine Adresse.
- Fremde Systeme haben aus der Annonce keinen Namen; welches davon Apple Home oder
  DIRIGERA ist, erkennt man an der Besetzung der Spalte. Einmal erkannt, lässt sich
  das System in der Konfiguration unter „Namen für andere Systeme" benennen — die
  Spalte heißt dann „Apple Home (A)" statt „A" (ab 0.5 build 41; seit build 49
  schreiben Spalte und Legende es gleich, und die Auswahlliste nennt nur Buchstabe,
  Kennung und Gerätezahl, weil der Name daneben steht). Ein Gerät ohne Häkchen
  bei „Symcon", das Symcon eigentlich kennt, meldet sich nur für andere Systeme —
  genau der Fall, in dem Symcon es nach einem Neustart nicht wiederfindet.
- Die Spalte „Hersteller" (ab 0.5 build 41) füllt sich aus drei Quellen: bei eigenen
  Geräten aus Symcon, bei LAN-/WLAN-Geräten aus anderen Diensten desselben Geräts
  (Shelly, Philips Hue, Google Cast, HomeKit, ESPHome nennen Hersteller und Modell)
  oder aus der MAC-Adresse (Herstellerkennung, z. B. „Espressif" für einen ESP-Chip)
  — entweder aus dem Hostnamen oder, wenn der zufällig gewählt ist, aus der
  IPv6-Adresse des Geräts (ab 0.5 build 50).
- Fremde Geräte im LAN tragen zusätzlich den Namen, unter dem sie im Router
  stehen (ab 0.5 build 51): Aus „3D59C51D251F" wird „EchoDot-Kueche", sofern sich
  das Gerät bei der Adressvergabe mit Namen gemeldet hat. Thread-Geräte haben
  keinen solchen Eintrag. Fremde Thread-Geräte bleiben Nummern — ihre Kennung ist zufällig,
  und die Matter-Annonce sagt nichts über das Gerät.
- Ein Hub, der Geräte anderer Funkstandards nach Matter übersetzt (etwa der Aqara
  Hub M3 mit seinen ZigBee-Geräten), meldet jedes davon unter eigenem Namen, aber
  mit seiner eigenen Adresse. Solche Geräte tragen den Zusatz „(über Aqara Hub M3)"
  und erben dessen Hersteller (ab 0.5 build 48); sie bleiben eine eigene Zeile, denn
  für Matter sind es eigene Geräte. Die Richtung wird nur angegeben, wenn sie belegt
  ist — sonst steht dort nichts.
- Das ist kein Befund, sondern ein Inventar: Es zeigt, was im Netz wirklich zu
  sehen ist — auch die Geräte anderer Systeme — und beantwortet Fragen wie „an
  welchem Border Router hängt das?" oder „läuft das auf Batterie?" mit einem Blick.

**Mein Thread-Netz hat öffentliche Adressen — wird es erkannt?**
- Ja (ab 0.5 build 45). Delegiert Ihr Router ein öffentliches IPv6-Präfix, nimmt
  sich mancher Border Router (etwa der Aqara Hub) daraus einen Adressbereich für das
  Thread-Netz. Das Modul erkennt ihn, wenn der Border Router ihn in seiner Ansage
  nennt oder die Thread-Geräte stellvertretend annonciert; ein Adressbereich ohne
  solchen Beleg gilt weiterhin nicht als Thread-Netz.

**Kommen meine gekoppelten Geräte durch?**
<!-- findings: no_matter_controller no_own_devices fabric_unknown own_devices_visible own_devices_missing own_devices_missing_battery own_devices_silent_for_symcon own_devices_unsubscribed own_devices_ambiguous device_fabrics_full -->
- Meldet sich ein Gerät zwar im Netz, aber nur für andere Systeme (Apple Home,
  Home Assistant) und nicht für Symcon, sagt der Bericht genau das (ab 0.5 build 43):
  Das Gerät lebt, nur die Kopplung mit Symcon hakt. Was zu tun ist, steht dabei —
  im Matter Konfigurator unter „Verbundene Systeme" nachsehen, ob Symcon noch
  eingetragen ist, sonst neu koppeln. Erkannt wird das über den Netzwerknamen, den
  sich das Modul beim letzten sichtbaren Lauf gemerkt hat; ein Gerät, das nie
  sichtbar war, kann so nicht zugeordnet werden.
- Jedes in Symcon gekoppelte Gerät wird im Netz gesucht. Meldet sich eines nicht,
  nennt der Befund den Verbindungszustand aus Symcons Sicht: Steht der auf „OK",
  kommen weiter Werte herein — ein Gerät kann seine Ansage einstellen, ohne eine
  bestehende Verbindung zu verlieren. Eilig ist es deshalb nicht, folgenlos aber
  auch nicht: Die Ansage ist das, womit Symcon ein Gerät wiederfindet — nach dem
  nächsten Neustart von Symcon oder mit einer neuen Geräteadresse kann der
  Verbindungsaufbau scheitern. Er muss es nicht: Im Feldtest lieferte ein stummes
  Gerät auch nach einem Neustart weiter Werte. Ist auch die Verbindung weg, wird
  daraus ein Blocker.
- Batteriegeräte sind in der Liste mit 🔋 gekennzeichnet: Sie dürfen die meiste
  Zeit still sein, ein Gerät am Stromnetz sollte sich melden. Woran ein Gerät
  hängt, erkennt das Modul an den Batteriewerten, die Symcon dafür führt — und
  sonst an der letzten Ansage des Geräts.
- Gibt es noch keinen Matter-Controller oder kein gekoppeltes Gerät, sagt der
  Bericht das, statt zu schweigen; konnte das Modul die Kennung des eigenen
  Systems nicht lesen oder war die Zuordnung nicht eindeutig, steht auch das
  dabei.
- Ist die Tabelle der verbundenen Systeme eines Geräts voll (bei den meisten
  Geräten fünf), scheitert jede weitere Kopplung ohne erkennbaren Grund — das
  Modul warnt vorher.

**Stimmt der Weg ins Thread-Funknetz?**
<!-- findings: thread_prefix_reachable thread_prefix_unreachable thread_prefix_no_reply thread_prefix_route_ok thread_prefix_untested thread_route_learned thread_route_learned_with_persistent thread_route_not_persistent thread_route_stale thread_route_gateway_unknown -->
- Ist das Thread-Netz erreichbar? Ein kurzer Ping auf Geräteadressen, mit
  Rücksicht auf schlafende Geräte: Ein Fehlversuch ist „nicht eindeutig", kein
  Ausfall; im Wächterbetrieb entfällt der Ping ganz, dann zählt nur die Route.
- Woher hat der Rechner die Route? Unter Windows meldet das Modul, ob sie
  **automatisch gelernt** wird (dann ist nichts zu tun) oder nur von Hand gesetzt
  und nach dem nächsten Neustart weg wäre — samt Befehl, der sie dauerhaft macht.
  Unter Linux erkennt es gelernte Routen ebenfalls und lässt sie unbeanstandet.
- Veraltete Routen nach einem Wechsel des Adressbereichs und Routen auf Border
  Router, die es nicht mehr gibt, werden mit Löschbefehl genannt. „Veraltet"
  urteilt das Modul nur, wenn feststeht, welche Adressbereiche genutzt werden —
  meldet sich in einem Lauf nicht jedes Gerät vollständig, bleibt die Route
  unbewertet, statt zu Unrecht gelöscht zu werden.

**Ist das Thread-Funknetz gesund?**
<!-- findings: thread_network_ok thread_single_border_router thread_networks_split thread_partitions thread_dataset_mismatch -->
- Nur ein Border Router (fällt er aus, ist das ganze Netz weg), zwei getrennte
  Thread-Netze (typisch, wenn Apple und Google jeweils ein eigenes aufgemacht
  haben), ein in Teile zerfallenes Netz oder Border Router mit
  unterschiedlichen Einstellungen.

## Grenzen

- Die Diagnose ist **rein lesend**. Empfohlene Befehle führt sie nicht aus.
- In Docker ohne `--network host` kommt kein Multicast an — das meldet die
  Diagnose als eigenen Befund, beheben kann sie es nicht.
- Über die **Funkqualität** im Thread-Netz sagt sie nichts; dafür wäre die
  Schnittstelle eines eigenen Border Routers nötig, die Apple und Google nicht
  anbieten. Ein Gerät gilt als sichtbar, sobald es sich meldet.
- Der Abgleich der gekoppelten Geräte liest die Konfigurationsformulare der
  Matter-Kernmodule aus. Ändert Symcon deren Aufbau, fällt das Modul auf die
  Zuordnung über die Geräteinstanzen zurück und sagt, dass es die Fabric-ID
  nicht lesen konnte.
- Windows-Ausgaben werden in Deutsch und Englisch verstanden; bei anderen
  Sprachen liest das Modul die Routentabelle positionsweise, was in der Regel
  klappt, aber nicht für jede Sprache geprüft ist.

## Begriffe

| Begriff | Bedeutung |
|---|---|
| **Fabric** | Ein Matter-System mit seinen Geräten — Symcon ist eines, die Apple-Home-Welt ein anderes. Ein Gerät kann zu mehreren gehören, aber nur zu einer begrenzten Zahl. |
| **Kopplungsfenster** | Der Zeitraum (meist 15 Minuten), in dem ein Gerät neue Systeme annimmt. Wird per Taste oder App geöffnet. |
| **Border Router** | Das Gerät, das Thread-Funk mit dem Heimnetz verbindet. Thread-Geräte sind ohne ihn unerreichbar. |
| **Abonnement** | Symcons stehende Verbindung zu einem Gerät, über die Werte hereinkommen. Geht sie verloren, bleibt die Instanz trotzdem „aktiv". |
| **Route** | Der Eintrag, der dem Rechner sagt, dass die Adressen des Thread-Netzes über den Border Router zu erreichen sind. |

---

## Anhang für Neugierige

### Wie die Prüfung arbeitet

Das Modul fragt per mDNS/DNS-SD nach drei Diensten: `_meshcop._udp` (Border
Router), `_matter._tcp` (eingebundene Geräte) und `_matterc._udp`
(koppelbereite Geräte). Fehlende Einzelheiten — Hostnamen, IPv6-Adressen, die
Netzangaben der Border Router, die TXT-Angaben zum Kopplungsmodus — fragt es in bis zu drei weiteren Runden nach,
Border Router zuerst; einmal gestellte Fragen wiederholt es nicht. Das
Gesamtbudget eines Laufs liegt bei 24 Sekunden, der Erreichbarkeitstest bekommt
den Rest — mit so vielen Ping-Versuchen, wie ohne Antwort noch hineinpassen. Die
Kopplungsbereitschaft steht im TXT-Schlüssel `CM` (0 = Fenster
zu, 1/2 = offen) — manche Geräte annoncieren nach dem Boot minutenlang mit
`CM=0`, das ist kein Kopplungsfenster.

Als Thread-Gerät gilt nur, was keine IPv4-Adresse hat: Thread-Geräte erreichen das
Heimnetz allein über IPv6 und den Border Router. Ein Gerät mit IPv4 — eine Shelly,
eine Hue Bridge, ein Gerät aus einem gespiegelten Nachbarsegment — hängt im LAN,
und sein Adressbereich ist kein Thread-Netz, auch wenn er wie eines aussieht.
Zusätzlich zur Multicast-Anfrage fragt das Modul jeden Border Router direkt: Ein
Border Router, der die Einträge seiner Thread-Geräte stellvertretend annonciert,
darf auf die Multicast-Anfrage per Multicast antworten, was am Port des Moduls
nicht ankommt; auf eine an ihn gerichtete Anfrage antwortet er direkt.

Die eigene Fabric erkennt das Modul an den Konfigurationsformularen der
Matter-Kernmodule (Fabric-ID des Controllers, Node-IDs der Geräte) und sucht
die passenden `<FabricID>-<NodeID>`-Annoncen im Netz. Bei mehreren Controllern
gleicht es jeden mit seiner eigenen Fabric und seinen eigenen Konfiguratoren ab.

### Thread-Details

Die Border Router verraten in ihren `_meshcop`-TXT-Records mehr, als für die
Kopplung nötig ist: `xp` (Extended PAN ID) und `nn` (Netzname) identifizieren
das Thread-Netz, `pt` die Partition, `at` den aktiven Datensatz, `sb` die
Backbone-Router-Rolle, `tv` die Thread-Version. Daraus ergeben sich die Befunde
zur Netzgesundheit. Hersteller kodieren die Partition-ID in unterschiedlicher
Byte-Reihenfolge; der Vergleich toleriert das.

### Windows und die Thread-Route

Der Border Router verteilt die Route ins Thread-Netz per Router Advertisement
(Route Information Option). Windows übernimmt sie, sofern der Heimrouter fremde
Präfixe zulässt (FRITZ!Box: „Auch IPv6-Präfixe zulassen, die andere IPv6-Router
im Heimnetz bekanntgeben", wirksam nach einem Neustart der Box). In der
Routentabelle sehen solche Routen wie von Hand gesetzte aus; nur ihre
Gültigkeitsdauer (`netsh interface ipv6 show route level=verbose`) verrät sie:
endlich und laufend erneuert bei gelernten, „Unendlich" bei manuellen. Das Modul
liest genau das und meldet gelernte Routen als in Ordnung — ein zusätzlich
vorhandener dauerhafter Eintrag gilt als Reserve für die Zeit nach einem
Neustart. Lernt Windows die Route nicht, hilft der vorgeschlagene Befehl mit
`store=persistent`; ohne den Zusatz wäre sie nach dem nächsten Neustart weg.
Unter Linux erneuert das Router Advertisement die Route selbst; das Modul erkennt
solche Routen an `proto ra` bzw. auf der SymBox an `expires` und bewertet sie
nicht als veraltet.

### Nicht geprüft, und warum

Die eigene Controller-Annonce und die Belegung von UDP-Port 5353 (nach
Rücksprache mit Symcon): Symcon ist als Matter-Controller reiner Konsument und
annonciert sich nicht; den mDNS-Port hält Bonjour (Windows) beziehungsweise
Avahi (Linux), ohne die Symcon gar nicht startet. Ebenso nicht: die Zahl fremder
Matter-Systeme im Netz — daraus folgt keine Handlung.

### Bei Rückfragen: das Debug-Fenster

Meldet der Bericht etwas, das nicht zu Ihrer Anlage passt, öffnen Sie in der
Konsole das Debug-Fenster der Instanz und starten die Diagnose erneut. Dort steht
alles, was das Modul erhoben hat: welche Geräte und Border Router mit welchen
Adressen geantwortet haben, was nach den Nachfragen offen blieb, die
Routentabelle samt Bewertung, die Ping-Ergebnisse und die in Symcon gekoppelten
Geräte mit Abonnement und Batterieangabe. Dieser Auszug ist das, was bei einer
Rückfrage im Forum weiterhilft.

### Tests

```
php tests/run_tests.php
php tests/check_locale.php
```

Die Unit-Tests laufen ohne Symcon: mDNS-Parser, Routenbewertung und
Befund-Logik werden mit echten Paketmitschnitten, echten Systemausgaben und
Szenario-Fixtures geprüft (`tests/fixtures/`). Ein Test hält außerdem diese
README und die englische Fassung gegen den Befundkatalog — jede Befundgruppe
trägt dafür einen `<!-- findings: … -->`-Kommentar. `tests/capture_fixtures.php`
sammelt bei Bedarf frische Mitschnitte aus dem eigenen LAN ein.
