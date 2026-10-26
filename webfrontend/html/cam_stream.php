<?php
/**
 * MJPEG-Proxy: liefert einen fortlaufenden Bilderstrom, ohne dass der Abrufer
 * die Zugangsdaten der Kamera kennen muss. Damit kann in der Loxone-Projektdatei
 * IntVideoUrl auf diese Adresse zeigen statt auf USER=...&PWD=... der Kamera.
 *
 * Aufruf:  cam_stream.php[?fps=2][&sec=300][&t=<Token>]
 *          cam_stream.php?einzeln=1   -> nur ein einzelnes JPEG (fuer Vorschau)
 *
 * (c) ACTi Kamera Plugin Authors - MIT-Lizenz
 */

require_once __DIR__ . '/cam_lib.php';

/* Unangemeldeter Baum: lesen ja, anlegen nein. */
cam_selbstheilung(false);

/** Einen Parameter holen - erst is_string, dann alles andere. */
function ac_get($name, $vorgabe = '')
{
    return (isset($_GET[$name]) && is_string($_GET[$name])) ? $_GET[$name] : $vorgabe;
}

$ac_ip = preg_replace('/[^0-9A-Fa-f:.]/', '',
    isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '');
if ($ac_ip === '') { $ac_ip = 'unbekannt'; }

/* Welche Kamera ist gemeint? Bis 1.9.16 kannte diese Datei den Parameter
 * ueberhaupt nicht: Vorschau und IntVideoUrl zeigten bei mehreren Kameras
 * immer die erste - still. Dieselbe Wahl wie in cam.php. */
$ac_kam = 1;
if (isset($_GET['kamera'])) {
    $ac_kroh = ac_get('kamera');
    if (!preg_match('/^[0-9]{1,2}$/', $ac_kroh)
        || (int) $ac_kroh < 1 || (int) $ac_kroh > CAM_MAX
        || !in_array((int) $ac_kroh, cam_kameras(), true)) {
        cam_log('cam_stream.php: abgewiesen, unbekannte Kamera (Aufrufer ' . $ac_ip . ')');
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Diese Kamera gibt es nicht.\n";
        exit;
    }
    $ac_kam = (int) $ac_kroh;
}

/* cam_kcfg() liefert die volle Konfiguration und legt die Felder DIESER
   Kamera ohne Kennziffer darueber - user, pass und rtsp_quality gehoeren
   damit zur gewaehlten Kamera, stream_* bleiben pluginweit. */
$ac_cfg = cam_kcfg($ac_kam);

// Optionaler Zugriffsschutz. Ohne hinterlegtes Token ist der Strom frei abrufbar
// (im Heimnetz gewollt, damit Loxone ihn ohne Anmeldung anzeigen kann).
$ac_token = (string) $ac_cfg['stream_token'];
if ($ac_token !== '') {
    $ac_hat = ac_get('t');
    if (!hash_equals($ac_token, $ac_hat)) {
        cam_log('cam_stream.php: abgewiesen, Stromkennwort falsch oder fehlend'
                . ' (Aufrufer ' . $ac_ip . ')');
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Zugriff verweigert: falsches oder fehlendes Token.\n";
        exit;
    }
}

// Einzelbild - fuer die Vorschau in der Oberflaeche und fuer IntAlertImage
if (isset($_GET['einzeln'])) {
    list($ac_body, $ac_weg, $ac_err) = cam_fetch_jpeg($ac_kam);
    if ($ac_body === false) {
        cam_log('cam_stream.php: Einzelbild fehlgeschlagen: ' . $ac_err
                . ' (Kamera ' . $ac_kam . ', Aufrufer ' . $ac_ip . ')');
        header('HTTP/1.1 502 Bad Gateway');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Kein Bild erhalten: ' . $ac_err . "\n";
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . strlen($ac_body));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $ac_body;
    exit;
}

