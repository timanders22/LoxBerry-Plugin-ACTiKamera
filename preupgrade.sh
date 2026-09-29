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
    echo "<WARNING> config/system/general.json. Es wurde nichts gesichert."
    exit 1
fi

# 0. Die Marke "Aktualisierung laeuft" - als ERSTES, vor jedem anderen
#    Schritt. Zwischen der neuen Cron-Datei und postinstall.sh liegt fast eine
#    Minute (Regeln/06). Solange die Marke gilt, speichert die Plugin-Seite
#    nichts und der Minutentakt tut nichts; postupgrade.sh entfernt sie als
#    Letztes. Sie liegt NEBEN dem Datenordner, weil purge_installation den
#    Ordner selbst loescht. Fuer die Sperre der Seite gilt sie hoechstens
#    3600 s - eine abgebrochene Installation darf die Seite nicht fuer immer
#    stilllegen.
#
#    Seit 1.9.23 (Entscheidung 1 vom 29.09.2026, Befund I1) entscheidet
#    ausserdem ALLEIN ihr Vorhandensein - ohne Altersvergleich -, ob
#    postinstall.sh eine Aktualisierung vor sich hat: nur dann spielt es die
#    Zweitschrift ein, sonst legt es sie als .alt beiseite. Laesst sie sich
#    nicht anlegen, bricht dieses Skript deshalb ab (vor purge_installation):
#    ohne Marke hielte postinstall.sh das Update fuer eine Neuinstallation und
#    legte die Einstellungen beiseite. Bis 1.9.22 stand hier nur eine Warnung.
#    Ob sie schon VOR diesem Lauf lag (abgebrochener Versuch desselben
#    Updates), braucht Schritt 0b.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
AC_MARKE_VORHER=0
if [ -f "$MARKE" ]; then AC_MARKE_VORHER=1; fi
mkdir -p "$BASE/data/plugins" 2>/dev/null
{ date +%s > "$MARKE"; } 2>/dev/null
if grep -Eq '^[0-9]+$' "$MARKE" 2>/dev/null; then
    echo "<OK> Bis zum Ende der Aktualisierung speichert die Plugin-Seite nichts."
else
    echo "<FAIL> Die Marke fuer die laufende Aktualisierung liess sich nicht anlegen: $MARKE"
    echo "<FAIL> Ohne sie hielte postinstall.sh dieses Update fuer eine Neuinstallation und"
    echo "<FAIL> legte die Einstellungen beiseite. Die Aktualisierung wird abgebrochen; die"
    echo "<FAIL> bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi

# 0b. Einen ALTEN Sicherungsbestand wegraeumen, bevor neu gesichert wird
#     (Entscheidung 1, letzter Satz; Befund I4). Bis 1.9.22 legte "mkdir -p"
#     den Ordner nur an: was von einem frueheren Vorgang darin lag, spielte
#     postupgrade.sh bei diesem Update ein - gemessen mit letztesbild2.jpg
#     einer laengst ausgetragenen Kamera (Fall F7); fehlte cam.json, kam eine
#     alte cam.json der Sicherung zurueck. Ausnahme wie KODI-NG 1.2.12: lag
#     die Marke schon vor diesem Lauf, hat ein frueherer Versuch DIESES
#     Updates abgebrochen, und seine Sicherung kann die einzige Abschrift
#     sein - sie bleibt und wird ergaenzt.
SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
if [ -e "$SICHER" ] || [ -L "$SICHER" ]; then
    if [ "$AC_MARKE_VORHER" = "1" ]; then
        echo "<INFO> Die Upgrade-Sicherung unter $SICHER bleibt liegen: ein frueherer Versuch"
        echo "<INFO> dieses Updates hat abgebrochen (seine Marke lag noch); sie wird ergaenzt."
    else
        case "$SICHER" in
            */data/plugins/?*.upgrade_sicherung) rm -rf "$SICHER" 2>/dev/null ;;
        esac
        if [ -e "$SICHER" ] || [ -L "$SICHER" ]; then
            echo "<WARNING> Unter $SICHER liegt eine Sicherung aus einem frueheren Vorgang, die sich"
            echo "<WARNING> nicht entfernen liess. postupgrade.sh spielte sie ein - bitte von Hand entfernen."
        else
            echo "<INFO> Eine Upgrade-Sicherung aus einem frueheren Vorgang wurde entfernt, damit"
            echo "<INFO> dieses Update keinen alten Stand einspielt."
        fi
    fi
fi
mkdir -p "$SICHER"
chmod 0700 "$SICHER" 2>/dev/null

# 1. Konfiguration
if [ -f "$BASE/config/plugins/$PFOLDER/cam.json" ]; then
    cp -p "$BASE/config/plugins/$PFOLDER/cam.json" "$SICHER/cam.json" \
        && chmod 0600 "$SICHER/cam.json" \
        || echo "<WARNING> Die Konfiguration konnte nicht gesichert werden."
fi
# Das Protokoll wird NICHT gesichert. purge_installation raeumt
# log/plugins/<ordner>/ beim Upgrade nicht ab (Regeln/06, Abschnitt
# purge_installation). Das Zurueckkopieren in postupgrade.sh ueberschrieb das
# laufende Protokoll mit dem Stand von hier - gemessen in WSL
# (Pruefung-ACTiKamera-1.9.20/messe_upgradeluecke.sh, Fall takt): die Zeile,
# die der Minutentakt zwischen Cron-Installation und postinstall schrieb, war
# danach weg.

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
