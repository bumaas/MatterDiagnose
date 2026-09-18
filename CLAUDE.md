# MatterDiagnose — Projekt-Hinweise

Symcon-Modul, das die häufigsten Stolpersteine bei der Einbindung von Matter-Geräten prüft
(`IPSModuleStrict`, `declare(strict_types=1)`). Entstanden 01.09.2026 aus einer konkreten
Fehlersuche: Ein IKEA-Sensor ließ sich am nuc nicht koppeln, weil Windows die IPv6-Route ins
Thread-Netz nicht übernahm — die Diagnose sollte solche Ketten künftig in einem Klick zeigen.

## Struktur

- `MatterDiagnose/module.php` — nur Kleber: erheben, an die Bibliotheken übergeben, Befunde
  in Texte übersetzen, Formular und Statusvariablen füllen. **Keine Logik**, die man testen
  wollte, gehört hierher.
- `MatterDiagnose/libs/` — der testbare Kern, jede Klasse ohne IPS-Aufrufe:
  - `MdnsCodec` — mDNS-Nachrichten kodieren/dekodieren (RFC 6762/1035, Teilmenge)
  - `MdnsBrowser` — der einzige Socket-Anteil; verschickt Queries, sammelt Antworten
  - `MatterDiscovery` — verdichtet die Antworten zum Lagebild (Border Router, Geräte)
  - `ThreadNetwork` — wertet die `_meshcop`-TXT-Records aus (Netzgesundheit)
  - `RouteTable`, `OsAdapter` — Routingtabelle lesen und bewerten, Systemkommandos
  - `SymconInventory` — was Symcon über seine Matter-Geräte weiß
  - `DiagnosisEngine` — die Bewertung: aus Erhebungsdaten werden Befunde
  - `ChangeTracker` — Vergleich zweier Läufe (macht aus der Momentaufnahme eine Überwachung)
  - `RunBudget` — Zeitbudget des Laufs mit Reserve für den Erreichbarkeitstest (ab build 46)
  - `DeviceInventory` — Geräteliste: ein Eintrag je physischem Gerät (Host), mit Anbindung,
    Betriebsart, einer Spalte je System (`fabricColumns`: Symcon zuerst, fremde als A, B, …
    nach Gerätezahl), Annonce-Quelle (ab 0.5, Anregung Burkhard 18.09.2026). Weil die
    Spaltenzahl erst der Lauf kennt, baut `GetConfigurationForm()` das Formular aus
    `form.json` plus dem Attribut `Devices` (Spalten und Zeilen des letzten Laufs); die
    Liste überlebt so auch das Schließen des Formulars. Fremde Systeme benennt der Anwender
    über die Property `FabricNames` (Kennung → Name); die Auswahlliste dazu füllt
    `GetConfigurationForm()` aus denselben Spalten.
  - `DeviceIdentity` — Hersteller/Modell hinter einer Nummer (build 41): andere Dienste
    desselben Geräts (`_shelly`, `_hue`, `_googlecast`, `_hap`, `_esphomelib`; eigene kurze
    mDNS-Runde `BUDGET_IDENTITY`, Zuordnung über Adresse, Host oder MAC im Hostnamen) und
    die OUI-Tabelle `libs/oui.php` (Auszug der IEEE-Liste für Smart-Home-Hersteller, 4.800
    Präfixe, neu erzeugen mit `tests/gen_oui.php <oui.csv>`). Thread-Kennungen sind zufällig
    — dort gibt es nichts zu holen.
- `README.md` / `README.en.md` — Anwenderdoku, Aufbau nach Punkt 10 der Referenz-Checkliste
- `docs/bericht.png` — Beispielbericht für die README (anonymisiert)

## Das Grundprinzip: Befunde statt Messwerte

Ein Befund ist `{severity, id, params}` — Schweregrad `ok | notice | blocker`, eine stabile
ID, Parameter. Die Anzeigetexte stehen als Katalog in `module.php` (`$catalog`), die
Übersetzungen in `locale.json`.

**Jeder Befund muss zu einer Handlung führen und in Anwendersprache stehen.** Was keine
Handlung nach sich zieht, wird nicht gemeldet — daran sind schon Befunde gescheitert und
wieder entfernt worden (siehe unten). Wo ein Befehl nötig ist, steht er fertig zum Kopieren
im Feld „Auszuführende Befehle"; **ausgeführt wird nie etwas**, die Diagnose ist rein lesend.

## Prüfen

