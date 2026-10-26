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
# Eine UNLESBARE Sicherung (abgeschnittenes JSON) mit mehr als leer/{}/[]
# wird als config/plugins/<ordner>/cam.json.kaputt.<zeit> (0600)
# beiseitegelegt, nicht geloescht (I6, README "Was verdraengt wird, bleibt als
# .kaputt"). Bis 1.9.22 loeschte der Aufraeumschritt unten den Ordner, und
# Adresse, Benutzer und Passwort waren nirgends mehr (Fall F3b).
AC_KAPUTT=0
if [ -f "$SICHER/cam.json" ]; then
    ac_rest=$(tr -d ' \t\r\n' < "$SICHER/cam.json" 2>/dev/null)
    if ! ac_json_ok "$SICHER/cam.json" && [ -n "$ac_rest" ] && [ "$ac_rest" != "{}" ] && [ "$ac_rest" != "[]" ]; then
        AC_K="$BASE/config/plugins/$PFOLDER/cam.json.kaputt.$(date +%Y%m%d%H%M%S)"
        if cp -p "$SICHER/cam.json" "$AC_K" 2>/dev/null && chmod 0600 "$AC_K" 2>/dev/null \
           && cmp -s "$SICHER/cam.json" "$AC_K"; then
            AC_KAPUTT=1
            if ac_inhalt "$CF"; then
                # Die Konfiguration kam schon aus der Zweitschrift (postinstall.sh).
                echo "<INFO> Die Upgrade-Sicherung der Konfiguration ist kein lesbares JSON (abgeschnitten?) - die Konfiguration kam aus der Zweitschrift. Die Sicherung liegt als $AC_K (0600) daneben."
            else
                echo "<WARNING> Die Upgrade-Sicherung der Konfiguration ist kein lesbares JSON (abgeschnitten?) - nichts zurueckgeholt."
                echo "<WARNING> Sie liegt als $AC_K (0600) bereit; Adresse, Benutzer und Passwort lassen sich daraus abschreiben."
            fi
        else
            AC_KAPUTT=2
            echo "<WARNING> Die Upgrade-Sicherung der Konfiguration ist kein lesbares JSON und liess sich nicht"
            echo "<WARNING> als .kaputt ablegen - sie bleibt unter $SICHER liegen."
        fi
    elif ! ac_inhalt "$SICHER/cam.json"; then
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

# Das letzte Bild liegt im Webordner, den der Installer abraeumt. Ohne diesen
# Schritt zeigt die Kamera-Kachel in Loxone bis zur naechsten Aufnahme 404,
# waehrend ALTER weiter ein Bild meldet.
WEB="$BASE/webfrontend/html/plugins/$PFOLDER"
if [ -d "$WEB" ]; then
    for E in "$SICHER"/letztesbild*.jpg; do
        [ -e "$E" ] && cp -p "$E" "$WEB/" 2>/dev/null
    done
fi
# Das Zeitraffer-Video liegt seit 1.9.32 im ARCHIV (A9/E3, E-E15): ein aus dem
# Webordner gesichertes zeitraffer<n>.mp4 geht einmal nach
# <archiv>/timelapse<n>/zeitraffer.mp4 - nur, wenn dort noch keines liegt. Die
# alte offene Adresse /plugins/<ordner>/zeitraffer.mp4 gibt es danach nicht
# mehr; abrufbar ist es ueber cam.php?video=1 (Stromkennwort wie ?bild).
AC_AR="$BASE/data/plugins/$PFOLDER.archiv"
if command -v php >/dev/null 2>&1 && [ -f "$CF" ]; then
    AC_EIGEN=$(php -r '$d = json_decode((string) @file_get_contents($argv[1]), true);
        $a = (is_array($d) && isset($d["archiv_pfad"]) && is_string($d["archiv_pfad"])) ? rtrim(trim($d["archiv_pfad"]), "/") : "";
        echo ($a !== "" && is_dir($a) && is_writable($a)) ? $a : "";' "$CF" 2>/dev/null)
    [ -n "$AC_EIGEN" ] && AC_AR="$AC_EIGEN"
fi
for E in "$SICHER"/zeitraffer*.mp4; do
    [ -f "$E" ] || continue
    AC_N=$(basename "$E" .mp4); AC_N=${AC_N#zeitraffer}
    case "$AC_N" in ''|2|3|4) ;; *) continue ;; esac
    AC_Z="$AC_AR/timelapse$AC_N/zeitraffer.mp4"
    if [ -e "$AC_Z" ]; then
        echo "<INFO> Zeitraffer-Video: im Archiv liegt schon $AC_Z - das gesicherte bleibt unbenutzt."
        continue
    fi
    mkdir -p "$AC_AR/timelapse$AC_N" 2>/dev/null
    if cp -p "$E" "$AC_Z" 2>/dev/null && cmp -s "$E" "$AC_Z"; then
        echo "<INFO> Zeitraffer-Video ins Archiv uebernommen ($AC_Z). Die alte Adresse /plugins/$PFOLDER/zeitraffer$AC_N.mp4 gibt es nicht mehr - neu: /plugins/$PFOLDER/cam.php?video=1 (mit &t=<Stromkennwort>, falls gesetzt)."
    else
        echo "<WARNING> Das gesicherte Zeitraffer-Video liess sich nicht ins Archiv kopieren: $E"
    fi
