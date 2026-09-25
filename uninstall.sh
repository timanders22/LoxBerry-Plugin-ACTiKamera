#!/bin/bash
# ACTi Kamera - Deinstallation
#
# DIESE DATEI LIEGT ZWEIMAL IM ARCHIV, BYTEWEISE GLEICH:
#   uninstall.sh          - der Ort, den diese Linie seit jeher benutzt
#   uninstall/uninstall   - der Ort, von dem gemessen ist, dass LoxBerry ihn
#                           ausfuehrt (Regeln/06, Abschnitt "Deinstallation":
#                           plugininstall.pl kopiert ihn nach
#                           data/system/uninstall/<ordner> und ruft ihn mit
#                           "$tempfile" "$pname" "$pfolder" "$pversion"
#                           "$lbhomedir" "$tempfolder" auf - am Geraet
#                           nachgesehen am 17.09.2026, LoxBerry 4.0.0.15).
# Am 17.09.2026 ueber den Arbeitsordner gezaehlt: 304 Plugin-Ordner fuehren
# uninstall/uninstall, 6 nur uninstall.sh - und alle sechs sind Fassungen
# DIESER Linie. Ob LoxBerry die Wurzeldatei ueberhaupt ausfuehrt, ist NICHT
# gemessen; deshalb liegt sie weiter da, und der gemessene Ort kommt dazu.
# Beide Laeufe sind vertraeglich: jeder Schritt haengt an einem [ -f ], der
# zweite Lauf findet nichts mehr und sagt nichts.
# Der Argumentplatz ist an beiden Orten derselbe ($3 Ordnername, $5 Wurzel).
#
# LoxBerry entfernt bei der Deinstallation nur die VERZEICHNISSE des Plugins.
# Die Zweitschrift liegt eine Ebene darueber - genau deshalb ueberlebt sie ein
# Update - und bliebe sonst liegen. Sie enthaelt Benutzername und
# Klartextpasswort der Kamera, gegebenenfalls dazu die vollstaendige
# Schnappschuss-URL mit PWD=.
#
# Ueberschreiben vor dem Loeschen: ein blankes rm laesst den Inhalt auf der
# Karte stehen.
#
# Angefasst wird ausschliesslich die eigene Datei. Ein pauschales rm -rf auf
# das Konfigurationsverzeichnis traefe alle Plugins.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-actikamera}"
BASE="${ARGV5:-$LBHOMEDIR}"
# Die LoxBerry-Wurzel. Der Installer uebergibt sie als fuenftes Argument;
# $LBHOMEDIR gilt nur mit config/plugins UND data/plugins darunter. Sonst wird
# vom eigenen Ablageort aufwaerts gesucht, und Wurzel ist nur, was
# config/plugins, data/plugins UND config/system/general.json traegt
# (Regeln/06). Ohne Wurzel wird gewarnt und nichts getan.
#
# Bis 1.9.21 stand hier nur BASE="${ARGV5:-$LBHOMEDIR}": fehlten beide, lagen
# alle Pfade ab / - in einem Wurzelbaum mit Schreibrecht legte preupgrade.sh
# /data/plugins/<ordner>.upgrade_laeuft an, postinstall.sh
# /data/plugins/<ordner>.archiv, postupgrade.sh /log/plugins/<ordner>, und die
# Deinstallation loeschte /config/plugins/<ordner>.backup.json (in WSL
# gemessen, Pruefung-ACTiKamera-1.9.22, Faelle W1-W5).
ac_wurzel_suchen() {
    ac_v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd)
    ac_i=0
    while [ -n "$ac_v" ] && [ "$ac_v" != "/" ] && [ $ac_i -lt 8 ]; do
        if [ -d "$ac_v/config/plugins" ] && [ -d "$ac_v/data/plugins" ] \
           && [ -f "$ac_v/config/system/general.json" ]; then
            echo "$ac_v"; return 0
        fi
        ac_v=$(dirname "$ac_v"); ac_i=$((ac_i + 1))
    done
    return 1
}
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    BASE=$(ac_wurzel_suchen) || BASE=""
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Es wurde kein LoxBerry-Wurzelverzeichnis gefunden: weder als"
    echo "<WARNING> fuenftes Argument noch in \$LBHOMEDIR, und oberhalb dieses Skripts"
    echo "<WARNING> traegt kein Verzeichnis config/plugins, data/plugins und"
    echo "<WARNING> config/system/general.json. Es wurde nichts entfernt."
    echo "<WARNING> Liegen bleiben kann config/plugins/$PFOLDER.backup.json mit den Zugangsdaten der Kamera."
    exit 1
fi

# Die zurueckbehaltenen MQTT-Themen der Linie (Regeln/07, Abschnitt 3: die
# Deinstallation raeumt sie ab). Bis 1.9.21 blieben BILDER, CLIPS, PERSON,
# zeit und die uebrigen Zustaende - und OK, ERREICHBAR, FEHLER, PUSHAKTIV,
# PTEST aus 1.9.19 bis 1.9.21 - nach dem Entfernen fuer immer im Broker und
# kamen nach jedem Neustart von Broker oder Gateway wieder am Miniserver an
# (Faelle U1-U6). Geloescht wird ueber den UDP-Eingang des Gateways, der
# nichts bestaetigt; cam_mqtt_leeren() fragt deshalb vorher und nach jeder
# Runde den Broker (hoechstens drei Runden). VOR dem Loeschen der Ordner,
# denn Praefix und Port stehen in der Konfiguration.
LAUF="$BASE/bin/plugins/$PFOLDER/cam_cron.php"
if [ -f "$LAUF" ] && command -v php >/dev/null 2>&1; then
    LBHOMEDIR="$BASE" LBPPLUGINDIR="$PFOLDER" php "$LAUF" --mqtt-leeren 2>&1
else
    echo "<INFO> cam_cron.php oder php fehlt - zurueckbehaltene MQTT-Themen wurden nicht geleert."
fi

BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -f "$BK" ]; then
    : > "$BK"
    rm -f "$BK"
    echo "<OK> Zweitschrift mit den Zugangsdaten entfernt."
fi

# Die Marke "Aktualisierung laeuft" aus preupgrade.sh liegt NEBEN dem
# Datenordner und uebersteht deshalb auch das Deinstallieren. Bliebe sie nach
# einer abgebrochenen Aktualisierung liegen, speicherte eine Neuinstallation
# innerhalb der naechsten Stunde nichts und der Minutentakt liefe nicht.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    rm -f "$MARKE" 2>/dev/null
    if [ -e "$MARKE" ]; then
        echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen."
    else
        echo "<OK> Die Marke der Aktualisierung entfernt."
    fi
fi

# Die Aufnahmen bleiben absichtlich stehen. Sie sind der einzige Teil, den der
# Anwender nicht wiederherstellen kann, und eine Deinstallation ist kein
# Auftrag, Bildmaterial zu vernichten.
DATA="$BASE/data/plugins/$PFOLDER.archiv"
if [ -d "$DATA" ]; then
    echo "<INFO> Die Aufnahmen unter $DATA bleiben erhalten und koennen von Hand geloescht werden."
fi
exit 0