$ac_fps = isset($_GET['fps']) ? (float) ac_get('fps', '0') : (float) $ac_cfg['stream_fps'];
/* Untergrenze, nicht nur Obergrenze.
 *
 * Bis 1.9.7 wurde nur gegen <= 0 und > 10 geprueft. ?fps=0.0001 ueberlebt
 * beides, und aus 1000000/0.0001 wird usleep(10 000 000 000) - 2,8 Stunden in
 * EINEM Schlaf, in dem weder connection_aborted() noch die Frist ausgewertet
 * wird. Das Formular begrenzt auf 0.2; der GET-Weg tat es nicht.
 */
if (!is_finite($ac_fps) || $ac_fps < 0.2) { $ac_fps = 2.0; }
if ($ac_fps > 10) { $ac_fps = 10.0; }   // die Kamera liefert Schnappschuesse, kein Video
$ac_pause = (int) round(1000000 / $ac_fps);

/* Obergrenze fuer die Dauer eines Stroms.
 *
 * Frueher waren hier 21600 Sekunden erlaubt - sechs Stunden. Das ist keine
 * Notbremse, das ist eine Falle: PHP laeuft auf einem LoxBerry mit einer
 * kleinen Zahl paralleler Arbeitsprozesse. Jeder offene Strom belegt einen
 * davon. Zwei geoeffnete Loxone-Apps und ein Browserfenster, und die Haelfte
 * ist weg; sind alle belegt, antwortet die gesamte LoxBerry-Oberflaeche nicht
 * mehr - auch kein anderes Plugin.
 *
 * 900 Sekunden reichen fuer jeden Blick auf die Haustuer. Wer laenger
 * zusehen will, laedt die Seite neu; das kostet einen Klick statt der
 * Bedienbarkeit des ganzen Geraets.
 *
 * ignore_user_abort(false) und die connection_aborted()-Pruefungen in den
 * Schleifen geben den Prozess frei, sobald der Abrufer weggeht - das ist die
 * eigentliche Rettung. Die Obergrenze fasst den Fall, dass jemand die Seite
 * offen stehen laesst.
 */
define('AC_STREAM_MAX', 900);
$ac_max = isset($_GET['sec']) ? (int) ac_get('sec', '0') : (int) $ac_cfg['stream_maxsec'];
if ($ac_max < 5) { $ac_max = 5; }
if ($ac_max > AC_STREAM_MAX) { $ac_max = AC_STREAM_MAX; }

/* EIN Zeitpunkt fuer den ganzen Aufruf, nicht einer je Weg.
 *
 * Bis 1.9.7 setzte jeder der drei Wege seine eigene Frist auf "jetzt + max".
 * Fielen Weg 1 und Weg 2 nacheinander aus, lief ein Aufruf bis zu dreimal so
 * lange wie zugesagt - 2700 statt 900 Sekunden, und jeder offene Strom belegt
 * einen PHP-Arbeitsprozess. Eine Grenze steht genau einmal. */
define('AC_STREAM_ENDE', time() + $ac_max);

// Der Strom laeuft, bis der Abrufer die Verbindung schliesst oder die Zeit ablaeuft.
@set_time_limit(0);
ignore_user_abort(false);
while (ob_get_level() > 0) { @ob_end_clean(); }

$ac_grenze = 'loxberrycam';
header('Content-Type: multipart/x-mixed-replace; boundary=' . $ac_grenze);
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Connection: close');

// ---------------------------------------------------------------------------
// Weg 1: den fertigen MJPEG-Strom der Kamera einfach durchreichen.
// Die Bilder werden am JPEG-Rahmen (FFD8 … FFD9) geschnitten und mit eigener
// Trennmarke neu verpackt - dadurch ist der Ausgang unabhaengig davon, wie die
// Kamera ihren Strom formatiert.
// ---------------------------------------------------------------------------
$ac_modus = (string) $ac_cfg['stream_mode'];
$ac_mjpeg = ($ac_modus === 'auto' || $ac_modus === 'mjpeg') ? cam_mjpeg_url($ac_kam) : '';