```bash
C:/php/php tests/run_tests.php        # alle Unit-Tests (Stand 18.09.2026: 989 Prüfungen)
C:/php/php tests/check_locale.php     # Übersetzungs-Vollständigkeit
php php-cs-fixer.phar fix --config=.style/.php-cs-fixer.php --dry-run --diff --allow-risky=yes
```

`tests/run_tests.php` lädt jede `*Test.php` im Verzeichnis; ein eigener Testrunner, kein
PHPUnit. Drei Tests halten die Struktur zusammen und sind beim Ändern zu beachten:

- **`FindingCatalogTest`** — die Befund-IDs der `DiagnosisEngine` und die Katalogeinträge in
  `module.php` müssen deckungsgleich sein. Ein neuer Befund ohne Text fiele sonst erst im
  Formular auf (als nackte ID), ein entfernter bliebe als Leiche liegen.
- **`ReadmeCoverageTest`** — beide READMEs müssen jeden Befund abdecken. Dafür trägt jede
  Befundgruppe einen `<!-- findings: id1 id2 … -->`-Kommentar. Doku-Aktualität ist damit
  Teil der CI, nicht eine Frage des Merkens.
- **`FormActionsTest`** — Formular-Buttons müssen `IPS_RequestAction($id, '<Ident>', …)`
  rufen. Die globale `RequestAction()` nimmt (VariablenID, Wert) und stürzt mit „Wrong
  parameter count" ab; ein RPC-Test der Modulmethode deckt Formular-Klicks **nicht** ab.

Bibliothek auf der Produktivanlage per `MC_ReloadModule` mit Ordnername `MatterDiagnose`
neu einlesen (siehe globale `CLAUDE.md`).

## Fixtures: echte Mitschnitte, keine erfundenen

Unter `tests/fixtures/` liegen **echte** Paketmitschnitte (`mdns/*.bin`), echte
Systemausgaben (`os/*.txt`) und Szenario-JSONs. `tests/capture_fixtures.php` sammelt frische
Mitschnitte aus dem LAN ein.

Das ist keine Förmlichkeit: Eine erfundene Routen-Fixture (`Manuell 256` statt `Standard`)
hielt hier einen Parserfehler bei grünem Test verborgen — siehe globale `CLAUDE.md`,
Abschnitt „Arbeitsweise bei Fehlerbehebungen", Absatz „Eine erfundene Fixture …".

## Zurückgezogene Befunde

`DiagnosisEngineTest` führt sie als `$retiredFindings` — **kein Szenario darf sie je wieder
liefern**:

- `own_controller_missing` / `own_controller_ok` und `port5353_competition` — nach Rücksprache
  mit paresy (02.09.2026): Symcon ist als Matter-Controller reiner Konsument und annonciert
  sich nicht; den mDNS-Port hält Bonjour bzw. Avahi, ohne die Symcon gar nicht startet.
- `foreign_fabrics` — die Zahl fremder Matter-Systeme im Netz beantwortet keine Frage des
  Anwenders und führt zu keiner Handlung.

## Stolpersteine, die schon Zeit gekostet haben

- **mDNS-Sockets** (Lehrgeld 01.09.2026, im Kopf von `MdnsBrowser` festgehalten): Ein per
  `stream_socket_client` „verbundener" UDP-Socket verwirft Antworten fremder Absender;
  `stream_socket_recvfrom` ignoriert `stream_set_timeout` (deshalb `stream_select`); und bei
  mehreren Interfaces geht der Multicast sonst über das VPN hinaus — an die LAN-IP binden.
- **Nachfragen brauchen ein Gedächtnis** (build 23): Hosts ohne IPv6 antworten nie auf AAAA.
  Ohne Merken wurden sie jede Runde erneut gefragt und verdrängten die SRV-Nachfragen; von
  29 Instanzen blieben 19 unaufgelöst. `MatterDiscovery::followUpQuestions($survey, $limit,
  $asked)` ordnet nach Bedeutung und wiederholt nichts.
- **mDNS-Namen und TXT-Schlüssel sind case-insensitiv** (build 23) — Gerät und
  Advertising-Proxy schreiben denselben Instanznamen nicht zwingend gleich.
- **`CM=0` ist kein Kopplungsfenster** (build 20): Shelly annonciert `_matterc._udp` nach
  jedem Boot minutenlang mit geschlossenem Fenster. Nur `CM >= 1` zählt.
- **Ein Border Router, der nur seine IPv4 nennt, gilt nicht als aufgelöst** (build 21). Der
  Apple TV antwortet auf die kombinierte Abfrage mit 29 Records, darunter kein AAAA — das
  Modul hielt ihn für fertig aufgelöst und erklärte die gesetzte Route für „führt zu einem
  unbekannten Gerät". **Einer solchen Löschempfehlung wurde gefolgt**; die Route war richtig.
