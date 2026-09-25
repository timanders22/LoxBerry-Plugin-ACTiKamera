# LoxBerry-Plugin: ACTi Kamera

Version 1.9.22 · LoxBerry ab 3.0 · PHP 7.4 und 8.x

Holt Bilder von einer **ACTi-Netzwerkkamera** (E-Serie und alle Modelle mit der
klassischen CGI-Schnittstelle) und stellt sie Loxone bereit — **ohne dass
Benutzername und Passwort in der Loxone-Projektdatei stehen müssen**.

Genau das ist der eigentliche Zweck: Bindet man eine Kamera direkt in Loxone ein,
landet die Adresse samt `USER=…&PWD=…` im Klartext in der Projektdatei — und damit
in jedem Backup und jeder Kopie, die man weitergibt. Mit diesem Plugin ruft Loxone
nur noch `cam.php?foto=1&token=…` auf; die Zugangsdaten bleiben auf dem LoxBerry.

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, PHP 7.4 und 8.x).

## Neu in 1.9.18

- **Das Auswahlfeld hatte gar keinen Pfeil.** Die eigene Feldregel dieser
  Seite setzte `background: #fff` — die Kurzform löscht das
  Hintergrundbild, mit dem die LoxBerry-Oberfläche den Pfeil zeichnet.
  Übrig blieb ein Feld, das aussieht wie ein Textfeld; wer nicht
  hineinklickt, erfährt nicht, dass eine Auswahl dahintersteht. Am
  05.09.2026 im Browser gegen die Rahmen-CSS des Geräts gemessen (LoxBerry 4.0.0.15)
  und behoben: die Seite zeichnet den Pfeil jetzt selbst
  (`Regeln/04`). Sonst ist an dieser Fassung nichts geändert.

## Funktionen

- **Schnappschuss auf Zuruf** — ausgelöst von Loxone: Klingel, Türtaster,
  Bewegungsmelder; der Anlass wird mitgespeichert
