# MatterDiagnose — Projekt-Hinweise

Symcon-Modul, das die häufigsten Stolpersteine bei der Einbindung von Matter-Geräten prüft
(`IPSModuleStrict`, `declare(strict_types=1)`). Entstanden 01.09.2026 aus einer konkreten
Fehlersuche: Ein IKEA-Sensor ließ sich am nuc nicht koppeln, weil Windows die IPv6-Route ins
Thread-Netz nicht übernahm — die Diagnose sollte solche Ketten künftig in einem Klick zeigen.

## Struktur

`MatterDiagnose/module.php` ist nur Kleber: erheben, an die Bibliotheken übergeben, Befunde in
Texte übersetzen, Formular und Statusvariablen füllen. **Keine Logik, die man testen wollte,
gehört hierher.** Der testbare Kern liegt in `MatterDiagnose/libs/`, jede Klasse ohne
IPS-Aufrufe:

- `MdnsCodec` — mDNS-Nachrichten kodieren/dekodieren (RFC 6762/1035, Teilmenge)
- `MdnsBrowser` — der einzige Socket-Anteil; verschickt Queries, sammelt Antworten
- `MatterDiscovery` — verdichtet die Antworten zum Lagebild (Border Router, Geräte)
- `ThreadNetwork` — wertet die `_meshcop`-TXT-Records aus (Netzgesundheit)
- `RouteTable`, `OsAdapter` — Routingtabelle lesen und bewerten, Systemkommandos
- `SymconInventory` — was Symcon über seine Matter-Geräte weiß
- `DiagnosisEngine` — die Bewertung: aus Erhebungsdaten werden Befunde
- `ChangeTracker` — Vergleich zweier Läufe (macht aus der Momentaufnahme eine Überwachung)
- `RunBudget` — Zeitbudget des Laufs mit Reserve für den Erreichbarkeitstest (build 46)
- `DeviceInventory` — ein Eintrag je physischem Gerät (Host): Anbindung, Betriebsart,
  Annonce-Quelle, eine Spalte je System (`fabricColumns`: Symcon zuerst, fremde als A, B, …
  nach Gerätezahl). Die Spaltenzahl kennt erst der Lauf, deshalb baut `GetConfigurationForm()`
  das Formular aus `form.json` plus dem Attribut `Devices` — so überlebt die Liste das
  Schließen des Formulars. Fremde Systeme benennt der Anwender über `FabricNames`
  (Kennung → Name), die Auswahlliste dazu kommt aus denselben Spalten.
- `DeviceIdentity` — Hersteller/Modell hinter einer Nummer (build 41): andere Dienste desselben
  Geräts (`_shelly`, `_hue`, `_googlecast`, `_hap`, `_esphomelib`; eigene mDNS-Runde
  `BUDGET_IDENTITY`, Zuordnung über Adresse, Host oder MAC im Hostnamen) und `libs/oui.php`
  (IEEE-Auszug, 4.800 Präfixe, neu mit `tests/gen_oui.php <oui.csv>`). Thread-Kennungen sind
  zufällig — dort gibt es nichts zu holen.

`README.md` / `README.en.md` sind die Anwenderdoku nach Punkt 10 der Referenz-Checkliste;
`docs/bericht.png` ist der Beispielbericht darin (anonymisiert).

## Grundprinzip: Befunde statt Messwerte

Ein Befund ist `{severity, id, params}` — `ok | notice | blocker`, stabile ID, Parameter. Die
Anzeigetexte stehen als Katalog in `module.php` (`$catalog`), die Übersetzungen in
`locale.json`.

**Jeder Befund muss zu einer Handlung führen und in Anwendersprache stehen.** Was keine
Handlung nach sich zieht, wird nicht gemeldet. Wo ein Befehl nötig ist, steht er fertig zum
Kopieren im Feld „Auszuführende Befehle"; **ausgeführt wird nie etwas**, die Diagnose ist rein
lesend.