if ($ac_mjpeg !== '' && function_exists('curl_init')) {
    $ac_puffer = '';
    $ac_bekommen = 0;
    $ac_bis = AC_STREAM_ENDE;
    $ac_letzte = 0.0;
    $ac_mind = 1.0 / $ac_fps;          // Bilder ausduennen, falls gewuenscht

    $ch = curl_init($ac_mjpeg);
    /* Keine Gesamtzeit (der Strom soll ja laufen), aber eine Leerlaufwache:
       die Abbruchpruefung sitzt in der Schreibfunktion und laeuft nur, WENN
       Daten eintreffen. Eine Kamera, die verstummt ohne die Verbindung zu
       schliessen, haelt den Arbeitsprozess sonst unbegrenzt fest - genau der
       Fall, den der Kommentar oben zu verhindern vorgibt. */
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
    curl_setopt($ch, CURLOPT_LOW_SPEED_LIMIT, 1);
    curl_setopt($ch, CURLOPT_LOW_SPEED_TIME, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_ANY);
    curl_setopt($ch, CURLOPT_USERPWD, $ac_cfg['user'] . ':' . $ac_cfg['pass']);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION,
        function ($ch, $daten) use (&$ac_puffer, &$ac_bekommen, &$ac_letzte,
                                    $ac_grenze, $ac_bis, $ac_mind) {
            $laenge = strlen($daten);
            $ac_puffer .= $daten;

            while (true) {
                $a = strpos($ac_puffer, "\xFF\xD8");
                if ($a === false) { break; }
                $e = strpos($ac_puffer, "\xFF\xD9", $a + 2);
                if ($e === false) { break; }
                $bild = substr($ac_puffer, $a, $e - $a + 2);
                $ac_puffer = substr($ac_puffer, $e + 2);

                $jetzt = microtime(true);
                if ($jetzt - $ac_letzte < $ac_mind) { continue; }   // zu dicht - auslassen
                $ac_letzte = $jetzt;

                echo "--" . $ac_grenze . "\r\n";
                echo "Content-Type: image/jpeg\r\n";
                echo "Content-Length: " . strlen($bild) . "\r\n\r\n";
                echo $bild . "\r\n";
                flush();
                $ac_bekommen++;
            }
            if (strlen($ac_puffer) > 4000000) { $ac_puffer = ''; }

            if (connection_aborted() || time() > $ac_bis) {
                return 0;   // beendet den Abruf sauber
            }
            return $laenge;
        });
    @curl_exec($ch);
    $ac_hcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if (PHP_VERSION_ID < 80000) { curl_close($ch); }

    if ($ac_bekommen > 0) {
        echo "--" . $ac_grenze . "--\r\n";
        exit;
    }
    cam_log('MJPEG-Weiterleitung lieferte kein Bild (HTTP ' . $ac_hcode . ') - weiter mit RTSP/Schnappschuessen');
}

// ---------------------------------------------------------------------------
// Weg 2: echtes Video ueber RTSP, von ffmpeg in JPEG-Bilder gewandelt.
// Das ist deutlich fluessiger und schont die Kamera, weil nur EINE Verbindung
// offen ist statt vieler Schnappschussabrufe.
// ---------------------------------------------------------------------------
$ac_ffmpeg = ($ac_modus === 'jpeg' || $ac_modus === 'mjpeg') ? '' : cam_ffmpeg();
$ac_rtsp = $ac_ffmpeg !== '' ? cam_rtsp_url(false, $ac_kam) : '';