- **Windows-Routen: nur die Lebensdauer verrät die Herkunft** (build 22; Details in der
  globalen `CLAUDE.md`, Abschnitt „Tooling: Windows-IPv6-Routen"). Gelernte Routen sind
  kein Persistenz-Befund.
- **Kein Urteil ohne vollständige Beweislage** (build 24): Fehlt einem Border Router die
  Link-Local, wird „unbekanntes Gateway" gar nicht erst gemeldet. Ein Löschrat ohne Beleg ist
  teurer als ein verpasster Hinweis. Seit build 31 gilt das auch für „veraltet":
  `RouteTable::assess` bekommt `null` statt der Präfixliste, solange
  `MatterDiscovery::prefixEvidenceComplete` nicht belegt, welche Präfixe genutzt werden
  (jeder Border Router nennt sein OMR — Apple tut das nie — oder jedes Gerät ist aufgelöst).
- **Linux: gelernte Routen an `proto ra` oder `expires`** (build 31). BusyBox auf der SymBox
  kennt kein `proto` und schreibt bei RA-Routen nur `expires 0sec` (Mitschnitt Testbox,
  `tests/fixtures/os/route_linux_symbox_busybox.txt`). Die Restlaufzeit ist dort wertlos,
  deshalb entfällt unter Linux der Info-Befund `thread_route_learned`.
- **Windows-Ping zählt „Zielnetz nicht erreichbar" nicht als Antwort** (geprüft 17.09.2026,
  `ping_unreachable_de.txt`) — ein Review-Verdacht, der sich am echten Mitschnitt nicht hielt.
- **ULA ≠ Thread** (build 37, Loerdys Debug-Auszug `t/144417/9`): Sein Router spiegelt die
  mDNS-Annoncen eines zweiten Netzsegments (Shellys mit 192.168.30.x und
  `fdb2:3abb:80f6:2::/64`). Das Modul hielt das /64 für ein Thread-Netz — ULA, nicht
  on-link — und empfahl eine Route über den Aqara-Hub. Thread-Geräte haben nie eine IPv4;
  `MatterDiscovery::threadCandidateAddresses` lässt Geräte mit IPv4 aus. Die Lehre:
  „nicht on-link" beweist nichts, nur der Weg über einen Border Router.
- **Direktabfrage je Border Router** (build 37): `MdnsBrowser::query(…, $target)` schickt die
  `_matter._tcp`-PTR-Anfrage unicast an die IPv4 des Routers; Apple TV und DIRIGERA
  antworten unicast (17.09.2026: 23 bzw. 3 Instanzen). Ein Proxy darf auf Multicast per
  Multicast antworten, was am eigenen Port nie ankommt. Nach der ersten Antwort endet die
  Abfrage nach 0,25 s, sonst kostete jeder Router die volle Sekunde (nuc: 28 s statt 22 s).
- **Aus dem nuc-Debug-Auszug** (build 38, 18.09.2026): (a) Der Ping traf den schlafenden
  KLIPPBOK (0 von 4) und meldete „kein Gerät antwortete" — `MatterDiscovery::pingCandidates`
  ordnet Netzgeräte zuerst, Schlafende zuletzt. (b) MYGGBETT/KLIPPBOK standen als
  „schläft=?", weil ihre Annonce ohne TXT kam — `SymconInventory::instancesWithoutSleepInfo`
  liefert die eigenen Geräte ohne Angabe, das Modul fragt ihr TXT gezielt nach. (c) „37
  Matter-Geräte" waren 37 Ansagen von 13 Geräten in 6 Fabrics — jedes Gerät annonciert sich
  je Fabric; `operational_found` zählt jetzt Hosts, Ansagen und Systeme getrennt.
  (d) `BUDGET_DIRECT` 0,5 s: Aqara-Hubs und HomePod beantworten Direktabfragen gar nicht.
- **SII allein sagt nichts über Batterie** (0.5 build 39): Die erste Geräteliste zeigte die
  GRILLPLATS am Strom als „Batterie", weil ihr TXT SII/SAI trägt. Echte Werte 18.09.2026:
  GRILLPLATS SII 2000, KLIPPBOK 15800, MYGGBETT 17000, Shellys nur `T=0`. Regel in
  `MatterDiscovery::sleepyFromTxt`: `ICD` vorhanden oder SII ≥ 5000 ms → Batterie; TXT ohne
  → Netz; kein TXT → unbekannt.
