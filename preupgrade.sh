#!/bin/bash
ARGV1=$1; ARGV3=$3; ARGV5=$5
PFOLDER="${ARGV3:-actikamera}"; BASE="${ARGV5:-$LBHOMEDIR}"
mkdir -p "$ARGV1" 2>/dev/null
cp -p "$BASE/config/plugins/$PFOLDER/cam.json" "$ARGV1/cam.json" 2>/dev/null
cp -p "$BASE/log/plugins/$PFOLDER/cam.log" "$ARGV1/cam.log" 2>/dev/null

# Aufnahmen aus dem Verzeichnis holen, das der Installer gleich abraeumt.
# Dies ist der EINZIGE Zeitpunkt, zu dem sie noch da sind: postinstall.sh
# laeuft erst danach, und dann ist data/plugins/<ordner>/ leer.
ALT="$BASE/data/plugins/$PFOLDER"
NEU="$BASE/data/plugins/$PFOLDER.archiv"
if [ -d "$ALT" ] && [ ! -d "$NEU" ]; then
    mkdir -p "$NEU" 2>/dev/null
    # Verschieben, nicht kopieren: eine halb kopierte Kopie waere schlimmer
    # als keine, und Platz fuer zwei Archive hat eine SD-Karte selten.
    for E in bilder clips timelapse letztesbild.json betrieb.json; do
        [ -e "$ALT/$E" ] && mv "$ALT/$E" "$NEU/" 2>/dev/null
    done
fi
exit 0
