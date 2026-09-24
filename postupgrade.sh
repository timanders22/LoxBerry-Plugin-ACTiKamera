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

SICHER="$BASE/data/plugins/$PFOLDER.upgrade_sicherung"
mkdir -p "$BASE/config/plugins/$PFOLDER" "$BASE/log/plugins/$PFOLDER" 2>/dev/null

CF="$BASE/config/plugins/$PFOLDER/cam.json"
# Dieselbe Pruefung wie in postinstall.sh: eingerichtet heisst, fuer
# mindestens eine Kamera steht eine Adresse in der Datei.
ac_hat_adresse() {
    [ -s "$1" ] && grep -Eq '"host[2-4]?"[[:space:]]*:[[:space:]]*"[^"]' "$1" 2>/dev/null
}
AC_VORHER=0; ac_hat_adresse "$CF" && AC_VORHER=1
AC_GESICHERT=0; ac_hat_adresse "$SICHER/cam.json" && AC_GESICHERT=1
if [ -s "$SICHER/cam.json" ]; then
    cp -p "$SICHER/cam.json" "$CF" && chmod 0600 "$CF" \
        && echo "<OK> Konfiguration aus der Upgrade-Sicherung zurueckgeholt." \
        || echo "<WARNING> Die gesicherte Konfiguration liess sich nicht zurueckholen."
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
BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -f "$BK" ]; then
    if [ ! -s "$CF" ] || [ "$(cat "$CF" 2>/dev/null)" = "{}" ]; then
        cp -p "$BK" "$CF" && chmod 0600 "$CF"
        echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt."
    fi
fi

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
# INHALT. Bis 1.9.20 genuegte "[ -s cam.json ]", und das traf auch den
# "{}"-Platzhalter aus postinstall.sh: scheiterte die Rueckholung, war die
# Upgrade-Sicherung danach trotzdem fort (Pruefung-ACTiKamera-1.9.21, Fall e2).
# Liegen bleibt sie, wenn ihre cam.json eine Adresse oder ein Aktionstoken
# traegt, die cam.json im Konfigordner aber weder das eine noch das andere.
ac_hat_token() {
    [ -s "$1" ] && grep -Eq '"aktionstoken"[[:space:]]*:[[:space:]]*"[^"]' "$1" 2>/dev/null
}
ac_inhalt() { ac_hat_adresse "$1" || ac_hat_token "$1"; }
if [ -d "$SICHER" ] && ac_inhalt "$SICHER/cam.json" && ! cmp -s "$SICHER/cam.json" "$CF" \
   && ! ac_inhalt "$CF"; then
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
