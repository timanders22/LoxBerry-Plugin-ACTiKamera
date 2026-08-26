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


/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Vom eigenen Ablageort aufwaerts, bis ein Verzeichnis gefunden ist, das
 * config/plugins UND webfrontend enthaelt. Das trifft die uebliche
 * Installation genauso wie eine an einem anderen Ort - und es trifft auch
 * den Fall, dass das Plugin noch als entpacktes Archiv daliegt (dann findet
 * es nichts und gibt einen Leerstring zurueck, was der Aufrufer ohnehin
 * abfangen muss).
 *
 * Der Name traegt kein Plugin-Kuerzel und ist deshalb abgesichert: zwei
 * Bibliotheken landen nie im selben Prozess, aber die Pruefung kostet nichts.
 */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

/* ==================================================================
 * Mehrere Kameras
 *
 * Durchnummerierte Einzelschluessel nach der Hausform: Kamera 1 behaelt die
 * alten Schluessel- und Dateinamen (host, bilder/, Feld OK), Kamera 2 traegt
 * ueberall die 2 (host2, bilder2/, Feld OK2). Wer eine zweite Kamera
 * ergaenzt, muss an der ersten in Loxone nichts anfassen.
 * ================================================================== */

/** Wie viele Kameras das Plugin fuehren kann. */
define('CAM_MAX', 4);

/**
 * Die Schluessel, die es JE KAMERA gibt, mit ihren Vorgabewerten.
 * Eine Quelle fuer die Vorgaben in cam_config(), fuer cam_kcfg() und fuer die
 * Oberflaeche - drei Listen liefen sonst frueher oder spaeter auseinander.
 */
function cam_kamerafelder()
{
    return array(
        'name' => '',                // Anzeigename, z. B. Haustuer
        'ausloeser_token' => '',     // kurzes Token fuer e.php (Kamera loest selbst aus)
        'host' => '',                // IP oder Hostname der Kamera
        'user' => '',
        'pass' => '',
        'channel' => 1,              // Kanal (bei Einzelkameras immer 1)
        'resolution' => '',          // z. B. N1280x720 - leer = Kameraeinstellung
        'snapcmd' => 'SNAPSHOT=N1920x1080,100&DUMMY=n',
        'snapurl' => '',             // komplette funktionierende URL (hat Vorrang)
        'auth' => 'auto',            // auto | url | basic | digest
        'timeout' => 8,              // Sekunden fuer einen Bildabruf
        'mjpeg_url' => '',           // leer = /cgi-bin/cmd/system?GET_STREAM
        'rtsp_url' => '',            // leer = ueber die System-Schnittstelle erfragen
        'rtsp_port' => 7070,
        'rtsp_stream' => 2,          // 1 = Hauptstrom, 2 = Nebenstrom
        'rtsp_quality' => 5,         // 2 (fein) bis 15 (grob)
    );
}

/** Das Suffix einer Kamera. Kamera 1 traegt keines - das ist die Hausform. */
function cam_sx($id)
{
    $i = (int) $id;
    return ($i <= 1) ? '' : (string) $i;
}

/**
 * Die Kennziffern der eingerichteten Kameras.
 *
 * Kamera 1 ist immer dabei, auch ohne Adresse - sonst haette eine frische
 * Anlage ueberhaupt keine Felder, und in Loxone stuende gar nichts.
 * Die uebrigen zaehlen mit, sobald eine Adresse eingetragen ist. Damit gibt
 * es KEINE zweite Zahl, die jemand mitpflegen muesste.
 */
function cam_kameras()
{
    $cfg = cam_config();
    $ids = array(1);
    for ($i = 2; $i <= CAM_MAX; $i++) {
        if (trim((string) (isset($cfg['host' . $i]) ? $cfg['host' . $i] : '')) !== '') {
            $ids[] = $i;
        }
    }
    return $ids;
}

/**
 * Die Konfiguration AUS SICHT EINER KAMERA.
 *
 * Fuer Kamera 2 erscheint host2 hier als host, pass2 als pass und so fort.
 * Dadurch bleibt das Innere aller kamerabezogenen Funktionen unveraendert -
 * 86 Fundstellen, die sonst einzeln haetten angefasst werden muessen, und
 * jede davon eine Gelegenheit, eine zu vergessen.
 */
function cam_kcfg($id = 1)
{
    $cfg = cam_config();
    $sx = cam_sx($id);
    if ($sx === '') {
        return $cfg;
    }
    foreach (cam_kamerafelder() as $k => $vorgabe) {
        $cfg[$k] = array_key_exists($k . $sx, $cfg) ? $cfg[$k . $sx] : $vorgabe;
    }
    return $cfg;
}

/** Der Anzeigename einer Kamera - oder eine Ersatzbezeichnung. */
function cam_kname($id)
{
    $cfg = cam_kcfg($id);
    $n = trim((string) $cfg['name']);
    return $n !== '' ? $n : sprintf(cam_t('TEXT.KAMERA_NR'), (int) $id);
}

/**
 * Der Ablageordner einer Kamera: bilder, clips, timelapse.
 * Kamera 1 behaelt die Namen ohne Nummer.
 */
function cam_ordner($id, $art)
{
    $d = cam_datadir() . '/' . $art . cam_sx($id);
    if (!is_dir($d)) {
        @mkdir($d, 0775, true);
    }
    return $d;
}

/** Die Zustandsdatei je Kamera (letztes Bild, Betrieb). */
function cam_kdatei($id, $name, $endung)
{
    return cam_datadir() . '/' . $name . cam_sx($id) . '.' . $endung;
}

function cam_paths()
{
    $lbhomedir = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
    $plugindir = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    if ($lbhomedir && is_dir($lbhomedir . '/config/plugins/' . $plugindir) === false) {
        $plugindir = 'actikamera';
    }
    if ($lbhomedir) {
        return array(
            'config' => $lbhomedir . '/config/plugins/' . $plugindir . '/cam.json',
            'backup' => $lbhomedir . '/config/plugins/' . $plugindir . '.backup.json',
            'log' => $lbhomedir . '/log/plugins/' . $plugindir . '/cam.log',
            /* 'data' ist das Verzeichnis, das der Installer bei jedem Update
               vollstaendig abraeumt - dort liegen nur fluechtige Sachen.
               'archiv' liegt DANEBEN und ueberlebt, wie die Zweitschrift der
               Konfiguration eine Ebene darueber. */
            'data' => $lbhomedir . '/data/plugins/' . $plugindir,
            'archiv' => $lbhomedir . '/data/plugins/' . $plugindir . '.archiv',
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
        'archiv' => sys_get_temp_dir() . '/actikamera/archiv',
        'web' => __DIR__,
        'tmp' => sys_get_temp_dir() . '/actikamera',
        'lbhome' => '',
    );
}

function cam_vorgaben()
{
    /* Herausgezogen aus cam_config(): die Vorgaben stehen weiterhin an
     * EINER Stelle, jetzt aber an einer abrufbaren. Die Sicherung
     * braucht die Schluesselliste, um Fremdes zu erkennen - ohne sie
     * koennte sie nur alles durchwinken. */
    return array(
    'stream_fps' => 2,           // Bilder je Sekunde im MJPEG-Strom
    'stream_maxsec' => 900,      // Notbremse: laengster Strom in Sekunden (Obergrenze 900)
    'stream_token' => '',        // optionales Kennwort fuer den Stromabruf
    'stream_mode' => 'auto',     // auto | mjpeg | rtsp (ffmpeg) | jpeg (Schnappschussfolge)
    'keep_days' => 90,           // Aufbewahrung der Aufnahmen in Tagen
    'clip_seconds' => 10,        // Laenge eines Videoclips
    'clip_fps' => 2,             // Bilder je Sekunde im Clip
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
    'aktionstoken' => '',        // Kennwort fuer alles, was etwas ausloest
    'archiv_pfad' => '',         // leer = data/plugins/<ordner>.archiv
    'pruef_minuten' => 5,        // Takt der Erreichbarkeitspruefung (0 = aus)
    'mindestpause' => 0,         // Sekunden zwischen zwei Aufnahmen von aussen (0 = aus)
    'keep_mb' => 0,              // Hoechstgroesse des Archivs in MB (0 = unbegrenzt)
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
    /* Die Schluessel, die es JE KAMERA gibt, stehen in cam_kamerafelder() -
       an einer Stelle, aus der sich Vorgaben, Oberflaeche und cam_kcfg()
       gleichermassen bedienen. Kamera 1 traegt kein Suffix. */
    foreach (cam_kamerafelder() as $ac_k => $ac_v) {
        for ($ac_i = 1; $ac_i <= CAM_MAX; $ac_i++) {
            $ac_name = $ac_k . ($ac_i > 1 ? $ac_i : '');
            if (!array_key_exists($ac_name, $cfg)) {
                $cfg[$ac_name] = $ac_v;
            }
        }
    }
    $cfg += cam_vorgaben();
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

/**
 * Eine Datei unteilbar schreiben: erst daneben, dann umbenennen.
 *
 * WARUM: letztesbild.jpg und letztesbild.json werden von mehreren Ausloesern
 * beschrieben - Klingel, Bewegung, Handaufnahme, Cron. Loesen zwei davon
 * gleichzeitig aus, schreiben zwei Prozesse in dieselbe Datei, und wer sie in
 * diesem Augenblick liest, bekommt ein halbes Bild oder unvollstaendiges JSON.
 * rename() ist auf demselben Dateisystem unteilbar: der Leser sieht entweder
 * die alte oder die neue Fassung, nie eine halbe.
 */
/* ==================================================================
 * Aktionstoken
 *
 * cam.php liegt im unangemeldeten Bereich - dort kommt jedes Geraet im
 * Heimnetz hin. Bis 1.9.7 konnte damit jeder eine Aufnahme ausloesen, eine
 * Pushnachricht verschicken, das Archiv aufraeumen lassen und ueber ?diag=1
 * Benutzernamen, Passwortlaenge sowie erstes und letztes Zeichen des
 * Kamerapassworts abrufen.
 * ================================================================== */

/** Ein neues Aktionstoken wuerfeln. */
function cam_token_erzeugen()
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes(16));
    }
    // Rueckfall fuer Aufbauten ohne den Zufallsgenerator des Kerns.
    return md5(uniqid('', true) . microtime(true) . getmypid());
}

/**
 * Traegt die Anfrage das richtige Token?
 *
 * Fail closed: ohne eingerichtetes Token wird abgewiesen. Ein leeres Soll
 * darf nicht auf ein leeres Ist passen - sonst schuetzt die Pruefung genau
 * die Anlage nicht, bei der nie jemand ein Token gesetzt hat, und
 * hash_equals('', '') ist true.
 *
 * Rueckgabe: '' = in Ordnung, sonst der Fehlercode fuer die Antwort.
 */
function cam_token_pruefen($mitgegeben)
{
    $cfg = cam_config();
    $soll = (string) $cfg['aktionstoken'];
    if ($soll === '') {
        return 'KEIN_TOKEN_EINGERICHTET';
    }
    if (!is_string($mitgegeben) || $mitgegeben === '') {
        return 'TOKEN';
    }
    return hash_equals($soll, $mitgegeben) ? '' : 'TOKEN';
}

/**
 * Ein kurzes Token wuerfeln - zum Abtippen gedacht.
 *
 * Ohne die Zeichen, die man beim Abschreiben verwechselt: kein l gegen 1,
 * kein O gegen 0. Zwoelf Stellen aus 32 Zeichen sind rund 1,2 Trillionen
 * Moeglichkeiten; im Heimnetz ist das reichlich fuer einen Aufruf, der ein
 * Bild aufnimmt.
 */
function cam_kurztoken()
{
    $zeichen = 'abcdefghjkmnpqrstuvwxyz23456789';
    $n = strlen($zeichen);
    $aus = '';
    for ($i = 0; $i < 12; $i++) {
        if (function_exists('random_int')) {
            $aus .= $zeichen[random_int(0, $n - 1)];
        } else {
            $aus .= $zeichen[mt_rand(0, $n - 1)];
        }
    }
    return $aus;
}

