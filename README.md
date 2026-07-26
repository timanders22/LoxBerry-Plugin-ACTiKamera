# LoxBerry-Plugin: ACTi Kamera

Holt Bilder von einer **ACTi-Netzwerkkamera** (E-Serie und alle Modelle mit der
klassischen CGI-Schnittstelle) und stellt sie Loxone bereit — **ohne dass
Benutzername und Passwort in der Loxone-Projektdatei stehen müssen**.

Genau das ist der eigentliche Zweck: Bindet man eine Kamera direkt in Loxone ein,
landet die Adresse samt `USER=…&PWD=…` im Klartext in der Projektdatei — und damit
in jedem Backup und jeder Kopie, die man weitergibt. Mit diesem Plugin ruft Loxone
nur noch `cam.php?foto=1` auf; die Zugangsdaten bleiben auf dem LoxBerry.

Kompatibel mit LoxBerry 3.x und **LoxBerry 4** (reines PHP, PHP 7.4 und 8.x).

## Funktionen

- **Schnappschuss auf Zuruf** — ausgelöst von Loxone: Klingel, Türtaster,
  Bewegungsmelder; der Anlass wird mitgespeichert
- **Bildserie** („Clip") über mehrere Sekunden, ohne ffmpeg oder Zusatzpakete
- **Letztes Bild unter fester Adresse** (`letztesbild.jpg`) für Kamera-Kachel,
  Webseiten-Baustein oder Push-Anhang — ohne Zugangsdaten in der URL
- **Push-Auslöser für Loxone**: `PUSHAKTIV=1` für ein einstellbares Zeitfenster
  nach jeder Aufnahme
- **Zeitraffer**: täglich zur eingestellten Uhrzeit ein Bild in den Unterordner
  `timelapse` (Dateiname = Datum); ist `ffmpeg` vorhanden, wird daraus
  `zeitraffer.mp4` neu erzeugt
- **Archiv-Bereinigung** täglich um 03:35 Uhr, nach **Alter und Anzahl**
  (jeweils 0 = unbegrenzt) über Bilder, Bildserien und Zeitraffer — die
  neuesten Dateien bleiben erhalten
- **KI-Objekterkennung** (optional) über CodeProject.AI oder DeepStack:
  erkannte Objekte erscheinen im JSON, in den Webhooks, per MQTT und als
  `ERKANNT=` in der Loxone-Ausgabe; `PERSON=1` ist der fertige Schalter
- **Webhooks**: Webhook 1 als POST mit JSON, Webhook 2 als GET mit Parametern
- **MQTT** über das LoxBerry MQTT Gateway, **JSON** für Drittsoftware
- Reiter: Einstellungen, Einbindung in Loxone (Schritt-für-Schritt inkl.
  kompletter Baustein-Liste), Aufnahmen, Test, Protokoll

## Endpunkte

| Aufruf | Zweck |
|---|---|
| `/plugins/actikamera/cam.php` | Loxone-Zeile `ACTI;OK=..;ALTER=..;PUSHAKTIV=..;…` |
| `/plugins/actikamera/cam.php?foto=1&anlass=klingel` | Bild aufnehmen |
| `/plugins/actikamera/cam.php?clip=1&anlass=klingel` | Bildserie aufnehmen |
| `/plugins/actikamera/cam.php?letztes=1` | letztes Bild als JPEG |
| `/plugins/actikamera/cam.php?json=1` | Zustand als JSON |
| `/plugins/actikamera/cam.php?test=1` | Verbindungstest im Klartext |
| `/plugins/actikamera/cam.php?timelapse=1` | Zeitrafferbild jetzt aufnehmen |
| `/plugins/actikamera/cam.php?cleanup=1` | Archiv jetzt aufräumen |
| `/plugins/actikamera/cam.php?ptest=1` | Test-Pushnachricht auslösen |

## Sicherheit und Datenschutz

- Zugangsdaten stehen ausschließlich in `config/plugins/actikamera/cam.json`;
  die Datei wird auf `chmod 600` gesetzt (nur für den Besitzer lesbar)
- Das Passwort wird in der Oberfläche nie angezeigt und im Protokoll maskiert
- Im Plugin sind **keine persönlichen Daten** enthalten
- Aufnahmen liegen lokal auf dem LoxBerry und werden nach der eingestellten
  Aufbewahrungszeit gelöscht

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
Weg, der funktioniert hat.
