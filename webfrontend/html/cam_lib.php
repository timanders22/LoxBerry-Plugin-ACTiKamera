<?php
/**
 * ACTi Kamera - gemeinsame Bibliothek
 *
 * Holt Schnappschuesse und kurze Videoclips von ACTi-Netzwerkkameras
 * (E-Serie und alle Modelle mit der klassischen CGI-API) und stellt sie
 * Loxone bereit - OHNE dass Benutzername und Passwort in der Loxone-
 * Projektdatei stehen muessen. Die Zugangsdaten liegen ausschliesslich
 * hier in der Plugin-Konfiguration (Datei nur fuer den Besitzer lesbar).
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-Globals - deshalb tragen alle
 * Variablen dieses Plugins das Praefix ac_ bzw. die Funktionen cam_.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
date_default_timezone_set('Europe/Berlin');

function cam_paths()
{
    $lbhomedir = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
    $plugindir = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    if ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $plugindir) === false) {
        $plugindir = 'actikamera';
    }
    if ($lbhomedir) {
        return array(
            'config' => $lbhomedir . '/config/plugins/' . $plugindir . '/cam.json',
            'backup' => $lbhomedir . '/config/plugins/' . $plugindir . '.backup.json',
            'log' => $lbhomedir . '/log/plugins/' . $plugindir . '/cam.log',
            'data' => $lbhomedir . '/data/plugins/' . $plugindir,
            'web' => $lbhomedir . '/webfrontend/html/plugins/' . $plugindir,
            'tmp' => '/tmp/actikamera',
            'lbhome' => $lbhomedir,
        );
    }
    $base = dirname(dirname(__DIR__));
    return array(
        'config' => $base . '/config/cam.json',
        'backup' => $base . '/config/cam.backup.json',
        'log' => sys_get_temp_dir() . '/actikamera/cam.log',
        'data' => sys_get_temp_dir() . '/actikamera/data',
        'web' => __DIR__,
        'tmp' => sys_get_temp_dir() . '/actikamera',
        'lbhome' => '',
    );
}

function cam_config()
{
    $p = cam_paths();
    if ((!is_file($p['config']) || trim((string) @file_get_contents($p['config'])) === ''
         || trim((string) @file_get_contents($p['config'])) === '{}') && is_file($p['backup'])) {
        @mkdir(dirname($p['config']), 0775, true);
        @copy($p['backup'], $p['config']);
        @chmod($p['config'], 0600);
    }
    $cfg = is_file($p['config']) ? (json_decode((string) file_get_contents($p['config']), true) ?: array()) : array();
    if (!is_array($cfg)) {
        $cfg = array();
    }
    $cfg += array(
        'host' => '',                // IP oder Hostname der Kamera
        'user' => '',
        'pass' => '',
        'channel' => 1,              // Kanal (bei Einzelkameras immer 1)
        'resolution' => '',          // z. B. N1280x720 - leer = Kameraeinstellung
        'snapcmd' => 'SNAPSHOT=N1920x1080,100&DUMMY=n',  // vollstaendiger CGI-Befehl
        'snapurl' => '',             // komplette funktionierende URL (hat Vorrang)
        'stream_fps' => 2,           // Bilder je Sekunde im MJPEG-Strom
        'stream_maxsec' => 900,      // Notbremse: laengster Strom in Sekunden
        'stream_token' => '',        // optionales Kennwort fuer den Stromabruf
        'stream_mode' => 'auto',     // auto | mjpeg | rtsp (ffmpeg) | jpeg (Schnappschussfolge)
        'mjpeg_url' => '',           // leer = /cgi-bin/cmd/system?GET_STREAM der Kamera
        'rtsp_url' => '',            // leer = ueber die System-Schnittstelle erfragen
        'rtsp_quality' => 5,         // 2 (fein) bis 15 (grob) - JPEG-Guete von ffmpeg
        'rtsp_stream' => 2,          // 1 = Hauptstrom (hohe Aufloesung), 2 = Nebenstrom
        'rtsp_port' => 7070,         // RTSP-Port der Kamera
        'timeout' => 8,              // Sekunden fuer einen Bildabruf
        'keep_days' => 90,           // Aufbewahrung der Aufnahmen in Tagen
        'clip_seconds' => 10,        // Laenge eines Videoclips
        'clip_fps' => 2,             // Bilder je Sekunde im Clip
        'auth' => 'auto',            // auto | url | basic | digest
        'notify' => array(),
        'mqtt_enabled' => 0,
        'mqtt_topic' => 'acti',
        'keep_max' => 0,             // max. Dateien je Archiv (0 = unbegrenzt)
        'timelapse' => 0,            // Zeitraffer-Aufnahme aktiv
        'timelapse_time' => '12:00', // taeglich zu dieser Uhrzeit
        'ai_url' => '',              // z. B. http://192.0.2.10:32168/v1/vision/detection
        'ai_min' => 50,              // Mindest-Konfidenz in %
        'webhook1' => '',            // POST mit JSON
        'webhook2' => '',            // GET mit ?bild=<URL>
    );
    if (!is_array($cfg['notify'])) {
        $cfg['notify'] = array();
    }
    $cfg['notify'] += array('push' => 1, 'push_minutes' => 2);
    return $cfg;
}

function cam_config_save(array $cfg)
{
    $p = cam_paths();
    if (!is_dir(dirname($p['config']))) {
        @mkdir(dirname($p['config']), 0775, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    // json_encode liefert bei ungueltigem UTF-8 false, und file_put_contents
    // schriebe dann eine Datei mit NULL Bytes - und meldete das als Erfolg.
    if ($json === false || @file_put_contents($p['config'], $json) === false) {
        return false;
    }
    // Zugangsdaten: nur fuer den Besitzer lesbar
    @chmod($p['config'], 0600);
    @copy($p['config'], $p['backup']);
    @chmod($p['backup'], 0600);
    return true;
}

/* ---------------- Protokoll ---------------- */

