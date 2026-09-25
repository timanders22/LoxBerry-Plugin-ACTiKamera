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
    echo "<WARNING> config/system/general.json. Es wurde nichts zurueckgeholt."
    exit 1
fi
# INHALT einer cam.json, Zweitschrift oder Sicherung: ein lesbares
# JSON-Objekt (geprueft mit php, wo es da ist) UND fuer mindestens eine Kamera
# eine Adresse (host, host2 bis host4 nicht leer) oder ein Aktionstoken.
# Dieselbe Regel wie cam_hat_token() in cam_lib.php, erweitert um die Adresse
# (Regeln/05, "Die Selbstheilung entscheidet nach Inhalt"). Bis 1.9.21
# entschieden die Haken nach FORM: die Zweitschrift wurde eingespielt, sobald
# cam.json leer oder "{}" war - gleich, was sie selbst trug -, und die
# Upgrade-Sicherung, sobald sie nicht leer war (Pruefung-ACTiKamera-1.9.22,
# Faelle Z1-Z8).
ac_json_ok() {
    command -v php >/dev/null 2>&1 || return 0
    php -r '$d = json_decode((string) @file_get_contents($argv[1]), true); exit(is_array($d) ? 0 : 1);' "$1" 2>/dev/null
}
ac_hat_adresse() {
    [ -s "$1" ] && grep -Eq '"host[2-4]?"[[:space:]]*:[[:space:]]*"[^"]' "$1" 2>/dev/null
}
ac_hat_token() {
    [ -s "$1" ] && grep -Eq '"aktionstoken"[[:space:]]*:[[:space:]]*"[^"]' "$1" 2>/dev/null
}
ac_inhalt() {
    [ -s "$1" ] && ac_json_ok "$1" && { ac_hat_adresse "$1" || ac_hat_token "$1"; }
}
# Die Zweitschrift einspielen - nur mit Inhalt, und nur ueber eine cam.json
# ohne Inhalt. Was verdraengt wird und mehr ist als leer, "{}" oder "[]",
# bleibt als cam.json.kaputt (0600) liegen, wie in cam_config().
# $1 Zweitschrift, $2 cam.json, $3 Meldung bei Erfolg
ac_zweitschrift_einspielen() {
    [ -f "$1" ] || return 0
    ac_inhalt "$2" && return 0
    if ! ac_inhalt "$1"; then
        echo "<INFO> Die Zweitschrift $1 traegt keine eingerichtete Konfiguration (weder Adresse noch Aktionstoken) - sie wird nicht eingespielt."
        return 0
    fi
    ac_rest=$(tr -d ' \t\r\n' < "$2" 2>/dev/null)
    if [ -n "$ac_rest" ] && [ "$ac_rest" != "{}" ] && [ "$ac_rest" != "[]" ]; then
        if cp -p "$2" "$2.kaputt" 2>/dev/null && chmod 0600 "$2.kaputt" 2>/dev/null; then
            echo "<WARNING> Die bisherige cam.json trug weder Adresse noch Aktionstoken; sie liegt als $2.kaputt daneben."
        fi
    fi
    if cp -p "$1" "$2" 2>/dev/null && chmod 0600 "$2" 2>/dev/null && cmp -s "$1" "$2"; then
        echo "$3"
    else
        echo "<WARNING> Die Zweitschrift liess sich nicht einspielen: $1"
    fi
}

SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" 2>/dev/null

