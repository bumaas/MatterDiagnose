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
| Annonciert sich der eigene Matter-Controller? | „Ihr Symcon annonciert sich nicht als Matter-Controller" |
| mDNS-Portkonkurrenz (nur Windows) | „Bonjour/Chrome/… nutzen den mDNS-Port mit" |

Hintergrund: Gerade unter Windows übernimmt das System die IPv6-Route zum
Thread-Netz des Border Routers nicht automatisch — die Kopplung scheitert
dann in der letzten Phase, obwohl Handy und Sensor alles richtig machen.

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

## Tests

```
php tests/run_tests.php
php tests/check_locale.php
```

Die Unit-Tests laufen ohne Symcon: mDNS-Parser und Befund-Logik werden mit
echten Paketmitschnitten bzw. Szenario-Fixtures geprüft
(`tests/fixtures/`). `tests/capture_fixtures.php` sammelt bei Bedarf frische
Mitschnitte aus dem eigenen LAN ein.