function cam_log($msg)
{
    $p = cam_paths();
    $f = $p['log'];
    if (!is_dir(dirname($f))) {
        @mkdir(dirname($f), 0775, true);
    }
    if (is_file($f) && filesize($f) > 512000) {
        $tail = array_slice(file($f, FILE_IGNORE_NEW_LINES) ?: array(), -200);
        @file_put_contents($f, implode("\n", $tail) . "\n");
    }
    // Passwoerter niemals ins Protokoll
    $cfg = cam_config();
    if ($cfg['pass'] !== '') {
        $msg = str_replace($cfg['pass'], '********', $msg);
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/* ---------------- Kamera-Zugriff ---------------- */

function cam_datadir()
{
    $p = cam_paths();
    foreach (array($p['data'], $p['data'] . '/bilder', $p['data'] . '/clips', $p['data'] . '/timelapse') as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
    }
    return $p['data'];
}

/**
 * Nur die Zeichen ersetzen, die eine Abfragezeichenkette zerreissen wuerden.
 * WICHTIG: Die ACTi-CGI dekodiert ihre Parameter NICHT prozentweise - ein
 * vollstaendiges rawurlencode() macht aus einem Passwort mit Sonderzeichen
 * einen anderen String und die Kamera antwortet "ERROR: not authorized".
 */
function cam_q($wert)
{
    return strtr((string) $wert, array('%' => '%25', '&' => '%26', '=' => '%3D',
                                       '#' => '%23', '+' => '%2B', ' ' => '%20'));
}

/** URL fuer die Anzeige: Passwort durch Sterne ersetzen, Laenge bleibt sichtbar. */
function cam_maske($url)
{
    return preg_replace_callback('/PWD=([^&]*)/i', function ($m) {
        $n = strlen($m[1]);
        return 'PWD=' . str_repeat('*', min(12, $n)) . '(' . $n . ' Zeichen)';
    }, (string) $url);
}

/** Basis-URL der CGI-Schnittstelle. Bei $mit_login=false ohne USER/PWD. */
function cam_url($befehl, $mit_login = true)
{
    $cfg = cam_config();
    $host = trim((string) $cfg['host']);
    if ($host === '') {
        return '';
    }
    if (strpos($host, 'http') !== 0) {
        $host = 'http://' . $host;
    }
    $url = rtrim($host, '/') . '/cgi-bin/encoder?';
    if ($mit_login) {
        $url .= 'USER=' . cam_q($cfg['user']) . '&PWD=' . cam_q($cfg['pass']) . '&';
    }
    return $url . $befehl;
}

/**
 * Adresse des fortlaufenden MJPEG-Stroms der Kamera.
 * Die ACTi-Firmware liefert unter /cgi-bin/cmd/system?GET_STREAM keinen Text,
 * sondern unmittelbar einen multipart-Bilderstrom - die beste Quelle fuer den
 * Weiterleiter, weil weder ffmpeg noch wiederholte Einzelabrufe noetig sind.
 */
function cam_mjpeg_url()
{
    $cfg = cam_config();
    $eigen = trim((string) $cfg['mjpeg_url']);
    if ($eigen !== '') {
        return $eigen;
    }
    $host = trim((string) $cfg['host']);
    if ($host === '') {
        return '';
    }
    if (strpos($host, 'http') !== 0) {
        $host = 'http://' . $host;
    }
    return rtrim($host, '/') . '/cgi-bin/cmd/system?USER=' . cam_q($cfg['user'])
         . '&PWD=' . cam_q($cfg['pass']) . '&GET_STREAM';
}

/**
 * Ruft die System-Schnittstelle der Kamera auf: /cgi-bin/cmd/system?<Befehl>
 * Das ist ein anderer Zweig als /cgi-bin/encoder und liefert Auskuenfte wie
 * GET_STREAM (RTSP-Adresse), GET_MODEL oder GET_FIRMWARE_VERSION.
 * Rueckgabe: array($antwort, $httpcode, $fehler)
 */
function cam_system($befehl)
{
    $cfg = cam_config();
    $host = trim((string) $cfg['host']);
    if ($host === '') {
        return array(false, 0, 'Keine Kamera-Adresse konfiguriert');
    }
    if (strpos($host, 'http') !== 0) {
        $host = 'http://' . $host;
    }
    $url = rtrim($host, '/') . '/cgi-bin/cmd/system?USER=' . cam_q($cfg['user'])
         . '&PWD=' . cam_q($cfg['pass']) . '&' . $befehl;
    list($body, $code, $err) = cam_http($url, 8, '');
    if ($body === false || $code >= 400) {
        // Zweiter Versuch ohne Zugangsdaten in der URL, dafuer mit HTTP-Anmeldung
        $url2 = rtrim($host, '/') . '/cgi-bin/cmd/system?' . $befehl;
        foreach (array('basic', 'digest') as $verf) {
            list($body2, $code2, $err2) = cam_http($url2, 8, $verf);
            if ($body2 !== false && $code2 < 400) {
                return array($body2, $code2, '');
            }
        }
    }
    return array($body, $code, $err);
}

/**
 * Ermittelt die RTSP-Adresse: entweder hinterlegt oder ueber GET_STREAM erfragt.
 * Die Zugangsdaten werden erst hier eingesetzt, damit sie nirgends gespeichert sind.
 */
function cam_rtsp_url($nur_gespeichert = false)
{
    $cfg = cam_config();
    $url = trim((string) $cfg['rtsp_url']);

    if ($url === '' && !$nur_gespeichert) {
        list($antwort, $code, $err) = cam_system('GET_STREAM');
        if ($antwort !== false && preg_match('#rtsp://[^\s\'"&]+#i', (string) $antwort, $m)) {
            $url = $m[0];
        } elseif ($antwort !== false && preg_match('#GET_STREAM=\'?([^\s\'"]+)#i', (string) $antwort, $m)) {
            $url = $m[1];
        }
        if ($url === '') {
            // Am Geraet geprueft: rtsp://<host>:7070//stream1 bzw. //stream2.
            // Der doppelte Schraegstrich gehoert dazu, die Kamera erwartet ihn so.
            $host = preg_replace('#^https?://#i', '', trim((string) $cfg['host']));
            $host = rtrim(explode('/', $host)[0], '/');
            $host = explode(':', $host)[0];
            $port = max(1, min(65535, (int) $cfg['rtsp_port']));
            $nr = ((int) $cfg['rtsp_stream']) === 1 ? 1 : 2;
            if ($host !== '') {
                $url = 'rtsp://' . $host . ':' . $port . '//stream' . $nr;
            }
        }
    }
    if ($url === '') {
        return '';
    }
    // Benutzer und Passwort in die RTSP-Adresse einsetzen, falls noch nicht enthalten
    if (strpos($url, '@') === false && trim((string) $cfg['user']) !== '') {
        $url = preg_replace('#^rtsp://#i',
            'rtsp://' . rawurlencode($cfg['user']) . ':' . rawurlencode($cfg['pass']) . '@', $url);
    }
    return $url;
}

/** RTSP-Adresse fuer die Anzeige: Passwort unkenntlich machen. */
function cam_rtsp_maske($url)
{
    return preg_replace('#(rtsp://[^:/@]*):[^@]*@#i', '$1:********@', (string) $url);
}

/** Ist ffmpeg vorhanden? Nur dann ist der RTSP-Weg moeglich. */
function cam_ffmpeg()
{
    $pfad = trim((string) @shell_exec('command -v ffmpeg 2>/dev/null'));
    return $pfad !== '' ? $pfad : '';
}

/**
 * Rohabruf mit cURL, faellt auf PHP-Streams zurueck.
 * $auth: '' = keine HTTP-Anmeldung (Zugangsdaten stehen in der URL),
 *        'basic' oder 'digest' = zusaetzliche HTTP-Anmeldung.
 */
function cam_http($url, $timeout = 8, $auth = '')
{
    if ($url === '') {
        return array(false, 0, 'Keine Kamera-Adresse konfiguriert');
    }
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(5, $timeout));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        if ($auth === 'basic' || $auth === 'digest') {
            $cfg = cam_config();
            curl_setopt($ch, CURLOPT_HTTPAUTH, $auth === 'digest' ? CURLAUTH_DIGEST : CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $cfg['user'] . ':' . $cfg['pass']);
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = ($body === false) ? ('cURL-Fehler: ' . curl_error($ch)) : '';
        curl_close($ch);
        return array($body, $code, $err);
    }
    $opt = array('timeout' => $timeout, 'ignore_errors' => true, 'user_agent' => 'LoxBerry ACTi-Plugin');
    if ($auth === 'basic') {
        $cfg = cam_config();
        $opt['header'] = 'Authorization: Basic ' . base64_encode($cfg['user'] . ':' . $cfg['pass']);
    }
    $ctx = stream_context_create(array('http' => $opt));
    $body = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
    }
    return array($body, $code, $body === false ? 'Verbindung fehlgeschlagen' : '');
}