CF="$BASE/config/plugins/$PFOLDER/cam.json"
# Dieselbe Pruefung wie in postinstall.sh: eingerichtet heisst, fuer
# mindestens eine Kamera steht eine Adresse in der Datei (ac_hat_adresse()
# steht oben bei ac_inhalt()).
AC_VORHER=0; ac_hat_adresse "$CF" && AC_VORHER=1
AC_GESICHERT=0; ac_hat_adresse "$SICHER/cam.json" && AC_GESICHERT=1
# Nach INHALT, nicht nach Groesse: bis 1.9.21 genuegte "[ -s ]", und eine
# Sicherung "{}" ueberschrieb die eben aus der Zweitschrift eingespielte
# Konfiguration und wurde als "zurueckgeholt" gemeldet (Fall Z4).
if [ -f "$SICHER/cam.json" ]; then
    if ! ac_inhalt "$SICHER/cam.json"; then
        echo "<INFO> Die Upgrade-Sicherung traegt keine eingerichtete Konfiguration (weder Adresse noch Aktionstoken) - nichts zurueckgeholt."
    elif cp -p "$SICHER/cam.json" "$CF" 2>/dev/null && chmod 0600 "$CF" 2>/dev/null \
         && cmp -s "$SICHER/cam.json" "$CF"; then
        echo "<OK> Konfiguration aus der Upgrade-Sicherung zurueckgeholt."
    else
        echo "<WARNING> Die gesicherte Konfiguration liess sich nicht zurueckholen."
    fi
fi
# Kein Protokoll zurueckholen: log/plugins/<ordner>/ uebersteht das Upgrade,
# eine Kopie von vorher ueberschriebe die Zeilen aus dem Upgrade selbst
# (siehe preupgrade.sh). Liegt aus einem abgebrochenen Upgrade noch ein
# cam.log im Sicherungsordner, bleibt es unbenutzt und geht mit ihm weg.

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
# Nur mit Inhalt und nur ueber eine cam.json ohne Inhalt (Faelle Z1, Z8).
BK="$BASE/config/plugins/$PFOLDER.backup.json"
ac_zweitschrift_einspielen "$BK" "$CF" "<OK> Konfiguration aus der Zweitschrift wiederhergestellt."

# Das Schlusswort zur Konfiguration steht hier nur, wenn postinstall.sh es
# hierher verwiesen hat: dort stand noch keine Adresse in cam.json, in der
# Upgrade-Sicherung aber schon.
if [ $AC_VORHER = 0 ] && [ $AC_GESICHERT = 1 ]; then
    if ac_hat_adresse "$CF"; then
        echo "<OK> Aktualisierung abgeschlossen, die Einstellungen der Kamera sind uebernommen."
    else
        echo "<WARNING> Die Einstellungen der Kamera liessen sich nicht zurueckholen."
        echo "<WARNING> Bitte die Plugin-Oberflaeche oeffnen und Adresse, Benutzer und Passwort der Kamera eintragen."
    fi
fi

# Aufgeraeumt wird erst, wenn wirklich etwas zurueckgeholt wurde - nach
# INHALT. Bis 1.9.20 genuegte "[ -s cam.json ]" (Pruefung-ACTiKamera-1.9.21,
# Fall e2); bis 1.9.21 genuegte irgendein Inhalt in cam.json - auch der
# aeltere aus der Zweitschrift, waehrend die neuere Sicherung nicht ankam
# (Pruefung-ACTiKamera-1.9.22, Fall Z6). Geloescht wird sie jetzt nur, wenn
# sie selbst nichts traegt oder byteweise in cam.json angekommen ist.
if [ -d "$SICHER" ] && ac_inhalt "$SICHER/cam.json" && ! cmp -s "$SICHER/cam.json" "$CF"; then
    echo "<WARNING> Die Upgrade-Sicherung bleibt liegen - ihre Konfiguration ist nicht angekommen:"
    echo "<WARNING>   $SICHER"
else
    rm -rf "$SICHER" 2>/dev/null
fi

# Die Marke aus preupgrade.sh geht ZULETZT weg - erst danach duerfen die
# Plugin-Seite und der Minutentakt wieder schreiben. Sie hier zu entfernen und
# nicht schon in postinstall.sh ist Absicht: postinstall.sh laeuft vor diesem
# Skript, und die Rueckholung oben ist der letzte Schritt, den ein Schreiben
# von aussen noch verderben koennte.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if [ -f "$MARKE" ]; then
    if rm -f "$MARKE" 2>/dev/null && [ ! -e "$MARKE" ]; then
        echo "<OK> Die Plugin-Seite und der Minutentakt arbeiten wieder."
    else
        echo "<WARNING> Die Marke $MARKE liess sich nicht entfernen."
        echo "<WARNING> Sie verfaellt von selbst eine Stunde nach ihrer Entstehung."
    fi
fi
exit 0
