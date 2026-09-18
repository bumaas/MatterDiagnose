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
C:/php/php tests/run_tests.php        # alle Unit-Tests (Stand 17.09.2026: 809 Prüfungen)
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