/* Seit 1.9.32 (A6, E4; Entwurf E-E14): das KENNWORT STEHT NIE AUF DER
 * KOMMANDOZEILE. Bis 1.9.31 ging die RTSP-Adresse samt Benutzer und Kennwort
 * als Argument an eine Schale und an ffmpeg - lesbar fuer jeden lokalen
 * Benutzer unter /proc/<pid>/cmdline -, und nach dem Ende blieb ffmpeg als
 * Waise stehen (proc_terminate traf nur die Schale). Jetzt wie
 * cam_rtsp_pruefen() (cam_lib.php):
 *   - die Adresse geht ueber eine ffconcat-Datei (0600) im Datenordner an
 *     ffmpeg, rtsp_transport und timeout reisen als "option"-Zeilen mit;
 *   - gestartet wird ohne Schale (proc_open mit Feld) - der Prozess ist
 *     ffmpeg selbst, die Prozessliste zeigt nur den Pfad der Datei;
 *   - die Datei wird nach dem ERSTEN Bild geloescht (ffmpeg hat sie dann
 *     gelesen), spaetestens beim Ende; bricht der Abrufer ab, beendet PHP das
 *     Skript beim naechsten Schreiben - die Abschlussfunktion loescht dann die
 *     Datei und beendet ffmpeg (15, nach 300 ms 9). Bleibt sie nach einem
 *     harten Abbruch des Arbeiters liegen, raeumt der Minutentakt sie nach
 *     15 Minuten (cam_livebild_reste()).
 *
 * Seit 1.9.33 (Geraetetest 26.10.2026 an der Gegensprechstelle "E72 Kamera
 * Gang"): kam kein erstes Bild, wartete dieser Weg bis 1.9.32 STUMM bis
 * stream_maxsec (900 s). connection_aborted() merkt einen gegangenen Abrufer
 * aber erst nach einem Schreibversuch - ffmpeg und ein Apache-Arbeiter hingen
 * deshalb bis 15 Minuten je Versuch (gemessen: 8 gleichzeitig). Gemessener
 * Ausloeser: Stream 1 dieser Kameras ist MJPEG 2048x1536; RTP/JPEG (RFC 2435)
 * traegt Breite und Hoehe in 8 Bit mal 8, also hoechstens 2040 Pixel, und
 * ffmpeg meldet "Picture size 0x0 is invalid" bzw. "Could not find codec
 * parameters ... mjpeg ... unspecified size" - ohne je ein Bild zu liefern.
 * Stream 2 (H.264 640x480) liefert sofort. Deshalb jetzt:
 *   1. Startfrist AC_RTSP_STARTFRIST_S bis zum ersten Bild, dann wird ffmpeg
 *      beendet (ac_livebild_ende()).
 *   2. Der Fehlerkanal von ffmpeg geht in eine Pipe (bis 1.9.32 /dev/null);
 *      daraus wird der Grund ermittelt - als fester, laienverstaendlicher
 *      Satz (ac_rtsp_grund()), NIE als Rohzeile: ffmpeg schreibt in manche
 *      Meldungen die Adresse samt Benutzer und Kennwort.
 *   3. Waehrend des Wartens auf das erste Bild alle AC_LEBENSZEICHEN_S
 *      Sekunden ein unsichtbares Lebenszeichen (CRLF) mit flush, damit PHP
 *      einen gegangenen Abrufer bemerkt.
 *   4. Bildquelle "Automatisch": weiter mit der Schnappschussfolge (Weg 3; die
 *      MJPEG-Weiterleitung, Weg 1, lief vorher schon). "Nur RTSP": Ende mit
 *      einem Fehlerbild (livebild_fehler.jpg) - eine HTTP-Fehlerantwort (502)
 *      ist nicht mehr moeglich, weil Kopf und Lebenszeichen schon draussen
 *      sind. */
$GLOBALS['ac_lb_datei'] = '';
$GLOBALS['ac_lb_proc'] = null;
$GLOBALS['ac_lb_rohr'] = null;
$GLOBALS['ac_lb_fehlerrohr'] = null;
$GLOBALS['ac_lb_fehler'] = '';
$GLOBALS['ac_lb_merkmale'] = array();