/**
 * Ein Bild von der Kamera holen. Probiert - solange das Anmeldeverfahren auf
 * "auto" steht - mehrere Kombinationen durch und merkt sich die erfolgreiche.
 * Rueckgabe: array(JPEG|false, Beschreibung des Wegs, Fehlertext)
 */
function cam_fetch_jpeg()
{
    $cfg = cam_config();
    $p = cam_paths();
    $res = trim((string) $cfg['resolution']);
    $kanal = max(1, (int) $cfg['channel']);
    $befehle = array();
    if (trim((string) $cfg['snapcmd']) !== '') {
        $befehle[] = trim((string) $cfg['snapcmd']);
    }
    if ($res !== '') {
        $befehle[] = 'SNAPSHOT=' . $res . '&DUMMY=n';
        $befehle[] = 'SNAPSHOT=' . $res;
    }
    $befehle[] = 'SNAPSHOT=N1920x1080,100&DUMMY=n';
    $befehle[] = 'SNAPSHOT=N1280x720,100&DUMMY=n';
    $befehle[] = 'SNAPSHOT&DUMMY=n';
    $befehle[] = 'SNAPSHOT';
    $befehle[] = 'CHANNEL=' . $kanal . '&SNAPSHOT&DUMMY=n';
    $befehle = array_values(array_unique($befehle));
    $verfahren = $cfg['auth'] === 'auto' ? array('url', 'basic', 'digest') : array($cfg['auth']);

    // Zuletzt erfolgreicher Weg zuerst
    $merker = $p['tmp'] . '/weg.txt';
    $wege = array();
    if (is_file($merker)) {
        $letzter = trim((string) @file_get_contents($merker));
        $teile = explode('|', $letzter, 2);
        if (count($teile) === 2) {
            $wege[] = array($teile[0], $teile[1]);
        }
    }
    foreach ($verfahren as $v) {
        foreach ($befehle as $b) {
            $wege[] = array($b, $v);
        }
    }
    // Eine vollstaendig hinterlegte URL hat immer Vorrang - damit laesst sich
    // eine nachweislich funktionierende Adresse unveraendert uebernehmen.
    $fertig = trim((string) $cfg['snapurl']);
    if ($fertig !== '') {
        $u = $fertig . (strpos($fertig, '?') === false ? '?' : '&') . 'z=' . time();
        list($body, $code, $err) = cam_http($u, (int) $cfg['timeout'], '');
        if ($body !== false && substr((string) $body, 0, 2) === "\xff\xd8") {
            return array($body, 'vollstaendige URL', '');
        }
        $letzterFehler = $err !== '' ? $err
            : ($body === false || $body === '' ? ('keine Antwort (HTTP ' . $code . ')')
               : trim(substr(strip_tags((string) $body), 0, 120)));
        if ($code === 401 || stripos((string) $letzterFehler, 'not authorized') !== false) {
            return array(false, '', 'Die hinterlegte vollstaendige URL wird abgewiesen (HTTP ' . $code . '): ' . $letzterFehler);
        }
    }

    $letzterFehler = isset($letzterFehler) ? $letzterFehler : '';
    foreach ($wege as $w) {
        list($befehl, $verf) = $w;
        // Bei HTTP-Anmeldung die Zugangsdaten NICHT zusaetzlich in die URL schreiben
        $url = cam_url($befehl, $verf === 'url');
        list($body, $code, $err) = cam_http($url, (int) $cfg['timeout'], $verf === 'url' ? '' : $verf);
        if ($body !== false && $body !== '' && substr((string) $body, 0, 2) === "\xff\xd8") {
            if (!is_dir($p['tmp'])) {
                @mkdir($p['tmp'], 0775, true);
            }
            @file_put_contents($merker, $befehl . '|' . $verf);
            return array($body, $befehl . ' / ' . $verf, '');
        }
        $letzterFehler = $err !== '' ? $err
            : ($body === false || $body === '' ? ('keine Antwort (HTTP ' . $code . ')')
               : trim(substr(strip_tags((string) $body), 0, 120)));
    }
    @unlink($merker);
    return array(false, '', $letzterFehler);
}