**Zurückgezogene Befunde** führt `DiagnosisEngineTest` als `$retiredFindings`, kein Szenario
darf sie je wieder liefern: `own_controller_missing`, `own_controller_ok` und
`port5353_competition` (paresy, 02.09.2026: Symcon ist als Controller reiner Konsument und
annonciert sich nicht; den mDNS-Port hält Bonjour bzw. Avahi, ohne die Symcon nicht startet)
sowie `foreign_fabrics` (die Zahl fremder Systeme führt zu keiner Handlung).

## Prüfen

`C:\php\php C:\Users\Burkhard\.claude\tools\modul_build.php T:\modules\MatterDiagnose` prüft
library.json, PHP-Syntax, JSON-Gültigkeit, Tests, `check_locale.php`, Stil und Git-Stand in
einem Durchgang. Beim Entwickeln einzeln:

```bash
C:/php/php tests/run_tests.php        # alle Unit-Tests (Stand 25.09.2026: 1394 Prüfungen)
C:/php/php tests/check_locale.php     # Übersetzungs-Vollständigkeit
```

`tests/run_tests.php` lädt jede `*Test.php` im Verzeichnis; eigener Runner, kein PHPUnit. Drei
Tests halten die Struktur zusammen:

- **`FindingCatalogTest`** — Befund-IDs der `DiagnosisEngine` und Katalogeinträge in
  `module.php` müssen deckungsgleich sein; sonst fiele ein neuer Befund erst im Formular auf
  (als nackte ID), ein entfernter bliebe als Leiche liegen.
- **`ReadmeCoverageTest`** — beide READMEs müssen jeden Befund abdecken, dafür trägt jede
  Befundgruppe einen `<!-- findings: id1 id2 … -->`-Kommentar. Doku-Aktualität ist damit CI.
- **`FormActionsTest`** — Formular-Buttons müssen `IPS_RequestAction($id, '<Ident>', …)` rufen;
  die globale `RequestAction()` nimmt (VariablenID, Wert) und stürzt mit „Wrong parameter
  count" ab. Ein RPC-Test der Modulmethode deckt Formular-Klicks **nicht** ab.

