#!/bin/bash
# ACTi Kamera - postinstall
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
    echo "<WARNING> config/system/general.json. Es wurde nichts angelegt."
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
ac_zweitschrift_einspielen "$BK" "$CF" "<OK> Konfiguration aus Sicherung wiederhergestellt."
# Die Erstanleitung nur, wenn keine eingerichtete Konfiguration vorliegt.
# postinstall.sh laeuft auch bei jedem Upgrade (Regeln/06); danach war der
# Rat, die Zugangsdaten einzutragen, falsch und legte nahe, sie seien weg.
# "Eingerichtet" heisst: fuer mindestens eine Kamera steht eine Adresse in
# der Datei (host, host2 bis host4 nicht leer). Das Aktionstoken zaehlt
# nicht - es entsteht beim ersten Oeffnen der Oberflaeche von selbst
# (cam_selbsterzeugte_schluessel() in cam_lib.php).
# (ac_hat_adresse() steht oben bei ac_inhalt().)
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