/**
 * Diagnose: probiert alle Kombinationen aus Befehl und Anmeldeverfahren und
 * meldet fuer jede, was die Kamera geantwortet hat. Das ist der schnellste Weg,
 * die Eigenheiten eines bestimmten ACTi-Modells zu finden.
 */
function cam_diag()
{
    $cfg = cam_config();
    $kanal = max(1, (int) $cfg['channel']);
    $res = trim((string) $cfg['resolution']);
    $befehle = array();
    if (trim((string) $cfg['snapcmd']) !== '') {
        $befehle[] = trim((string) $cfg['snapcmd']);
    }
    if ($res !== '') {
        $befehle[] = 'SNAPSHOT=' . $res . '&DUMMY=n';
    }
    foreach (array('SNAPSHOT=N1920x1080,100&DUMMY=n', 'SNAPSHOT=N1280x720,100&DUMMY=n',
                   'SNAPSHOT=N640x480,100&DUMMY=n', 'SNAPSHOT&DUMMY=n', 'SNAPSHOT',
                   'CHANNEL=' . $kanal . '&SNAPSHOT&DUMMY=n', 'GET_STREAM') as $b) {
        $befehle[] = $b;
    }
    $befehle = array_values(array_unique($befehle));
    $zeilen = array();
    $fertig = trim((string) $cfg['snapurl']);
    if ($fertig !== '') {
        list($body, $code, $err) = cam_http($fertig, min(6, (int) $cfg['timeout']), '');
        $jpeg = ($body !== false && substr((string) $body, 0, 2) === "\xff\xd8");
        $zeilen[] = array('befehl' => 'VOLLSTAENDIGE URL', 'auth' => 'wie hinterlegt', 'http' => $code,
            'ms' => 0, 'ok' => $jpeg ? 1 : 0,
            'antwort' => $jpeg ? ('JPEG, ' . round(strlen($body) / 1024) . ' kB')
                : ($err !== '' ? $err : trim(substr(strip_tags((string) $body), 0, 80))));
        if ($jpeg) {
            return $zeilen;
        }
    }
    foreach (array('url', 'basic', 'digest') as $verf) {
        foreach ($befehle as $befehl) {
            $url = cam_url($befehl, $verf === 'url');
            $start = microtime(true);
            list($body, $code, $err) = cam_http($url, min(6, (int) $cfg['timeout']), $verf === 'url' ? '' : $verf);
            $ms = round((microtime(true) - $start) * 1000);
            $jpeg = ($body !== false && substr((string) $body, 0, 2) === "\xff\xd8");
            $antwort = $jpeg ? ('JPEG, ' . round(strlen($body) / 1024) . ' kB')
                : ($err !== '' ? $err : trim(substr(strip_tags((string) $body), 0, 80)));
            $zeilen[] = array(
                'befehl' => $befehl, 'auth' => $verf, 'http' => $code,
                'ms' => $ms, 'ok' => $jpeg ? 1 : 0, 'antwort' => $antwort,
            );
            if ($jpeg) {
                // Erfolgreichen Weg gleich merken
                $p = cam_paths();
                if (!is_dir($p['tmp'])) {
                    @mkdir($p['tmp'], 0775, true);
                }
                @file_put_contents($p['tmp'] . '/weg.txt', $befehl . '|' . $verf);
                cam_log('Diagnose: erfolgreicher Weg gefunden - ' . $befehl . ' / ' . $verf);
                return $zeilen;
            }
        }
    }
    cam_log('Diagnose: keine Kombination lieferte ein Bild (' . count($zeilen) . ' Versuche)');
    return $zeilen;
}

