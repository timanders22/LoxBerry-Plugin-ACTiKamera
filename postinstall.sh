#!/bin/bash
# ACTi Kamera - postinstall
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-actikamera}"
BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/data/plugins/$PFOLDER" 2>/dev/null
# Das Archiv liegt NEBEN dem Datenverzeichnis, damit es das naechste
# Update ueberlebt - der Installer raeumt data/plugins/$PFOLDER/ ab.
mkdir -p "$BASE/data/plugins/$PFOLDER.archiv" 2>/dev/null
if [ ! -f "$BASE/config/plugins/$PFOLDER/cam.json" ]; then
    echo '{}' > "$BASE/config/plugins/$PFOLDER/cam.json"
fi
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$BASE/config/plugins/$PFOLDER/cam.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF"
        echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi
echo "<OK> Installation abgeschlossen. Bitte die Plugin-Oberflaeche oeffnen und Adresse, Benutzer und Passwort der Kamera eintragen."
exit 0
