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
    curl_close($ch);

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

if ($ac_ffmpeg !== '' && $ac_rtsp !== '') {
    $ac_gute = max(2, min(15, (int) $ac_cfg['rtsp_quality']));
    $ac_cmd = escapeshellarg($ac_ffmpeg)
        . ' -nostdin -loglevel error -rtsp_transport tcp -stimeout 5000000'
        . ' -i ' . escapeshellarg($ac_rtsp)
        . ' -an -f mjpeg -q:v ' . $ac_gute
        . ' -r ' . escapeshellarg((string) $ac_fps)
        . ' -t ' . escapeshellarg((string) $ac_max) . ' - 2>/dev/null';

    /* Alle drei Standard-Kanaele angeben, nicht nur die Ausgabe.
     * Fehlt der Eingang, erbt ffmpeg den des Webservers; fehlt der
     * Fehlerkanal, kann ein unerwarteter Wortschwall von ffmpeg den
     * Elternprozess blockieren. Das  2>/dev/null  im Befehl allein genuegt
     * nicht - es faengt nur, was die Shell weiterreicht. */
    $ac_desc = array(
        0 => array('file', '/dev/null', 'r'),
        1 => array('pipe', 'w'),
        2 => array('file', '/dev/null', 'w'),
    );
    $ac_proc = @proc_open($ac_cmd, $ac_desc, $ac_pipes);
    if (is_resource($ac_proc)) {
        stream_set_blocking($ac_pipes[1], false);
        $ac_puffer = '';
        $ac_bekommen = 0;
        $ac_bis = AC_STREAM_ENDE;

        while (!connection_aborted() && time() < $ac_bis) {
            $ac_teil = fread($ac_pipes[1], 65536);
            if ($ac_teil === false || ($ac_teil === '' && feof($ac_pipes[1]))) {
                break;
            }
            if ($ac_teil === '') { usleep(20000); continue; }
            $ac_puffer .= $ac_teil;

            // Einzelbilder aus dem fortlaufenden Strom schneiden: FFD8 … FFD9
            while (true) {
                $ac_a = strpos($ac_puffer, "\xFF\xD8");
                if ($ac_a === false) { break; }
                $ac_e = strpos($ac_puffer, "\xFF\xD9", $ac_a + 2);
                if ($ac_e === false) { break; }
                $ac_bild = substr($ac_puffer, $ac_a, $ac_e - $ac_a + 2);
                $ac_puffer = substr($ac_puffer, $ac_e + 2);
                echo "--" . $ac_grenze . "\r\n";
                echo "Content-Type: image/jpeg\r\n";
                echo "Content-Length: " . strlen($ac_bild) . "\r\n\r\n";
                echo $ac_bild . "\r\n";
                flush();
                $ac_bekommen++;
            }
            if (strlen($ac_puffer) > 4000000) { $ac_puffer = ''; }   // Notbremse
        }

        @fclose($ac_pipes[1]);
        @proc_terminate($ac_proc);
        @proc_close($ac_proc);

        if ($ac_bekommen > 0) {
            echo "--" . $ac_grenze . "--\r\n";
            exit;
        }
        // Kein einziges Bild: auf die Schnappschussfolge zurueckfallen
        cam_log('RTSP lieferte nichts (' . cam_rtsp_maske($ac_rtsp) . ') - weiter mit Schnappschuessen');
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