/**
 * Einen Schnappschuss holen und speichern.
 * Rueckgabe: array(ok, Dateiname|Fehlertext)
 */
function cam_snapshot($anlass = 'manuell')
{
    $cfg = cam_config();
    list($body, $weg, $err) = cam_fetch_jpeg();
    if ($body === false) {
        cam_log('FEHLER Schnappschuss (' . $anlass . '): ' . $err);
        $hinweis = stripos($err, 'not authorized') !== false
            ? ' Tipp: Benutzer/Passwort pruefen und im Reiter Test die Anmeldearten durchprobieren.' : '';
        return array(0, 'Kein Bild erhalten: ' . $err . $hinweis);
    }
    $dir = cam_datadir() . '/bilder';
    $name = date('Ymd_His') . '_' . preg_replace('/[^a-z0-9]/i', '', $anlass) . '.jpg';
    if (@file_put_contents($dir . '/' . $name, $body) === false) {
        cam_log('FEHLER: Bild konnte nicht gespeichert werden: ' . $dir . '/' . $name);
        return array(0, 'Bild konnte nicht gespeichert werden');
    }
    // Immer auch als "letztes Bild" ablegen - das holt sich Loxone
    $p = cam_paths();
    @file_put_contents($p['web'] . '/letztesbild.jpg', $body);
    @file_put_contents(cam_datadir() . '/letztesbild.json', json_encode(array(
        'datei' => $name, 'anlass' => $anlass, 'zeit' => date('c'), 'bytes' => strlen($body),
    )));
    cam_log('Schnappschuss gespeichert (' . $anlass . '): ' . $name . ', '
        . round(strlen($body) / 1024) . ' kB, Weg: ' . $weg);
    $objekte = cam_ai($dir . '/' . $name);
    if ($objekte) {
        cam_log('Erkannt: ' . implode(', ', $objekte));
        @file_put_contents(cam_datadir() . '/letztesbild.json', json_encode(array(
            'datei' => $name, 'anlass' => $anlass, 'zeit' => date('c'),
            'bytes' => strlen($body), 'objekte' => $objekte,
        )));
    }
    cam_cleanup();
    cam_mqtt(array('letztes_bild' => $name, 'anlass' => $anlass, 'zeit' => date('c'),
                   'objekte' => implode(',', $objekte)));
    cam_webhooks($name, $anlass, $objekte);
    return array(1, $name . ($objekte ? ' (' . implode(', ', $objekte) . ')' : ''));
}

/**
 * Kurzen Clip aufnehmen: mehrere Einzelbilder im Abstand von 1/fps Sekunden.
 * ACTi-Kameras liefern MJPEG; ein echter Videoschnitt wuerde ffmpeg
 * voraussetzen - die Bildserie kommt ohne Zusatzpakete aus und reicht,
 * um zu sehen wer vor der Tuer stand.
 */