done

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
elif [ "$AC_KAPUTT" = "2" ]; then
    : # gemeldet oben; die unlesbare Sicherung ist die einzige Abschrift
else
    rm -rf "$SICHER" 2>/dev/null
fi

# endpunkt_ein festschreiben (seit 1.9.32, Entscheidung 2, E-E12): eine
# EINGERICHTETE Datei (mindestens ein Schluessel ausser den selbst erzeugten
# Token - dasselbe Kriterium wie cam_config_eingerichtet()) ohne den Schluessel
# bekommt endpunkt_ein=1: der HTTP-Lesezweig einer bestehenden Anlage bleibt
# erreichbar. Fuer cam.json und die Zweitschrift; Nebendatei .neu (umask 077),
# Gegenprobe (gleich bis auf endpunkt_ein), mv -f, chmod 600. Scheitert es,
# gilt die Lese-Regel cam_uebergang() beim Lesen. Erst NACH dem Aufraeumen der Upgrade-Sicherung: deren
# Gegenprobe vergleicht sie byteweise mit cam.json (sonst bliebe sie liegen, und ein zweiter Lauf spielte sie ein).
ac_endpunkt_festschreiben() { # $1 Datei
    [ -f "$1" ] && command -v php >/dev/null 2>&1 || return 0
    php -r '$f = $argv[1]; $d = json_decode((string) @file_get_contents($f), true);
        if (!is_array($d)) { exit(1); }
        $r = $d; foreach (array("aktionstoken", "ausloeser_token", "ausloeser_token2", "ausloeser_token3", "ausloeser_token4") as $s) { unset($r[$s]); }
        if (count($r) === 0 || array_key_exists("endpunkt_ein", $d)) { exit(1); }
        $d["endpunkt_ein"] = 1;
        $j = json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($j === false) { exit(3); }
        $alt = umask(077); $ok = (file_put_contents($f . ".neu", $j) === strlen($j)); umask($alt);
        exit($ok ? 0 : 3);' "$1" 2>/dev/null
    ac_rc=$?
    [ "$ac_rc" = 1 ] && return 0
    if [ "$ac_rc" = 0 ] && php -r '$a = json_decode((string) @file_get_contents($argv[1]), true);
        $n = json_decode((string) @file_get_contents($argv[2]), true);
        if (!is_array($a) || !is_array($n) || !isset($n["endpunkt_ein"]) || $n["endpunkt_ein"] !== 1) { exit(1); }
        unset($n["endpunkt_ein"]); exit($a === $n ? 0 : 1);' "$1" "$1.neu" 2>/dev/null \
       && mv -f "$1.neu" "$1" 2>/dev/null && chmod 600 "$1" 2>/dev/null; then
        echo "<INFO> Konfiguration ergaenzt: endpunkt_ein=1 in $(basename "$1") (bestehende Anlage - der HTTP-Lesezweig fuer Loxone bleibt erreichbar; abschalten im Reiter Einbindung)."
    else
        [ -f "$1.neu" ] && { : > "$1.neu"; rm -f "$1.neu"; } 2>/dev/null
        echo "<WARNING> endpunkt_ein liess sich in $1 nicht festschreiben - das Plugin liest den Wert beim Lesen (1)."
    fi
}
ac_endpunkt_festschreiben "$CF"
ac_endpunkt_festschreiben "$BK"

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

# Die Befehlsbruecke starten (seit 1.9.32, E-E5) - erst jetzt, nach dem
# Entfernen der Marke (solange sie liegt, startet sie nicht). befehlsbruecke.sh
# startet nur mit den Haken "Befehle ueber MQTT annehmen" und "Werte ueber MQTT
# veroeffentlichen"; ein ausgelassener Start holt der Waechter im Minutentakt
# binnen 60 s nach.
BBSH="$BASE/bin/plugins/$PFOLDER/befehlsbruecke.sh"
if [ -f "$BBSH" ]; then
    BB_AUS=$(timeout 40 bash "$BBSH" start 2>&1 | head -n 1)
    case "$BB_AUS" in
        *gestartet*|*laeuft*) echo "<OK> Befehlsbruecke: $BB_AUS" ;;
        "Haken aus - kein Start.") echo "<INFO> Befehlsbruecke: aus (Reiter MQTT, Haken 'Befehle ueber MQTT annehmen')." ;;
        *) echo "<WARNING> Befehlsbruecke: $BB_AUS - der Minutentakt versucht es erneut." ;;
    esac
fi
exit 0
