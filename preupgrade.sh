#!/bin/bash
# ACTi Kamera - preupgrade
#
# $1 IST KEIN PFAD. Es ist eine zehnstellige Zufallskennung aus &generate(10);
# der Arbeitsordner steht im SECHSTEN Argument. Bis 1.9.16 stand hier
#   mkdir -p "$ARGV1"; cp ... "$ARGV1/cam.json"
# also ein Verzeichnis, das es so nicht gibt - und weil jeder Schritt mit
# 2>/dev/null stumm war, waere ein Ausfall niemandem aufgefallen. Getragen hat
# die Konfiguration allein die Zweitschrift <ordner>.backup.json.
#
# Gesichert wird deshalb in einen GESCHWISTERORDNER neben dem Datenordner:
# der Installer loescht data/plugins/<ordner>/ ohne jede Bedingung, aber
# "rm -rf .../<ordner>/" trifft "<ordner>.upgrade_sicherung" nicht. Der Punkt
# im Namen ist der ganze Unterschied.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-actikamera}"
BASE="${ARGV5:-$LBHOMEDIR}"

SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
mkdir -p "$SICHER"
chmod 0700 "$SICHER" 2>/dev/null

# 1. Konfiguration und Protokoll
if [ -f "$BASE/config/plugins/$PFOLDER/cam.json" ]; then
    cp -p "$BASE/config/plugins/$PFOLDER/cam.json" "$SICHER/cam.json" \
        && chmod 0600 "$SICHER/cam.json" \
        || echo "<WARNING> Die Konfiguration konnte nicht gesichert werden."
fi
if [ -f "$BASE/log/plugins/$PFOLDER/cam.log" ]; then
    cp -p "$BASE/log/plugins/$PFOLDER/cam.log" "$SICHER/cam.log" 2>/dev/null
fi

# 2. Was im Webordner liegt. Der Installer raeumt BEIDE webfrontend-Ordner ab;
#    bis 1.9.16 war letztesbild.jpg nach jedem Update weg, waehrend
#    letztesbild.json im Archiv ueberlebte und weiter ein Bild meldete -
#    ALTER in Loxone zeigte auf eine Datei, die es nicht mehr gab.
WEB="$BASE/webfrontend/html/plugins/$PFOLDER"
if [ -d "$WEB" ]; then
    for E in "$WEB"/letztesbild*.jpg "$WEB"/zeitraffer*.mp4; do
        [ -e "$E" ] && cp -p "$E" "$SICHER/" 2>/dev/null
    done
fi

# 3. Aufnahmen aus dem Verzeichnis holen, das der Installer gleich abraeumt.
#    Dies ist der EINZIGE Zeitpunkt, zu dem sie noch da sind: postinstall.sh
#    laeuft erst danach, und dann ist data/plugins/<ordner>/ leer.
ALT="$BASE/data/plugins/$PFOLDER"
NEU="$BASE/data/plugins/$PFOLDER.archiv"
if [ -d "$ALT" ]; then
    mkdir -p "$NEU" 2>/dev/null
    # Verschieben, nicht kopieren: eine halb kopierte Kopie waere schlimmer
    # als keine, und Platz fuer zwei Archive hat eine SD-Karte selten.
    # Alle vier Kennziffern - bis 1.9.16 nannte die Liste nur Kamera 1, die
    # Aufnahmen der Kameras 2 bis 4 blieben liegen und wurden geloescht.
    for I in "" 2 3 4; do
        for E in "bilder$I" "clips$I" "timelapse$I" "letztesbild$I.json" "betrieb$I.json"; do
            [ -e "$ALT/$E" ] && [ ! -e "$NEU/$E" ] && mv "$ALT/$E" "$NEU/" 2>/dev/null
        done
    done
    [ -e "$ALT/herzschlag.json" ] && [ ! -e "$NEU/herzschlag.json" ] \
        && mv "$ALT/herzschlag.json" "$NEU/" 2>/dev/null
fi
exit 0