function cam_clip($anlass = 'klingel')
{
    $cfg = cam_config();
    $sek = max(2, min(60, (int) $cfg['clip_seconds']));
    $fps = max(1, min(5, (int) $cfg['clip_fps']));
    $dir = cam_datadir() . '/clips/' . date('Ymd_His') . '_' . preg_replace('/[^a-z0-9]/i', '', $anlass);
    @mkdir($dir, 0775, true);
    $n = 0;
    $ende = microtime(true) + $sek;
    while (microtime(true) < $ende) {
        list($body, $weg, $err) = cam_fetch_jpeg();
        if ($body !== false) {
            @file_put_contents(sprintf('%s/%03d.jpg', $dir, ++$n), $body);
        }
        usleep((int) (1000000 / $fps));
    }
    cam_log('Clip aufgenommen (' . $anlass . '): ' . $n . ' Bilder in ' . $dir);
    cam_cleanup();
    return array($n > 0 ? 1 : 0, $n > 0 ? basename($dir) . ' (' . $n . ' Bilder)' : 'Keine Bilder erhalten');
}

/**
 * Archiv aufraeumen: nach Alter UND nach Hoechstzahl je Archiv.
 * Die jeweils neuesten Dateien bleiben immer erhalten.
 */
function cam_cleanup()
{
    $cfg = cam_config();
    $tage = (int) $cfg['keep_days'];          // 0 = unbegrenzt
    $max = (int) $cfg['keep_max'];            // 0 = unbegrenzt
    $grenze = $tage > 0 ? time() - $tage * 86400 : 0;
    $weg = 0;

    foreach (array('/bilder/*.jpg', '/timelapse/*.jpg') as $muster) {
        $dateien = glob(cam_datadir() . $muster) ?: array();
        if ($grenze > 0) {
            foreach ($dateien as $f) {
                if (filemtime($f) < $grenze) {
                    @unlink($f);
                    $weg++;
                }
            }
            $dateien = glob(cam_datadir() . $muster) ?: array();
        }
        if ($max > 0 && count($dateien) > $max) {
            usort($dateien, function ($a, $b) { return filemtime($b) - filemtime($a); });
            foreach (array_slice($dateien, $max) as $f) {
                @unlink($f);
                $weg++;
            }
        }
    }

    $clips = glob(cam_datadir() . '/clips/*', GLOB_ONLYDIR) ?: array();
    if ($grenze > 0) {
        foreach ($clips as $d) {
            if (filemtime($d) < $grenze) {
                foreach (glob($d . '/*') ?: array() as $f) {
                    @unlink($f);
                }
                @rmdir($d);
                $weg++;
            }
        }
        $clips = glob(cam_datadir() . '/clips/*', GLOB_ONLYDIR) ?: array();
    }
    if ($max > 0 && count($clips) > $max) {
        usort($clips, function ($a, $b) { return filemtime($b) - filemtime($a); });
        foreach (array_slice($clips, $max) as $d) {
            foreach (glob($d . '/*') ?: array() as $f) {
                @unlink($f);
            }
            @rmdir($d);
            $weg++;
        }
    }
    if ($weg > 0) {
        cam_log('Aufraeumen: ' . $weg . ' Aufnahmen entfernt (Alter: '
            . ($tage > 0 ? $tage . ' Tage' : 'unbegrenzt') . ', Hoechstzahl: '
            . ($max > 0 ? $max : 'unbegrenzt') . ')');
    }
    return $weg;
}

/* ---------------- Zeitraffer ---------------- */

/**
 * Ein Bild fuer den Zeitraffer aufnehmen (Dateiname = Datum) und - falls
 * ffmpeg vorhanden ist - daraus "zeitraffer.mp4" neu erzeugen.
 */
function cam_timelapse()
{
    list($body, $weg, $err) = cam_fetch_jpeg();
    if ($body === false) {
        cam_log('FEHLER Zeitraffer: ' . $err);
        return array(0, $err);
    }
    $dir = cam_datadir() . '/timelapse';
    $name = date('Y-m-d') . '.jpg';
    @file_put_contents($dir . '/' . $name, $body);
    cam_log('Zeitraffer-Bild gespeichert: ' . $name);
    $film = cam_timelapse_video();
    cam_cleanup();
    return array(1, $name . ($film ? ' (Video neu erzeugt)' : ''));
}