/* Startfrist bis zum ersten Bild (Sekunden), gezaehlt ab dem Start von ffmpeg.
 * Gemessen am Geraet (26.10.2026, stream2 H.264 ueber die gebildete Adresse):
 * ffmpeg liefert sein erstes Bild nach rund 6,4 s (Verbindungsaufbau, Suche
 * nach dem ersten Schluesselbild; probesize/analyzeduration aendern daran
 * nichts). 8 s liessen dafuer nur 1,6 s Luft - ein spaeteres Schluesselbild
 * oder ein beschaeftigter LoxBerry haetten einen gesunden Strom abgebrochen.
 * Deshalb 12 s: knapp das Doppelte des gemessenen Anlaufs. Der 0x0-Fall
 * liefert NIE ein Bild; dort kostet die laengere Frist 4 s, nicht 15 Minuten. */
define('AC_RTSP_STARTFRIST_S', 12);

/* Abstand des Lebenszeichens vor dem ersten Bild (Sekunden). Geschrieben wird
 * ein CRLF VOR der ersten Trennmarke: nach RFC 2046 Abschnitt 5.1.1 ist alles
 * vor der ersten Trennmarke Praeambel, die der Empfaenger verwirft; die erste
 * Trennmarke steht danach in ihrer Normalform CRLF "--" Grenze. PHP bemerkt
 * einen gegangenen Abrufer beim ersten oder zweiten Schreiben danach - ffmpeg
 * endet also 1-2 s nach dem Schliessen statt nach 900 s. */
define('AC_LEBENSZEICHEN_S', 1);

/* Wortschwall begrenzen: vom Fehlerkanal bleiben hoechstens so viele Bytes im
 * Speicher (danach die letzten 8 KiB). Die Merkmale fuer den Grund werden je
 * gelesenem Stueck festgehalten (ac_lb_merkmale), gehen also beim Kuerzen
 * nicht verloren. Nichts davon landet auf der Platte oder im Protokoll. */
define('AC_FEHLERKANAL_MAX', 16384);

/** Sekunden auf einer stetigen Uhr fuer Startfrist und Lebenszeichen:
 *  hrtime() (PHP >= 7.3) springt nicht, wenn die Systemuhr gestellt wird
 *  (NTP am LoxBerry; in der Probe unter WSL endete die Frist mit
 *  microtime() einmal nach 11,4 statt 12 s). */
function ac_uhr()
{
    return function_exists('hrtime') ? hrtime(true) / 1e9 : microtime(true);
}

/** Erkennungsmuster im Fehlerkanal, in der Rangfolge des Grundes. */
function ac_rtsp_muster()
{
    return array(
        'mjpeg'      => '/Picture size 0x0|unspecified size/i',
        'anmeldung'  => '/\b401\b|Unauthorized/i',
        'verweigert' => '/Connection refused/i',
        'zeit'       => '/timed out/i',
        'adresse'    => '/\b404\b|Not Found/i',
    );
}

/** Den Fehlerkanal leeren (nicht blockierend) und Merkmale festhalten. */
function ac_fehlerkanal_lesen()
{
    $r = $GLOBALS['ac_lb_fehlerrohr'];
    if (!is_resource($r)) {
        return;
    }
    $neu = @stream_get_contents($r);
    if (!is_string($neu) || $neu === '') {
        return;
    }
    /* Ein Merkmal kann an der Stueckgrenze zerschnitten sein: die letzten
       64 Bytes des Bisherigen gehoeren zur Suche dazu. */
    $suche = substr($GLOBALS['ac_lb_fehler'], -64) . $neu;
    foreach (ac_rtsp_muster() as $name => $muster) {
        if (preg_match($muster, $suche)) {
            $GLOBALS['ac_lb_merkmale'][$name] = true;
        }
    }
    $GLOBALS['ac_lb_fehler'] .= $neu;
    if (strlen($GLOBALS['ac_lb_fehler']) > AC_FEHLERKANAL_MAX) {
        $GLOBALS['ac_lb_fehler'] = substr($GLOBALS['ac_lb_fehler'], -8192);
    }
}

