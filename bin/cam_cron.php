<?php
/**
 * Wird jede Minute aufgerufen und entscheidet selbst, was ansteht:
 *   - Waechter der Befehlsbruecke, Herzschlag und Lebenszeichen ueber MQTT
 *     (seit 1.9.32 vorn, vor allem, was lange dauern kann)
 *   - Zeitraffer-Bild zur eingestellten Uhrzeit (einmal taeglich)
 *   - Archiv-Bereinigung taeglich um 03:35
 *   - Erreichbarkeit der Kamera, Zustand nach MQTT (nur Geaendertes)
 * Dazu zwei Aufrufformen mit Schalter: --mqtt-leeren (Deinstallation) und
 * --aufnahme (abgekoppelte Aufnahme der Befehlsbruecke, seit 1.9.32).
 *
 * WARUM NICHT AUF DIE MINUTE GENAU VERGLICHEN WIRD
 * Frueher stand hier  if ($jetzt === $soll ...)  - also ein Vergleich auf die
 * exakte Minute. Faellt der Minutentakt einmal aus, weil der LoxBerry gerade
 * ein Backup schreibt, ein anderes Plugin den Rechner beschaeftigt, die Uhr
 * per NTP springt oder das Geraet in dieser Minute gar nicht lief, dann ist
 * die Sollminute vorbei, bevor jemand hinsieht - und der Zeitraffer faellt
 * fuer den GANZEN TAG aus. Aufgefallen ist das nie, weil nichts fehlschlaegt;
 * es passiert einfach nichts.
 *
 * Deshalb wird jetzt nachgeholt: sobald die Sollzeit heute ueberschritten ist
 * und der Merker nicht auf dem heutigen Datum steht, wird ausgefuehrt. Der
 * Merker verhindert weiterhin die Mehrfachausfuehrung. Ein verpasster Takt
 * kostet damit hoechstens eine Minute Verspaetung statt eines Tages.
 */
/* WARUM DIESE DATEI UNTER bin/ LIEGT (1.9.17)
 * Bis 1.9.16 lag sie in webfrontend/html/ und wurde von cron.01min ueber
 * den HTML-Ordner des Plugins gerufen - also aus dem UNANGEMELDETEN
 * Webordner. (Der Platzhaltername steht hier absichtlich NICHT ausgeschrieben:
 * der Installer ersetzt ihn auch im Kommentar, dann weicht die installierte
 * Datei vom Archiv ab und jeder byteweise Vergleich schlaegt an. Gemessen
 * 06.09.2026 am Geraet: md5 e4ca1205 statt 6b6b59c7.) Sie
 * war damit fuer jedes Geraet im Heimnetz per HTTP erreichbar und prueft
 * kein Token: ein anonymer Aufruf schrieb den Herzschlag (HERZ sprang von
 * -1 auf 0, gemessen), stiess cam_timelapse() an und konnte ueber
 * cam_cleanup() Archivdateien loeschen. Der Herzschlag ist genau der Wert,
 * an dem Loxone einen stehengebliebenen Minutentakt erkennt - er liess
 * sich von aussen frisch halten. Denselben Weg ist MG iSmart 1.0.3
 * gegangen.
 */
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Dieses Skript laeuft nur ueber den Minutentakt.
";
    exit(1);
}