- **Controller-Datensätze sind keine Geräte** (build 40): Der Befund zählte auf dem nuc „14
  Geräte in 8 Systemen", die Liste zeigte 13 — der 14. war der Controller-Datensatz der
  Testbox (`…-FFFFFFEFFFFFFFFF`, Host `SymBox.local`) mit eigener Fabric. `DeviceInventory`
  ließ reservierte Node-IDs schon aus, `DiagnosisEngine` zählte sie mit. Fremde Systeme
  haben aus der Annonce keinen Namen — nur die Kennung; welche Apple oder DIRIGERA ist,
  verrät die Besetzung der Spalte (Kandidat: Fabric-Liste der eigenen Geräte mit Vendor-ID).
- **Zeitbudget: erst messen, dann zuschreiben** (build 46/47, Loerdys „konnte nicht getestet
  werden" bei jedem Lauf). Die erste Erklärung war geraten und falsch: Aus der 6-Sekunden-Lücke
  zwischen zwei Debug-Zeilen seines Dumps wurde „das Lesen des Matter-Konfigurators dauert bei
  21 Geräten 6 s". **Gemessen** (18.09.2026, `IPS_GetConfigurationForm` dreimal je Instanz):
  **nuc 1,06 s bei 5 Geräten, Testbox 1,02 s bei 7 Geräten** — dreimal derselbe Wert, also eine
  feste Wartezeit im Matter-Konfigurator, die **nicht** mit der Gerätezahl wächst (Controller:
  0 s). Eine C++-Gegenprobe fehlt: In Neustadt (9.0) gibt es keine Matter-Instanz, ob es ein
  Rust-Thema ist, ist damit offen. In der Lücke steckte in Wahrheit die **mDNS-Abgleichsrunde**
  (`missesSomethingKnown` → volle `BUDGET_MDNS` 4 s), die nur läuft, wenn Geräte fehlen — bei
  Loerdy also immer. Sein Budget: 4 (mDNS) + 6 (3 Nachfragen) + 4 (Abgleich) + 1 (Identität) +
  2 (4 Direktabfragen) + 1 (TXT) + 3× Inventar ≈ 21 s von 24 s; für den Ping blieb nichts.
  Abhilfe in dieser Reihenfolge: `RunBudget` mit `BUDGET_PING_RESERVE` (7 s) für jeden optionalen
  Schritt, die Abgleichsrunde wird gekürzt statt gestrichen, und `readInventory()` (teuer, einmal)
  ist von `matchInventory()` (nur Zuordnung, ohne IPS-Aufruf) getrennt — das spart die ~2 s der
  Mehrfachlesungen. **Merke:** Eine Zeitlücke zwischen zwei Debug-Zeilen benennt keine Ursache;
  seit build 47 gibt der Debug die gemessene Dauer je Abschnitt aus.
- **Die Folge einer fehlenden Ansage ist ein Risiko, keine Gewissheit** (build 46): Der Befundtext
  behauptete, nach einem Neustart von Symcon komme die Verbindung nicht wieder zustande. Loerdys
  Test am 18.09.2026 widerlegt das: Nach einem Neustart der ganzen SymBox lieferte seine stumme
  GRILLPLATS weiter Werte und ließ sich schalten. Seitdem heißt es „kann scheitern", mit dem
  Feldtest als Gegenbeispiel im Text. **Eine Folge, die man nicht belegt hat, gehört nicht als
  Tatsache in einen Befund** — die Prämisse „Symcon speichert keine Adressen" (Gedächtnis
  17.09.2026) trägt das Urteil nicht allein.
- **Ein Thread-Präfix muss kein ULA sein** (build 45, Rainers Dump `t/144417/12`): Seine
  FRITZ!Box delegiert `2a02:…:a900::/56`, der Aqara Hub M3 nimmt sich `…:a9ff::/64` als OMR —
  global, kein `fd…`. `DiagnosisEngine::threadPrefixes` und `RouteTable::assess` ließen nur ULA
  zu; das Thread-Netz war unsichtbar (kein Erreichbarkeitstest, keine Routenbewertung, und
  bei fehlender Route hätte das Modul geschwiegen). Globale Präfixe zählen jetzt mit Beleg:
  OMR aus der Border-Router-Annonce oder Geräte ohne IPv4, die ein Border Router stellvertretend
  annonciert (`MatterDiscovery::proxiedPrefixes`). Ohne Beleg bleibt es bei ULA — sonst wäre
  Loerdys gespiegeltes Segment wieder ein „Thread-Netz". Fixture: `route_linux_erpe_gua.txt`.