/** Erzeugt zeitraffer.mp4 aus allen Zeitrafferbildern - nur wenn ffmpeg da ist. */
function cam_timelapse_video()
{
    $out = array();
    @exec('command -v ffmpeg 2>/dev/null', $out);
    if (empty($out)) {
        return 0;
    }
    $dir = cam_datadir() . '/timelapse';
    $bilder = glob($dir . '/*.jpg') ?: array();
    if (count($bilder) < 2) {
        return 0;
    }
    sort($bilder);
    $liste = $dir . '/liste.txt';
    $txt = '';
    foreach ($bilder as $b) {
        $txt .= "file '" . $b . "'\nduration 0.4\n";
    }
    $txt .= "file '" . end($bilder) . "'\n";
    @file_put_contents($liste, $txt);
    $ziel = cam_paths()['web'] . '/zeitraffer.mp4';
    @exec('ffmpeg -y -f concat -safe 0 -i ' . escapeshellarg($liste)
        . ' -vf "scale=1280:-2,format=yuv420p" -r 25 ' . escapeshellarg($ziel) . ' 2>&1', $o, $rc);
    @unlink($liste);
    if ($rc === 0) {
        cam_log('Zeitraffer-Video erzeugt aus ' . count($bilder) . ' Bildern');
        return 1;
    }
    cam_log('FEHLER Zeitraffer-Video: ' . trim(implode(' ', array_slice($o, -2))));
    return 0;
}

/* ---------------- KI-Objekterkennung ---------------- */

/**
 * Bild an einen Erkennungsdienst schicken (CodeProject.AI oder DeepStack -
 * beide nehmen dieselbe Form entgegen: POST mit Feld "image").
 * Rueckgabe: Liste erkannter Objekte, z. B. array('person', 'car').
 */
function cam_ai($datei)
{
    $cfg = cam_config();
    $url = trim((string) $cfg['ai_url']);
    if ($url === '' || !is_file($datei) || !function_exists('curl_init')) {
        return array();
    }
    $min = max(1, min(99, (int) $cfg['ai_min'])) / 100;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, array(
        'image' => new CURLFile($datei, 'image/jpeg', basename($datei)),
        'min_confidence' => (string) $min,
    ));
    $antwort = curl_exec($ch);
    $fehler = curl_error($ch);
    curl_close($ch);
    if ($antwort === false) {
        cam_log('FEHLER Objekterkennung: ' . $fehler);
        return array();
    }
    $d = @json_decode((string) $antwort, true);
    if (!is_array($d) || empty($d['predictions'])) {
        return array();
    }
    $objekte = array();
    foreach ((array) $d['predictions'] as $p) {
        $konf = isset($p['confidence']) ? (float) $p['confidence'] : 0;
        $label = isset($p['label']) ? (string) $p['label'] : '';
        if ($label !== '' && $konf >= $min && !in_array($label, $objekte, true)) {
            $objekte[] = $label;
        }
    }
    return $objekte;
}

/* ---------------- Webhooks ---------------- */

/** Nach jeder Aufnahme aufgerufen: Webhook 1 als POST/JSON, Webhook 2 als GET. */
function cam_webhooks($name, $anlass, $objekte)
{
    $cfg = cam_config();
    $bildurl = 'http://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'loxberry')
             . '/plugins/actikamera/letztesbild.jpg';
    $daten = array('bild' => $bildurl, 'datei' => $name, 'anlass' => $anlass,
                   'objekte' => $objekte, 'zeit' => date('c'));
    if (trim((string) $cfg['webhook1']) !== '' && function_exists('curl_init')) {
        $ch = curl_init(trim((string) $cfg['webhook1']));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($daten));
        curl_exec($ch);
        curl_close($ch);
        cam_log('Webhook 1 ausgeloest');
    }
    if (trim((string) $cfg['webhook2']) !== '') {
        $u = trim((string) $cfg['webhook2']);
        $u .= (strpos($u, '?') === false ? '?' : '&') . 'bild=' . rawurlencode($bildurl)
            . '&anlass=' . rawurlencode($anlass)
            . ($objekte ? '&objekte=' . rawurlencode(implode(',', $objekte)) : '');
        cam_http($u, 8);
        cam_log('Webhook 2 ausgeloest');
    }
}

/** Verbindungstest: liefert Klartext-Ergebnis fuer die Oberflaeche. */
function cam_test()
{
    $cfg = cam_config();
    if (trim((string) $cfg['host']) === '') {
        return 'FEHLER: Es ist keine Kamera-Adresse eingetragen.';
    }
    $start = microtime(true);
    list($body, $code, $err) = cam_http(cam_url('SNAPSHOT'), (int) $cfg['timeout']);
    $ms = round((microtime(true) - $start) * 1000);
    if ($body === false || $body === '' || $body === null) {
        return 'FEHLER: ' . ($err !== '' ? $err : 'keine Antwort (HTTP ' . $code . ')')
             . ' - Adresse, Benutzer und Passwort pruefen.';
    }
    if (substr($body, 0, 2) !== "\xff\xd8") {
        return 'FEHLER: Die Kamera antwortet, liefert aber kein Bild. Antwort: '
             . trim(substr(strip_tags((string) $body), 0, 200));
    }
    return 'OK: Bild erhalten (' . round(strlen($body) / 1024) . ' kB in ' . $ms . ' ms).';
}

/* ---------------- Zustand fuer Loxone ---------------- */

