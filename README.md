# Matter Diagnose

[![Checks](https://github.com/bumaas/MatterDiagnose/actions/workflows/check.yml/badge.svg)](https://github.com/bumaas/MatterDiagnose/actions/workflows/check.yml)

Symcon-Modul, das die häufigsten Stolpersteine bei der Einbindung von
Matter-Geräten prüft — insbesondere bei **Matter over Thread**, wo eine
fehlgeschlagene Kopplung („Fehlgeschlagen" ohne weitere Angabe) viele mögliche
Ursachen haben kann. Ein Klick auf **Diagnose starten** liefert eine
Ampel-Liste mit Klartext-Befunden und konkreten Handlungsempfehlungen.

## Was geprüft wird

| Prüfung | typischer Befund |
|---|---|
| IPv6 am Symcon-Rechner (VPN-Adapter wie Tailscale/WireGuard zählen nicht) | „Ihr System hat keine IPv6-Adresse" |
| mDNS-Grundfunktion (allgemeine Probe `_services._dns-sd._udp`, nur wenn kein Matter-Dienst antwortet) | „mDNS funktioniert, aber kein Matter-Dienst annonciert sich" |
| Thread Border Router im Netz (mDNS `_meshcop._udp`) | „Kein Thread Border Router gefunden" |
| Koppelbereite und eingebundene Matter-Geräte (`_matterc._udp`, `_matter._tcp`) | „1 Gerät koppelbereit" |
| Route ins Thread-Netz (Ping auf die Geräteadressen) | „Thread-Netzwerk fd… ist NICHT erreichbar" — samt fertigem `netsh`-/`ip route`-Befehl |
| Gekoppelte Geräte gegen die Annoncen im Netz (Fabric-ID des Controllers, Node-IDs) | „1 gekoppeltes Gerät annonciert sich nicht" |
| Abonnement-Status vermisster Geräte | „Gerät verschwunden und Abonnement meldet ein Problem" |
| Fremde Matter-Controller im Netz (andere Fabrics) | „12 Matter-Annoncen gehören zu anderen Controllern" |
| Thread-Netz-Gesundheit aus den `_meshcop`-TXT-Records (Extended PAN ID, Partition, aktiver Datensatz) | „Nur ein Thread Border Router", „2 getrennte Thread-Netze", „Thread-Netz … ist in 2 Partitionen zerfallen" |
| Routen-Persistenz unter Windows (`store=persistent`) | „Route ins Thread-Netz fd… ist nicht dauerhaft" — samt Befehl, der sie dauerhaft macht |
| Präfixdrift und getauschte Border Router | „Veraltete Route nach fd…", „Route … zeigt auf ein unbekanntes Gateway" — samt Löschbefehl |

Hintergrund: Gerade unter Windows übernimmt das System die IPv6-Route zum
Thread-Netz des Border Routers nicht automatisch — die Kopplung scheitert
dann in der letzten Phase, obwohl Handy und Sensor alles richtig machen.

## Laufender Betrieb

Neben der Inbetriebnahme überwacht das Modul den Dauerbetrieb. Im Formular
lässt sich ein **Prüfintervall in Minuten** einstellen (Vorgabe 60, 0 = aus);
die Prüfung wiederholt sich dann im Hintergrund. Angepingt wird dabei nicht — schlafende
Batteriegeräte bleiben in Ruhe, ausgewertet wird nur, ob die Route steht.

Statusvariablen:

| Variable | Bedeutung |
|---|---|
| Matter-Netz OK | falsch, sobald ein Befund als Blocker gilt |
| Gekoppelte Geräte / Geräte, die sich annoncieren | Soll- und Ist-Zahl der Geräte |
| Thread Border Router | Anzahl der gefundenen Border Router |
| Letzte Prüfung | Zeitpunkt des letzten Laufs |
| Letzte Änderungen | Klartext der Änderungen — **nur bei echten Änderungen beschrieben** |
| Letzter Bericht | vollständiger Bericht als HTML |

Für eine Benachrichtigung ein Ereignis **„bei Aktualisierung"** auf „Letzte
Änderungen" legen: Es feuert genau dann, wenn ein Gerät verschwindet oder
zurückkommt, ein Border Router wegfällt oder ein Befund neu auftritt bzw. sich
erledigt. Der erste Lauf meldet nichts, er legt nur den Vergleichsstand an.

Fehlt beim Lauf ein bekanntes Gerät oder ein zuvor gesehener Border Router,
fragt das Modul einmal per mDNS nach, bevor es urteilt — ein einzelnes
verlorenes Multicast-Paket löst so keinen Fehlalarm aus.

## Thread-Netz und Routen

Die Border Router verraten in ihren `_meshcop`-TXT-Records mehr, als für die
Kopplung nötig ist: `xp` (Extended PAN ID) und `nn` (Netzname) identifizieren
das Thread-Netz, `pt` die Partition, `at` den aktiven Datensatz, `sb` die
Backbone-Router-Rolle, `tv` die Thread-Version. Daraus ergeben sich Befunde wie
„nur ein Border Router" (Einzelrisiko), „zwei getrennte Thread-Netze" (typisch,
wenn Apple TV und Google Hub jeweils ein eigenes Netz aufgemacht haben) oder
„Netz in Partitionen zerfallen". Hersteller kodieren die Partition-ID in
unterschiedlicher Byte-Reihenfolge; der Vergleich toleriert das.

Unter Windows hält das System aktive und persistente Routen getrennt. Eine
Thread-Route, die nur aktiv gesetzt wurde, ist nach dem nächsten Neustart weg —
die Diagnose meldet das als Hinweis und nennt den Befehl mit `store=persistent`.
Ebenso gemeldet werden Routen zu Präfixen, die kein Border Router mehr
annonciert (Präfixdrift nach Reset oder Tausch), und Routen auf Gateways, die
kein aktueller Border Router mehr ist. Unter Linux erneuert das
Router-Advertisement die Route selbst; dort entfällt die Persistenzprüfung.

## Installation

Über die Modulverwaltung: `https://github.com/bumaas/MatterDiagnose.git`

Danach eine Instanz **Matter Diagnose** anlegen (Kern-Instanzen → Instanz
hinzufügen) und im Konfigurationsformular **Diagnose starten** klicken.
Der letzte Bericht steht zusätzlich in der Variablen „Letzter Bericht"
(HTML) für die Visualisierung.

## Grenzen

- Die Diagnose ist **rein lesend**; empfohlene Befehle (z. B. das Setzen einer
  Route) führt sie bewusst nicht selbst aus — dafür sind Administratorrechte
  und eine bewusste Entscheidung nötig.
- In Docker ohne `--network host` kommt kein Multicast an — das meldet die
  Diagnose als eigenen Befund.
- Schlafende Thread-Geräte (Batteriesensoren) antworten träge; ein einzelner
  Fehlversuch beim Erreichbarkeitstest wird deshalb als „nicht eindeutig"
  gewertet, nicht als Ausfall.
- **Nicht geprüft** werden die eigene Controller-Annonce und die Belegung von
  UDP-Port 5353 (Stand 0.2, nach Rücksprache mit Symcon): Symcon ist als
  Matter-Controller reiner Konsument und annonciert sich nicht; erst die
  künftige Matter Bridge wird das tun. Den mDNS-Port hält nicht Symcon selbst,
  sondern Bonjour (Windows) bzw. Avahi (Linux) — ohne die startet Symcon gar
  nicht, ein „Störer"-Befund dazu wäre irreführend.
- Der Abgleich der gekoppelten Geräte liest die Konfigurationsformulare der
  Matter-Kernmodule aus. Deren Aufbau ist nicht dokumentiert; ändert er sich,
  fällt das Modul auf die Zuordnung über die Geräteinstanzen zurück und meldet,
  dass die Fabric-ID nicht lesbar war.
- Ein Gerät gilt als sichtbar, sobald es sich annonciert. Über die Funkqualität
  im Thread-Netz sagt das nichts — dafür wäre die Schnittstelle eines eigenen
  Border Routers nötig, die Apple und Google nicht anbieten.

## Tests

```
php tests/run_tests.php
php tests/check_locale.php
```

Die Unit-Tests laufen ohne Symcon: mDNS-Parser und Befund-Logik werden mit
echten Paketmitschnitten bzw. Szenario-Fixtures geprüft
(`tests/fixtures/`). `tests/capture_fixtures.php` sammelt bei Bedarf frische
Mitschnitte aus dem eigenen LAN ein.