/**
 * Zu welcher Kamera gehoert dieses Ausloese-Token?
 *
 * Rueckgabe: die Kennziffer, oder 0 wenn keine passt. Fail closed - eine
 * Kamera ohne eingerichtetes Token passt auf gar nichts, auch nicht auf einen
 * leeren Aufruf.
 */
function cam_ausloeser_kamera($mitgegeben)
{
    if (!is_string($mitgegeben) || $mitgegeben === '') {
        return 0;
    }
    foreach (cam_kameras() as $id) {
        $k = cam_kcfg($id);
        $soll = (string) $k['ausloeser_token'];
        if ($soll !== '' && hash_equals($soll, $mitgegeben)) {
            return $id;
        }
    }
    return 0;
}

/**
 * Die vollstaendige Adresse, die in ein Geraet eingetragen wird.
 * Sie entsteht an EINER Stelle - die Oberflaeche zeigt genau das, was auch
 * wirklich gilt, samt Zeichenzahl.
 */
function cam_ausloeser_adresse($id, $host = '')
{
    if ($host === '') {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : (gethostname() ?: 'loxberry');
    }
    $ordner = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    $k = cam_kcfg($id);
    $t = (string) $k['ausloeser_token'];
    return 'http://' . $host . '/plugins/' . $ordner . '/e.php?t=' . $t;
}

/**
 * Darf jetzt aufgenommen werden, oder ist die Mindestpause noch nicht um?
 *
 * Eine Kamera, die selbst ausloest, kann das im Sekundentakt tun - die
 * Vorgabe im ACTi-Web-Konfigurator ist ein Ausloesungsintervall von EINER
 * Sekunde. Ohne Bremse schreibt das Plugin dann 3600 Bilder je Stunde auf die
 * Karte.
 *
 * Ab Werk aus (0): eine Bremse, die niemand bestellt hat, verschluckt sonst
 * beim naechsten Update eine Klingelaufnahme, und das faellt erst auf, wenn
 * jemand vor der Tuer stand.
 *
 * Rueckgabe: 0 = darf, sonst die Zahl der noch verbleibenden Sekunden.
 */
function cam_pause_rest($id)
{
    $cfg = cam_config();
    $sek = max(0, (int) $cfg['mindestpause']);
    if ($sek === 0) {
        return 0;
    }
    $f = cam_paths()['tmp'] . '/letzte' . cam_sx($id) . '.txt';
    if (!is_file($f)) {
        return 0;
    }
    $letzte = (int) @file_get_contents($f);
    $rest = $sek - (time() - $letzte);
    return $rest > 0 ? $rest : 0;
}

/** Den Zeitpunkt der letzten Aufnahme von aussen merken. */
function cam_pause_setzen($id)
{
    $p = cam_paths();
    if (!is_dir($p['tmp'])) {
        @mkdir($p['tmp'], 0775, true);
    }
    @file_put_contents($p['tmp'] . '/letzte' . cam_sx($id) . '.txt', time());
}

/**
 * Merkmal gegen fremde Absender in den Formularen der Oberflaeche.
 *
 * Die Anmeldung des LoxBerry schuetzt nicht davor, dass eine fremde Seite
 * ein Formular unterschiebt - der Browser schickt die hinterlegten
 * Zugangsdaten mit. Ohne eingerichtetes Aktionstoken gibt es nichts zu
 * vergleichen; dann liefert die Funktion einen Leerstring, und die Pruefung
 * in der Oberflaeche weist ab, statt durchzulassen.
 */
function cam_formtoken()
{
    $cfg = cam_config();
    $t = (string) $cfg['aktionstoken'];
    return $t === '' ? '' : hash_hmac('sha256', 'formular-v1', $t);
}

