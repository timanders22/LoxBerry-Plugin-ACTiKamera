#!/bin/bash
# ACTi Kamera - preinstall
# command <TEMPFOLDER> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# X-1 (B-Nachzug 01.10.2026, Entscheidungen 1 und 6 vom 29.09.2026), Muster
# LoxBerry-Plugin-Abfahrtsassistent-1.6.19/preinstall.sh. Der Installer ruft
# dieses Skript bei JEDEM Einbau auf, nach dem Aufraeumen der alten Fassung
# und VOR dem Kopieren von Konfiguration, Cron-Datei und Oberflaeche
# (sbin/plugininstall.pl: preupgrade :846, purge :874, preinstall :877,
# Cron :990, HTML :1066 - Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: Zweitschrift und
# Upgrade-Sicherung braucht postinstall.sh bzw. postupgrade.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Was eine fruehere Installation
# liegen liess, geht nach <name>.alt, gemeldet mit genau einer <WARNING>:
#   config/plugins/<ordner>.backup.json      Zweitschrift (Kamerapasswort,
#                                            Aktionstoken)
#   data/plugins/<ordner>.upgrade_sicherung  Upgrade-Sicherung
#   im Archivordner die Betriebsdateien      betrieb<N>.json,
#     letztesbild<N>.json/.jpg, herzschlag.json, mqtt_kameras.json
#     (Entscheidung 6; die Aufnahmen in bilder*/, clips*/, timelapse*/ bleiben)
# Bis 1.9.26 tat das erst postinstall.sh, rund eine Minute nach der
# Cron-Datei. In dieser Luecke heilte der Minutentakt (cam_config) cam.json
# aus der Zweitschrift der frueheren Installation, und postinstall.sh liess
# die geheilte cam.json stehen: Kamerapasswort und altes Aktionstoken galten
# weiter (in WSL gemessen, vb_acti2_bau_skripte/proben, Fall N1).
# Die Selbstheilung der Bibliothek liest .alt nie; die Deinstallation raeumt
# es ab.

ARGV3=$3
ARGV5=$5
# Rueckfall, falls sudo die Umgebung ausgeraeumt hat (env_reset).
# Das fuenfte Argument ist das Wurzelverzeichnis und traegt immer.
LBHOMEDIR="${LBHOMEDIR:-$5}"
PFOLDER="${ARGV3:-actikamera}"
BASE="${ARGV5:-$LBHOMEDIR}"

# Wurzelsuche wie im Muster: ohne config/plugins, data/plugins UND
# config/system/general.json wird nichts angefasst (Regeln/06,
# Raumklima-Vorfall).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts beiseitegelegt."
    exit 0
fi
# Der Ordnername darf keinen Pfadtrenner tragen, sonst griffe mv/rm daneben.
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac

# Merker "preinstall.sh hat bei dieser Neuinstallation aufgeraeumt" fuer
# postinstall.sh. Ohne ihn hielt postinstall.sh die Betriebsdateien, die der
# Minutentakt DIESER Installation in der Luecke schreibt (herzschlag.json,
# betrieb.json), fuer Reste einer frueheren und meldete sie mit einer zweiten
# <WARNING> (in WSL gemessen, Faelle N1 und N4). Er wird hier zuerst entfernt,
# damit nie ein Merker eines abgebrochenen frueheren Laufs gilt.
NEU_MERKER="$BASE/data/plugins/$PFOLDER.neuinstallation"
rm -f "$NEU_MERKER" 2>/dev/null

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    # Aktualisierung: nichts zu tun, postinstall.sh spielt zurueck.
    exit 0
fi

BEISEITE=""
FEST=""
ac_beiseite() { # $1 Pfad (Datei oder, nur die Upgrade-Sicherung, Verzeichnis)
    if [ -d "$1" ] && [ ! -L "$1" ]; then
        case "$1" in
            */data/plugins/?*.upgrade_sicherung) rm -rf "${1:?}.alt" 2>/dev/null ;;
            *) FEST="$FEST $1"; return ;;
        esac
    else
        rm -f "${1:?}.alt" 2>/dev/null
    fi
    if mv -f "$1" "$1.alt" 2>/dev/null; then
        # Die Dateien koennen das Kamerapasswort tragen.
        if [ -f "$1.alt" ] && [ ! -L "$1.alt" ]; then chmod 600 "$1.alt" 2>/dev/null; fi
        BEISEITE="$BEISEITE $1.alt"
    else
        FEST="$FEST $1"
    fi
}
BK="$BASE/config/plugins/$PFOLDER.backup.json"
US="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
for ZIEL in "$BK" "$US"; do
    if [ -e "$ZIEL" ] || [ -L "$ZIEL" ]; then ac_beiseite "$ZIEL"; fi
done
AR="$BASE/data/plugins/$PFOLDER.archiv"
if [ -d "$AR" ] && [ ! -L "$AR" ]; then
    for I in "" 2 3 4; do
        for E in "betrieb$I.json" "letztesbild$I.json" "letztesbild$I.jpg"; do
            if [ -f "$AR/$E" ] || [ -L "$AR/$E" ]; then ac_beiseite "$AR/$E"; fi
        done
    done
    for E in herzschlag.json mqtt_kameras.json; do
        if [ -f "$AR/$E" ] || [ -L "$AR/$E" ]; then ac_beiseite "$AR/$E"; fi
    done
fi

# Nur wenn alles verschoben ist: dann bleibt fuer postinstall.sh nichts
# Fruehere mehr, und es laesst die Dateien dieser Installation stehen.
if [ -z "$FEST" ]; then
    { date +%s > "$NEU_MERKER"; } 2>/dev/null
fi
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    AC_TEXT="<WARNING> Neuinstallation: Einstellungen (mit Kamerapasswort und Aktionstoken) bzw. Betriebsdateien einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && AC_TEXT="$AC_TEXT Beiseitegelegt:$BEISEITE - die Aufnahmen im Archiv bleiben; die Deinstallation raeumt die .alt-Dateien mit ab."
    [ -n "$FEST" ] && AC_TEXT="$AC_TEXT Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$AC_TEXT"
fi
exit 0