/**
 * Der Grund fuer "kein Bild" als fester Satz fuer das Protokoll - ohne ein
 * Wort aus dem Fehlerkanal selbst (dort kann die Adresse samt Kennwort
 * stehen). $beendet: ffmpeg endete von selbst (sonst: Frist abgelaufen).
 * Rueckgabe array(Kurzname, Satz).
 */
function ac_rtsp_grund(array $merkmale, $beendet)
{
    $saetze = array(
        'mjpeg'      => 'die Kamera liefert diesen Strom als MJPEG ueber 2040 Pixel (ffmpeg: "Picture size 0x0"'
                      . ' bzw. "unspecified size") - das laesst sich ueber RTSP nicht uebertragen. Abhilfe: im Reiter'
                      . ' Einstellungen als RTSP-Strom stream2 waehlen (H.264)',
        'anmeldung'  => 'die Kamera lehnt die Anmeldung ab (401) - Benutzer und Kennwort der Kamera pruefen',
        'verweigert' => 'die Kamera verweigert die Verbindung (Connection refused) - RTSP-Port und RTSP-Adresse pruefen',
        'zeit'       => 'die Kamera antwortet nicht (timed out) - Adresse, Port und Netz pruefen',
        'adresse'    => 'die Kamera kennt diesen Strom nicht (404) - RTSP-Adresse pruefen',
    );
    foreach (array_keys(ac_rtsp_muster()) as $name) {
        if (!empty($merkmale[$name])) {
            return array($name, $saetze[$name]);
        }
    }
    return $beendet
        ? array('ende', 'ffmpeg endete ohne Bild und ohne bekannte Meldung')
        : array('frist', 'ffmpeg lieferte kein Bild und keine bekannte Meldung');
}

/** Bildquelle "Nur RTSP" ohne Bild: ein Fehlerbild, dann die Schlussmarke. */
function ac_fehlerbild($grenze)
{
    $bild = @file_get_contents(__DIR__ . '/livebild_fehler.jpg');
    if (is_string($bild) && $bild !== '') {
        echo "--" . $grenze . "\r\n";
        echo "Content-Type: image/jpeg\r\n";
        echo "Content-Length: " . strlen($bild) . "\r\n\r\n";
        echo $bild . "\r\n";
    }
    echo "--" . $grenze . "--\r\n";
    flush();
}

function ac_livebild_ende()
{
    foreach (array('ac_lb_rohr', 'ac_lb_fehlerrohr') as $ac_n) {
        if (is_resource($GLOBALS[$ac_n])) {
            @fclose($GLOBALS[$ac_n]);
        }
        $GLOBALS[$ac_n] = null;
    }
    $pr = $GLOBALS['ac_lb_proc'];
    $GLOBALS['ac_lb_proc'] = null;
    if (is_resource($pr)) {
        @proc_terminate($pr, 15);
        usleep(300000);
        $st = @proc_get_status($pr);
        if (is_array($st) && !empty($st['running'])) {
            @proc_terminate($pr, 9);
        }
        @proc_close($pr);
    }
    $f = (string) $GLOBALS['ac_lb_datei'];
    $GLOBALS['ac_lb_datei'] = '';
    if ($f !== '' && is_file($f)) {
        @unlink($f);
    }
}