function cam_schreibe_unteilbar($pfad, $inhalt)
{
    if ($inhalt === false || $inhalt === null) { return false; }
    $neben = $pfad . '.' . getmypid() . '.neu';
    if (@file_put_contents($neben, $inhalt) !== strlen($inhalt)) {
        @unlink($neben);
        return false;
    }
    if (!@rename($neben, $pfad)) {
        @unlink($neben);
        return false;
    }
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

/* ==================================================================
 * Erreichbarkeit, Stoerungszaehler und Herzschlag
 *
 * OK sagt nur, dass eine Adresse eingetragen ist - die Kamera kann seit Tagen
 * stromlos sein. Und wer nur bei Aenderungen sendet, hoert bei einer Stoerung
 * einfach auf: die zuletzt gesendeten Werte bleiben im Broker stehen, und in
 * Loxone sieht ein toter Dienst genauso aus wie ein ruhiges Haus.
 *
 * Der Zustand liegt im Datenverzeichnis, nicht unter /tmp: auf dem LoxBerry
 * ist /tmp eine RAM-Scheibe, und nach jedem Neustart stuende der Zaehler
 * wieder auf 0 - eine Kamera, die seit Tagen schweigt, saehe dann aus wie
 * eine, die gerade eben noch antwortete.
 * ================================================================== */

/**
 * Eine JSON-Datei lesen, die es auch noch nicht geben darf.
 *
 * Bis 1.9.7 stand hier dreimal  @json_decode(@file_get_contents(...))  - das
 * @ schaltet die ANZEIGE ab, nicht den Fehler. Auf einer frischen Anlage
 * standen deshalb bei jedem Seitenaufbau drei Warnungen im Protokoll des
 * Webservers, und ein eigener Fehler-Aufnehmer sieht sie ohnehin.
 */
function cam_json_lesen($pfad)
{
    if (!is_file($pfad)) {
        return array();
    }
    $roh = @file_get_contents($pfad);
    if ($roh === false || trim((string) $roh) === '') {
        return array();
    }
    $d = json_decode((string) $roh, true);
    return is_array($d) ? $d : array();
}

/**
 * Eine Meldung ins Benachrichtigungszentrum von LoxBerry.
 *
 * Der Hausweg dafuer ist notify_ext() aus libs/phplib/loxberry_log.php; im
 * Bestand benutzen ihn unter anderem APC-UPS und Bewaesserung. Die Wache auf
 * function_exists() gehoert dazu: die Funktion steckt in einer Bibliothek,
 * die nicht in jeder LoxBerry-Fassung dasselbe bietet, und ein @ hilft gegen
 * "undefined function" nicht.
 *
 * $schwere nach der Skala von LoxBerry: 3 = Fehler, 4 = Warnung, 6 = Hinweis.
 */
function cam_melden($schwere, $text)
{
    $p = cam_paths();
    if ($p['lbhome'] === '') {
        return false;
    }
    $sdk = $p['lbhome'] . '/libs/phplib/loxberry_log.php';
    if (!is_file($sdk)) {
        return false;
    }
    require_once $p['lbhome'] . '/libs/phplib/loxberry_system.php';
    require_once $sdk;
    if (!function_exists('notify_ext')) {
        return false;
    }
    $s = (int) $schwere;
    if ($s < 1 || $s > 7) { $s = 4; }
    notify_ext(array(
        'PACKAGE'  => getenv('LBPPLUGINDIR') ?: 'actikamera',
        'NAME'     => 'ACTi Kamera',
        'MESSAGE'  => (string) $text,
        'SEVERITY' => $s,
    ));
    return true;
}

function cam_zustandsdatei($id = 1)
{
    return cam_kdatei($id, 'betrieb', 'json');
}

/**
 * Der Herzschlag gehoert zum PLUGIN, nicht zu einer Kamera - der Minutentakt
 * laeuft einmal, gleich wie viele Kameras eingerichtet sind. Er liegt deshalb
 * in einer eigenen Datei und nicht in der Zustandsdatei von Kamera 1.
 */
function cam_herzdatei()
{
    return cam_datadir() . '/herzschlag.json';
}

function cam_betrieb($id = 1)
{
    $d = cam_json_lesen(cam_zustandsdatei($id));
    $d += array('erreichbar' => -1, 'fehler' => 0, 'geprueft' => '', 'grund' => '');
    return $d;
}

/**
 * Antwortet die Kamera? Ein leichter Abruf ueber die System-Schnittstelle,
 * der kein Bild laedt.
 *
 * Der Zaehler wird nur zurueckgesetzt, wenn wirklich etwas Verwertbares kam -
 * eine Anlage, die brav HTTP 200 mit einer leeren Huelle antwortet, zaehlt
 * sonst als "geht wieder".
 */
function cam_erreichbarkeit($id = 1)
{
    $cfg = cam_kcfg($id);
    $z = cam_betrieb($id);
    if (trim((string) $cfg['host']) === '') {
        $z['erreichbar'] = -1;
        $z['grund'] = 'keine Adresse eingetragen';
        cam_betrieb_schreiben($z, $id);
        return $z;
    }
    $vorher_fehler = (int) $z['fehler'];
    list($antwort, $code, $err) = cam_system('GET_MODEL', $id);
    $gut = ($antwort !== false && $code < 400 && trim((string) $antwort) !== '');
    $z['erreichbar'] = $gut ? 1 : 0;
    $z['fehler'] = $gut ? 0 : ((int) $z['fehler'] + 1);
    $z['geprueft'] = date('c');
    $z['grund'] = $gut ? '' : ($err !== '' ? $err : 'HTTP ' . (int) $code);
    cam_betrieb_schreiben($z, $id);
    if (!$gut) {
        cam_log(cam_kname($id) . ' antwortet nicht (' . $z['fehler']
            . '. Versuch in Folge): ' . $z['grund']);
    }
    /* Genau EINMAL melden, beim dritten Fehlschlag in Folge - nicht bei jedem.
       Ein einzelner Aussetzer ist keine Meldung wert, und eine Meldung je
       Minute waere eine Belaestigung. Beim ersten Erfolg danach kommt die
       Entwarnung, damit niemand nach einer Stoerung sucht, die vorbei ist. */
    if (!$gut && (int) $z['fehler'] === 3) {
        cam_melden(3, cam_kname($id) . ' antwortet seit drei Pruefungen nicht mehr: '
            . $z['grund']);
    }
    if ($gut && (int) $vorher_fehler >= 3) {
        cam_melden(6, cam_kname($id) . ' antwortet wieder.');
    }
    return $z;
}

function cam_betrieb_schreiben(array $z, $id = 1)
{
    $js = json_encode($z);
    if ($js !== false) {
        cam_schreibe_unteilbar(cam_zustandsdatei($id), $js);
    }
}

/** Den Herzschlag setzen - unabhaengig davon, ob MQTT ueberhaupt an ist. */
function cam_herzschlag()
{
    $jetzt = date('c');
    $js = json_encode(array('herz' => $jetzt));
    if ($js !== false) {
        cam_schreibe_unteilbar(cam_herzdatei(), $js);
    }
    return $jetzt;
}

/** Wann lief der Minutentakt zuletzt? '' = noch nie. */
function cam_herzstand()
{
    $d = cam_json_lesen(cam_herzdatei());
    return isset($d['herz']) ? (string) $d['herz'] : '';
}

/* ---------------- Kamera-Zugriff ---------------- */

/**
 * Wo liegen die Aufnahmen?
 *
 * NICHT unter data/plugins/<ordner>/ - der LoxBerry-Installer entfernt dieses
 * Verzeichnis vor jedem postinstall.sh vollstaendig. Gemessen mit einem
 * Pruefstand, der genau diesen Schritt nachbildet: 7 Aufnahmen vorher, 0
 * danach. Deshalb liegt das Archiv DANEBEN, so wie die Zweitschrift der
 * Konfiguration eine Ebene ueber ihrem Verzeichnis liegt.
 *
 * Ein eigener Ort laesst sich einstellen (USB-Platte, Netzlaufwerk). Er wird
 * nur genommen, wenn er sich wirklich anlegen und beschreiben laesst - sonst
 * bliebe die Anlage ohne Aufnahmen, und niemand wuesste warum.
 */
function cam_datadir()
{
    static $gemerkt = null;
    if ($gemerkt !== null) {
        return $gemerkt;
    }
    $p = cam_paths();
    $ziel = $p['archiv'];
    $cfg = cam_config();
    $eigen = trim((string) $cfg['archiv_pfad']);
    if ($eigen !== '') {
        if (!is_dir($eigen)) {
            @mkdir($eigen, 0775, true);
        }
        if (is_dir($eigen) && is_writable($eigen)) {
            $ziel = rtrim($eigen, '/');
        } else {
            cam_log('Der eingestellte Archivordner ist nicht beschreibbar, es gilt ' . $ziel . ': ' . $eigen);
        }
    }
    foreach (array($ziel, $ziel . '/bilder', $ziel . '/clips', $ziel . '/timelapse') as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
    }
    $gemerkt = $ziel;
    return $ziel;
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
function cam_url($befehl, $mit_login = true, $id = 1)
{
    $cfg = cam_kcfg($id);
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
function cam_mjpeg_url($id = 1)
{
    $cfg = cam_kcfg($id);
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
function cam_system($befehl, $id = 1)
{
    $cfg = cam_kcfg($id);
    $host = trim((string) $cfg['host']);
    if ($host === '') {
        return array(false, 0, 'Keine Kamera-Adresse konfiguriert');
    }
    if (strpos($host, 'http') !== 0) {
        $host = 'http://' . $host;
    }
    $url = rtrim($host, '/') . '/cgi-bin/cmd/system?USER=' . cam_q($cfg['user'])
         . '&PWD=' . cam_q($cfg['pass']) . '&' . $befehl;
    list($body, $code, $err) = cam_http($url, 8, '', $id);
    if ($body === false || $code >= 400) {
        // Zweiter Versuch ohne Zugangsdaten in der URL, dafuer mit HTTP-Anmeldung
        $url2 = rtrim($host, '/') . '/cgi-bin/cmd/system?' . $befehl;
        foreach (array('basic', 'digest') as $verf) {
            list($body2, $code2, $err2) = cam_http($url2, 8, $verf, $id);
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
function cam_rtsp_url($nur_gespeichert = false, $id = 1)
{
    $cfg = cam_kcfg($id);
    $url = trim((string) $cfg['rtsp_url']);

    if ($url === '' && !$nur_gespeichert) {
        list($antwort, $code, $err) = cam_system('GET_STREAM', $id);
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
function cam_http($url, $timeout = 8, $auth = '', $id = 1)
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
            $cfg = cam_kcfg($id);
            curl_setopt($ch, CURLOPT_HTTPAUTH, $auth === 'digest' ? CURLAUTH_DIGEST : CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $cfg['user'] . ':' . $cfg['pass']);
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = ($body === false) ? ('cURL-Fehler: ' . curl_error($ch)) : '';
        curl_close($ch);
        return array($body, $code, $err);
    }
    /* ---------------- Rueckfall ohne cURL ----------------
     *
     * Hier lag ein stiller Fehler: nur 'basic' bekam eine Kopfzeile. Verlangte
     * die Kamera 'digest', ging die Anfrage OHNE jede Anmeldung hinaus, die
     * Kamera antwortete mit 401, und im Protokoll stand nur "Verbindung
     * fehlgeschlagen" - man haette ewig an den Zugangsdaten gesucht, die in
     * Ordnung waren. Deshalb wird Digest hier vollstaendig nachgebaut.
     */
    $cfg = cam_kcfg($id);
    /* Keine Weiterleitungen: curl folgt ohne CURLOPT_FOLLOWLOCATION keiner,
       file_get_contents dagegen von sich aus bis zu zwanzigmal - und schickt
       den mitgegebenen Kopf ERNEUT, samt Authorization. Wohin weitergeleitet
       wird, bestimmt die Gegenstelle. Damit haenge es davon ab, ob php-curl
       geladen ist, ob Zugangsdaten abfliessen koennen. */
    $grund = array('timeout' => $timeout, 'ignore_errors' => true,
                   'follow_location' => 0, 'max_redirects' => 1,
                   'user_agent' => 'LoxBerry ACTi-Plugin');

    $anfrage = function ($kopf) use ($url, $grund) {
        $opt = $grund;
        if ($kopf !== '') { $opt['header'] = $kopf; }
        $ctx = stream_context_create(array('http' => $opt));
        $body = @file_get_contents($url, false, $ctx);
        $code = 0;
        $wwwauth = '';
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $code = (int) $m[1]; }
                if (stripos($h, 'WWW-Authenticate:') === 0) { $wwwauth = trim(substr($h, 17)); }
            }
        }
        return array($body, $code, $wwwauth);
    };

    if ($auth === 'basic') {
        list($body, $code, ) = $anfrage('Authorization: Basic '
            . base64_encode($cfg['user'] . ':' . $cfg['pass']));
        return array($body, $code, $body === false ? 'Verbindung fehlgeschlagen' : '');
    }

    if ($auth === 'digest') {
        // Erster Anlauf ohne Anmeldung - die Kamera liefert die Vorgabewerte
        // (realm, nonce, qop) in ihrer 401-Antwort mit.
        list($body, $code, $wwwauth) = $anfrage('');
        if ($code !== 401 || stripos($wwwauth, 'Digest') !== 0) {
            // Kein Digest verlangt (oder gleich durchgelassen) - so lassen.
            return array($body, $code, $body === false ? 'Verbindung fehlgeschlagen' : '');
        }
        $kopf = cam_digest_header($wwwauth, $url, $cfg['user'], $cfg['pass'], 'GET');
        if ($kopf === '') {
            return array(false, 401, 'Die Kamera verlangt Digest, die Vorgabe liess sich '
                                   . 'aber nicht auswerten: ' . $wwwauth);
        }
        list($body, $code, ) = $anfrage($kopf);
        return array($body, $code, $body === false ? 'Verbindung fehlgeschlagen' : '');
    }

    list($body, $code, ) = $anfrage('');
    return array($body, $code, $body === false ? 'Verbindung fehlgeschlagen' : '');
}

/**
 * Baut die Authorization-Kopfzeile fuer HTTP Digest (RFC 2617).
 *
 * NACHGEBAUTES PROTOKOLL, ALSO GEGEN DAS ORIGINAL GEMESSEN: Die Funktion
 * liefert fuer das Rechenbeispiel aus RFC 2617, Abschnitt 3.5, genau den dort
 * abgedruckten Wert
 *   6629fae49393a05397450978507c4ef1
 * Wer hier etwas aendert, prueft das mit cam_digest_selbsttest() nach.
 *
 * Unterstuetzt qop=auth und den alten Fall ganz ohne qop. qop=auth-int bleibt
 * aussen vor - dafuer muesste der Rumpf vorab bekannt sein, und keine Kamera
 * verlangt es.
 */
function cam_digest_header($wwwauth, $url, $user, $pass, $methode = 'GET',
                           $cnonce = null, $nc = '00000001')
{
    $w = array();
    // Die Werte stehen als  name="wert"  oder unquoted  name=wert  darin.
    if (preg_match_all('/(\w+)\s*=\s*(?:"([^"]*)"|([^,\s]+))/', $wwwauth, $m, PREG_SET_ORDER)) {
        foreach ($m as $t) { $w[strtolower($t[1])] = $t[2] !== '' ? $t[2] : $t[3]; }
    }
    if (!isset($w['realm']) || !isset($w['nonce'])) { return ''; }

    $pfad = parse_url($url, PHP_URL_PATH);
    if ($pfad === null || $pfad === false || $pfad === '') { $pfad = '/'; }
    $frage = parse_url($url, PHP_URL_QUERY);
    if ($frage !== null && $frage !== false && $frage !== '') { $pfad .= '?' . $frage; }

    $ha1 = md5($user . ':' . $w['realm'] . ':' . $pass);
    $ha2 = md5($methode . ':' . $pfad);

    // qop kann mehrere Verfahren nennen ("auth,auth-int") - auth waehlen.
    $qop = '';
    if (isset($w['qop'])) {
        foreach (explode(',', $w['qop']) as $q) {
            if (strtolower(trim($q)) === 'auth') { $qop = 'auth'; break; }
        }
    }

    $teile = array(
        'username="' . $user . '"',
        'realm="' . $w['realm'] . '"',
        'nonce="' . $w['nonce'] . '"',
        'uri="' . $pfad . '"',
    );
    if ($qop === 'auth') {
        if ($cnonce === null) { $cnonce = substr(md5(uniqid('', true)), 0, 8); }
        $antwort = md5($ha1 . ':' . $w['nonce'] . ':' . $nc . ':' . $cnonce . ':auth:' . $ha2);
        $teile[] = 'qop=auth';
        $teile[] = 'nc=' . $nc;
        $teile[] = 'cnonce="' . $cnonce . '"';
    } else {
        $antwort = md5($ha1 . ':' . $w['nonce'] . ':' . $ha2);
    }
    $teile[] = 'response="' . $antwort . '"';
    if (isset($w['opaque'])) { $teile[] = 'opaque="' . $w['opaque'] . '"'; }
    if (isset($w['algorithm'])) { $teile[] = 'algorithm=' . $w['algorithm']; }

    return 'Authorization: Digest ' . implode(', ', $teile);
}

/**
 * Selbsttest gegen das Rechenbeispiel aus RFC 2617, Abschnitt 3.5.
 * Rueckgabe: array(bestanden, erhaltener Wert, erwarteter Wert)
 */
function cam_digest_selbsttest()
{
    $wwwauth = 'Digest realm="testrealm@host.com", qop="auth,auth-int", '
             . 'nonce="dcd98b7102dd2f0e8b11d0f600bfb0c093", '
             . 'opaque="5ccc069c403ebaf9f0171e9517f40e41"';
    $kopf = cam_digest_header($wwwauth, 'http://www.nowhere.org/dir/index.html',
                              'Mufasa', 'Circle Of Life', 'GET', '0a4f113b', '00000001');
    $soll = '6629fae49393a05397450978507c4ef1';
    $ist = preg_match('/response="([0-9a-f]{32})"/', $kopf, $m) ? $m[1] : '';
    return array($ist === $soll, $ist, $soll);
}

/**
 * Ein Bild von der Kamera holen. Probiert - solange das Anmeldeverfahren auf
 * "auto" steht - mehrere Kombinationen durch und merkt sich die erfolgreiche.
 * Rueckgabe: array(JPEG|false, Beschreibung des Wegs, Fehlertext)
 */
function cam_fetch_jpeg($id = 1)
{
    $cfg = cam_kcfg($id);
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
    $merker = $p['tmp'] . '/weg' . cam_sx($id) . '.txt';
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
        list($body, $code, $err) = cam_http($u, (int) $cfg['timeout'], '', $id);
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

    /* Gesamtschranke ueber ALLE Versuche.
     *
     * Ohne sie probiert die Suche mit den ausgelieferten Vorgaben 5 Befehle
     * mal 3 Anmeldeverfahren = 15 Versuche zu je 8 Sekunden durch. Gemessen
     * gegen eine nicht antwortende Adresse: 120 Sekunden, in denen ein
     * ?foto=1 beim Klingeln einen PHP-Arbeitsprozess festhaelt - und davon hat
     * ein LoxBerry nur eine Handvoll.
     *
     * Die volle Suche gehoert in die Diagnose, nicht in den Klingelweg. Wer
     * sie braucht, drueckt im Reiter Test auf "Diagnose".
     */
    $ac_frist = microtime(true) + max(5, min(120, 3 * (int) $cfg['timeout']));
    foreach ($wege as $w) {
        if (microtime(true) > $ac_frist) {
            $letzterFehler = $letzterFehler !== ''
                ? $letzterFehler . ' (Suche nach ' . (int) (3 * (int) $cfg['timeout'])
                  . ' s abgebrochen)'
                : 'Die Kamera hat innerhalb der Zeitschranke nicht geantwortet.';
            break;
        }
        list($befehl, $verf) = $w;
        // Bei HTTP-Anmeldung die Zugangsdaten NICHT zusaetzlich in die URL schreiben
        $url = cam_url($befehl, $verf === 'url', $id);
        list($body, $code, $err) = cam_http($url, (int) $cfg['timeout'], $verf === 'url' ? '' : $verf, $id);
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
    /* Den gelernten Weg NICHT bei jedem Fehlschlag wegwerfen.
       Eine Kamera, die kurz nicht antwortet, hat ihren Befehl nicht geaendert;
       bis 1.9.7 kostete jeder Aussetzer den Merker, und der naechste Abruf
       suchte wieder von vorn. Erst nach mehreren Fehlschlaegen in Folge ist
       die Annahme "der Weg stimmt noch" fragwuerdig. */
    $ac_b = cam_betrieb($id);
    if ((int) $ac_b['fehler'] >= 3) {
        @unlink($merker);
    }
    return array(false, '', $letzterFehler);
}

/**
 * Diagnose: probiert alle Kombinationen aus Befehl und Anmeldeverfahren und
 * meldet fuer jede, was die Kamera geantwortet hat. Das ist der schnellste Weg,
 * die Eigenheiten eines bestimmten ACTi-Modells zu finden.
 */
function cam_diag($id = 1)
{
    $cfg = cam_kcfg($id);
    $kanal = max(1, (int) $cfg['channel']);
    $res = trim((string) $cfg['resolution']);
    $befehle = array();
    if (trim((string) $cfg['snapcmd']) !== '') {
        $befehle[] = trim((string) $cfg['snapcmd']);
    }
    if ($res !== '') {
        $befehle[] = 'SNAPSHOT=' . $res . '&DUMMY=n';
    }
    /* GET_STREAM stand hier bis 1.9.7 mit in der Liste - der Befehl gehoert
       aber an /cgi-bin/cmd/system und nicht an /cgi-bin/encoder. Drei der
       Diagnosezeilen konnten deshalb nie gelingen und haben nur Zeit gekostet
       (drei Anmeldeverfahren mal ein aussichtsloser Befehl). Die
       System-Schnittstelle beantwortet der Knopf "Kamera-Auskunft". */
    foreach (array('SNAPSHOT=N1920x1080,100&DUMMY=n', 'SNAPSHOT=N1280x720,100&DUMMY=n',
                   'SNAPSHOT=N640x480,100&DUMMY=n', 'SNAPSHOT&DUMMY=n', 'SNAPSHOT',
                   'CHANNEL=' . $kanal . '&SNAPSHOT&DUMMY=n') as $b) {
        $befehle[] = $b;
    }
    $befehle = array_values(array_unique($befehle));
    $zeilen = array();
    $fertig = trim((string) $cfg['snapurl']);
    if ($fertig !== '') {
        list($body, $code, $err) = cam_http($fertig, min(6, (int) $cfg['timeout']), '', $id);
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
            $url = cam_url($befehl, $verf === 'url', $id);
            $start = microtime(true);
            list($body, $code, $err) = cam_http($url, min(6, (int) $cfg['timeout']), $verf === 'url' ? '' : $verf, $id);
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
                @file_put_contents($p['tmp'] . '/weg' . cam_sx($id) . '.txt', $befehl . '|' . $verf);
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
function cam_snapshot($anlass = 'manuell', $id = 1)
{
    $cfg = cam_kcfg($id);
    $sx = cam_sx($id);
    list($body, $weg, $err) = cam_fetch_jpeg($id);
    if ($body === false) {
        cam_log('FEHLER Schnappschuss ' . cam_kname($id) . ' (' . $anlass . '): ' . $err);
        $hinweis = stripos($err, 'not authorized') !== false
            ? ' Tipp: Benutzer/Passwort pruefen und im Reiter Test die Anmeldearten durchprobieren.' : '';
        return array(0, 'Kein Bild erhalten: ' . $err . $hinweis);
    }
    $dir = cam_ordner($id, 'bilder');
    // Millisekunden im Namen: Klingel und Bewegung koennen in derselben
    // Sekunde ausloesen. Bei gleichem Anlass waere der Name sonst identisch
    // und die zweite Aufnahme ueberschriebe die erste.
    $ms = explode('.', sprintf('%.3f', microtime(true)));
    $name = date('Ymd_His') . '-' . (isset($ms[1]) ? $ms[1] : '000')
          . '_' . preg_replace('/[^a-z0-9]/i', '', $anlass) . '.jpg';
    if (@file_put_contents($dir . '/' . $name, $body) === false) {
        cam_log('FEHLER: Bild konnte nicht gespeichert werden: ' . $dir . '/' . $name);
        return array(0, 'Bild konnte nicht gespeichert werden');
    }
    // Immer auch als "letztes Bild" ablegen - das holt sich Loxone.
    // Unteilbar, weil mehrere Ausloeser gleichzeitig hier ankommen koennen.
    $p = cam_paths();
    cam_schreibe_unteilbar($p['web'] . '/letztesbild' . $sx . '.jpg', $body);
    cam_log('Schnappschuss gespeichert, ' . cam_kname($id) . ' (' . $anlass . '): '
        . $name . ', ' . round(strlen($body) / 1024) . ' kB, Weg: ' . $weg);

    /* Die Objekterkennung VOR dem Schreiben der Zustandsdatei, und die Datei
       nur einmal schreiben.
       Bis 1.9.8 stand hier ein Schreibvorgang davor und einer danach, mit
       einem Abruf von bis zu 20 Sekunden dazwischen. Loesen zwei Ausloeser
       kurz nacheinander aus, ueberschreibt der zweite Schreibvorgang der
       FRUEHEREN Aufnahme den Stand der spaeteren - letztesbild.jpg zeigt dann
       das neuere Bild, letztesbild.json nennt die aeltere Datei, und ALTER,
       PERSON und OBJEKTE in Loxone stammen alle aus dieser Datei. */
    $objekte = cam_ai($dir . '/' . $name);
    if ($objekte) {
        cam_log('Erkannt (' . cam_kname($id) . '): ' . implode(', ', $objekte));
    }
    cam_schreibe_unteilbar(cam_kdatei($id, 'letztesbild', 'json'), json_encode(array(
        'datei' => $name, 'anlass' => $anlass, 'zeit' => date('c'),
        'bytes' => strlen($body), 'objekte' => $objekte,
    )));
    cam_cleanup();
    cam_mqtt(array('letztes_bild' . $sx => $name, 'anlass' . $sx => $anlass,
                   'zeit' . $sx => date('c'), 'objekte' . $sx => implode(',', $objekte)));
    cam_webhooks($name, $anlass, $objekte, $id);
    return array(1, $name . ($objekte ? ' (' . implode(', ', $objekte) . ')' : ''));
}

/**
 * Kurzen Clip aufnehmen: mehrere Einzelbilder im Abstand von 1/fps Sekunden.
 * ACTi-Kameras liefern MJPEG; ein echter Videoschnitt wuerde ffmpeg
 * voraussetzen - die Bildserie kommt ohne Zusatzpakete aus und reicht,
 * um zu sehen wer vor der Tuer stand.
 */
function cam_clip($anlass = 'klingel', $id = 1)
{
    $cfg = cam_kcfg($id);
    $sx = cam_sx($id);
    $sek = max(2, min(60, (int) $cfg['clip_seconds']));
    $fps = max(1, min(5, (int) $cfg['clip_fps']));
    $dir = cam_ordner($id, 'clips') . '/' . date('Ymd_His') . '_' . preg_replace('/[^a-z0-9]/i', '', $anlass);
    @mkdir($dir, 0775, true);
    $nr = 0;          // fortlaufende Nummer im Ordner
    $n = 0;           // wirklich geschriebene Bilder
    $letztes = false; // Inhalt des letzten geschriebenen Bildes
    $letzter_pfad = '';
    $ende = microtime(true) + $sek;
    while (microtime(true) < $ende) {
        list($body, $weg, $err) = cam_fetch_jpeg($id);
        if ($body !== false) {
            // Gezaehlt wird, was wirklich auf der Karte steht. Bis 1.9.7 zaehlte
            // die Nummer auch dann hoch, wenn das Schreiben fehlschlug - das
            // Protokoll meldete dann Bilder, die es nicht gibt.
            $datei = sprintf('%s/%03d.jpg', $dir, ++$nr);
            if (@file_put_contents($datei, $body) !== false) {
                $n++;
                $letztes = $body;
                $letzter_pfad = $datei;
            }
        }
        usleep((int) (1000000 / $fps));
    }
    cam_log('Clip aufgenommen, ' . cam_kname($id) . ' (' . $anlass . '): '
        . $n . ' Bilder in ' . $dir);

    /* Eine Bildserie ist eine Aufnahme wie jede andere.
     *
     * Bis 1.9.7 endete diese Funktion hier: kein letztesbild.jpg, keine
     * Zustandsdatei, keine Objekterkennung, kein MQTT, kein Webhook. Damit
     * blieb PUSHAKTIV nach einer Bildserie auf 0 und ALTER wuchs weiter - wer
     * die Klingel nach der Befehlstabelle im Reiter "Einbindung in Loxone" auf
     * ?clip=1 gelegt hat, bekam nie eine Pushnachricht. Der Reiter bietet
     * ?clip=1 gleichrangig neben ?foto=1 an, und die Anleitung sagt PUSHAKTIV
     * "nach jeder Aufnahme" zu.
     *
     * Anders als cam_snapshot() wird die Zustandsdatei hier nur EINMAL
     * geschrieben, naemlich nach der Objekterkennung. Zwei Schreibvorgaenge mit
     * einem Abruf von bis zu 20 Sekunden dazwischen sind ein Zeitfenster, in
     * dem eine spaetere Aufnahme von einer frueheren ueberschrieben wird.
     *
     * Kam kein einziges Bild an, wird auch nichts vermerkt: eine Zustandsdatei
     * ohne Aufnahme dahinter waere eine Falschaussage an Loxone.
     */
    if ($n > 0 && $letztes !== false) {
        $p = cam_paths();
        cam_schreibe_unteilbar($p['web'] . '/letztesbild' . $sx . '.jpg', $letztes);
        $objekte = cam_ai($letzter_pfad);
        if ($objekte) {
            cam_log('Erkannt: ' . implode(', ', $objekte));
        }
        $name = basename($dir) . '/' . basename($letzter_pfad);
        cam_schreibe_unteilbar(cam_kdatei($id, 'letztesbild', 'json'), json_encode(array(
            'datei' => $name, 'anlass' => $anlass, 'zeit' => date('c'),
            'bytes' => strlen($letztes), 'objekte' => $objekte,
        )));
        cam_cleanup();
        cam_mqtt(array('letztes_bild' . $sx => $name, 'anlass' . $sx => $anlass,
                       'zeit' . $sx => date('c'), 'objekte' . $sx => implode(',', $objekte)));
        cam_webhooks($name, $anlass, $objekte, $id);
        return array(1, basename($dir) . ' (' . $n . ' Bilder'
            . ($objekte ? ', ' . implode(', ', $objekte) : '') . ')');
    }
    cam_cleanup();
    return array(0, 'Keine Bilder erhalten');
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

    /* Ueber ALLE Kameras: jede hat ihre eigenen Ordner (bilder, bilder2, ...),
       die Grenzen gelten aber fuer das Archiv als Ganzes - sonst muesste man
       sie je Kamera pflegen, und die Karte ist trotzdem nur einmal voll. */
    $ac_muster = array();
    foreach (cam_kameras() as $ac_id) {
        $ac_s = cam_sx($ac_id);
        $ac_muster[] = '/bilder' . $ac_s . '/*.jpg';
        $ac_muster[] = '/timelapse' . $ac_s . '/*.jpg';
    }
    foreach ($ac_muster as $muster) {
        $dateien = glob(cam_datadir() . $muster) ?: array();
        if ($grenze > 0) {
            /* Die neueste Datei bleibt IMMER stehen, auch wenn sie aelter ist
               als die Aufbewahrungszeit. Bis 1.9.7 loeschte die Altersschleife
               ohne Mindestbestand: nach einem Kameraausfall ueber die
               Aufbewahrungszeit hinweg war das Archiv leer, und
               letztesbild.json zeigte auf eine Datei, die es nicht mehr gab.
               README und Kommentar sagen das Gegenteil zu. */
            $behalten = '';
            if ($dateien) {
                $sortiert = $dateien;
                usort($sortiert, function ($a, $b) { return filemtime($b) - filemtime($a); });
                $behalten = $sortiert[0];
            }
            foreach ($dateien as $f) {
                if ($f !== $behalten && filemtime($f) < $grenze) {
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

    $clips = array();
    foreach (cam_kameras() as $ac_id) {
        foreach (glob(cam_ordner($ac_id, 'clips') . '/*', GLOB_ONLYDIR) ?: array() as $ac_c) {
            $clips[] = $ac_c;
        }
    }
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
        $clips = array();
        foreach (cam_kameras() as $ac_id) {
            foreach (glob(cam_ordner($ac_id, 'clips') . '/*', GLOB_ONLYDIR) ?: array() as $ac_c) {
                $clips[] = $ac_c;
            }
        }
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
    /* Dritte Grenze: die Groesse.
       Alter und Anzahl sagen nichts darueber, wie voll die Karte ist - ein
       Bild einer 4-Megapixel-Kamera ist ein Vielfaches eines Bildes im
       Nebenstrom. Ab Werk 0, also unbegrenzt: ein Vorgabewert, der loescht,
       erreicht sonst jede bestehende Anlage beim Update. */
    $mb = (int) $cfg['keep_mb'];
    if ($mb > 0) {
        $alle = array();
        foreach (cam_kameras() as $ac_id) {
            $ac_s = cam_sx($ac_id);
            foreach (array('/bilder' . $ac_s . '/*.jpg', '/timelapse' . $ac_s . '/*.jpg',
                           '/clips' . $ac_s . '/*/*.jpg') as $muster) {
                foreach (glob(cam_datadir() . $muster) ?: array() as $f) {
                    $alle[$f] = filemtime($f);
                }
            }
        }
        $summe = 0;
        foreach (array_keys($alle) as $f) { $summe += (int) @filesize($f); }
        $ziel = $mb * 1024 * 1024;
        if ($summe > $ziel) {
            asort($alle);                       // aelteste zuerst
            foreach (array_keys($alle) as $f) {
                if ($summe <= $ziel || count($alle) <= 1) { break; }
                $summe -= (int) @filesize($f);
                @unlink($f);
                unset($alle[$f]);
                $weg++;
            }
            // Leergeraeumte Serienordner mitnehmen.
            foreach (cam_kameras() as $ac_id) {
                foreach (glob(cam_ordner($ac_id, 'clips') . '/*', GLOB_ONLYDIR) ?: array() as $d) {
                    if (!glob($d . '/*.jpg')) { @rmdir($d); }
                }
            }
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
function cam_timelapse($id = 1)
{
    list($body, $weg, $err) = cam_fetch_jpeg($id);
    if ($body === false) {
        cam_log('FEHLER Zeitraffer ' . cam_kname($id) . ': ' . $err);
        return array(0, $err);
    }
    $dir = cam_ordner($id, 'timelapse');
    $name = date('Y-m-d') . '.jpg';
    if (@file_put_contents($dir . '/' . $name, $body) === false) {
        // Bis 1.9.8 meldete diese Funktion auch dann Erfolg, wenn nichts
        // geschrieben wurde - die Oberflaeche sagte dann "aufgenommen".
        cam_log('FEHLER: Zeitrafferbild konnte nicht gespeichert werden: ' . $dir . '/' . $name);
        return array(0, 'Zeitrafferbild konnte nicht gespeichert werden');
    }
    cam_log('Zeitraffer-Bild gespeichert (' . cam_kname($id) . '): ' . $name);
    $film = cam_timelapse_video($id);
    cam_cleanup();
    return array(1, $name . ($film ? ' (Video neu erzeugt)' : ''));
}

/** Erzeugt zeitraffer.mp4 aus allen Zeitrafferbildern - nur wenn ffmpeg da ist. */
function cam_timelapse_video($id = 1)
{
    $out = array();
    @exec('command -v ffmpeg 2>/dev/null', $out);
    if (empty($out)) {
        return 0;
    }
    $dir = cam_ordner($id, 'timelapse');
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
    $ziel = cam_paths()['web'] . '/zeitraffer' . cam_sx($id) . '.mp4';
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
    // Beides gehoert gesetzt, nicht nur die Gesamtzeit: ohne
    // CONNECTTIMEOUT gilt die Vorgabe des Systems.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
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
function cam_webhooks($name, $anlass, $objekte, $id = 1)
{
    $cfg = cam_config();
    /* Der Ordnername kommt aus der Umgebung, nicht aus einer festen
       Zeichenkette - ueberall sonst im Plugin wird LBPPLUGINDIR ausgewertet.
       Bei abweichendem Ordnernamen zeigte die verschickte Bildadresse ins
       Leere. */
    $ac_ordner = getenv('LBPPLUGINDIR') ?: basename(dirname(__FILE__));
    $bildurl = 'http://' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'loxberry')
             . '/plugins/' . $ac_ordner . '/letztesbild' . cam_sx($id) . '.jpg';
    $daten = array('bild' => $bildurl, 'datei' => $name, 'anlass' => $anlass,
                   'objekte' => $objekte, 'zeit' => date('c'),
                   'kamera' => (int) $id, 'kameraname' => cam_kname($id));
    if (trim((string) $cfg['webhook1']) !== '' && function_exists('curl_init')) {
        $ch = curl_init(trim((string) $cfg['webhook1']));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
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
function cam_test($id = 1)
{
    $cfg = cam_kcfg($id);
    if (trim((string) $cfg['host']) === '') {
        return 'FEHLER: Es ist keine Kamera-Adresse eingetragen.';
    }
    /* Denselben Weg gehen wie der Ernstfall.
     *
     * Bis 1.9.7 rief diese Funktion fest cam_url('SNAPSHOT') mit Zugangsdaten
     * in der URL auf und ignorierte damit die Einstellung "Anmeldeverfahren",
     * die hinterlegte vollstaendige URL, den eigenen Schnappschuss-Befehl und
     * den gemerkten Weg. Bei einer Kamera, die Digest verlangt oder eine
     * hinterlegte URL braucht, meldete "Verbindung pruefen" FEHLER, waehrend
     * Schnappschuesse tadellos liefen - und der Text schickte den Anwender zu
     * Benutzer und Passwort, wo nichts falsch war.
     */
    $start = microtime(true);
    list($body, $weg, $err) = cam_fetch_jpeg($id);
    $ms = round((microtime(true) - $start) * 1000);
    if ($body === false || $body === '') {
        return 'FEHLER: ' . ($err !== '' ? $err : 'keine Antwort')
             . ' - Adresse, Benutzer und Passwort pruefen. Der Knopf "Diagnose" '
             . 'probiert alle Befehls- und Anmeldevarianten durch.';
    }
    return 'OK: Bild erhalten (' . round(strlen($body) / 1024) . ' kB in ' . $ms . ' ms). '
         . 'Verwendeter Weg: ' . $weg;
}

/* ==================================================================
 * Eine BESTIMMTE gespeicherte Aufnahme ausliefern
 *
 * Bis 1.9.7 gab es nur letztesbild.jpg. Die Galerie im Reiter Aufnahmen zeigte
 * deshalb zwoelfmal dieselbe Datei, und aus einer Bildserie war kein einziges
 * Bild erreichbar - obwohl die Serie als eigene Funktion angepriesen wird.
 *
 * Ein Endpunkt, der Dateinamen entgegennimmt, ist der klassische Ort fuer
 * einen Pfadausbruch. Deshalb zwei Wachen, nicht eine:
 *   - eine POSITIVLISTE: der Name muss dem Muster entsprechen, das dieses
 *     Plugin selbst erzeugt. Was nicht hineinpasst, wird abgewiesen, nicht
 *     zurechtgebogen.
 *   - danach realpath(): der aufgeloeste Pfad MUSS unterhalb des
 *     Datenverzeichnisses liegen. Ein Muster allein reicht nicht, wenn das
 *     Dateisystem Verknuepfungen kennt.
 * ================================================================== */

/**
 * Loest eine Anfrage in einen Dateipfad auf - oder gibt '' zurueck.
 * $art: 'bild' | 'zeitraffer' | 'serie'
 */
function cam_archivdatei($art, $name, $nr = 0, $id = 1)
{
    if (!is_string($art) || !is_string($name)) {
        return '';
    }
    $wurzel = realpath(cam_datadir());
    if ($wurzel === false) {
        return '';
    }
    // Der Ordner traegt die Kennziffer der Kamera: bilder, bilder2, ...
    $sx = cam_sx($id);
    $pfad = '';
    if ($art === 'bild' && preg_match('/^\d{8}_\d{6}-\d{3}_[a-z0-9]*\.jpg$/i', $name)) {
        // erzeugt in cam_snapshot(): Ymd_His-mmm_anlass.jpg
        $pfad = $wurzel . '/bilder' . $sx . '/' . $name;
    } elseif ($art === 'zeitraffer' && preg_match('/^\d{4}-\d{2}-\d{2}\.jpg$/', $name)) {
        // erzeugt in cam_timelapse(): Y-m-d.jpg
        $pfad = $wurzel . '/timelapse' . $sx . '/' . $name;
    } elseif ($art === 'serie' && preg_match('/^\d{8}_\d{6}_[a-z0-9]*$/i', $name)) {
        // erzeugt in cam_clip(): Ordner Ymd_His_anlass, darin %03d.jpg
        $n = (int) $nr;
        if ($n < 1 || $n > 999) {
            return '';
        }
        $pfad = $wurzel . '/clips' . $sx . '/' . $name . '/' . sprintf('%03d', $n) . '.jpg';
    }
    if ($pfad === '' || !is_file($pfad)) {
        return '';
    }
    $echt = realpath($pfad);
    // Der aufgeloeste Pfad muss unterhalb des Datenverzeichnisses liegen.
    if ($echt === false || strpos($echt, $wurzel) !== 0) {
        return '';
    }
    return $echt;
}

/* ---------------- Zustand fuer Loxone ---------------- */

function cam_state($id = 1)
{
    $cfg = cam_kcfg($id);
    $letzte = cam_json_lesen(cam_kdatei($id, 'letztesbild', 'json'));
    $bilder = glob(cam_ordner($id, 'bilder') . '/*.jpg') ?: array();
    $clips = glob(cam_ordner($id, 'clips') . '/*', GLOB_ONLYDIR) ?: array();
    /* strtotime() liefert bei einem unlesbaren Zeitstempel false, und
       time() - false ist time(): daraus wurden gemessen 29 785 405 Minuten,
       also rund 57 Jahre. Die Zahl sah plausibel aus und ging so an Loxone.
       Ein nicht lesbarer Zeitstempel ist kein Alter, sondern keines. */
    $alter = -1;
    if (is_array($letzte) && !empty($letzte['zeit'])) {
        $ac_ts = strtotime((string) $letzte['zeit']);
        if ($ac_ts !== false && $ac_ts > 0) {
            $alter = max(0, (int) round((time() - $ac_ts) / 60));
        }
    }
    $tl = glob(cam_ordner($id, 'timelapse') . '/*.jpg') ?: array();
    $b = cam_betrieb($id);
    /* Wie lange ist der letzte Minutentakt her? -1 = noch nie gelaufen.
       Daran erkennt Loxone einen stehengebliebenen Cron, ohne dass sich
       sonst ein Wert aendern muesste. */
    $herz = -1;
    $ac_hs = cam_herzstand();
    if ($ac_hs !== '') {
        $ac_h = strtotime($ac_hs);
        if ($ac_h !== false && $ac_h > 0) {
            $herz = max(0, (int) round((time() - $ac_h) / 60));
        }
    }
    return array(
        'ok' => trim((string) $cfg['host']) !== '' ? 1 : 0,
        'erreichbar' => (int) $b['erreichbar'],
        'fehler' => (int) $b['fehler'],
        'grund' => (string) $b['grund'],
        'geprueft' => (string) $b['geprueft'],
        'herz_min' => $herz,
        'objekte' => is_array($letzte) && !empty($letzte['objekte']) ? (array) $letzte['objekte'] : array(),
        'person' => is_array($letzte) && !empty($letzte['objekte']) && in_array('person', (array) $letzte['objekte'], true) ? 1 : 0,
        'timelapse' => count($tl),
        'letztes_bild' => isset($letzte['datei']) ? (string) $letzte['datei'] : '',
        'letzter_anlass' => isset($letzte['anlass']) ? (string) $letzte['anlass'] : '',
        'alter_min' => $alter,
        'bilder' => count($bilder),
        'clips' => count($clips),
        'push' => empty($cfg['notify']['push']) ? 0 : 1,
        'kamera' => (int) $id,
        'name' => cam_kname($id),
        'zeit' => date('c'),
    );
}

/** Push-Fenster: nach einer Aufnahme fuer X Minuten aktiv (0->1-Flanke in Loxone). */
/**
 * Laeuft gerade ein Test-Push? (5 Minuten lang nach ?ptest=1)
 *
 * Liegt in /tmp - auf einem LoxBerry ist das eine RAM-Scheibe, die Datei
 * beruehrt die SD-Karte also nicht. Eine Datenbank fuer ein Flag mit einer
 * Zahl darin waere ein Dienst mehr, der laufen und ausfallen kann.
 */
function cam_ptest_active()
{
    $f = cam_paths()['tmp'] . '/ptest';
    if (!is_file($f)) {
        return 0;
    }
    if (time() - (int) @file_get_contents($f) > 300) {
        @unlink($f);
        return 0;
    }
    return 1;
}

function cam_push_active($id = 1)
{
    $cfg = cam_config();
    if (empty($cfg['notify']['push'])) {
        return 0;
    }
    $letzte = cam_json_lesen(cam_kdatei($id, 'letztesbild', 'json'));
    if (!is_array($letzte) || empty($letzte['zeit'])) {
        return 0;
    }
    $min = max(1, (int) $cfg['notify']['push_minutes']);
    $ac_z = strtotime((string) $letzte['zeit']);
    if ($ac_z === false || $ac_z <= 0) {
        return 0;
    }
    return (time() - $ac_z) < $min * 60 ? 1 : 0;
}

/* ---------------- MQTT (LoxBerry MQTT Gateway) ---------------- */

/* ==================================================================
 * Feldtabelle - EINE Quelle fuer Statuszeile, MQTT-Themen und Vorlage
 *
 * Drei Stellen, die dieselben Felder aufzaehlen, laufen frueher oder spaeter
 * auseinander; dann stimmt die Vorlage nicht mehr zur Wirklichkeit, und der
 * Anwender sucht den Fehler in Loxone Config.
 * ================================================================== */

/** name => array(analog, min, max, Sprachschluessel, Einheit fuer Loxone, ueber MQTT) */
function cam_basisfelder()
{
    /* Neue Felder werden HINTEN angehaengt. Wer die Reihenfolge aendert,
       aendert nichts an den Namen - die Statuszeile sucht mit fuehrendem
       Semikolon nach dem Namen, nicht nach der Stelle. Trotzdem bleibt die
       Reihenfolge stabil, damit ein Bildschirmfoto von gestern noch passt.

       Die letzte Spalte: gilt das Feld je Kamera (1) oder fuer das ganze
       Plugin (0)? Ein Test-Push und ein Minutentakt gibt es einmal - stuenden
       sie je Kamera da, saehe man auf einer Anlage mit vier Kameras viermal
       denselben Wert und hielte ihn fuer vier Messungen. */
    return array(
        'OK'         => array(0, 0, 1, 'FELD.OK', '', 1, 1),
        'ALTER'      => array(1, -1, 100000, 'FELD.ALTER', '<v.1> min', 1, 1),
        'BILDER'     => array(1, 0, 100000, 'FELD.BILDER', '', 1, 1),
        'CLIPS'      => array(1, 0, 100000, 'FELD.CLIPS', '', 1, 1),
        'ZEITRAFFER' => array(1, 0, 100000, 'FELD.ZEITRAFFER', '', 1, 1),
        'PERSON'     => array(0, 0, 1, 'FELD.PERSON', '', 1, 1),
        'OBJEKTE'    => array(1, 0, 100, 'FELD.OBJEKTE', '', 1, 1),
        'PUSH'       => array(0, 0, 1, 'FELD.PUSH', '', 1, 0),
        'PUSHAKTIV'  => array(0, 0, 1, 'FELD.PUSHAKTIV', '', 1, 1),
        'PTEST'      => array(0, 0, 1, 'FELD.PTEST', '', 1, 0),
        'ERREICHBAR' => array(0, -1, 1, 'FELD.ERREICHBAR', '', 1, 1),
        'FEHLER'     => array(1, 0, 100000, 'FELD.FEHLER', '', 1, 1),
        'HERZ'       => array(1, -1, 100000, 'FELD.HERZ', '<v.1> min', 0, 0),
    );
}

/**
 * Alle Felder aller eingerichteten Kameras.
 *
 * Kamera 1 behaelt die Namen ohne Nummer - wer eine zweite Kamera ergaenzt,
 * muss an der ersten in Loxone nichts anfassen. Je Feld kommt die Kennziffer
 * der Kamera mit, damit Vorlage und Oberflaeche sagen koennen, um welche es
 * geht.
 *
 * name => array(analog, min, max, Sprachschluessel, Einheit, ueber MQTT, Kamera)
 */
function cam_felder()
{
    $aus = array();
    foreach (cam_kameras() as $id) {
        $sx = cam_sx($id);
        foreach (cam_basisfelder() as $name => $d) {
            if (empty($d[6]) && $id !== 1) {
                continue;   // pluginweites Feld, steht nur bei Kamera 1
            }
            $d[6] = $id;
            $aus[$name . $sx] = $d;
        }
    }
    return $aus;
}

/** Die Feldwerte aus dem aktuellen Zustand. */
function cam_werte($st = null)
{
    $aus = array();
    foreach (cam_kameras() as $id) {
        $sx = cam_sx($id);
        /* Der uebergebene Zustand gilt fuer Kamera 1 - so kann cam.php ihn
           einmal holen, statt das Archiv ein zweites Mal zu durchsuchen. */
        $s = ($id === 1 && $st !== null) ? $st : cam_state($id);
        $je = array(
            'OK'         => (int) $s['ok'],
            'ALTER'      => (int) $s['alter_min'],
            'BILDER'     => (int) $s['bilder'],
            'CLIPS'      => (int) $s['clips'],
            'ZEITRAFFER' => (int) $s['timelapse'],
            'PERSON'     => (int) $s['person'],
            'OBJEKTE'    => count($s['objekte']),
            'PUSHAKTIV'  => cam_push_active($id) ? 1 : 0,
            'ERREICHBAR' => (int) $s['erreichbar'],
            'FEHLER'     => (int) $s['fehler'],
        );
        foreach ($je as $k => $v) {
            $aus[$k . $sx] = $v;
        }
        if ($id === 1) {
            // Pluginweit, nicht je Kamera.
            $aus['PUSH'] = (int) $s['push'];
            $aus['PTEST'] = cam_ptest_active() ? 1 : 0;
            $aus['HERZ'] = (int) $s['herz_min'];
        }
    }
    /* Die Reihenfolge der Statuszeile folgt der Feldtabelle, nicht der
       Reihenfolge, in der die Werte entstanden sind. */
    $sortiert = array();
    foreach (cam_felder() as $name => $d) {
        $sortiert[$name] = isset($aus[$name]) ? $aus[$name] : 0;
    }
    return $sortiert;
}

/**
 * Die Statuszeile fuer den Miniserver.
 *
 * Jedem Feld geht ein Semikolon voran, und die Befehlserkennungen in der
 * Vorlage suchen ebenfalls mit fuehrendem Semikolon. Grund: Loxone sucht die
 * Zeichenkette woertlich und nimmt den ERSTEN Treffer. Ohne Semikolon faende
 * "PUSH=" auch die Stelle in "PUSHAKTIV=" - solange PUSH vorher in der Zeile
 * steht, faellt das nicht auf, aber sobald sich die Reihenfolge einmal
 * aendert, stuende der falsche Wert im Eingang.
 */
function cam_zeile($st = null)
{
    $teile = array('ACTI');
    foreach (cam_werte($st) as $k => $v) { $teile[] = $k . '=' . $v; }
    return implode(';', $teile);
}

/**
 * Alle Statuswerte per MQTT veroeffentlichen - aber nur, was sich geaendert hat.
 *
 * Wird aus cam_cron.php im Minutentakt aufgerufen. Nur so bekommt Loxone mit,
 * dass die Kamera seit einer Stunde schweigt (ALTER waechst), ohne dass der
 * Miniserver dafuer jede Minute nachfragen muss.
 *
 * Der Vergleich ist feldweise: der Zaehler BILDER aendert sich bei jeder
 * Aufnahme und gehoert gesendet, PUSH und OK dagegen stehen tagelang gleich -
 * die wuerden den Broker sonst hundertmal am Tag mit demselben Wert belegen.
 */
/**
 * Einen Wert fuer den UDP-Eingang des MQTT-Gateways unschaedlich machen.
 *
 * Das Gateway liest ZEILENWEISE. Ein Zeilenumbruch im Wert - aus einer
 * Fehlermeldung des Betriebssystems, einem Geraetenamen oder der Ausgabe
 * eines Systembefehls - zerlegt die Uebertragung, und aus den Bruchstuecken
 * bildet das Gateway erfundene Themen. Ein Tabulator schadet ebenso, weil
 * Leerzeichen Thema und Wert trennt.
 */
function cam_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

function cam_mqtt_zustand($erzwingen = false)
{
    $cfg = cam_config();
    if (empty($cfg['mqtt_enabled'])) { return 0; }
    $werte = cam_werte();
    /* Felder, die der Empfaenger selbst ausrechnet, gehen nicht ueber MQTT -
       siehe die sechste Spalte in cam_felder(). */
    foreach (cam_felder() as $ac_n => $ac_d) {
        if (empty($ac_d[5]) && array_key_exists($ac_n, $werte)) {
            unset($werte[$ac_n]);
        }
    }
    $merker = cam_paths()['tmp'] . '/mqtt_letzte.json';
    $vorher = cam_json_lesen($merker);
    if ($erzwingen) { $vorher = array(); }
    $neu = array();
    foreach ($werte as $k => $v) {
        if (!array_key_exists($k, $vorher) || (string) $vorher[$k] !== (string) $v) { $neu[$k] = $v; }
    }
    if (!$neu) { return 0; }
    /* Der Merker wird nur fortgeschrieben, wenn wirklich etwas hinausging.
       Bis 1.9.7 stand er unbedingt da - ein Lauf ohne Broker, ohne UDP-Port
       oder ohne Netzverbindung galt danach als erledigt, und die Werte kamen
       erst bei der naechsten Aenderung wieder. OK und PUSH stehen tagelang
       gleich; die fehlten dann dauerhaft. */
    if (cam_mqtt($neu) < 1) {
        return 0;
    }
    $js = json_encode($werte);
    if ($js !== false) { @file_put_contents($merker, $js, LOCK_EX); }
    return count($neu);
}

/* ==================================================================
 * Loxone-Vorlage (XML-Export)
 *
 * Geprueefter PHP-Nachbau des LoxoneTemplateBuilder - Attributreihenfolge,
 * CRLF und der Tabulator vor den Kindelementen entsprechen dem Original.
 * Uebernommen aus LoxBerry-Plugin-APC-UPS, nur das Kuerzel getauscht.
 * ================================================================== */

function cam_x($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function cam_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    // HintText steht VORN - so kommen die Ausfuhren aus Loxone Config.
    $o .= 'HintText="" ';
    $o .= 'Title="' . cam_x($kopf['title']) . '" ';
    $o .= 'Comment="' . cam_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . cam_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . cam_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    // templateType: 1 = UDP-Eingang, 2 = HTTP-Eingang, 3 = Ausgang.
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . cam_x($c['title']) . '" ';
        $o .= 'Comment="' . cam_x($c['comment']) . '" ';
        $o .= 'Check="' . cam_x($c['check']) . '" ';
        $o .= 'Signed="' . ($c['min'] < 0 ? 'true' : 'false') . '" ';
        $o .= 'Analog="' . (!empty($c['analog']) ? 'true' : 'false') . '" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . (int) $c['min'] . '" ';
        $o .= 'MaxVal="' . (int) $c['max'] . '" ';
        // Ohne Unit steht am virtuellen Eingang eine nackte Zahl.
        $o .= 'Unit="' . cam_x(isset($c['unit']) ? $c['unit'] : '') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Die zweite Bauform: Loxone sendet Befehle.
 *
 * Ein Schnappschuss hat kein "Aus" - die Ausbefehle bleiben deshalb leer.
 * Leer heisst hier wirklich leer und nicht "fehlt": so kommen die Ausfuhren
 * aus Loxone Config.
 */
function cam_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . cam_x($kopf['title']) . '" ';
    $o .= 'Comment="' . cam_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . cam_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="true" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . cam_x($c['title']) . '" ';
        $o .= 'Comment="' . cam_x($c['comment']) . '" ';
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOffMethod="" ';
        $o .= 'CmdOn="' . cam_x($c['on']) . '" ';
        $o .= 'CmdOnHTTP="" ';
        $o .= 'CmdOnPost="" ';
        $o .= 'CmdOff="" ';
        $o .= 'CmdOffHTTP="" ';
        $o .= 'CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        $o .= 'Analog="false" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/** array(Dateiname, Inhalt) der Importdatei fuer Loxone Config. */
function cam_vorlage($host = '')
{
    if ($host === '') { $host = gethostname() ?: 'loxberry'; }
    $plugindir = getenv('LBPPLUGINDIR') ?: 'actikamera';
    $cmds = array();
    foreach (cam_felder() as $name => $d) {
        list($analog, $min, $max, $schluessel) = $d;
        $ac_kid = isset($d[6]) ? (int) $d[6] : 1;
        $ac_zusatz = (count(cam_kameras()) > 1 && $ac_kid >= 1)
            ? ' [' . cam_kname($ac_kid) . ']' : '';
        $cmds[] = array(
            'title'   => 'ACTI_' . $name,
            'comment' => trim(strip_tags(html_entity_decode(cam_t($schluessel), ENT_QUOTES, 'UTF-8')))
                       . $ac_zusatz,
            'check'   => '\i;' . $name . '=\i\v',
            'analog'  => $analog, 'min' => $min, 'max' => $max,
            'unit'    => isset($d[4]) ? (string) $d[4] : '',
        );
    }
    return array('VI_actikamera.xml', cam_xml_virtual_in_http(array(
        'title'   => 'ACTi Kamera',
        'address' => 'http://' . $host . '/plugins/' . $plugindir . '/cam.php',
        'polling' => '60',
        'comment' => 'Erzeugt vom LoxBerry-Plugin ACTi Kamera (' . date('d.m.Y') . '). '
                   . 'Loxone Config legt beim Import neu an und ueberschreibt nichts - '
                   . 'zweimal eingelesen ergibt doppelte Bausteine.',
    ), $cmds));
}


/**
 * array(Dateiname, Inhalt) der Importdatei fuer die virtuellen AUSGAENGE.
 *
 * Bis 1.9.7 musste der Anwender diese vier Adressen abtippen. Seit sie das
 * Aktionstoken tragen, ist das eine Fehlerquelle mehr - und ein Tippfehler
 * darin faellt nicht auf: ein virtueller Ausgang wertet die Antwort nicht
 * aus, der Ausfall bliebe still.
 */
/**
 * Die Sendebefehle - EINE Quelle fuer die Importdatei UND die Tabelle im
 * Reiter "Einbindung in Loxone".
 *
 * Zwei Stellen, die dieselben Adressen aufzaehlen, laufen frueher oder spaeter
 * auseinander. Bei Adressen mit Token faellt das niemandem auf: ein
 * virtueller Ausgang wertet die Antwort nicht aus, der Ausfall bliebe still.
 *
 * Ein Titel darf kein Gleichheitszeichen tragen - aus "&lp=1" wurde in einer
 * anderen Linie schon einmal der Bausteinname "EVCC_MODUS_LP=1".
 */
function cam_ausgangsbefehle()
{
    $plugindir = getenv('LBPPLUGINDIR') ?: 'actikamera';
    $cfg = cam_config();
    $tok = (string) $cfg['aktionstoken'];
    $pfad = '/plugins/' . $plugindir . '/cam.php?';
    $anhang = $tok !== '' ? '&token=' . $tok : '';
    $vorlagen = array(
        array('Bild_Klingel',      'LOX.VO_KLINGEL',  'foto=1&anlass=klingel'),
        array('Bild_Tuer',         'LOX.VO_TUER',     'foto=1&anlass=tuer'),
        array('Bild_Bewegung',     'LOX.VO_BEWEGUNG', 'foto=1&anlass=bewegung'),
        array('Bildserie_Klingel', 'LOX.VO_CLIP',     'clip=1&anlass=klingel'),
    );
    $mehrere = count(cam_kameras()) > 1;
    $cmds = array();
    foreach (cam_kameras() as $id) {
        $sx = cam_sx($id);
        // &kamera= nur, wo es wirklich mehr als eine gibt - eine Adresse ohne
        // ueberfluessige Teile ist leichter nachzuvollziehen.
        $kam = $id > 1 ? '&kamera=' . $id : '';
        foreach ($vorlagen as $v) {
            $cmds[] = array(
                'title'   => 'ACTI_' . $v[0] . $sx,
                'comment' => cam_t($v[1]) . ($mehrere ? ' [' . cam_kname($id) . ']' : ''),
                'on'      => $pfad . $v[2] . $kam . $anhang,
                'kamera'  => $id,
            );
        }
    }
    return $cmds;
}

function cam_vorlage_ausgang($host = '')
{
    if ($host === '') { $host = gethostname() ?: 'loxberry'; }
    return array('VQ_actikamera.xml', cam_xml_virtual_out(array(
        'title'   => 'ACTi Kamera senden',
        'address' => 'http://' . $host,
        'comment' => 'Erzeugt vom LoxBerry-Plugin ACTi Kamera (' . date('d.m.Y') . '). '
                   . 'Die Adressen enthalten das Aktionstoken - nach einem neuen Token '
                   . 'muss diese Datei neu erzeugt und erneut eingelesen werden.',
    ), cam_ausgangsbefehle()));
}

/* ==================================================================
 * Selbstpruefung
 *
 * Beantwortet OHNE Loxone: traegt die Einrichtung? Von unten nach oben -
 * der erste Kreuz-Eintrag ist in aller Regel die Ursache.
 * ================================================================== */

/**
 * Wo liegt die Oberflaeche? Installiert stehen html/ und htmlauth/ in
 * GETRENNTEN Baeumen, im entpackten Archiv nebeneinander. Beide Lagen treffen.
 */
function cam_oberflaeche_pfad()
{
    $p = cam_paths();
    $ordner = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
    $kandidaten = array();
    if ($p['lbhome'] !== '') {
        $kandidaten[] = $p['lbhome'] . '/webfrontend/htmlauth/plugins/' . $ordner . '/index.php';
    }
    $kandidaten[] = dirname(dirname(__DIR__)) . '/htmlauth/index.php';
    $kandidaten[] = dirname(__DIR__) . '/htmlauth/index.php';
    foreach ($kandidaten as $k) {
        if (is_file($k)) {
            return $k;
        }
    }
    return '';
}

/**
 * Zaehlt die drei Stellen gegeneinander, die immer zusammengehoeren:
 * Reiterleiste, Bereiche und Positivliste - und prueft, ob Leiste und
 * Bereiche das sm-active serverseitig setzen.
 *
 * Rueckgabe: array(leiste, bereiche, liste, aktiv_leiste, aktiv_bereiche)
 */
function cam_reiter_pruefen()
{
    $aus = array('leiste' => 0, 'bereiche' => 0, 'liste' => 0,
                 'aktiv_leiste' => 0, 'aktiv_bereiche' => 0, 'gelesen' => false);
    $pfad = cam_oberflaeche_pfad();
    if ($pfad === '') {
        return $aus;
    }
    $q = (string) @file_get_contents($pfad);
    if ($q === '') {
        return $aus;
    }
    $aus['gelesen'] = true;
    $aus['leiste'] = preg_match_all('/data-pane="(tab-[a-z0-9]+)"/', $q);
    $aus['bereiche'] = preg_match_all('/id="(tab-[a-z0-9]+)"/', $q);
    if (preg_match('/\^tab-\(([a-z0-9|]+)\)/', $q, $m)) {
        $aus['liste'] = count(explode('|', $m[1]));
    }
    // Serverseitiges sm-active, je an der Leiste und am Bereich.
    $aus['aktiv_leiste'] = preg_match_all('/class="sm-tab<\?=[^>]*sm-active/', $q);
    $aus['aktiv_bereiche'] = preg_match_all('/class="sm-pane<\?=[^>]*sm-active/', $q);
    return $aus;
}

function cam_pruefungen()
{
    $cfg = cam_config();
    $p = cam_paths();
    $z = array();
    $zeile = function ($stand, $frage, $antwort) use (&$z) {
        $z[] = array((int) $stand, $frage, $antwort);
    };

    /* Kamera erreichbar? */
    $ac_adressen = array();
    foreach (cam_kameras() as $ac_kid) {
        $ac_kc = cam_kcfg($ac_kid);
        if (trim((string) $ac_kc['host']) !== '') {
            $ac_adressen[] = cam_kname($ac_kid) . ': ' . $ac_kc['host'];
        }
    }
    $host = trim((string) $cfg['host']);
    $zeile($ac_adressen ? 1 : 0, cam_t('TEST.F_HOST'),
        $ac_adressen ? cam_e(implode(' | ', $ac_adressen)) : cam_t('TEST.A_HOST_FEHLT'));

    /* Zugangsdaten - Form beurteilen, Wert nie zeigen */
    if ($cfg['user'] === '' && $cfg['pass'] === '') {
        $zeile(-1, cam_t('TEST.F_ZUGANG'), cam_t('TEST.A_ZUGANG_KEINE'));
    } elseif ($cfg['pass'] !== '' && $cfg['user'] === '') {
        $zeile(0, cam_t('TEST.F_ZUGANG'), cam_t('TEST.A_ZUGANG_PW_OHNE_USER'));
    } else {
        $zeile(1, cam_t('TEST.F_ZUGANG'),
            sprintf(cam_t('TEST.A_ZUGANG_OK'), cam_e($cfg['user']), strlen($cfg['pass'])));
    }

    /* Rechte der Konfiguration */
    if (is_file($p['config'])) {
        $rechte = substr(sprintf('%o', fileperms($p['config'])), -3);
        $zeile($rechte === '600' ? 1 : 0, cam_t('TEST.F_RECHTE'),
            sprintf(cam_t($rechte === '600' ? 'TEST.A_RECHTE_OK' : 'TEST.A_RECHTE_OFFEN'), $rechte));
    }

    /* Anmeldeart */
    if ($cfg['auth'] === 'digest' && !function_exists('curl_init')) {
        $t = cam_digest_selbsttest();
        $zeile($t[0] ? 1 : 0, cam_t('TEST.F_DIGEST'),
            cam_t($t[0] ? 'TEST.A_DIGEST_OK' : 'TEST.A_DIGEST_FEHL'));
    } else {
        $zeile(1, cam_t('TEST.F_AUTH'), sprintf(cam_t('TEST.A_AUTH'), cam_e($cfg['auth']),
            function_exists('curl_init') ? 'cURL' : cam_t('TEST.A_AUTH_STREAM')));
    }

    /* Der Zustand wird EINMAL geholt und von hier ab benutzt. Vorher stand
       die Zuweisung weiter unten, und die Zeile zum Minutentakt las eine noch
       nicht gesetzte Variable - sie war deshalb immer gruen. */
    $st = cam_state();

    /* Antwortet JEDE Kamera?
       Die Ursache gehoert vor die Wirkung: wer hier ein Kreuz sieht, braucht
       die Zeilen darunter ueber Bilder und Archiv gar nicht mehr zu deuten.
       Und je Kamera eine eigene Zeile - eine gemeinsame waere bei zwei
       Kameras eine wahre Aussage ueber die halbe Anlage. */
    $ac_mehrere = count(cam_kameras()) > 1;
    foreach (cam_kameras() as $ac_kid) {
        $ac_b = cam_betrieb($ac_kid);
        $ac_frage = cam_t('TEST.F_ERREICHBAR')
            . ($ac_mehrere ? ' (' . cam_e(cam_kname($ac_kid)) . ')' : '');
        $ac_kc = cam_kcfg($ac_kid);
        if (trim((string) $ac_kc['host']) === '') {
            $zeile(-1, $ac_frage, cam_t('TEST.A_HOST_FEHLT'));
        } elseif ((int) $cfg['pruef_minuten'] <= 0) {
            $zeile(-1, $ac_frage, cam_t('TEST.A_ERREICHBAR_AUS'));
        } elseif ((int) $ac_b['erreichbar'] === -1) {
            $zeile(-1, $ac_frage, cam_t('TEST.A_ERREICHBAR_NIE'));
        } elseif ((int) $ac_b['erreichbar'] === 1) {
            $zeile(1, $ac_frage,
                sprintf(cam_t('TEST.A_ERREICHBAR_JA'), cam_e((string) $ac_b['geprueft'])));
        } else {
            $zeile(0, $ac_frage,
                sprintf(cam_t('TEST.A_ERREICHBAR_NEIN'), (int) $ac_b['fehler'],
                        cam_e((string) $ac_b['grund'])));
        }
    }

    /* Laeuft der Minutentakt? Ohne ihn gibt es weder Zeitraffer noch
       Bereinigung noch MQTT - und keine der Zeilen darunter ist noch aktuell. */
    $ac_hm = (int) $st['herz_min'];
    if ($ac_hm < 0) {
        $zeile(0, cam_t('TEST.F_HERZ'), cam_t('TEST.A_HERZ_NIE'));
    } else {
        $zeile($ac_hm <= 5 ? 1 : 0, cam_t('TEST.F_HERZ'),
            sprintf(cam_t($ac_hm <= 5 ? 'TEST.A_HERZ_OK' : 'TEST.A_HERZ_ALT'), $ac_hm));
    }

    /* Letztes Bild - je Kamera. */
    foreach (cam_kameras() as $ac_kid) {
        $ac_ks = ($ac_kid === 1) ? $st : cam_state($ac_kid);
        $ac_frage = cam_t('TEST.F_BILD')
            . ($ac_mehrere ? ' (' . cam_e(cam_kname($ac_kid)) . ')' : '');
        if ((int) $ac_ks['alter_min'] < 0) {
            $zeile(0, $ac_frage, cam_t('TEST.A_BILD_KEINS'));
        } else {
            $frisch = (int) $ac_ks['alter_min'] <= 1440;
            $zeile($frisch ? 1 : -1, $ac_frage,
                sprintf(cam_t('TEST.A_BILD_OK'), (int) $ac_ks['alter_min'],
                        cam_e($ac_ks['letzter_anlass'])));
        }
    }

    /* Speicherbestand.
       Diese Zeile urteilt nicht, sie zaehlt - und bei einer leeren Menge sagt
       sie das auch. Ein Haken auf "0 Bilder, 0 Serien, 0 Zeitrafferbilder"
       beruhigt an genau der Stelle, an der jemand hinsieht, weil nichts
       ankommt. */
    $ac_ges = (int) $st['bilder'] + (int) $st['clips'] + (int) $st['timelapse'];
    $zeile($ac_ges > 0 ? 1 : -1, cam_t('TEST.F_ARCHIV'),
        $ac_ges > 0
            ? sprintf(cam_t('TEST.A_ARCHIV'), (int) $st['bilder'], (int) $st['clips'],
                      (int) $st['timelapse'], (int) $cfg['keep_days'])
            : cam_t('TEST.A_ARCHIV_LEER'));

    /* Zeitraffer und Bereinigung - laufen sie? */
    foreach (array(
        array('timelapse_am.txt', 'TEST.F_TIMELAPSE', 'TEST.A_TIMELAPSE_OK', 'TEST.A_TIMELAPSE_NIE', !empty($cfg['timelapse'])),
        array('cleanup_am.txt',   'TEST.F_CLEANUP',   'TEST.A_CLEANUP_OK',   'TEST.A_CLEANUP_NIE',   true),
    ) as $eintrag) {
        list($datei, $frage, $ok, $nie, $aktiv) = $eintrag;
        if (!$aktiv) {
            $zeile(-1, cam_t($frage), cam_t('TEST.A_TIMELAPSE_AUS'));
            continue;
        }
        $f = $p['tmp'] . '/' . $datei;
        $letzter = is_file($f) ? trim((string) @file_get_contents($f)) : '';
        $zeile($letzter !== '' ? 1 : -1, cam_t($frage),
            $letzter !== '' ? sprintf(cam_t($ok), cam_e($letzter)) : cam_t($nie));
    }

    /* ffmpeg - fuer Clips und Zeitraffer-Video */
    $ff = cam_ffmpeg();
    $zeile($ff !== '' ? 1 : -1, cam_t('TEST.F_FFMPEG'),
        $ff !== '' ? cam_e($ff) : cam_t('TEST.A_FFMPEG_FEHLT'));

    /* MQTT */
    $m = cam_mqtt_zustand_pruefen();
    if (empty($cfg['mqtt_enabled'])) {
        $zeile(-1, cam_t('TEST.F_MQTT'), cam_t('TEST.A_MQTT_AUS'));
    } elseif (!$m['gefunden']) {
        $zeile(0, cam_t('TEST.F_MQTT'), cam_t('TEST.A_MQTT_KEIN_ABSCHNITT'));
    } elseif (!$m['udpport']) {
        $zeile(0, cam_t('TEST.F_MQTT'), cam_t('TEST.A_MQTT_KEIN_PORT'));
    } elseif (!$m['autostart']) {
        $zeile(0, cam_t('TEST.F_MQTT'), cam_t('TEST.A_MQTT_KEIN_AUTOSTART'));
    } else {
        $zeile(1, cam_t('TEST.F_MQTT'),
            sprintf(cam_t('TEST.A_MQTT_OK'), (int) $m['udpport'], cam_e($cfg['mqtt_topic'])));
    }

    /* Reiterleiste, Bereiche und Positivliste gegeneinander.
       Diese Zeile ersetzt eine Pruefung, die hausstandard_pruefen.py seit dem
       serverseitigen sm-active nicht mehr leisten kann: eine zusammengesetzte
       CSS-Klasse ist statisch nicht mehr zu lesen. Fehlt ein Name in der
       Positivliste, ist der Reiter sichtbar und anklickbar - aber nach jedem
       Absenden springt die Seite zurueck auf Einstellungen. */
    $ac_r = cam_reiter_pruefen();
    if (!$ac_r['gelesen']) {
        $zeile(-1, cam_t('TEST.F_REITER'), cam_t('TEST.A_REITER_UNGELESEN'));
    } else {
        $ac_einig = ($ac_r['leiste'] === $ac_r['bereiche']
                     && $ac_r['leiste'] === $ac_r['liste']
                     && $ac_r['leiste'] === $ac_r['aktiv_leiste']
                     && $ac_r['leiste'] === $ac_r['aktiv_bereiche']
                     && $ac_r['leiste'] > 0);
        $zeile($ac_einig ? 1 : 0, cam_t('TEST.F_REITER'),
            sprintf(cam_t($ac_einig ? 'TEST.A_REITER_OK' : 'TEST.A_REITER_FEHL'),
                    (int) $ac_r['leiste'], (int) $ac_r['bereiche'], (int) $ac_r['liste'],
                    (int) $ac_r['aktiv_leiste'], (int) $ac_r['aktiv_bereiche']));
    }

    /* Vorlage wohlgeformt - gehoert hierher, nicht erst in die Pruefung vor
       dem Ausliefern: eine kaputte Vorlage merkt der Anwender sonst erst in
       Loxone Config, und dort sucht er den Fehler bei sich. */
    /* Beide Bauformen pruefen, nicht nur die eine - seit es eine
       Ausgangsvorlage gibt, waere ein Haken auf die haelfte der Wahrheit. */
    $alt = libxml_use_internal_errors(true);
    $gut = true;
    foreach (array(cam_vorlage(), cam_vorlage_ausgang()) as $ac_v) {
        if (simplexml_load_string($ac_v[1]) === false) { $gut = false; }
    }
    libxml_clear_errors();
    libxml_use_internal_errors($alt);
    $zeile($gut ? 1 : 0, cam_t('TEST.F_VORLAGE'),
        cam_t($gut ? 'TEST.A_VORLAGE_OK' : 'TEST.A_VORLAGE_KAPUTT'));

    return $z;
}

function cam_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** Zustand des MQTT-Gateways von LoxBerry. */
function cam_mqtt_zustand_pruefen()
{
    $p = cam_paths();
    $aus = array('gefunden' => false, 'udpport' => 0, 'autostart' => false,
                 'fassung' => 0);
    if ($p['lbhome'] === '') { return $aus; }
    $f = $p['lbhome'] . '/config/system/general.json';
    if (!is_file($f)) { return $aus; }
    $d = json_decode((string) @file_get_contents($f), true);
    if (!isset($d['Mqtt'])) { return $aus; }
    $aus['gefunden'] = true;
    $aus['udpport'] = isset($d['Mqtt']['Udpinport']) ? (int) $d['Mqtt']['Udpinport'] : 0;
    $aus['autostart'] = !empty($d['Mqtt']['Gatewayautostart']); // 1.9.3: richtiger Schluessel - 'Autostart' gibt es nicht, die Warnung kam deshalb immer
    /* Die FASSUNG des Gateways, ab Werk 1. Sie entscheidet, was der Anwender
     * eintragen muss: unter V1 jedes Thema von Hand, ab V2 erscheint die
     * Themengruppe von selbst in den Subscriptions. Ohne diese Zeile
     * behauptete die Oberflaeche den V1-Satz unbedingt - fuer jeden
     * V2-Anwender ein Verweis auf einen Eingabeplatz, den es nicht gibt.
     * 0 heisst "nicht feststellbar" und wird als solches angezeigt. */
    $aus['fassung'] = isset($d['Mqtt']['Gatewayversion']) ? (int) $d['Mqtt']['Gatewayversion'] : 0;
    return $aus;
}

/** Rueckgabe: Zahl der wirklich abgesetzten Meldungen (0 = nichts ging hinaus). */
function cam_mqtt($werte)
{
    $cfg = cam_config();
    if (empty($cfg['mqtt_enabled'])) {
        return 0;
    }
    $p = cam_paths();
    if ($p['lbhome'] === '') {
        return 0;
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
        return 0;
    }
    $prefix = trim((string) $cfg['mqtt_topic']) !== '' ? trim((string) $cfg['mqtt_topic']) : 'acti';
    /* stream_socket_client() statt socket_create(): letzteres steckt in der
       Erweiterung php-sockets, die nicht garantiert geladen ist. Fehlt sie,
       ist das KEIN abfangbarer Fehler, sondern ein fataler - und im Cron, der
       nach /dev/null schreibt, sieht das niemand. stream_socket_client
       gehoert zum Kern und tut dasselbe. */
    $s = @stream_socket_client('udp://127.0.0.1:' . $udpport, $eno, $estr, 2);
    if (!$s) {
        cam_log('MQTT: UDP-Eingang ' . $udpport . ' nicht erreichbar (' . $estr . ')');
        return 0;
    }
    $gesendet = 0;
    foreach ((array) $werte as $k => $v) {
        $msg = 'publish ' . $prefix . '/' . $k . ' ' . cam_mqtt_wert_saeubern($v);
        if (@fwrite($s, $msg) !== false) {
            $gesendet++;
        }
    }
    fclose($s);
    return $gesendet;
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
            foreach (array(lb_wurzel_ermitteln(), '/home/loxberry/loxberry') as $k) {
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


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Der wichtigste Punkt: eine halb gueltige Datei ueberschreibt GAR NICHTS.
 * Wer eine Sicherung zurueckspielt, will entweder den ganzen Stand oder
 * gar keinen - eine zur Haelfte uebernommene Konfiguration ist schlimmer
 * als die alte, und man sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function cam_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(cam_t('TEXT.SICH_KEIN_JSON')), 0);
    }
    $neu = cam_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(cam_t('TEXT.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = cam_t('TEXT.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
