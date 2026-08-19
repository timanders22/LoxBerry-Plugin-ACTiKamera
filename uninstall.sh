#!/bin/bash
# ACTi Kamera - uninstall
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

BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -f "$BK" ]; then
    : > "$BK"
    rm -f "$BK"
    echo "<OK> Zweitschrift mit den Zugangsdaten entfernt."
fi

# Die Aufnahmen bleiben absichtlich stehen. Sie sind der einzige Teil, den der
# Anwender nicht wiederherstellen kann, und eine Deinstallation ist kein
# Auftrag, Bildmaterial zu vernichten.
DATA="$BASE/data/plugins/$PFOLDER.archiv"
if [ -d "$DATA" ]; then
    echo "<INFO> Die Aufnahmen unter $DATA bleiben erhalten und koennen von Hand geloescht werden."
fi
exit 0