if ($ac_ffmpeg !== '' && $ac_rtsp !== '') {
    $ac_gute = max(2, min(15, (int) $ac_cfg['rtsp_quality']));
    $ac_pf = cam_paths();
    $ac_datei = $ac_pf['datadir'] . '/livebild.' . getmypid() . '.' . mt_rand(100000, 999999) . '.ffconcat';
    /* ffconcat: in einfachen Anfuehrungszeichen gilt nichts als Sonderzeichen;
       ein Anfuehrungszeichen selbst wird als '\'' geschrieben. -timeout statt
       -stimeout (C9): die RTSP-Eingabe nimmt timeout in Mikrosekunden. */
    $ac_inhalt = "ffconcat version 1.0\n"
        . "file '" . str_replace("'", "'\\''", $ac_rtsp) . "'\n"
        . "option rtsp_transport tcp\n"
        . "option timeout 5000000\n";
    register_shutdown_function('ac_livebild_ende');
    if (!cam_datei_schreiben($ac_datei, $ac_inhalt, 0600)) {
        cam_log('RTSP-Weg: die Uebergabedatei liess sich nicht schreiben - weiter mit Schnappschuessen');
    } else {
        $GLOBALS['ac_lb_datei'] = $ac_datei;
        /* Alle drei Standard-Kanaele angeben: fehlt der Eingang, erbt ffmpeg den
           des Webservers. Der Fehlerkanal ist seit 1.9.33 eine Pipe (siehe
           oben, Punkt 2); sie wird in jeder Runde geleert, damit ein Wortschwall
           ffmpeg nicht anhaelt. */
        $ac_befehl = array($ac_ffmpeg, '-nostdin', '-loglevel', 'error', '-f', 'concat', '-safe', '0',
            '-protocol_whitelist', 'file,rtsp,rtp,tcp,udp', '-i', $ac_datei,
            '-an', '-f', 'mjpeg', '-q:v', (string) $ac_gute, '-r', (string) $ac_fps, '-t', (string) $ac_max, '-');
        $ac_desc = array(
            0 => array('file', '/dev/null', 'r'),
            1 => array('pipe', 'w'),
            2 => array('pipe', 'w'),
        );
        $ac_pipes = array();
        $ac_proc = @proc_open($ac_befehl, $ac_desc, $ac_pipes);
        if (is_resource($ac_proc)) {
            $GLOBALS['ac_lb_proc'] = $ac_proc;
            $GLOBALS['ac_lb_rohr'] = $ac_pipes[1];
            $GLOBALS['ac_lb_fehlerrohr'] = $ac_pipes[2];
            stream_set_blocking($ac_pipes[1], false);
            stream_set_blocking($ac_pipes[2], false);
            $ac_puffer = '';
            $ac_bekommen = 0;
            $ac_bis = AC_STREAM_ENDE;
            $ac_start = ac_uhr();
            $ac_frist = $ac_start + AC_RTSP_STARTFRIST_S;
            $ac_zeichen = $ac_start + AC_LEBENSZEICHEN_S;
            $ac_beendet = false;   // ffmpeg endete von selbst, ohne ein Bild

            while (!connection_aborted() && time() < $ac_bis) {
                ac_fehlerkanal_lesen();
                $ac_teil = fread($ac_pipes[1], 65536);
                if ($ac_teil === false || ($ac_teil === '' && feof($ac_pipes[1]))) {
                    $ac_beendet = true;
                    break;
                }
                if ($ac_teil !== '') {
                    $ac_puffer .= $ac_teil;

                    // Einzelbilder aus dem fortlaufenden Strom schneiden: FFD8 … FFD9
                    while (true) {
                        $ac_a = strpos($ac_puffer, "\xFF\xD8");
                        if ($ac_a === false) { break; }
                        $ac_e = strpos($ac_puffer, "\xFF\xD9", $ac_a + 2);
                        if ($ac_e === false) { break; }
                        $ac_bild = substr($ac_puffer, $ac_a, $ac_e - $ac_a + 2);
                        $ac_puffer = substr($ac_puffer, $ac_e + 2);
                        if ($ac_bekommen === 0 && is_file($ac_datei)) {
                            // ffmpeg hat die Datei gelesen - das Kennwort bleibt nicht auf der Platte.
                            @unlink($ac_datei);
                        }
                        echo "--" . $ac_grenze . "\r\n";
                        echo "Content-Type: image/jpeg\r\n";
                        echo "Content-Length: " . strlen($ac_bild) . "\r\n\r\n";
                        echo $ac_bild . "\r\n";
                        flush();
                        $ac_bekommen++;
                    }
                    if (strlen($ac_puffer) > 4000000) { $ac_puffer = ''; }   // Notbremse
                }
                if ($ac_bekommen === 0) {
                    $ac_jetzt = ac_uhr();
                    if ($ac_jetzt >= $ac_frist) {
                        break;   // Startfrist abgelaufen
                    }
                    if ($ac_jetzt >= $ac_zeichen) {
                        echo "\r\n";   // Praeambel vor der ersten Trennmarke (RFC 2046) - unsichtbar
                        flush();
                        $ac_zeichen = $ac_jetzt + AC_LEBENSZEICHEN_S;
                    }
                }
                if ($ac_teil === '') { usleep(20000); }
            }

            ac_fehlerkanal_lesen();
            $ac_dauer = (int) round(ac_uhr() - $ac_start);
            ac_livebild_ende();

            if ($ac_bekommen > 0) {
                echo "--" . $ac_grenze . "--\r\n";
                exit;
            }
            /* Kein einziges Bild: Grund ins Protokoll (fester Satz, ohne Adresse
               und Kennwort; nur der Name des Stroms, wenn die Adresse ihn als
               "streamN" traegt). */
            list($ac_gname, $ac_gsatz) = ac_rtsp_grund($GLOBALS['ac_lb_merkmale'], $ac_beendet);
            $ac_strom = preg_match('#/(stream[0-9]{1,2})(?![0-9A-Za-z])#i', $ac_rtsp, $ac_sm) ? ' (' . $ac_sm[1] . ')' : '';
            cam_log('Livebild Kamera ' . $ac_kam . ': RTSP' . $ac_strom . ' lieferte '
                . ($ac_beendet ? 'kein Bild (ffmpeg endete nach ' . $ac_dauer . ' s)' : 'in ' . $ac_dauer . ' s kein Bild')
                . ' - Grund: ' . $ac_gsatz
                . ($ac_modus === 'rtsp' ? ' - Ende mit Fehlerbild (Bildquelle "Nur RTSP")' : ' - weiter mit Schnappschuessen'));
            if ($ac_modus === 'rtsp') {
                ac_fehlerbild($ac_grenze);
                exit;
            }
        } else {
            ac_livebild_ende();
            cam_log('RTSP-Weg: ffmpeg liess sich nicht starten - weiter mit Schnappschuessen');
        }
    }
}
// ---------------------------------------------------------------------------
// Weg 3: Schnappschussfolge - funktioniert immer, wenn das Einzelbild geht.
// ---------------------------------------------------------------------------
$ac_letztes = '';
$ac_fehler = 0;

