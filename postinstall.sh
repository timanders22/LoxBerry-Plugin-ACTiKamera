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
# Die Unterordner des Archivs gleich hier anlegen, als loxberry. Entstehen sie
# erst im Minutentakt, gehoeren sie root, und eine Aufnahme aus der Oberflaeche
# oder aus e.php kann nicht mehr hineinschreiben.
for I in "" 2 3 4; do
    mkdir -p "$BASE/data/plugins/$PFOLDER.archiv/bilder$I" \
             "$BASE/data/plugins/$PFOLDER.archiv/clips$I" \
             "$BASE/data/plugins/$PFOLDER.archiv/timelapse$I" 2>/dev/null
done
CF="$BASE/config/plugins/$PFOLDER/cam.json"
if [ ! -f "$CF" ]; then
    echo '{}' > "$CF"
fi
# In dieser Datei stehen Benutzername und Kennwort der Kamera. 0600 gehoert
# hierhin, nicht erst an das naechste Speichern aus der Oberflaeche.
chmod 0600 "$CF" 2>/dev/null
BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF"
        chmod 0600 "$CF" 2>/dev/null
        echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi
echo "<OK> Installation abgeschlossen. Bitte die Plugin-Oberflaeche oeffnen und Adresse, Benutzer und Passwort der Kamera eintragen."
exit 0