**Fixtures sind echte Mitschnitte** (`tests/fixtures/mdns/*.bin`, `os/*.txt`, Szenario-JSONs;
frische holt `tests/capture_fixtures.php` aus dem LAN). Keine Förmlichkeit: Eine erfundene
Routen-Fixture (`Manuell 256` statt `Standard`) hielt hier einen Parserfehler bei grünem Test
verborgen (globale `CLAUDE.md`, „Eine erfundene Fixture …").

Bibliothek auf der Produktivanlage per `MC_ReloadModule` mit Ordnername `MatterDiagnose` neu
einlesen (siehe globale `CLAUDE.md`).

## Ablauf eines Laufs

`BUDGET_TOTAL` 24 s: 4 s die erste mDNS-Runde (`BUDGET_MDNS`), je 2 s die Nachfragen (ein
Versuch je Runde — unbeantwortete AAAA-Fragen an IPv4-Hosts sind normal), dazu Direktabfragen,
Identität und Reverse-Runde; der Rest bleibt dem Erreichbarkeitstest. Dessen Versuchszahl
leitet `OsAdapter::pingAttempts` aus der Restzeit ab, plattformabhängig: Windows kostet
n·Timeout + (n−1)·1 s Pause (gemessen 18.09.2026: 13,9 s für `ping -6 -n 5 -w 2000` auf ein
schlafendes Thread-Gerät), Linux (n−1)·1 s + Timeout. Mit dem alten Modell (n·Timeout) lief der
nuc 28 s statt 22 s.

`MonitorInterval` (Minuten, Vorgabe 60, 0 = aus) wiederholt die Prüfung im Hintergrund; im
Wächterlauf wird **nicht** gepingt, damit schlafende Batteriegeräte in Ruhe bleiben. Die
Variable `Changes` wird nur beschrieben, wenn sich gegenüber dem Vorlauf wirklich etwas
geändert hat — sie ist der Anknüpfungspunkt für eine Benachrichtigung (Rezept in der README).

Die Momentaufnahme (`ChangeTracker`, `VERSION` 2 seit build 31) führt Befunde je Gegenstand
(`<id>@<subject>`, etwa Präfix oder Route), damit eine zweite veraltete Route nicht im ersten
Eintrag verschwindet. Ein stummer Lauf (`mdns_silent`) übernimmt per `carryOver` den Vorlauf —
sonst meldet ein Aussetzer alles als behoben und der nächste alles als neu. Dasselbe im Kleinen
seit build 64: Ein `thread_prefix_untested` übernimmt die Erreichbarkeitsaussage des Vorlaufs
für dieses Präfix (Wächterlauf ohne Ping, dann Handlauf ohne Ping-Ergebnis gab am nuc „Neuer
Befund" und eine Minute später „Behoben"; ein roter `thread_prefix_unreachable` wäre so als
behoben erschienen).
Kopplungsfenster-Befunde werden gar nicht verglichen; eine neue `VERSION` verwirft den alten
Stand, der erste Lauf danach meldet nichts.

**Debug-Fenster (build 36):** `SendDebug` je Lauf — mDNS-Antworten je Quelle, Border Router und
Geräte mit Adressen und Schlafangabe, offene Nachfragen, Direktabfragen je Router,
Routentabelle roh und geparst, Bewertung, Pings, Symcon-Geräte mit Abo, seit build 47 die
gemessene Dauer je Abschnitt. Bei einer Forumsrückfrage ist das der Auszug, um den man bittet;
Loerdys Dump (66 KB) hat in einem Durchgang zwei Fehldiagnosen aufgedeckt.

## Regeln aus Lehrgeld

### mDNS abfragen

- **Socket-Fallen** (01.09.2026, ausführlich im Kopf von `MdnsBrowser`): Ein „verbundener"
  UDP-Socket verwirft fremde Absender, `stream_socket_recvfrom` ignoriert `stream_set_timeout`
  (deshalb `stream_select`), und ohne Bindung an die LAN-IP geht der Multicast ins VPN.
- **Nachfragen brauchen ein Gedächtnis** (build 23): Hosts ohne IPv6 antworten nie auf AAAA und
  verdrängten so die SRV-Nachfragen (19 von 29 Instanzen unaufgelöst);
  `MatterDiscovery::followUpQuestions($survey, $limit, $asked)` ordnet nach Bedeutung.
- **Namen und TXT-Schlüssel sind case-insensitiv** (build 23) — Gerät und Advertising-Proxy
  schreiben denselben Instanznamen nicht zwingend gleich.
- **Eigene Antworten zählen nicht** (build 31): Bonjour/Avahi antworten per Loopback,
  `MatterDiscovery::foreignResponses` sortiert sie vor jedem Urteil über „mDNS funktioniert"
  aus; Symcons Linux-Dummy-Annonce ist kein Gerät.
- **Identitätsdienste in eigener Runde** (build 41), sonst zählten `_shelly`/`_hue`-Antworten
  als „mDNS funktioniert" und die Probe für „Multicast blockiert oder kein Matter" entfiele.
  Ihr SRV-Host weicht oft ab (`ShellyPlugSG3-E4B063E529D0.local` gegen `E4B063E529D0.local`).
- **Eine fehlende Antwort ist kein Urteil** (build 63): Die Identitätsrunde ist eine Frage mit
  1 s Wartezeit; in einer von zehn Runden fehlten am nuc drei von fünf Shellys. Zwei Shellys
  ohne Matter-Ansage pendelten so stündlich zwischen `own_devices_announce_missing` und
  `own_devices_unsubscribed`. Seither merkt sich die Momentaufnahme die IPv4 des Belegs
  (`aliveAt`), und `DeviceIdentity::recheckTargets` fragt vor dem roten Urteil direkt dort
  nach (vor dem Ping, `BUDGET_DIRECT`, Antwort nach ~265 ms).
- **WLAN-Geräte haben Lücken von 1–2 s** (build 66): Ein einzelner Nachfrageversuch reichte
  nicht, der Dimmer war um 13:43 wieder rot. Gemessen am nuc (25.09.2026, 270 s, alle 3 s):
  Dimmer 6/90, Plug 2/90 ohne Antwort, beide etwa alle 30 s gleichzeitig; der Apple TV am LAN
  0/90. `DeviceIdentity::recheckRounds` fragt deshalb bis zu `RECHECK_ROUNDS` (3) Mal mit
  `RECHECK_PAUSE` (2,5 s) dazwischen, jede Runde nur die noch stummen Ziele.
  **Die eigentliche Ursache war aber das Budget:** Das Debug (per `IPS_EnableDebugFile`)
  zeigte „kein Budget" — die Nachfrage kam bei 16,7 s, `phaseAllowed` erlaubt mit 7 s
  Ping-Reserve nur bis 16,5 s. Sie lief seit build 63 nie, auch im Wächterlauf nicht, der gar
  nicht pingt. Seither `RunBudget::forRun` (Wächterlauf ohne Reserve) und
  `judgementAllowed` (erste Nachfragerunde darf an die Reserve, weitere nicht).
  **Debug eines Laufs ohne offenes Fenster:** `IPS_EnableDebugFile(15117)`, Lauf auslösen,
  `IPS_DisableDebugFile(15117)`, dann `T:\logs\debug_15117.log`.
- **Fragen wie Avahi, über IPv4 und IPv6** (build 67, Alexandro `t/144417/46–47`): Sein
  Apple TV beantwortete weder Legacy-Fragen vom freien Port noch IPv4-Multicast von 5353 —
  nur IPv6-Multicast an `ff02::fb`. `MdnsBrowser` fragt deshalb ohne Ziel von Port 5353
  (`SO_REUSEADDR` neben Bonjour/Avahi, am nuc und an der Testbox erprobt) über beide
  Familien, ohne QU-Bit; der alte Weg bleibt Rückfall und für Direktabfragen. Der Socket
  hört den ganzen Link mit: `MdnsResponses::relevant` behält nur Antworten auf unsere
  Fragen, `preferIpv4` ersetzt IPv6-Absender durch die IPv4 desselben Hosts (A/AAAA der
  Antworten), weil `source` Schlüssel für Direktabfragen und Router-Zuordnung ist.
  Schnittstelle: `OsAdapter::defaultRouteInterface` (Windows Index, Linux Name).
  Nebenwirkung am nuc: Über IPv6 kam eine fünfte Ansage der DIRIGERA dazu, und
  `device_fabrics_full` schlug an — zu Unrecht, siehe „Ein Knoten ist Host plus Port"
  (build 70). Fixtures `tests/fixtures/mdns/dualstack/`.
- **Direktabfrage je Border Router** (build 37): `MdnsBrowser::query(…, $target)` schickt die
  `_matter._tcp`-PTR-Anfrage unicast an die IPv4 des Routers — ein Proxy darf auf Multicast per
  Multicast antworten, was am eigenen Port nie ankommt. Apple TV und DIRIGERA antworten (23
  bzw. 3 Instanzen), Aqara-Hubs und HomePod nie; daher `BUDGET_DIRECT` 0,5 s, Abbruch 0,25 s
  nach der ersten Antwort.

### Thread-Netz und Routen

- **ULA ≠ Thread** (build 37, Loerdy `t/144417/9`): Ein gespiegeltes Fremdsegment
  (`fdb2:3abb:80f6:2::/64`, Shellys mit IPv4) galt als Thread-Netz und brachte eine
  Routenempfehlung hervor. Thread-Geräte haben nie eine IPv4
  (`MatterDiscovery::threadCandidateAddresses`); „nicht on-link" beweist nichts, nur der Weg
  über einen Border Router.
- **Ein Thread-Präfix muss kein ULA sein** (build 45, Rainer `t/144417/12`): Aus dem
  delegierten `2a02:…:a900::/56` nimmt sich der Aqara Hub M3 ein globales OMR — das Thread-Netz
  war unsichtbar. `DiagnosisEngine::threadPrefixes` zählt globale Präfixe **nur mit Beleg**:
  OMR aus der Router-Annonce oder Geräte ohne IPv4, die ein Border Router stellvertretend
  annonciert (`MatterDiscovery::proxiedPrefixes`). Fixture `route_linux_erpe_gua.txt`.
- **Kein Urteil ohne vollständige Beweislage** (build 21/24/31): Der Apple TV antwortet mit 29
  Records ohne AAAA; das Modul hielt ihn für aufgelöst und erklärte die Route für falsch —
  **der Löschempfehlung wurde gefolgt**, die Route war richtig. Seither: keine Link-Local →
  kein „unbekanntes Gateway", und `RouteTable::assess` bekommt `null` statt der Präfixliste,
  solange `MatterDiscovery::prefixEvidenceComplete` die genutzten Präfixe nicht belegt.
- **Die Herkunft einer Route** verrät unter Windows nur die Lebensdauer (build 22, global
  „Tooling: Windows-IPv6-Routen") — gelernte Routen sind kein Persistenz-Befund. Linux:
  `proto ra` oder `expires`; BusyBox kennt kein `proto` und schreibt nur `expires 0sec`
  (`route_linux_symbox_busybox.txt`), deshalb entfällt dort `thread_route_learned` (build 31).
- **Windows-Ping zählt „Zielnetz nicht erreichbar" nicht als Antwort** (17.09.2026,
  `ping_unreachable_de.txt`) — ein Review-Verdacht, der am echten Mitschnitt nicht hielt.
- **Im Container fehlen `ip` und `ping`** (build 59, reblade `t/142087/1140`, Docker auf
  Synology): „sh: 1: ip: not found" wurde als leere Routentabelle gelesen, im Wächterlauf
  wurde daraus ein roter `thread_prefix_unreachable`. Seither erkennt
  `OsAdapter::commandMissing` die Meldung, `RouteTable::fromSystem` weicht auf
  `/proc/net/ipv6_route` aus und liefert `null` (unbekannt) statt `[]`; fehlt `ping`, meldet
  `thread_prefix_untested_no_ping` den wahren Grund. Fixture-Paar
  `route_linux_proc_testbox.txt`/`route_linux_ip_testbox.txt` stammt aus demselben Lauf.
- **Fehlt `accept_ra_rt_info_max_plen` überall, lernt der Kernel keine Routen** — die
  Einstellung und die Auswertung der Route Information hängen beide an
  `CONFIG_IPV6_ROUTE_INFO` (Kernel-Quelltext, 21.09.2026). `null` in `readIpv6Conf` heißt
  „unlesbar", erst `ipv6ConfOptionMissing` sagt „gibt es nicht";
  `sysctl_route_info_unsupported` nennt dann den Routenbefehl von Hand.

### Geräte erkennen und benennen

- **SII allein sagt nichts über Batterie** (build 39): Die GRILLPLATS am Strom galt als
  Batteriegerät (SII 2000; KLIPPBOK 15800, MYGGBETT 17000, Shellys nur `T=0`).
  `MatterDiscovery::sleepyFromTxt`: `ICD` oder SII ≥ 5000 ms → Batterie, TXT ohne → Netz, kein
  TXT → unbekannt (dann Nachfrage, `SymconInventory::instancesWithoutSleepInfo`). Bei eigenen
  Geräten hat Symcons „(ICD)" das letzte Wort (`sleepyFromSubscription`, build 45).
- **Controller-Datensätze sind keine Geräte** (build 40): Der Datensatz der Testbox
  (`…-FFFFFFEFFFFFFFFF`) wurde mitgezählt. Ebenso waren „37 Matter-Geräte" 37 Ansagen von 13
  Geräten in 6 Fabrics — `operational_found` zählt Hosts, Ansagen und Systeme getrennt
  (build 38). Welches fremde System welches ist, verraten Reverse-Namen und `FabricNames`.
- **Ein Knoten ist Host plus Port, und Controller sind keine Geräte** (build 70, 26.09.2026):
  Symcon zeigte für die DIRIGERA „Verbundene Systeme (4 von 10)", das Modul fünf. Die fünfte
  Ansage (`39E99BD14DFBBCD1-…`, nur über IPv6, `dualstack/nuc_01.bin`) ist ein zweiter
  Knoten desselben Hosts auf Port 5541 mit eigenem TXT — der Controller der IKEA-Fabric neben
  der Bridge auf 5540. Ebenso stand `haos-pi4` als Gerät da: Node-ID 0x1B669 = 112233 ist die
  Standard-Controller-ID des Matter-SDK, der Matter-Server von Home Assistant sagt sich damit
  in seiner Fabric an. `SymconInventory::nodeKey` gruppiert nach Host und Port;
  `MatterDiscovery::markControllers` setzt `controller` (`sdk_default`, `second_node`) nur mit
  Beleg — der Echo Dot läuft als einziger Knoten auf 5541 und bleibt ein Gerät. Controller
  stehen in der Liste mit Zusatz, zählen aber weder als Gerät noch als Fabric-Platz.
  Test `ControllerNodeTest` am Mitschnitt.
- **Wer selbst antwortet, ist kein Thread-Gerät** (build 69, Alexandro `t/144417/53`): Seine
  Govee-Stehlampe H16B0 (Matter über WLAN, nur IPv6) stand als „Thread", weil
  `DeviceInventory` nur „IPv4 vorhanden → LAN" kannte. Seither macht `via = self` (Quelle
  in den eigenen Adressen) das Gerät zum LAN/WLAN-Gerät. Test `DeviceLinkTest` mit den
  Einträgen aus seinem Debug-Auszug.