function cam_state()
{
    $cfg = cam_config();
    $letzte = @json_decode((string) @file_get_contents(cam_datadir() . '/letztesbild.json'), true);
    $bilder = glob(cam_datadir() . '/bilder/*.jpg') ?: array();
    $clips = glob(cam_datadir() . '/clips/*', GLOB_ONLYDIR) ?: array();
    $alter = is_array($letzte) && !empty($letzte['zeit'])
        ? max(0, (int) round((time() - strtotime($letzte['zeit'])) / 60)) : -1;
    $tl = glob(cam_datadir() . '/timelapse/*.jpg') ?: array();
    return array(
        'ok' => trim((string) $cfg['host']) !== '' ? 1 : 0,
        'objekte' => is_array($letzte) && !empty($letzte['objekte']) ? (array) $letzte['objekte'] : array(),
        'person' => is_array($letzte) && !empty($letzte['objekte']) && in_array('person', (array) $letzte['objekte'], true) ? 1 : 0,
        'timelapse' => count($tl),
        'letztes_bild' => is_array($letzte) ? (string) $letzte['datei'] : '',
        'letzter_anlass' => is_array($letzte) ? (string) $letzte['anlass'] : '',
        'alter_min' => $alter,
        'bilder' => count($bilder),
        'clips' => count($clips),
        'push' => empty($cfg['notify']['push']) ? 0 : 1,
        'zeit' => date('c'),
    );
}

/** Push-Fenster: nach einer Aufnahme fuer X Minuten aktiv (0->1-Flanke in Loxone). */
function cam_push_active()
{
    $cfg = cam_config();
    if (empty($cfg['notify']['push'])) {
        return 0;
    }
    $letzte = @json_decode((string) @file_get_contents(cam_datadir() . '/letztesbild.json'), true);
    if (!is_array($letzte) || empty($letzte['zeit'])) {
        return 0;
    }
    $min = max(1, (int) $cfg['notify']['push_minutes']);
    return (time() - strtotime($letzte['zeit'])) < $min * 60 ? 1 : 0;
}

/* ---------------- MQTT (LoxBerry MQTT Gateway) ---------------- */

function cam_mqtt($werte)
{
    $cfg = cam_config();
    if (empty($cfg['mqtt_enabled'])) {
        return;
    }
    $p = cam_paths();
    if ($p['lbhome'] === '') {
        return;
    }
    $gen = @json_decode((string) @file_get_contents($p['lbhome'] . '/config/system/general.json'), true);
    $udpport = 0;
    if (isset($gen['Mqtt']['Udpinport'])) {
        $udpport = (int) $gen['Mqtt']['Udpinport'];
    }
    if (!$udpport && isset($gen['mqtt']['udpinport'])) {
        $udpport = (int) $gen['mqtt']['udpinport'];
    }
    if (!$udpport) {
        return;
    }
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'acti';
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        return;
    }
    foreach ((array) $werte as $k => $v) {
        $msg = 'publish ' . $prefix . '/' . $k . ' ' . $v;
        @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $udpport);
    }
    socket_close($s);
}

/* ==================================================================
 * Sprache (Pflicht: Deutsch und Englisch)
 *
 * Englisch ist die Rueckfallebene, nicht Deutsch: wer eine dritte Sprache
 * eingestellt hat, versteht eher Englisch. Deshalb muss language_en.ini
 * immer vollstaendig sein.
 * ================================================================== */

function cam_sprache()
{
    $sprache = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $sprache = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $sprache = getenv('LBLANG');
    }
    $sprache = strtolower(substr((string) $sprache, 0, 2));
    return in_array($sprache, array('de', 'en'), true) ? $sprache : 'en';
}

/**
 * Text zu einem Schluessel "ABSCHNITT.SCHLUESSEL".
 *
 * Ist der Schluessel unbekannt, wird er selbst zurueckgegeben - so faellt
 * beim Durchsehen sofort auf, was noch fehlt, statt dass die Seite leer
 * bleibt.
 */
function cam_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        // Installiert liegen die Dateien unter
        // <home>/templates/plugins/<ordner>/lang/ - der Ordnername ergibt
        // sich aus dem Ablageort dieser Datei.
        $home = getenv('LBHOMEDIR');
        if (!$home || !is_dir($home)) {
            foreach (array('/opt/loxberry', '/home/loxberry/loxberry') as $k) {
                if (is_dir($k)) { $home = $k; break; }
            }
        }
        $ordner = basename(dirname(__FILE__));
        $pfad = $home . '/templates/plugins/' . $ordner . '/lang';
        if (!is_dir($pfad)) {
            // Nicht installiert (Entwicklung): neben dem Plugin nachsehen.
            $pfad = dirname(dirname(dirname(__FILE__))) . '/templates/lang';
        }
        $texte = @parse_ini_file($pfad . '/language_' . cam_sprache() . '.ini',
                                 true, INI_SCANNER_RAW);
        if (!is_array($texte)) { $texte = array(); }
        $rueck = @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW);
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        // parse_ini_file mit INI_SCANNER_RAW liefert die Werte samt der
        // Anfuehrungszeichen zurueck, in die sie in der Datei stehen muessen.
        // Die gehoeren nicht in die Ausgabe.
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) {
                $texte[$ab][$s] = trim((string) $w, '"');
            }
        }
    }
    list($a, $s) = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$a][$s]) ? $texte[$a][$s] : $schluessel;
}