/* Die Bibliothek liegt in einem ANDEREN Baum: im Archiv nebenan, auf dem
 * installierten LoxBerry drei Ebenen hoeher unter webfrontend/html/plugins/.
 * Installiert oder Archiv entscheidet der eigene Ablageort: nur unter
 * .../bin/plugins/<ordner> gilt der installierte Kandidat, sonst die
 * Bibliothek daneben. Ob sie dann auf der Anlage arbeiten darf, entscheidet
 * cam_paths() (Archivmodus), und cam_keine_wurzel_abbruch() haelt diesen Lauf
 * an, wenn nicht.
 *
 * Bis 1.9.21 stand der installierte Kandidat auch im Archiv vorn: aus einem
 * Archiv unter / wurde //webfrontend/html/plugins/bin/cam_lib.php geladen
 * (Fall T4), und ein Archiv unter einer echten Wurzel schrieb den Herzschlag
 * der Anlage und sendete deren MQTT-Werte (Faelle A1-A4, in WSL gemessen,
 * Pruefung-ACTiKamera-1.9.22). Abgebrochen wird mit Rueckgabewert 1 und der
 * Angabe, wo gesucht wurde - ein Cron, der still stirbt, faellt niemandem
 * auf. */
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'bin') {
    $ac_kandidaten = array(dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/'
                         . basename(__DIR__) . '/cam_lib.php');
} else {
    $ac_kandidaten = array(dirname(__DIR__) . '/webfrontend/html/cam_lib.php');
}
$ac_gefunden = false;
foreach ($ac_kandidaten as $ac_kand) {
    if (is_file($ac_kand)) { require_once $ac_kand; $ac_gefunden = true; break; }
}
if (!$ac_gefunden || !function_exists('cam_keine_wurzel_abbruch')) {
    fwrite(STDERR, "cam_cron.php: cam_lib.php nicht gefunden, gesucht in: "
                   . implode(', ', $ac_kandidaten) . "
");
    exit(1);
}
cam_keine_wurzel_abbruch('cam_cron.php');

/* ---------------- Deinstallation: zurueckbehaltene Themen leeren ----------
 * uninstall/uninstall ruft diese Datei mit --mqtt-leeren, LBHOMEDIR und
 * LBPPLUGINDIR, VOR dem Loeschen der Ordner (Praefix und Port stehen in der
 * Konfiguration). Sonst tut dieser Aufruf nichts. */
if (in_array('--mqtt-leeren', isset($argv) ? (array) $argv : array(), true)) {
    exit(cam_mqtt_leeren());
}

/* ---------------- Abgekoppelte Aufnahme der Befehlsbruecke (seit 1.9.32) ----
 * cam_aufnahme_starten() startet "cam_cron.php --aufnahme foto|clip <kamera>
 * <anlass> mqtt" als abgekoppelten Kindprozess (E-E3, N4): der Dienst der
 * Bruecke wartet nie auf die Kamera. Der Zweig steht VOR der Upgrade-Pruefung,
 * prueft die Marke aber selbst - waehrend einer Aktualisierung wird nichts
 * aufgenommen, mit einer Protokollzeile statt still. Argumente streng geprueft
 * (sonst Rueckgabe 2). */
$ac_av = isset($argv) ? (array) $argv : array();
$ac_ai = array_search('--aufnahme', $ac_av, true);
if ($ac_ai !== false) {
    exit(cam_aufnahme_lauf(array_slice($ac_av, $ac_ai + 1)));
}

/* ---------------- Waehrend einer Aktualisierung: nichts tun ----------------
 * Zwischen der neuen Cron-Datei und postinstall.sh liegt fast eine Minute
 * (Regeln/06). In dieser Zeit ist cam.json das "{}" aus dem Archiv, und
 * data/plugins/<ordner>/ ist leer. Gemessen in WSL am 17.09.2026
 * (Pruefung-ACTiKamera-1.9.20/messe_oberflaeche.sh, Fall marke_frisch): der
 * Takt schrieb betrieb.json im Archiv mit "keine Adresse eingetragen" bzw.
 * dem Fehler eines Abrufs ohne Adresse und setzte den Bereinigungsmerker des
 * Tages - die richtige Bereinigung lief an dem Tag nicht mehr. Der Lauf
 * entfaellt; der naechste Takt nach postupgrade holt alles nach. Die Marke
 * legt preupgrade.sh an, postupgrade.sh entfernt sie; aelter als 3600 s gilt
 * sie nicht (cam_upgrade_laeuft). */
if (function_exists('cam_upgrade_laeuft') && cam_upgrade_laeuft()) {
    exit(0);
}

$cfg = cam_config();
$p = cam_paths();
if (!is_dir($p['tmp'])) {
    @mkdir($p['tmp'], 0775, true);
}

/* ---------------- Waechter der Befehlsbruecke (seit 1.9.32, E-E5) ----------
 * Vorn, vor Zeitraffer, Bereinigung und Erreichbarkeit: startet einen
 * abgestuerzten Dienst neu bzw. haelt ihn an, wenn der Haken aus ist. Dieser
 * Takt haelt keine Sperre - der Dienst erbt nichts. Waehrend der Upgrade-Marke
 * kommt der Lauf gar nicht hierher (oben), und die Bruecke ruht ohnehin. */
if (cam_bruecke_laden()) {
    try {
        bb_waechter();
    } catch (Throwable $ac_t) {
        cam_log_selten('bruecke', 'Waechter der Befehlsbruecke: ' . get_class($ac_t) . ': ' . $ac_t->getMessage());
    }
}

/* ---------------- Herzschlag und Lebenszeichen (seit 1.9.32 vorn, N9) ------
 * Erst setzen, dann senden: daran erkennt der Reiter Test und - ueber HTTP -
 * auch Loxone, ob der Minutentakt ueberhaupt noch laeuft. Eine Prozessnummer
 * beantwortet das nicht, ein Prozess kann dastehen und nichts mehr tun.
 * Bis 1.9.31 stand beides am Ende hinter Zeitraffer (mit A9 bis 300 s ffmpeg),
 * Bereinigung und Erreichbarkeit; ein langer Lauf verzoegerte das
 * Lebenszeichen. Ueber MQTT geht je Lauf zaehler und je Kamera OK und
 * ERREICHBAR (cam_mqtt_lebenszeichen(), E-E7) - nie retained. */
cam_herzschlag();
cam_mqtt_lebenszeichen();

$heute = date('Y-m-d');
$jetzt_min = (int) date('H') * 60 + (int) date('i');

/**
 * Ist die Aufgabe heute faellig und noch nicht gelaufen?
 * Setzt den Merker gleich mit, damit zwei gleichzeitige Laeufe nicht beide
 * loslegen - der Merker wird VOR der Arbeit geschrieben, nicht danach.
 */
function cam_faellig($merkerdatei, $soll_min, $jetzt_min, $heute)
{
    if ($jetzt_min < $soll_min) {
        return false;
    }
    $letzter = is_file($merkerdatei) ? trim((string) @file_get_contents($merkerdatei)) : '';
    if ($letzter === $heute) {
        return false;
    }
    return @file_put_contents($merkerdatei, $heute, LOCK_EX) !== false;
}

/* ---------------- Zeitraffer ---------------- */
if (!empty($cfg['timelapse']) && preg_match('/^\d{1,2}:\d{2}$/', (string) $cfg['timelapse_time'])) {
    $t = array_map('intval', explode(':', (string) $cfg['timelapse_time']));
    $soll_min = min(23, $t[0]) * 60 + min(59, $t[1]);
    /* Je Kamera ein eigener Merker: faellt eine Kamera aus, soll die andere
       ihr Zeitrafferbild trotzdem bekommen. */
    foreach (cam_kameras() as $ac_id) {
        if (cam_faellig($p['tmp'] . '/timelapse_am' . cam_sx($ac_id) . '.txt',
                        $soll_min, $jetzt_min, $heute)) {
            cam_timelapse($ac_id);
        }
    }
}

/* ---------------- Archiv-Bereinigung um 03:35 ----------------
 * Nur mit eingerichteter Konfiguration, und der Merker nur dann.
 * Ohne cam.json (und ohne Zweitschrift, aus der cam_config() oben heilt)
 * waeren alle Grenzen Vorgabewerte aus cam_vorgaben(): keep_days 90 statt
 * des eingestellten Werts. Gemessen in WSL, Fall E_takt_ohne_zweit
 * (Pruefung-ACTiKamera-1.9.20/messe_upgradeluecke.sh): der Takt zwischen
 * Cron-Installation und postinstall loeschte eine 200 Tage alte Aufnahme bei
 * eingestellten 3650 Tagen und setzte den Merker - die richtige Bereinigung
 * lief an dem Tag nicht mehr. Ausgelassen wird deshalb OHNE Merker; der erste
 * Takt nach postupgrade holt sie mit der zurueckgeholten Konfiguration nach.
 * Gemeldet wird einmal am Tag (eigener Merker), nicht jede Minute. */
if (!cam_config_eingerichtet()) {
    if (cam_faellig($p['tmp'] . '/cleanup_ausgelassen_am.txt', 3 * 60 + 35, $jetzt_min, $heute)) {
        cam_log('Archiv-Bereinigung ausgelassen: keine eingerichtete Konfiguration '
            . '(cam.json fehlt, ist leer oder {}) - sie laeuft, sobald Einstellungen gespeichert sind.');
    }
} elseif (cam_faellig($p['tmp'] . '/cleanup_am.txt', 3 * 60 + 35, $jetzt_min, $heute)) {
    cam_cleanup();
}

/* ---------------- Reste des Livebilds (seit 1.9.32, N15) ----------------
 * Die Uebergabedatei des RTSP-Wegs (cam_stream.php) bleibt nur liegen, wenn
 * Apache den Arbeiter hart beendet; nach 15 Minuten wird sie geraeumt. */
cam_livebild_reste();

/* ---------------- Erreichbarkeit der Kamera ----------------
 * Ohne diese Pruefung sagt OK nur, dass eine Adresse eingetragen ist; die
 * Kamera kann seit Tagen stromlos sein. Der Abruf holt kein Bild, sondern
 * fragt nur das Modell ab.
 *
 * Der Takt ist einstellbar (0 = aus), damit eine Kamera an einer schmalen
 * Strecke nicht jede Minute angefasst wird. Gemerkt wird der Zeitpunkt in
 * derselben Zustandsdatei - ein eigener Merker unter /tmp waere nach jedem
 * Neustart weg, und die Pruefung liefe dann oefter als eingestellt. */
$ac_takt = max(0, (int) $cfg['pruef_minuten']);
if ($ac_takt > 0) {
    foreach (cam_kameras() as $ac_id) {
        $ac_b = cam_betrieb($ac_id);
        $ac_letzte = $ac_b['geprueft'] !== '' ? strtotime((string) $ac_b['geprueft']) : false;
        if ($ac_letzte === false || (time() - $ac_letzte) >= $ac_takt * 60) {
            cam_erreichbarkeit($ac_id);
        }
    }
}

/* ---------------- Abo-Datei des Gateways (M7) ----------------
 * Nur bei eingeschaltetem MQTT und nur, wenn sie fehlt oder ein anderes
 * Praefix traegt. Ein Update raeumt den Konfigordner ab; dieser Takt legt sie
 * wieder an. */
cam_mqtt_abo_datei(true);

/* ---------------- Zustand nach MQTT ----------------
 * Gesendet wird ausschliesslich, was sich geaendert hat, und alle 30 Minuten
 * der volle Satz (siehe cam_mqtt_zustand) - am Ende, damit eine eben
 * festgestellte Erreichbarkeit noch in diesem Lauf hinausgeht. Aufnahmen und
 * Test-Push senden seit 1.9.32 sofort selbst (A10); der Lauf hier findet
 * danach keine Aenderung mehr.
 *
 * Das Lebenszeichen steht seit 1.9.32 vorn (cam_mqtt_lebenszeichen():
 * zaehler, OK, ERREICHBAR). Die Themen online und ts gibt es nicht mehr:
 * online war immer 1 und kam unter Gateway V2 genau einmal an, ts ist ein
 * ISO-Text, den kein Virtueller Eingang lesen kann (E-E7). */
cam_mqtt_zustand();