- **Ein Hub bringt gebrückte Geräte unter seiner eigenen Adresse mit** (build 48, Aqara Hub M3,
  `t/144417/16`): Das FP300 annonciert sich als eigener Knoten mit eigenem Hostnamen, aber mit
  der IPv6 des Hubs. `DeviceInventory::markBridged` gruppiert nach Adresse; Träger ist, wessen
  MAC im eigenen Hostnamen steht (`DeviceIdentity::macFromAddress`: EUI-64 ohne FF:FE, Bit 1
  zurückgedreht) oder wer als einziger der Gruppe in Symcon gekoppelt ist. **Ohne Beleg bleibt
  die Gruppe unberührt.**
- **Die MAC steht auch in der Adresse** (build 50): `3D59C51D251F` verwarf `ouiVendor` als
  lokal verwaltet, doch die Adresse `fd86:…:de54:d7ff:fe14:dd72` trug die echte
  (`DC:54:D7` = Amazon).
  `DeviceIdentity::ouiFromAddresses` liest jede EUI-64-Adresse; Privacy-Adressen,
  Thread-Kennungen und IPv4 liefern nichts. Hostname vor Adresse, Identitätsdienst vor beidem.
- **Den Klarnamen kennt der Router** (build 51/52): Ohne A-Record kennt das Modul nur IPv6, und
  darauf nennt die FRITZ!Box bloß den mDNS-Namen — `module.php::reverseFor` geht deshalb IPv6 →
  Name → dessen IPv4 (`OsAdapter::resolveIpv4`) → Klarname; so wurde `3D59C51D251F` zu
  `EchoDot-Kueche.fritz.box`. `OsAdapter::reverseName` fragt,
  `DeviceInventory::applyReverseNames` benennt nur Geräte ohne Symcon-Eintrag.
  **`gethostbyaddr` kennt keinen Zeitschalter**: Abbruch der ganzen Runde nach der ersten
  Antwort über `REVERSE_SLOW` (0,4 s), höchstens `REVERSE_MAX` (8) Abfragen.
