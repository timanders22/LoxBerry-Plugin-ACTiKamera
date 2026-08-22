# LoxBerry-Plugin: ACTi Kamera

Holt Bilder von einer **ACTi-Netzwerkkamera** (E-Serie und alle Modelle mit der
klassischen CGI-Schnittstelle) und stellt sie Loxone bereit — **ohne dass
Benutzername und Passwort in der Loxone-Projektdatei stehen müssen**.

Genau das ist der eigentliche Zweck: Bindet man eine Kamera direkt in Loxone ein,
landet die Adresse samt `USER=…&PWD=…` im Klartext in der Projektdatei — und damit
in jedem Backup und jeder Kopie, die man weitergibt. Mit diesem Plugin ruft Loxone
nur noch `cam.php?foto=1&token=…` auf; die Zugangsdaten bleiben auf dem LoxBerry.

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, PHP 7.4 und 8.x).

## Funktionen

- **Schnappschuss auf Zuruf** — ausgelöst von Loxone: Klingel, Türtaster,
  Bewegungsmelder; der Anlass wird mitgespeichert
- **Bildserie** („Clip") über mehrere Sekunden, ohne ffmpeg oder Zusatzpakete —
  sie setzt danach dieselben Werte wie ein Einzelbild, also auch `PUSHAKTIV`
- **Letztes Bild unter fester Adresse** (`letztesbild.jpg`) für Kamera-Kachel,
  Webseiten-Baustein oder Push-Anhang — ohne Zugangsdaten in der URL
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
| *Ereignis → Ereignis-Server → HTTP-Server 1* | nur der Rechner: `192.168.178.14` | 64 Zeichen |
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
| `…/cam.php?letztes=1` | nein | letztes Bild als JPEG |
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
hinterlegt, verlangen diese Aufrufe zusätzlich `&t=<Kennwort>`.

² Eigenes, kurzes Token je Kamera — es darf **nur** aufnehmen, nicht aufräumen
und nicht die Diagnose lesen.

## Sicherheit und Datenschutz

- Zugangsdaten stehen ausschließlich in `config/plugins/actikamera/cam.json`;
  die Datei wird auf `chmod 600` gesetzt (nur für den Besitzer lesbar)
- Das Passwort wird in der Oberfläche nie angezeigt und im Protokoll maskiert
- `?diag=1` nennt Länge sowie erstes und letztes Zeichen des Passworts und ist
  deshalb seit 1.9.8 tokenpflichtig
- Die Formulare der Oberfläche tragen ein Merkmal gegen fremde Absender
- `uninstall.sh` überschreibt und löscht die Zweitschrift mit den Zugangsdaten;
  die Aufnahmen bleiben absichtlich stehen
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
