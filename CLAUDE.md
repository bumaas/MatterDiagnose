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
C:/php/php tests/run_tests.php        # alle Unit-Tests (Stand 09.09.2026: 738 Prüfungen)
C:/php/php tests/check_locale.php     # Übersetzungs-Vollständigkeit
php php-cs-fixer.phar fix --dry-run --diff --allow-risky=yes
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

Bibliothek auf der Produktivanlage ohne Kernel-Neustart einlesen:

```bash
C:/php/php C:/Users/Burkhard/.claude/tools/symcon_rpc.php MC_ReloadModule 51062 '"MatterDiagnose"'
```

## Fixtures: echte Mitschnitte, keine erfundenen

Unter `tests/fixtures/` liegen **echte** Paketmitschnitte (`mdns/*.bin`), echte
Systemausgaben (`os/*.txt`) und Szenario-JSONs. `tests/capture_fixtures.php` sammelt frische
Mitschnitte aus dem LAN ein.

Das ist keine Förmlichkeit: Eine erfundene Fixture für Windows' persistenten Routenspeicher
zeigte `Manuell 256` in der Metrikspalte — dort steht in Wirklichkeit `Standard`. Der Parser
las die Spalte als `\d+`, verwarf jede echte Zeile und meldete „Route nicht dauerhaft",
während sie dauerhaft war. **Der Test war die ganze Zeit grün.** Wo eine Fixture ein
Fremdformat nachbildet, muss sie aus einem echten Lauf stammen.

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
- **Windows-Routen: nur die Lebensdauer verrät die Herkunft** (build 22). `netsh … show
  route` führt RA-gelernte Routen als „Manuell", `Get-NetRoute` als `NetMgmt` — wie manuelle.
  Erst `show route level=verbose` zeigt die Gültigkeitsdauer: endlich = per Router
  Advertisement gelernt, „Unendlich" = von Hand. Gelernte Routen sind kein Persistenz-Befund.
- **Kein Urteil ohne vollständige Beweislage** (build 24): Fehlt einem Border Router die
  Link-Local, wird „unbekanntes Gateway" gar nicht erst gemeldet. Ein Löschrat ohne Beleg ist
  teurer als ein verpasster Hinweis.

## Wächterbetrieb

`MonitorInterval` (Minuten, Vorgabe 60, 0 = aus) wiederholt die Prüfung im Hintergrund. Im
Wächterlauf wird **nicht** gepingt, damit schlafende Batteriegeräte in Ruhe bleiben. Die
Variable `Changes` wird nur beschrieben, wenn sich gegenüber dem Vorlauf wirklich etwas
geändert hat — sie ist der Anknüpfungspunkt für eine Benachrichtigung (Rezept in der README).

Der Zeitplan eines Laufs: `BUDGET_TOTAL` 24 s gesamt, davon 4 s die erste mDNS-Runde, je 2 s
die Nachfragen; der Rest bleibt dem Erreichbarkeitstest.

**Modul-Timer sind unter der Rust-Edition keine sichtbaren Ereignisobjekte** — `IPS_GetEvent`
findet nichts, ein `NextRun` gibt es nicht. Das Feuern lässt sich nur über die Wirkung
belegen: Intervall kurz auf 1 Minute, `LastRun` beobachten, zurückstellen.
