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
# Die Erstanleitung nur, wenn keine eingerichtete Konfiguration vorliegt.
# postinstall.sh laeuft auch bei jedem Upgrade (Regeln/06); danach war der
# Rat, die Zugangsdaten einzutragen, falsch und legte nahe, sie seien weg.
# "Eingerichtet" heisst: fuer mindestens eine Kamera steht eine Adresse in
# der Datei (host, host2 bis host4 nicht leer). Das Aktionstoken zaehlt
# nicht - es entsteht beim ersten Oeffnen der Oberflaeche von selbst
# (cam_selbsterzeugte_schluessel() in cam_lib.php).
ac_hat_adresse() {
    [ -s "$1" ] && grep -Eq '"host[2-4]?"[[:space:]]*:[[:space:]]*"[^"]' "$1" 2>/dev/null
}
if ac_hat_adresse "$CF"; then
    echo "<OK> Installation abgeschlossen, die Einstellungen der Kamera sind uebernommen. Es ist nichts weiter zu tun."
elif ac_hat_adresse "$BASE/data/plugins/$PFOLDER.upgrade_sicherung/cam.json"; then
    # Ohne Zweitschrift holt erst postupgrade.sh die Konfiguration zurueck
    # und meldet dort, ob es gelang.
    echo "<OK> Installation abgeschlossen. Die Einstellungen der Kamera holt postupgrade.sh gleich aus der Upgrade-Sicherung zurueck."
else
    echo "<OK> Installation abgeschlossen. Bitte die Plugin-Oberflaeche oeffnen und Adresse, Benutzer und Passwort der Kamera eintragen."
fi
exit 0
