#!/bin/bash
# ACTi Kamera - postupgrade
#
# Holt zurueck, was preupgrade.sh in den Geschwisterordner gelegt hat. Pfad
# und Dateinamen sind hier zeichengleich mit preupgrade.sh - eine Datei, die
# gesichert und nie zurueckgeholt wird, faellt sonst niemandem auf.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-actikamera}"
BASE="${ARGV5:-$LBHOMEDIR}"

SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" 2>/dev/null

CF="$BASE/config/plugins/$PFOLDER/cam.json"
if [ -s "$SICHER/cam.json" ]; then
    cp -p "$SICHER/cam.json" "$CF" && chmod 0600 "$CF" \
        && echo "<OK> Konfiguration aus der Upgrade-Sicherung zurueckgeholt." \
        || echo "<WARNING> Die gesicherte Konfiguration liess sich nicht zurueckholen."
fi
[ -s "$SICHER/cam.log" ] && cp -p "$SICHER/cam.log" "$BASE/log/plugins/$PFOLDER/cam.log" 2>/dev/null

# Das letzte Bild und der Zeitraffer liegen im Webordner, den der Installer
# abraeumt. Ohne diesen Schritt zeigt die Kamera-Kachel in Loxone bis zur
# naechsten Aufnahme 404, waehrend ALTER weiter ein Bild meldet.
WEB="$BASE/webfrontend/html/plugins/$PFOLDER"
if [ -d "$WEB" ]; then
    for E in "$SICHER"/letztesbild*.jpg "$SICHER"/zeitraffer*.mp4; do
        [ -e "$E" ] && cp -p "$E" "$WEB/" 2>/dev/null
    done
fi

# Zweitschrift als zweiter, unabhaengiger Weg: sie traegt dieselbe
# Konfiguration und ueberlebt das Abraeumen, weil sie NEBEN dem Ordner liegt.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF" && chmod 0600 "$CF"
        echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt."
    fi
fi

# Aufgeraeumt wird erst, wenn wirklich etwas zurueckgeholt wurde.
if [ -s "$CF" ]; then
    rm -rf "$SICHER" 2>/dev/null
fi
exit 0