while (!connection_aborted() && time() < AC_STREAM_ENDE) {
    $ac_t0 = microtime(true);
    list($ac_body, $ac_weg, $ac_err) = cam_fetch_jpeg($ac_kam);

    if ($ac_body === false || $ac_body === '') {
        $ac_fehler++;
        // Kurze Aussetzer ueberbrueckt der letzte gute Frame, damit die Anzeige
        // in Loxone nicht abbricht. Erst nach mehreren Fehlversuchen aufgeben.
        if ($ac_letztes === '' || $ac_fehler > 10) {
            cam_log('Stream beendet nach ' . $ac_fehler . ' Fehlversuchen: ' . $ac_err);
            break;
        }
        $ac_body = $ac_letztes;
    } else {
        $ac_fehler = 0;
        $ac_letztes = $ac_body;
    }

    echo "--" . $ac_grenze . "\r\n";
    echo "Content-Type: image/jpeg\r\n";
    echo "Content-Length: " . strlen($ac_body) . "\r\n\r\n";
    echo $ac_body;
    echo "\r\n";
    flush();

    $ac_rest = $ac_pause - (int) ((microtime(true) - $ac_t0) * 1000000);
    if ($ac_rest > 0) {
        usleep($ac_rest);
    }
}

echo "--" . $ac_grenze . "--\r\n";