- **Ein System wird überall gleich geschrieben** (build 49): `DeviceInventory::columnTitle`
  („Apple Home (A)") und `columnChoice` („A: 35FA… (9)") sind die einzigen beiden
  Schreibweisen — in der Bibliothek und damit prüfbar, der Kleber bildet keine eigenen.
- **„Meldet sich für andere, nicht für Symcon" braucht ein Gedächtnis** (build 43, Loerdys
  GRILLPLATS): Ohne eigene Annonce ist der Host unbekannt, die Node-ID ist je Fabric eine
  andere. `matchDevices` merkt den Host, `ChangeTracker` legt ihn in die Momentaufnahme,
  `SymconInventory::silentForSymcon` sucht ihn im nächsten Lauf unter fremden Fabrics
  (`own_devices_silent_for_symcon`). Ein nie sichtbares Gerät bleibt unzuordenbar.

### Befund-Disziplin

- **Ein Befund ohne nötige Handlung ist keiner — auch nicht als Blocker** (build 52, Ralf
  `t/144417/22`): Kein IPv6, aber zwei einwandfrei laufende WLAN-Geräte; der rote `no_ipv6`
  verlangte eine wirkungslose Reparatur. `DiagnosisEngine::threadInvolved` entscheidet: Border
  Router, Thread-Präfix oder ein Gerät nur mit IPv6 → Blocker `no_ipv6`, sonst der Hinweis
  `no_ipv6_no_thread` („Ihre Geräte brauchen keine").
- **Eine unbelegte Folge gehört nicht als Tatsache in einen Befund** (build 46): Der Text
  behauptete, nach einem Neustart von Symcon komme die Verbindung nicht wieder zustande —
  Loerdys Neustart der SymBox widerlegte das, die stumme GRILLPLATS lieferte weiter Werte.
  Seitdem „kann scheitern", mit dem Feldtest als Gegenbeispiel im Text.
- **`CM=0` ist kein Kopplungsfenster** (build 20): Shelly annonciert `_matterc._udp` nach jedem
  Boot minutenlang mit geschlossenem Fenster. Nur `CM >= 1` zählt.

### Zeitbudget und Erreichbarkeitstest

- **Erst messen, dann zuschreiben** (build 46/47): Aus einer 6-Sekunden-Lücke im Debug wurde
  die falsche Erklärung „der Matter-Konfigurator braucht bei 21 Geräten 6 s". Gemessen
  (18.09.2026, `IPS_GetConfigurationForm` dreimal je Instanz): **nuc 1,06 s bei 5 Geräten,
  Testbox 1,02 s bei 7** — feste Wartezeit, wächst nicht mit der Gerätezahl (C++-Gegenprobe
  fehlt, Neustadt hat keine Matter-Instanz). In der Lücke steckte die mDNS-Abgleichsrunde
  (`missesSomethingKnown`), die nur bei fehlenden Geräten läuft. Abhilfe: `BUDGET_PING_RESERVE`
  (7 s) vor jedem optionalen Schritt, Abgleichsrunde kürzen statt streichen, `readInventory()`
  (teuer, einmal) von `matchInventory()` getrennt. **Eine Zeitlücke benennt keine Ursache.**
- **`phaseAllowed` gilt nicht für Schritte nach dem Ping** (build 52): Die Reverse-Runde lief
  nie, weil der Guard 1,5 s plus 7 s Reserve verlangte — die Reserve gehört aber dem Ping, und
  der ist da schon gelaufen. Danach zählt `remaining()`.
- **Der Ping fragt Netzgeräte zuerst** (build 38): Er traf den schlafenden KLIPPBOK (0 von 4)
  und meldete „kein Gerät antwortete"; `MatterDiscovery::pingCandidates` ordnet Schlafende ans
  Ende.

### Anzeige

- **Der Text muss aus sich heraus lesbar sein** (build 48): Die Änderungsliste trennt mit `\n`;
  bei Rainer klebten drei Einträge in einer Zeile, weil seiner Variablen die Option `MULTILINE`
  fehlt (Gegenprobe am nuc: damit bricht die Kachel korrekt um). Die Darstellung einer
  bestehenden Variablen kann ein Modul nicht nachziehen, deshalb beginnt jeder Eintrag mit „• "
  (`ChangeTracker::bulletList`). Dass der Wert bei `MULTILINE` die Umbrüche selbst tragen muss,
  steht in der Doku nirgends (Feedback an Symcon).