- **Bildserie** („Clip") über mehrere Sekunden, ohne ffmpeg oder Zusatzpakete —
  sie setzt danach dieselben Werte wie ein Einzelbild, also auch `PUSHAKTIV`
- **Letztes Bild unter fester Adresse** (`letztesbild.jpg`) für Kamera-Kachel,
  Webseiten-Baustein oder Push-Anhang — ohne Zugangsdaten in der URL. Seit
  1.9.19 abschaltbar: dann gibt es das Bild nur über `cam.php?letztes=1`, und
  mit gesetztem Stromkennwort nur mit Token
- **Ausfallerkennung**: das Plugin fragt die Kamera in einstellbarem Takt, ob sie
  antwortet, und meldet `ERREICHBAR` sowie `FEHLER` (fehlgeschlagene Prüfungen in
  Folge) an Loxone. Bleibt der Minutentakt selbst stehen, wächst `HERZ`
- **Push-Auslöser für Loxone**: `PUSHAKTIV=1` für ein einstellbares Zeitfenster
  nach jeder Aufnahme
- **Zeitraffer**: täglich zur eingestellten Uhrzeit ein Bild in den Unterordner
  `timelapse` (Dateiname = Datum); ist `ffmpeg` vorhanden, wird daraus
  `zeitraffer.mp4` neu erzeugt
- **Archiv-Bereinigung** täglich um 03:35 Uhr, nach **Alter, Anzahl und Größe**
  (jeweils 0 = unbegrenzt) über Bilder, Bildserien und Zeitraffer — die
  jeweils neueste Aufnahme bleibt immer erhalten
- **KI-Objekterkennung** (optional) über CodeProject.AI oder DeepStack:
  erkannte Objekte erscheinen im JSON, in den Webhooks, per MQTT und als
  `ERKANNT=` in der Loxone-Ausgabe; `PERSON=1` ist der fertige Schalter
- **Webhooks**: Webhook 1 als POST mit JSON, Webhook 2 als GET mit Parametern
- **MQTT** über das LoxBerry MQTT Gateway, mit Herzschlag (`online`, `ts`), damit
  ein toter Dienst nicht wie ein ruhiges Haus aussieht; **JSON** für Drittsoftware
- **Fertige Importdateien für Loxone Config** — eine für die virtuellen Eingänge,
  eine für die virtuellen Ausgänge samt Aktionstoken
- Reiter: Einstellungen, MQTT, Einbindung in Loxone (Schritt-für-Schritt inkl.
  kompletter Baustein-Liste), Aufnahmen, Test, Logdateien

## Mehrere Kameras

Das Plugin führt bis zu **vier** Kameras. Die erste behält dabei alles, was sie
vorher hatte — die Konfigurationsschlüssel (`host`, `user`, `pass`), den
Ablageordner `bilder/` und die Feldnamen `OK`, `ALTER`, `PUSHAKTIV`. Jede
weitere trägt ihre Nummer:

| | Kamera 1 | Kamera 2 |
|---|---|---|
| Konfiguration | `host`, `user`, `pass` | `host2`, `user2`, `pass2` |
| Ablage | `bilder/`, `clips/`, `timelapse/` | `bilder2/`, `clips2/`, `timelapse2/` |
| Werte in Loxone | `OK`, `ALTER`, `PUSHAKTIV` … | `OK2`, `ALTER2`, `PUSHAKTIV2` … |
| Befehl | `?foto=1&anlass=klingel&token=…` | `?foto=1&anlass=klingel&kamera=2&token=…` |
| MQTT | `acti/OK` | `acti/OK2` |

**Wer eine zweite Kamera ergänzt, muss an der ersten in Loxone nichts
anfassen.** Beide Importdateien wachsen einfach um die neuen Bausteine; Loxone
Config legt beim Import neu an und überschreibt nichts.

`PUSH`, `PTEST` und `HERZ` gibt es genau einmal — ein Test-Push und ein
Minutentakt gehören zum Plugin, nicht zu einer Kamera. Stünden sie je Kamera
da, sähe man auf einer Anlage mit vier Kameras viermal denselben Wert und
hielte ihn für vier Messungen.

Eingerichtet wird eine weitere Kamera im Reiter *Einstellungen*: dort steht
unter der letzten Kamera immer ein leerer Abschnitt. Sobald eine Adresse
eingetragen und gespeichert ist, führt das Plugin die Kamera mit.

## Auslösung durch die Kamera selbst

Viele ACTi-Kameras können bei eigener Bewegungserkennung eine Adresse
aufrufen. Dann braucht es keinen Bewegungsmelder an Loxone. Eingerichtet
wird das im Web-Konfigurator an **drei** Stellen — und die Adresse verteilt
sich dabei auf zwei Felder:

| Wo | Was hinein gehört | Grenze |
|---|---|---|
| *Ereignis → Ereignis-Server → HTTP-Server 1* | nur der Rechner: `192.0.2.14` | 64 Zeichen |
| *Ereignis → Ereignis-Setup → URL-Befehle senden → [+] bei Befehl 1* | Pfad und Anhang | 255 Zeichen |
| *Ereignis → Ereignis-Liste → 1* | die Regel: Bewegung → URL-Befehl 1 | — |

In das zweite Feld gehört nur der Pfad, ohne Rechner und ohne `http://`:

    /plugins/actikamera/e.php?t=<12 Zeichen>

Die fertige Adresse samt Zeichenzahl steht im Reiter *Einbindung in Loxone*.
Anhängen lässt sich `&a=klingel` für einen anderen Anlass und `&c=1` für eine
Bildserie. Das untere Feld *Befehl als Ereignis wird inaktiv* bleibt leer —
sonst kommt am Ende jeder Bewegung ein zweites Bild.

### Max. Verbindungszeit darf nicht 0 sein

**Der wichtigste Satz dieses Abschnitts.** Im Dialog *HTTP-Server 1* steht
ab Werk eine **Max. Verbindungszeit von 0 Sekunden**. Dabei bricht die Kamera
jeden ausgehenden Aufruf ab, bevor er das Gerät verlässt — und zwar lautlos:
ihr eigenes Protokoll verzeichnet ausschließlich eingehende Zugriffe, ein
gescheiterter Anruf hinterlässt dort keine Spur.

Solange dort 0 steht, bleibt alles wirkungslos: der Testknopf, die
Bewegungsregel, auch nach einem Neustart. Gemessen: mit 0 kam über drei
Stunden lang kein einziger Aufruf an, drei Sekunden nach der Umstellung auf
10 der erste.

Steht dort ein Wert und es passiert trotzdem nichts, hilft das Protokoll des
Plugins weiter — `e.php` schreibt **jeden** Aufruf mit, auch den abgewiesenen,
samt Adresse des Anrufers. Steht dort die Adresse der Kamera, kommt sie durch.

### Die übrigen Felder

Der ACTi-Konfigurator verlangt im HTTP-Server-Dialog **Benutzername**,
**Benutzerpasswort** und einen **Netzwerkport**; ohne sie lässt er das Formular
nicht speichern. Der Port ist `80`, die Anmeldedaten sind beliebig — der
Endpunkt wertet sie nicht aus (gemessen mit und ohne Basic-Kopf, als GET und
als POST). Ein echtes Kennwort gehört trotzdem nicht hinein: die Kamera schickt
es bei jedem Auslösen als HTTP-Basic über das Netz, und das ist nur Base64.

In der Regel selbst (*Ereignis-Liste → 1*) gehören **Dauer 24:00** und alle
sieben Wochentage; eine Dauer von 01:00 lässt die Regel nur zwischen
Mitternacht und ein Uhr greifen. Unter *Antwort an* genügt **URL-Befehl
senden → URL-Befehl 1**; alles andere bleibt leer.

### Warum ein eigenes Token

Nicht wegen der Länge — die gewöhnliche Adresse über `cam.php` bräuchte mit
dem 32-stelligen Aktionstoken 89 Zeichen und passt in die 255 mühelos.

**Dieses Token darf ausschließlich aufnehmen.** Es kann das Archiv nicht
aufräumen und keine Diagnose lesen — wer ein Token in ein fremdes Gerät legt,
gibt ihm nur, was es braucht. Und jede Kamera hat ihr eigenes: das Token sagt
dem Plugin selbst, welche gemeint ist, ohne dass ein `&kamera=` in der Adresse
steht.

### Mindestpause

Der ACTi-Konfigurator schlägt ein *Auslösungsintervall* von einer Sekunde vor.
Bleibt es dabei, ruft die Kamera bei Regen oder Laub im Sekundentakt an —
3600 Bilder je Stunde. Die **Mindestpause** in den Einstellungen fängt das ab;
weitere Aufrufe innerhalb der Pause werden abgewiesen und **gemeldet**
(`ACTI;OK=0;ERR=PAUSE;REST=…`), nicht stillschweigend verworfen.

Ab Werk steht sie auf 0, also aus: eine Bremse, die niemand bestellt hat,
verschluckt sonst nach einem Update eine Klingelaufnahme, und das fällt erst
auf, wenn jemand vor der Tür stand. Wer die Kamera selbst auslösen lässt,
sollte 20–30 Sekunden eintragen.

## Aktionstoken

Jeder Aufruf, der **etwas auslöst**, verlangt seit 1.9.8 ein Token. Es wird beim
ersten Öffnen der Oberfläche erzeugt und steht im Reiter *Einbindung in Loxone*
samt den vollständigen Adressen zum Abschreiben; einfacher ist der Knopf, der die
Importdatei für die virtuellen Ausgänge erzeugt.

Ob eine in Loxone eingetragene Adresse noch stimmt, beantwortet

    /plugins/actikamera/cam.php?selftest=1&token=<TOKEN>

ohne dass etwas ausgelöst wird — Antwort `SELFTEST;OK=1;TOKEN=OK`, bei falschem
Token HTTP 403 und `SELFTEST;OK=0;ERR=TOKEN`.

> **Beim Update von 1.9.7 oder älter:** die bisher in Loxone eingetragenen
> Adressen ohne Token werden ab 1.9.8 mit HTTP 403 abgewiesen. Ein virtueller
> Ausgang wertet die Antwort nicht aus — der Ausfall bliebe still. Die Ausgänge
> müssen deshalb einmal nachgezogen werden.

## Endpunkte

| Aufruf | Token | Zweck |
|---|---|---|
| `/plugins/actikamera/cam.php` | nein | Loxone-Zeile `ACTI;OK=..;ALTER=..;PUSHAKTIV=..;…` |
| `…/cam.php?json=1` | nein | Zustand als JSON |
| `…/cam.php?letztes=1` | nein¹ | letztes Bild als JPEG (seit 1.9.19) |
| `…/cam.php?bild=<Datei>` | nein¹ | eine bestimmte gespeicherte Aufnahme |
| `…&kamera=<n>` | — | an jedem Aufruf: welche Kamera gemeint ist (ohne Angabe die erste) |
| `…/cam.php?serie=<Ordner>&nr=<n>` | nein¹ | ein Bild aus einer Bildserie |
| `…/cam.php?zeitraffer=<Datei>` | nein¹ | ein Zeitrafferbild |
| `…/cam_stream.php` | nein¹ | fortlaufender MJPEG-Strom (`IntVideoUrl`) |
| `…/cam_stream.php?einzeln=1` | nein¹ | Einzelbild (`IntAlertImage`) |
| `…/cam.php?selftest=1&token=…` | **ja** | prüft nur das Token, löst nichts aus |
| `…/cam.php?foto=1&anlass=…&token=…` | **ja** | Bild aufnehmen |
| `…/cam.php?clip=1&anlass=…&token=…` | **ja** | Bildserie aufnehmen |
| `…/cam.php?timelapse=1&token=…` | **ja** | Zeitrafferbild jetzt aufnehmen |
| `…/cam.php?cleanup=1&token=…` | **ja** | Archiv jetzt aufräumen |
| `…/cam.php?ptest=1&token=…` | **ja** | Test-Pushnachricht auslösen |
| `…/cam.php?test=1&token=…` | **ja** | Verbindungstest im Klartext |
| `…/cam.php?diag=1&token=…` | **ja** | Diagnose: alle Befehls- und Anmeldevarianten |
| `…/cam.php?sys=1&token=…` | **ja** | Auskunft der System-Schnittstelle |
| `…/e.php?t=<Auslöse-Token>` | **ja²** | kurzer Auslöser für Geräte mit knappem Adressfeld |

¹ Lesend, deshalb ohne Aktionstoken. Ist in den Einstellungen ein **Stromkennwort**
hinterlegt, verlangen diese Aufrufe zusätzlich `&t=<Kennwort>`. Bis 1.9.18 galt das
für `?letztes=1` **nicht** — am 06.09.2026 an der Anlage gemessen: `?bild=…`
antwortete mit HTTP 403, `?letztes=1` mit HTTP 200 und dem aktuellen Bild.

² Eigenes, kurzes Token je Kamera — es darf **nur** aufnehmen, nicht aufräumen
und nicht die Diagnose lesen.

## Sicherheit und Datenschutz

- Zugangsdaten stehen in `config/plugins/actikamera/cam.json` **und** in der
  Zweitschrift `config/plugins/actikamera.backup.json`, die ein Update
  überlebt; beide werden auf `chmod 600` gesetzt (nur für den Besitzer
  lesbar). Wer den LoxBerry weitergibt, denkt an beide Dateien.
- Das Passwort wird in der Oberfläche nie angezeigt und im Protokoll
  maskiert — seit 1.9.17 die Kennwörter **aller** Kameras sowie Aktions-
  und Stromtoken, vorher nur das der ersten Kamera
- Der RTSP-Weg (Bildquelle *Nur RTSP*) ist die eine Ausnahme: ffmpeg kennt
  für RTSP keine getrennte Anmeldung, das Kennwort steht deshalb in der
  Kommandozeile und ist unter `/proc` für jeden lokalen Benutzer lesbar.
  Die Oberfläche sagt das am Auswahlfeld; *Nur Kamerastrom* kommt ohne aus.
- `?diag=1` nennt Länge sowie erstes und letztes Zeichen des Passworts und ist
  deshalb seit 1.9.8 tokenpflichtig
- Die Formulare der Oberfläche tragen ein Merkmal gegen fremde Absender
- Die Deinstallation überschreibt und löscht die Zweitschrift mit den
  Zugangsdaten, räumt die Marke `data/plugins/actikamera.upgrade_laeuft`
  weg und leert seit 1.9.22 die zurückbehaltenen MQTT-Themen des Plugins;
  die Aufnahmen bleiben absichtlich stehen. Das Skript liegt seit 1.9.20
  zweimal byteweise gleich im Archiv: als `uninstall/uninstall` (der Ort, von
  dem gemessen ist, dass LoxBerry ihn ausführt) und weiterhin als
  `uninstall.sh` an der Wurzel
- Musste die Konfiguration aus der Zweitschrift geheilt werden, bleibt der
  verdrängte Stand als `config/plugins/actikamera/cam.json.kaputt` mit
  `chmod 600` liegen — er kann Zugangsdaten tragen. Der Installer räumt ihn
  beim nächsten Update und bei der Deinstallation mit dem Ordner ab
- Im Plugin sind **keine persönlichen Daten** enthalten
- Aufnahmen liegen lokal auf dem LoxBerry und werden nach der eingestellten
  Aufbewahrungszeit gelöscht

### Wo die Aufnahmen liegen

Unter `data/plugins/actikamera.archiv/` — also **neben** dem
Datenverzeichnis, nicht darin. Grund: der LoxBerry-Installer räumt
`data/plugins/actikamera/` vor jedem `postinstall.sh` vollständig ab; gemessen
mit einem Prüfstand, der genau diesen Schritt nachbildet, waren nach einem
Update sieben von sieben Aufnahmen weg. Am neuen Ort überstehen sie das Update,
so wie die Zweitschrift der Konfiguration es seit jeher tut. Ein eigener Ort
(USB-Platte, Netzlaufwerk) lässt sich in den Einstellungen eintragen.

> **Beim Update von 1.9.7 oder älter:** `preupgrade.sh` holt die Aufnahmen an
> den neuen Ort, bevor der Installer aufräumt. Ob LoxBerry dabei das
> `preupgrade.sh` der alten oder der neuen Fassung ausführt, ist nicht
> gemessen — läuft das alte, kennt es den neuen Ort noch nicht. Sicher ist der
> Handgriff vorher, auf dem LoxBerry:
>
>     mv /opt/loxberry/data/plugins/actikamera /opt/loxberry/data/plugins/actikamera.archiv

## Fassung 1.9.22 — Nachlese: Retain, Archiv, Wurzel, Zweitschrift (25.09.2026)

**Nicht mehr zurückbehalten: `OK`, `ERREICHBAR`, `FEHLER`, `PUSHAKTIV`, `PTEST`**
(je Kamera, `PTEST` nur einmal). `OK` heißt hier „Adresse eingerichtet" und
wird vom Plugin aus seiner Konfiguration gebildet, `ERREICHBAR` und `FEHLER`
aus dem Erfolg seiner eigenen Abfrage — keiner der drei Werte kommt von der
Kamera. `PUSHAKTIV` und `PTEST` werden allein durch die Uhr falsch. Stand ein
solcher Wert zurückbehalten im Broker, las Loxone nach einem Neustart von
Broker oder Gateway den letzten Stand eines Minutentakts, der vielleicht längst
steht. `ok` und jede andere Aussage des Plugins über sich selbst gehen deshalb
nie retained hinaus, ebenso nichts, was allein durch die Uhr veraltet. Zurückbehalten
bleiben die Zustände der Kamera und des Archivs: `BILDER`, `CLIPS`,
`ZEITRAFFER`, `PERSON`, `OBJEKTE`, `PUSH`, `letztes_bild`, `anlass`, `zeit`.

**Die Altwerte aus 1.9.19 bis 1.9.21 werden abgeräumt.** Der Minutentakt fragt
den Broker selbst (MQTT 3.1.1, mit `Brokeruser`/`Brokerpass` aus der
`general.json`, ohne Zusatzbibliothek), welche der alten Themen dort noch
stehen, und schickt für genau diese eine leere `retain`-Nachricht über den
UDP-Eingang des Gateways — unmittelbar vor dem gültigen Wert. Erst wenn der
Broker meldet, dass nichts mehr steht, legt er den Merker
`data/plugins/actikamera/retain_altlast_bestaetigt` (Kennung mit Präfix und
Themenliste); ein Senden allein zählt nicht, denn der UDP-Eingang verwirft
unter Last Datagramme, ohne dass der Absender es merkt. Eine abgewiesene
Anmeldung (CONNACK ≠ 0) oder ein abgelehnter Filter (SUBACK 0x80) heißt „nicht
zu fragen", nie „nichts da".

**Grenze:** Ist der Broker nicht zu fragen, gibt es keinen Beleg und damit
keinen Merker. Dann geht in **jedem** Minutentakt vor jedem der alten Themen
ein leeres `retain` hinaus, dazu der Wert selbst (bei einer Kamera zehn
Datagramme mehr je Minute),
und am Miniserver kann dabei kurz ein leerer Wert ankommen, unmittelbar gefolgt
vom gültigen. Das Protokoll sagt das einmal am Tag. Was stehen bleibt, lässt
sich mit `mosquitto_pub -r -n -t <thema>` von Hand löschen.

**Der volle Satz geht wieder hinaus** — einmal nach dem Update und nach jedem
Wechsel des Themenpräfixes, danach spätestens alle 30 Minuten. Bis 1.9.21
merkte sich der Doppelt-senden-Filter unter `/tmp/actikamera` nur Werte; ein
Wert, der sich nie änderte, ging nach einem Update nie wieder hinaus (am Gerät
10.09.2026 für 1.9.19 gemessen: 5 von 14 zurückbehaltenen Themen im Broker).
Ohne diesen Satz käme ein flüchtiges Thema wie `OK` nach einem Neustart des
Miniservers erst bei der nächsten Änderung wieder.

**Die Deinstallation leert die zurückbehaltenen Themen** des Plugins — für
alle vier Kameraplätze, auch bei ausgeschaltetem MQTT. Sie fragt vorher und
nach jeder Runde den Broker, schickt nur, was noch steht, höchstens drei
Runden, und sagt am Ende, ob der Broker leer ist oder welche Themen von Hand
zu löschen bleiben.

**Ein ausgepacktes Archiv wirkt nicht mehr auf die Anlage.** Die Pfade der
Anlage gelten nur, wenn das Plugin dort installiert liegt oder `LBHOMEDIR`
**und** `LBPPLUGINDIR` ausdrücklich gesetzt sind; sonst bleibt alles im
Archivordner. Bis 1.9.21 schrieb `bin/cam_cron.php` aus einem Archiv den
Herzschlag der Anlage und sendete deren MQTT-Werte, `cam.php` nahm das
Aktionstoken der Anlage an und legte `PTEST` in deren Zwischenspeicher, die
Oberfläche schrieb deren Konfiguration. `cam_cron.php` aus einem Archiv endet
jetzt mit Rückgabewert 1 und nennt die gefundene Installation.

**Keine Pfade ab `/` mehr.** Die Installationsskripte (`preupgrade.sh`,
`postinstall.sh`, `postupgrade.sh`, `uninstall/uninstall`) nehmen die Wurzel
aus dem fünften Argument oder `LBHOMEDIR`, sonst nur ein Verzeichnis mit
`config/system/general.json`; ohne Wurzel warnen sie und tun nichts. Bis 1.9.21
lagen dann alle Pfade ab `/`. Die Bibliothek erkennt die Wurzel ebenfalls nur
an `general.json`, die Sprachdateien kommen ohne Wurzel aus dem Plugin selbst
(nicht mehr aus `/templates/…` oder dem festen Heimpfad des Geräts), und
`htmlauth/index.php` und `bin/cam_cron.php` suchen die Bibliothek nur dann im
installierten Baum, wenn sie selbst dort liegen.

**Zweitschrift und Upgrade-Sicherung nach Inhalt.** Inhalt heißt: lesbares
JSON und für mindestens eine Kamera eine Adresse oder ein Aktionstoken. Die
Zweitschrift `actikamera.backup.json` wird nur noch eingespielt, wenn sie
Inhalt trägt und `cam.json` keinen; ein verdrängter Stand bleibt als
`cam.json.kaputt` (0600) liegen. Die Upgrade-Sicherung wird nur mit Inhalt
zurückgeholt und erst gelöscht, wenn sie byteweise in `cam.json` angekommen
ist — bis 1.9.21 genügte dafür irgendein Inhalt in `cam.json`, auch der
ältere aus der Zweitschrift. Meldungen wie „wiederhergestellt" oder
„zurückgeholt" stehen nur noch da, wenn es geschehen ist.

Der Reiter **Test** prüft neu, dass keine dieser Aussagen wieder retained
geriete.

Nachgestellt in WSL mit Attrappen für UDP-Eingang und Broker, PHP 7.4.33 und
8.4.24, nicht am Gerät (`Pruefung-ACTiKamera-1.9.22/`: 71 Fälle, vorher 49
rot, nachher 0; Eichung 20 Rückbauten, jeder an seinen Fällen rot).

## Fassung 1.9.21 — Schlusswort nach dem Update, Digest, Bildnamen (24.09.2026)

**Nach einem Update fordert die Installation nicht mehr zur Ersteinrichtung
auf.** Bis 1.9.20 endete `postinstall.sh` immer mit dem Rat, Adresse,
Benutzer und Passwort der Kamera einzutragen — auch wenn die Einstellungen
gerade übernommen worden waren. Jetzt steht dort „die Einstellungen der
Kamera sind übernommen", sobald für mindestens eine Kamera eine Adresse
eingetragen ist; die Anleitung erscheint nur noch bei der Erstinstallation,
wenn keine Kamera eingetragen ist, oder als Warnung aus `postupgrade.sh`,
wenn die Rückholung gescheitert ist.

**Digest-Anmeldung mit leerem `opaque=""`.** Ein leerer Wert in
Anführungszeichen kam bis 1.9.20 als NULL an (PHP 8: Warnung „Undefined
array key 3"), und `opaque` fehlte in der Antwort an die Kamera. Er wird
jetzt als leerer Wert übernommen und zurückgeschickt.

**Millisekunden im Bildnamen unabhängig von der Locale.** Der Name wurde
mit `%.3f` gebildet; unter einer Locale mit Dezimalkomma fielen die
Millisekunden auf `000`, und zwei Aufnahmen derselben Sekunde mit gleichem
Anlass überschrieben sich. Jetzt `%.3F`.

**Die Upgrade-Sicherung bleibt liegen, wenn die Rückholung scheitert.**
`postupgrade.sh` räumte sie ab, sobald `cam.json` nicht leer war — das traf
auch den Platzhalter `{}`, und nach einer gescheiterten Rückholung gab es
weder Konfiguration noch Sicherung. Jetzt wird sie nur gelöscht, wenn die
Konfiguration danach eine Adresse oder ein Aktionstoken trägt; sonst nennt
eine Warnung ihren Pfad.

Nachgestellt in WSL und mit PHP 7.4.33 und 8.4.24, nicht am Gerät
(`Pruefung-ACTiKamera-1.9.21/`).

## Fassung 1.9.20 — die Minute mitten im Update (17.09.2026)

Beim Update legt LoxBerry die Cron-Datei fast eine Minute vor
`postinstall.sh` an; der Minutentakt läuft in dieser Lücke schon mit den
neuen Dateien, aber ohne Konfiguration. Nachgestellt in WSL, nicht am Gerät.
Sieben Punkte sind behoben:

**Das Protokoll verliert beim Update keine Zeilen mehr.** `preupgrade.sh`
sicherte `cam.log`, `postupgrade.sh` kopierte es zurück — obwohl der
Installer den Protokollordner gar nicht abräumt. Die Kopie von vorher
überschrieb damit alles, was während des Updates geschrieben wurde
(gemessen: die Zeile „antwortet nicht" aus der Lücke war danach weg). Das
Protokoll wird jetzt weder gesichert noch zurückkopiert.

**Die Archiv-Bereinigung löscht nie mehr nach Vorgabewerten.** Fehlte die
Zweitschrift der Konfiguration, lief die tägliche Bereinigung in der Lücke
mit 90 Tagen Aufbewahrung statt mit dem eingestellten Wert (gemessen: eine
200 Tage alte Aufnahme bei eingestellten 3650 Tagen gelöscht) und galt für
den Tag als erledigt. Jetzt wird sie ausgelassen, solange `cam.json` fehlt,
leer ist oder nur `{}` enthält, und am selben Tag nachgeholt, sobald die
Konfiguration wieder da ist; das Protokoll sagt es einmal am Tag. Dasselbe
gilt nach einer Neuinstallation: bis die Einstellungen einmal gespeichert
sind, räumt das Plugin nichts auf — auch keine Aufnahmen, die eine frühere
Installation im Archiv zurückgelassen hat.

**Während der Aktualisierung ruhen Plugin-Seite und Minutentakt.**
`preupgrade.sh` legt als Erstes die Marke
`data/plugins/<ordner>.upgrade_laeuft` neben den Datenordner, `postupgrade.sh`
entfernt sie als Letztes, die Deinstallation räumt sie weg, und älter als eine
Stunde gilt sie nicht — eine abgebrochene Installation legt das Plugin nicht
für immer still. Solange sie gilt, zeigt die Plugin-Seite nur einen Hinweis
und speichert nichts, und der Minutentakt beendet sich sofort. Gemessen war
vorher: die in dieser Minute geöffnete Seite schrieb alle 87 Einstellungen
samt einem frischen Aktionstoken nach `cam.json` **und** in die Zweitschrift —
danach galt die Konfiguration als eingerichtet, und die Zweitschrift trug
Vorgabewerte mit einem Token, das in Loxone nirgends steht. Der Takt
überschrieb in derselben Minute den Betriebszustand im Archiv mit dem Ergebnis
eines Abrufs ohne Kameraadresse.

**Das bloße Öffnen der Plugin-Seite legt keine Aufbewahrungsgrenze mehr
fest.** Bisher schrieb die Seite beim ersten Aufruf die vollständige
Vorgabenliste — darunter 90 Tage Aufbewahrung — in `cam.json`. Wer das Plugin
neu installierte und ein Archiv einer früheren Installation liegen hatte,
verlor beim nächsten nächtlichen Lauf Aufnahmen, ohne je eine Grenze gewählt
zu haben. In `cam.json` landen beim Öffnen jetzt nur noch die beiden Token,
die das Plugin selbst würfelt; alles andere kommt erst beim ausdrücklichen
Speichern hinein. Die Token zählen dabei nicht als „eingerichtet". Solange
nichts gespeichert ist, sagt der Reiter *Aufnahmen* ausdrücklich, dass die
angezeigten Grenzen Vorschläge sind und nichts gelöscht wird.

**Die Selbstheilung entscheidet nach Inhalt, nicht nach Form.** Eine
`cam.json`, die zwar Inhalt hat, aber kein Aktionstoken — etwa nach einem
abgebrochenen Schreibvorgang —, wurde bisher nicht aus der Zweitschrift
geheilt: Die Seite würfelte ein neues Token und kopierte es über die
Zweitschrift, womit das alte Token endgültig weg war und jede Loxone-Adresse
ins Leere lief. Jetzt wird zuerst geheilt; der verdrängte Stand bleibt als
`cam.json.kaputt` mit den Rechten 0600 daneben liegen.

**Die Zweitschrift wird nie durch einen Stand ohne Aktionstoken ersetzt.**
Sie ist der einzige Rückweg nach einem Update; ohne Token ist sie wertlos.
Trägt der zu speichernde Stand kein Token, die Zweitschrift aber eines, bleibt
sie unangetastet, und das Protokoll sagt es.

**Die Deinstallation liegt jetzt auch dort, wo LoxBerry sie wirklich sucht.**
Das Skript, das die Zweitschrift mit Benutzername und Kamerapasswort
überschreibt und löscht, lag nur als `uninstall.sh` an der Archivwurzel.
Gemessen ist aber (`Regeln/06`, Abschnitt *Deinstallation*, am Gerät am
17.09.2026 in `sbin/plugininstall.pl` nachgesehen): der Installer kopiert
`uninstall/uninstall` nach `data/system/uninstall/<ordner>` und ruft **diese**
Datei auf. Von 306 Plugin-Ordnern im Arbeitsordner führen 304 genau diese
Form; die sechs Ausnahmen waren alle Fassungen dieser Linie. Ob die
Wurzeldatei überhaupt je ausgeführt wurde, ist **nicht gemessen** — sie bleibt
deshalb liegen, und der gemessene Ort kommt daneben. Beide Dateien sind
byteweise gleich, jeder Schritt hängt an einem `[ -f ]`, ein zweiter Lauf
findet nichts mehr und sagt nichts.

## Fassung 1.9.19 — am Gerät nachgemessen (06.09.2026)

Seit dem 05.09.2026 lässt sich am laufenden LoxBerry messen statt zu vermuten.
Acht Punkte kamen dabei heraus; sieben sind hier behoben, der achte ist eine
Einstellung.

**Das aktuelle Bild hängt jetzt am Stromkennwort.** Gemessen an der Anlage:
`cam.php?bild=…` antwortete ohne Token mit **HTTP 403**, `cam.php?letztes=1`
dagegen mit **HTTP 200** und 704 137 Byte — dem aktuellen Bild der Haustür, von
jedem Gerät im Heimnetz. Der Grund: das Tor in `cam.php` prüfte `bild`, `serie`
und `zeitraffer`, `letztes` stand dahinter. Jetzt steht es mit darin. **Das gilt
nur, wenn ein Stromkennwort gesetzt ist** — ohne eines ändert sich nichts.

**Der Webordner lässt sich nicht mehr auflisten.** Ein Aufruf von
`/plugins/actikamera/` lieferte eine „Index of“-Liste, weil LoxBerry den
`html`-Baum mit `Options +Indexes` fährt. Ein `index.php`, das mit 403
antwortet, beendet das — dasselbe Muster wie im Intercom-Plugin.

**Das letzte Bild liegt jetzt maßgeblich im Archiv**, das ein Update überlebt;
die feste Adresse im Webordner wird zusätzlich bedient. Der neue Schalter
*Letztes Bild unter der festen Adresse anbieten* steht ab Werk **an** — für jede
bestehende Anlage ändert sich also nichts. Wer ihn herausnimmt, bekommt das
Bild nur noch über `cam.php?letztes=1`, eine dort liegengebliebene Datei wird
einmal entfernt und gemeldet. Der Reiter *Test* sagt, wenn ein Stromkennwort
gesetzt ist und die feste Adresse trotzdem offensteht.

**MQTT: Zustände gehen jetzt zurückbehalten (retained) hinaus.** Dass der
UDP-Eingang des MQTT-Gateways `retain <thema> <wert>` genauso versteht wie
`publish`, ist am laufenden Gateway belegt — an der Quelle
(`mqttgateway.pl`, `sub udpin`) und mit einer Probe, bei der die
`publish`-Zeile nicht stehenblieb und die `retain`-Zeile schon. Damit steht
der Zustand der Kamera nach einem Neustart des Brokers sofort wieder an.

**Das Lebenszeichen geht nie retained hinaus** und trägt nur noch `online` und
`ts`. Bis 1.9.18 hingen `erreichbar`, `fehler` und `name` in Kleinschreibung
mit darin — drei Themen, die in keiner Themenliste standen. Dieselben Werte
liefert der Zustandsweg unter ihren dokumentierten Namen `ERREICHBAR` und
`FEHLER`.

**`ALTER` geht nicht mehr über MQTT.** Der Wert ändert sich jede Minute und kam
damit an dem Filter vorbei, der nur Änderungen sendet (gemessen: `acti/ALTER`
47, eine Minute später 48). Wie alt die letzte Aufnahme ist, sagt das
zurückbehaltene Thema `zeit` — ein Zeitstempel, aus dem der Miniserver das
Alter selbst rechnet. **Wer in Loxone einen virtuellen Eingang auf `acti/ALTER`
gebaut hat, stellt ihn auf `acti/zeit` um.** In der Antwortzeile des virtuellen
HTTP-Eingangs steht `ALTER` unverändert.

**Die Themenliste im Reiter *Einbindung in Loxone* ist vollständig** — sie
kommt aus einer Funktion, nennt zu jedem Thema, ob es retained ist, und der
Reiter *Test* hält sie gegen die `cam_mqtt()`-Aufrufe im Quelltext.

**Nicht geändert, weil es eine Einstellung ist:** Auf der gemessenen Anlage
liegen 2 804 Bilder mit 1,8 GB im Archiv, weil die Kamera sich alle 20–25
Sekunden selbst auslöst und die **Mindestpause auf dem Vorgabewert 0** steht.
Wer das nicht will, setzt sie auf den Auslösetakt der Kamera und dazu eine
Größengrenze (`keep_mb`).

## Fassung 1.9.17 — die Durchsicht vom 04.09.2026

Eine vollständige Gegenlesung der Fassung 1.9.16 hat 36 Punkte ergeben; 35
davon sind hier behoben. Was ein Anwender merkt:

**Die Sicherung ist wieder ein Paar.** „Einstellungen sichern" schrieb alle
86 Schlüssel, „zurückspielen" kannte aber nur 22 — die eigene Datei wurde
mit 64 Beanstandungen abgelehnt, der Umzug auf einen zweiten LoxBerry ging
auf keiner Anlage. Schlimmer: eine Datei mit wenigen Schlüsseln wurde
**angenommen** und löschte alles Übrige — Aktionstoken, Kameraadresse,
Benutzer, Kennwort und alle vier Auslöse-Token —, während die Oberfläche
grün „übernommen" meldete. Seit 1.9.17 wird gegen die vollständige
Schlüsselliste geprüft, jeder **Wert** gegen seine Grenzen, und was nicht in
der Datei steht, behält seinen bisherigen Wert.

**`cam_cron.php` liegt jetzt unter `bin/`.** Bis 1.9.16 lag der Minutentakt
im unangemeldeten Webordner und prüfte kein Token: ein beliebiges Gerät im
Heimnetz konnte den Herzschlag frisch halten — genau den Wert, an dem Loxone
einen toten Cron erkennt — und Aufnahme wie Archiv-Bereinigung auslösen.
Der Cron-Eintrag zeigt auf den neuen Ort; wer ihn von Hand angepasst hatte,
prüft ihn nach dem Update.

**Das Update verliert `letztesbild.jpg` nicht mehr.** Der Installer räumt
beide `webfrontend/`-Ordner ab; die Datei unter der festen Adresse war nach
jedem Update weg, während `letztesbild.json` im Archiv weiter ein Bild
meldete und `ALTER` in Loxone auf eine Datei zeigte, die es nicht gab.
`preupgrade.sh` sichert sie jetzt zusammen mit `zeitraffer.mp4` in einen
Geschwisterordner neben dem Datenordner, `postupgrade.sh` holt sie zurück.

**Weiter behoben:** `cam.php` schreibt jetzt auf jedem Weg eine
Protokollzeile mit der Adresse des Anrufers (bis 1.9.16 auf keinem — vier
abgewiesene Aufrufe hinterließen nicht einmal eine Datei); Warn- und
Hinweiskästen haben ihre CSS-Klassen zurück (die Warnung, dass die
Sicherungsdatei ein Geheimnis trägt, stand als nackter Fließtext da);
Rückweisungen erscheinen rot statt grün und zeigen keine HTML-Tags mehr als
Text; die englische Oberfläche nennt die Parameter, die es wirklich gibt
(`anlass=` statt `trigger=`); `?kamera=2` schreibt den Zustand nicht mehr in
die Felder der ersten Kamera; ein Aufruf ohne Token legt keine Konfiguration
mehr an; ein Uhrensprung sperrt die Mindestpause höchstens noch für ihre
eigene Dauer statt für Stunden; die eingestellte Zeitgrenze gilt jetzt auch
für Erreichbarkeitsprüfung und Diagnose; die Webhooks melden Erfolg nur noch
nach einem gelesenen Rückgabewert; die Selbstprüfung im Reiter *Test*
vergleicht die Reiternamen statt sie nur zu zählen und findet damit einen
umbenannten Bereich; `cam_stream.php` kennt `&kamera=`.

**Nicht angefasst:** Retain für die MQTT-Zustandsthemen. Die Voraussetzung ist
inzwischen geklärt — der UDP-Eingang des Gateways kennt `retain my/topic data`
als eine seiner dokumentierten Zeilenformen (`sbin/mqttgateway.pl`, Zeile 227,
am 04.09.2026 am Quelltext gemessen), und ein unbekannter Befehl fiele auf
`publish` zurück. Der Umbau selbst steht noch aus und kommt mit einer eigenen
Fassung.

## Fassung 1.9.15 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in
`webfrontend/html/cam_lib.php:477`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

## Lizenz

MIT — siehe [LICENSE](LICENSE).

## Fehlerbehebung: „ERROR: not authorized"

Die ACTi-CGI **dekodiert ihre Parameter nicht prozentweise**. Ein vollständiges
`rawurlencode()` des Passworts macht daraus einen anderen String, und die Kamera
lehnt ab — auch wenn dieselben Zugangsdaten im Browser funktionieren. Seit
v1.1.0 werden nur noch die Zeichen ersetzt, die die Abfragezeichenkette
zerreißen würden (`% & = # +` und Leerzeichen).

Zweite häufige Ursache: Manche Modelle verlangen HTTP-Basic oder Digest und
lehnen ab, wenn zusätzlich `USER`/`PWD` in der URL stehen. Deshalb gibt es in
den Einstellungen das **Anmeldeverfahren**; auf „Automatisch" probiert das
Plugin der Reihe nach URL-Parameter, Basic und Digest durch und merkt sich den
Weg, der funktioniert hat. Der Knopf **Verbindung prüfen** geht seit 1.9.8
denselben Weg wie der Ernstfall und nennt die Kombination, die getragen hat.