- **Symcons „(ICD)" schlägt die SII-Schwelle** (build 45): Rainers Aqara Smart Wall Switch am
  Strom annonciert lange Intervalle, Symcon führt ihn als „OK!" ohne ICD.
  `SymconInventory::sleepyFromSubscription` hat für eigene Geräte das letzte Wort; die
  Geräteliste übernimmt Symcons Urteil vor der Annonce.
- **„Meldet sich für andere, nicht für Symcon" braucht ein Gedächtnis** (build 43, Loerdys
  GRILLPLATS): Ohne eigene Annonce kennt das Modul den Host des Geräts nicht — die Node-ID ist
  je Fabric eine andere. `matchDevices` merkt den Host der eigenen Annonce, `ChangeTracker`
  legt ihn in die Momentaufnahme, `SymconInventory::silentForSymcon` sucht ihn beim nächsten
  Lauf unter fremden Fabrics. Befund `own_devices_silent_for_symcon`; das Gerät fällt aus
  „melden sich nicht" heraus. Ein Gerät, das nie sichtbar war, bleibt unzuordenbar.
- **Identitätsdienste in eigener Runde abfragen** (build 41): Kämen `_shelly`/`_hue`-Antworten
  in der Erstabfrage mit, zählten sie als „mDNS funktioniert" und die Probe für „Multicast
  blockiert oder kein Matter" entfiele. Der SRV-Host eines Dienstes ist oft ein anderer als in
  der Matter-Annonce (`ShellyPlugSG3-E4B063E529D0.local` gegen `E4B063E529D0.local`) — die
  Zuordnung läuft über gemeinsame Adressen oder die MAC am Ende des Hostnamens.
- **Eigene Antworten zählen nicht** (build 31): Bonjour/Avahi beantworten die eigene Anfrage
  per Multicast-Loopback. `MatterDiscovery::foreignResponses` sortiert sie vor jedem Urteil
  über „mDNS funktioniert" aus; Symcons Linux-Dummy-Annonce zählt nicht als Gerät.

## Wächterbetrieb

`MonitorInterval` (Minuten, Vorgabe 60, 0 = aus) wiederholt die Prüfung im Hintergrund. Im
Wächterlauf wird **nicht** gepingt, damit schlafende Batteriegeräte in Ruhe bleiben. Die
Variable `Changes` wird nur beschrieben, wenn sich gegenüber dem Vorlauf wirklich etwas
geändert hat — sie ist der Anknüpfungspunkt für eine Benachrichtigung (Rezept in der README).

**Debug-Fenster (seit build 36):** `SendDebug` je Lauf — mDNS-Antworten je Quelle, Border
Router und Geräte mit Adressen und Schlafangabe, offene Nachfragen, Direktabfragen je
Router, Routentabelle roh und geparst, Bewertung, Pings, Symcon-Geräte mit Abo. Bei einer
Forumsrückfrage ist das der Auszug, um den man bittet; Loerdys Dump (66 KB) hat in einem
Durchgang zwei Fehldiagnosen aufgedeckt.

Die Momentaufnahme (`ChangeTracker`, `VERSION` 2 seit build 31) führt Befunde je Gegenstand
(`<id>@<subject>`, etwa Präfix oder Route), damit eine zweite veraltete Route nicht im ersten
Eintrag verschwindet. Ein stummer Lauf (`mdns_silent`) übernimmt per `carryOver` den Stand
des Vorlaufs — sonst meldet ein Aussetzer alles als behoben und der nächste Lauf alles als
neu. Kopplungsfenster-Befunde werden gar nicht verglichen. Eine neue `VERSION` verwirft den
alten Stand: Der erste Lauf danach meldet nichts.

Der Zeitplan eines Laufs: `BUDGET_TOTAL` 24 s gesamt, davon 4 s die erste mDNS-Runde, je 2 s
die Nachfragen (ein Versuch je Runde — unbeantwortete AAAA-Fragen an IPv4-Hosts sind normal);
der Rest bleibt dem Erreichbarkeitstest, dessen Versuchszahl `OsAdapter::pingAttempts` aus der
Restzeit ableitet — plattformabhängig: Windows kostet n·Timeout + (n−1)·1 s Pause (gemessen
18.09.2026: 13,9 s für `ping -6 -n 5 -w 2000` auf ein schlafendes Thread-Gerät), Linux
(n−1)·1 s + Timeout. Mit dem alten Modell (n·Timeout) lief der nuc 28 s statt 22 s.
