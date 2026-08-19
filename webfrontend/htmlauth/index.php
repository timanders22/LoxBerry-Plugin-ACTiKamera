<?php
/**
 * ACTi Kamera - Admin-Oberflaeche
 * Reiter: Einstellungen | Einbindung in Loxone | Aufnahmen | Test | Protokoll
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg als stdClass) und
 * wuerde gleichnamige Plugin-Variablen ueberschreiben - daher tragen hier
 * ALLE Variablen ein ac_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen.
 *
 * Diese Definition stand bis 1.9.7 WEITER UNTEN in der Datei. PHP zieht
 * bedingte Definitionen (if (!function_exists(...)) { function ... }) nicht
 * vor - der Aufruf in der naechsten Zeile endete deshalb ohne gesetztes
 * LBHOMEDIR mit "Call to undefined function", also einer weissen Seite.
 * Gemessen: Rueckgabewert 255.
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

$ac_lbhome = getenv('LBHOMEDIR') ?: lb_wurzel_ermitteln();
$ac_plugin = getenv('LBPPLUGINDIR') ?: basename(__DIR__);
if ($ac_lbhome && is_dir($ac_lbhome . '/config/plugins/' . $ac_plugin) === false) {
    $ac_plugin = basename(dirname(__DIR__));
    if (is_dir($ac_lbhome . '/config/plugins/' . $ac_plugin) === false) {
        $ac_plugin = 'actikamera';
    }
}
if ($ac_lbhome) {
    $ac_sdk = $ac_lbhome . '/libs/phplib/loxberry_system.php';
    if (file_exists($ac_sdk)) {
        require_once $ac_sdk;
        require_once $ac_lbhome . '/libs/phplib/loxberry_web.php';
    }
    $ac_logfile = $ac_lbhome . '/log/plugins/' . $ac_plugin . '/cam.log';
} else {
    $ac_logfile = sys_get_temp_dir() . '/actikamera/cam.log';
}

foreach (array(
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . $ac_plugin . '/cam_lib.php',
    dirname(__DIR__) . '/html/cam_lib.php',
) as $ac_cand) {
    if (is_file($ac_cand)) { require_once $ac_cand; break; }
}

$ac_saved = false; $ac_note = ''; $ac_err = '';

/* Aktionstoken beim ersten Aufruf erzeugen.
 *
 * Das gehoert in den ANGEMELDETEN Bereich - der unangemeldete Endpunkt darf
 * nichts anlegen. Erzeugt wird nur, wenn noch keines dasteht; jedes Speichern
 * traegt es weiter, weil beide Handler auf cam_config() aufbauen.
 */
if (function_exists('cam_config') && function_exists('cam_token_erzeugen')) {
    $ac_start = cam_config();
    $ac_neu = false;
    if ((string) $ac_start['aktionstoken'] === '') {
        $ac_start['aktionstoken'] = cam_token_erzeugen();
        $ac_neu = true;
    }
    /* Je Kamera ein kurzes Ausloese-Token - auch fuer Kameras, die es noch
       nicht gibt: sonst stuende die Adresse erst nach einem zweiten Speichern
       da, und niemand versteht warum. */
    for ($ac_i = 1; $ac_i <= CAM_MAX; $ac_i++) {
        $ac_sl = $ac_i > 1 ? (string) $ac_i : '';
        if ((string) $ac_start['ausloeser_token' . $ac_sl] === '') {
            $ac_start['ausloeser_token' . $ac_sl] = cam_kurztoken();
            $ac_neu = true;
        }
    }
    if ($ac_neu) {
        cam_config_save($ac_start);
    }
}

/* EINE zentrale Pruefung gegen fremde Absender, vor jedem Handler - einen
 * einzelnen Handler kann man vergessen. Faellt sie durch, wird der POST
 * zurueckgenommen UND gemeldet: ein Formular, das wortlos nichts tut,
 * schickt den Anwender auf die Suche nach einem Fehler, den es nicht gibt.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('cam_formtoken')) {
    $ac_fsoll = cam_formtoken();
    $ac_fist = isset($_POST['formtoken']) ? (string) $_POST['formtoken'] : '';
    if ($ac_fsoll === '' || $ac_fist === '' || !hash_equals($ac_fsoll, $ac_fist)) {
        $_POST = array();
        $ac_err = cam_t('TEXT.FORMULAR_ABGEWIESEN');
    }
}

// ---------- Speichern: nur die MQTT-Werte ----------
// Bewusst ein eigener Zweig. Der grosse Speichervorgang liest zwar die alte
// Konfiguration ein, ueberschreibt danach aber JEDES Feld aus dem Formular -
// wuerde das MQTT-Formular dieselbe Taste druecken, stuenden Kameraadresse
// und Zugangsdaten anschliessend auf ihren Vorgabewerten.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mqtt'])) {
    $ac_m = cam_config();
    $ac_m['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $ac_mt = preg_replace('#[^\w/\-]#', '', (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : ''));
    if ($ac_mt === '') {
        $ac_err = cam_t('MQTT.FEHLER_TOPIC');
    } else {
        $ac_m['mqtt_topic'] = $ac_mt;
        if (cam_config_save($ac_m)) {
            $ac_saved = true;
            // Nach dem Umstellen alles einmal frisch senden, damit der Broker
            // nicht auf Werten unter dem alten Thema sitzenbleibt.
            cam_mqtt_zustand(true);
        } else {
            $ac_err = cam_t('MQTT.FEHLER_SPEICHERN');
        }
    }
    $ac_tab = 'tab-mqtt';
}

/* Drei Stellen gehoeren immer zusammen: Reiterleiste, Bereich (sm-pane mit
   gleicher id) und diese Positivliste. Fehlt ein Name hier, ist der Reiter
   sichtbar und anklickbar - aber nach jedem Absenden springt die Seite
   zurueck auf Einstellungen. */
$ac_muster = '/^tab-(settings|mqtt|loxone|shots|test|log)$/';
$ac_tab = preg_match($ac_muster, (string) (isset($_POST['activetab']) ? $_POST['activetab'] : '')) ? $_POST['activetab'] : 'tab-settings';
if (isset($_GET['form']) && preg_match($ac_muster, 'tab-' . $_GET['form'])) {
    $ac_tab = 'tab-' . $_GET['form'];
}

// ---------- Loxone-Vorlage herunterladen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    $ac_host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', $_SERVER['HTTP_HOST']) : '';
    /* Zwei Bauformen, ein Handler: der Wert des Knopfes entscheidet.
       Zwei getrennte Handler waeren zwei Stellen, die man mitpflegen muss. */
    $ac_v = ($_POST['download'] === 'xml_out' && function_exists('cam_vorlage_ausgang'))
        ? cam_vorlage_ausgang($ac_host) : cam_vorlage($ac_host);
    header('Content-Type: application/x-download');
    // Die Anfuehrungszeichen um den Dateinamen sind Pflicht - ohne sie bricht
    // jeder Name, der ein Leerzeichen enthaelt.
    header('Content-Disposition: attachment; filename="' . $ac_v[0] . '"');
    header('Content-Length: ' . strlen($ac_v[1]));
    echo $ac_v[1];
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['neuestoken'])
    && function_exists('cam_token_erzeugen')) {
    $ac_nt = cam_config();
    $ac_nt['aktionstoken'] = cam_token_erzeugen();
    // Die Ausloese-Token gleich mit - wer neu wuerfelt, will alles neu.
    for ($ac_i = 1; $ac_i <= CAM_MAX; $ac_i++) {
        $ac_nt['ausloeser_token' . ($ac_i > 1 ? (string) $ac_i : '')] = cam_kurztoken();
    }
    if (cam_config_save($ac_nt)) {
        $ac_note = cam_t('TEXT.TOKEN_NEU_ERZEUGT');
    } else {
        $ac_err = cam_t('TEXT.TOKEN_NICHT_GESPEICHERT');
    }
    $ac_tab = 'tab-loxone';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    @mkdir(dirname($ac_logfile), 0775, true);
    @file_put_contents($ac_logfile, '[' . date('Y-m-d H:i:s') . "] Protokoll geleert (Admin-Oberflaeche)\n");
    $ac_tab = 'tab-log';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shotnow']) && function_exists('cam_snapshot')) {
    list($ac_ok, $ac_info) = cam_snapshot('test');
    $ac_note = $ac_ok ? ('Bild aufgenommen: ' . $ac_info) : ('Aufnahme fehlgeschlagen: ' . $ac_info);
    $ac_tab = 'tab-test';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['timelapsenow']) && function_exists('cam_timelapse')) {
    list($ac_ok, $ac_info) = cam_timelapse();
    $ac_note = $ac_ok ? ('Zeitrafferbild aufgenommen: ' . $ac_info) : ('Aufnahme fehlgeschlagen: ' . $ac_info);
    $ac_tab = 'tab-test';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanupnow']) && function_exists('cam_cleanup')) {
    $ac_note = 'Aufraeumen erledigt: ' . cam_cleanup() . ' alte Aufnahmen entfernt.';
    $ac_tab = 'tab-shots';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save']) && function_exists('cam_config')) {
    $ac_new = cam_config();
    /* Eingaben nur von Steuerzeichen und Anfuehrungszeichen befreien - NICHT von
       Doppelpunkt, Schraegstrich oder Punkt. Ein zu strenger Filter hat aus einer
       eingefuegten URL frueher "http19216817817cgi-binencoder..." gemacht. */
    $ac_saeubern = function ($wert) {
        $wert = preg_replace('/[\x00-\x1F\x7F"\'<>\s]/', '', (string) $wert);
        return trim((string) $wert);
    };

    /* Eine Schleife ueber alle Kameras statt eines Blocks je Kamera.
       Kamera 1 traegt die Feldnamen ohne Nummer - so, wie sie seit jeher
       heissen. */
    $ac_mangel = array();
    $ac_namen = array();
    for ($ac_i = 1; $ac_i <= CAM_MAX; $ac_i++) {
        $ac_s = $ac_i > 1 ? (string) $ac_i : '';
        $ac_f = function ($feld) use ($ac_s) {
            return isset($_POST[$feld . $ac_s]) ? $_POST[$feld . $ac_s] : '';
        };

        $ac_new['host' . $ac_s] = trim((string) $ac_f('host'));

        /* Leeres Benutzerfeld loescht NICHT den gespeicherten Wert - genau das
           ist hier schon einmal passiert und fuehrte zu USER=&PWD=… und damit
           zu HTTP 401. */
        $ac_u = trim((string) $ac_f('user'));
        if ($ac_u !== '') { $ac_new['user' . $ac_s] = $ac_u; }

        /* Passwortfeld leer lassen = bisheriges Passwort behalten.
           trim() ist kein Schoenheitsfehler: Passwortverwaltungen im Browser
           schreiben gelegentlich ein einzelnes Leerzeichen in ein leer
           gelassenes Feld. Ohne trim() wuerde damit das gespeicherte Passwort
           durch ein Leerzeichen ersetzt - und die Kamera meldet nur noch 401. */
        $ac_pw = trim((string) $ac_f('pass'));
        if ($ac_pw !== '') { $ac_new['pass' . $ac_s] = $ac_pw; }

        /* Doppelte Namen weist die Hausform ab. Abgewiesen wird der NAME, nicht
           das ganze Formular: ein Speichern, das wegen einer Kleinigkeit gar
           nichts uebernimmt, hat hier schon einmal Schaden angerichtet. */
        $ac_nm = trim(preg_replace('/[\x00-\x1F\x7F"<>]/', '', (string) $ac_f('name')));
        if ($ac_nm !== '' && in_array(strtolower($ac_nm), $ac_namen, true)) {
            $ac_mangel[] = sprintf(cam_t('TEXT.NAME_DOPPELT'), $ac_i, $ac_nm);
        } else {
            $ac_new['name' . $ac_s] = $ac_nm;
            if ($ac_nm !== '') { $ac_namen[] = strtolower($ac_nm); }
        }

        $ac_new['channel' . $ac_s] = max(1, min(16, (int) ($ac_f('channel') !== '' ? $ac_f('channel') : 1)));
        $ac_res = preg_replace('/[^A-Za-z0-9x,]/', '', (string) $ac_f('resolution'));
        if (stripos($ac_res, 'http') === 0) { $ac_res = ''; }
        $ac_new['resolution' . $ac_s] = $ac_res;

        $ac_cmd = $ac_saeubern($ac_f('snapcmd'));
        $ac_su = $ac_saeubern($ac_f('snapurl'));
        // Wer die komplette Adresse ins Befehlsfeld einfuegt, meint die vollstaendige URL
        if ($ac_su === '' && stripos($ac_cmd, '://') !== false) {
            $ac_su = $ac_cmd;
            $ac_cmd = '';
            $ac_note = 'Die eingefuegte Adresse wurde als vollstaendige Schnappschuss-URL uebernommen.';
        }
        if ($ac_su !== '' && !preg_match('#^https?://#i', $ac_su)) {
            $ac_su = 'http://' . ltrim($ac_su, '/');
        }
        $ac_new['snapcmd' . $ac_s] = $ac_cmd;
        $ac_new['snapurl' . $ac_s] = $ac_su;

        $ac_a = (string) $ac_f('auth');
        $ac_new['auth' . $ac_s] = in_array($ac_a, array('auto', 'url', 'basic', 'digest'), true) ? $ac_a : 'auto';
        $ac_new['timeout' . $ac_s] = max(2, min(30, (int) ($ac_f('timeout') !== '' ? $ac_f('timeout') : 8)));

        /* Eine Adresse, die dem Muster nicht entspricht, wird gemeldet statt
           stillschweigend geleert - bis 1.9.8 verschwand sie wortlos. */
        $ac_mu = trim((string) $ac_f('mjpeg_url'));
        if ($ac_mu !== '' && !preg_match('#^https?://#i', $ac_mu)) {
            $ac_mangel[] = sprintf(cam_t('TEXT.ADRESSE_VERWORFEN'), $ac_i, 'http://', ac_e($ac_mu));
        } else {
            $ac_new['mjpeg_url' . $ac_s] = $ac_mu;
        }
        $ac_ru = trim((string) $ac_f('rtsp_url'));
        if ($ac_ru !== '' && !preg_match('#^rtsp://#i', $ac_ru)) {
            $ac_mangel[] = sprintf(cam_t('TEXT.ADRESSE_VERWORFEN'), $ac_i, 'rtsp://', ac_e($ac_ru));
        } else {
            $ac_new['rtsp_url' . $ac_s] = $ac_ru;
        }
        $ac_new['rtsp_stream' . $ac_s] = ((int) ($ac_f('rtsp_stream') !== '' ? $ac_f('rtsp_stream') : 2)) === 1 ? 1 : 2;
        $ac_new['rtsp_port' . $ac_s] = max(1, min(65535, (int) ($ac_f('rtsp_port') !== '' ? $ac_f('rtsp_port') : 7070)));
        $ac_new['rtsp_quality' . $ac_s] = max(2, min(15, (int) ($ac_f('rtsp_quality') !== '' ? $ac_f('rtsp_quality') : 5)));
    }
    $ac_new['stream_fps'] = max(0.2, min(10, (float) (isset($_POST['stream_fps']) ? $_POST['stream_fps'] : 2)));
    // 900 s statt 21600: jeder offene Strom belegt einen PHP-Arbeitsprozess,
    // und davon hat ein LoxBerry nur eine Handvoll. Siehe cam_stream.php.
    $ac_new['stream_maxsec'] = max(5, min(900, (int) (isset($_POST['stream_maxsec']) ? $_POST['stream_maxsec'] : 900)));
    $ac_sm = (string) (isset($_POST['stream_mode']) ? $_POST['stream_mode'] : 'auto');
    $ac_new['stream_mode'] = in_array($ac_sm, array('auto', 'mjpeg', 'jpeg', 'rtsp'), true) ? $ac_sm : 'auto';
    $ac_new['stream_token'] = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) (isset($_POST['stream_token']) ? $_POST['stream_token'] : ''));
    $ac_new['clip_seconds'] = max(2, min(60, (int) (isset($_POST['clip_seconds']) ? $_POST['clip_seconds'] : 10)));
    $ac_new['clip_fps'] = max(1, min(5, (int) (isset($_POST['clip_fps']) ? $_POST['clip_fps'] : 2)));
    $ac_new['notify'] = array(
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'push_minutes' => max(1, min(30, (int) (isset($_POST['push_minutes']) ? $_POST['push_minutes'] : 2))),
    );
    /* mqtt_enabled und mqtt_topic werden hier NICHT mehr angefasst: die
     * Felder stehen nur noch im Reiter MQTT und haben dort einen eigenen
     * Handler (save_mqtt). $ac_new kommt aus cam_config(), die Werte
     * ueberleben also unveraendert. Stuende die Zeile hier weiter, schaltete
     * jedes Speichern der Einstellungen MQTT stillschweigend ab. */
    $ac_new['keep_max'] = max(0, min(100000, (int) (isset($_POST['keep_max']) ? $_POST['keep_max'] : 0)));
    $ac_new['keep_mb'] = max(0, min(1000000, (int) (isset($_POST['keep_mb']) ? $_POST['keep_mb'] : 0)));
    $ac_new['keep_days'] = max(0, min(3650, (int) (isset($_POST['keep_days']) ? $_POST['keep_days'] : 90)));
    $ac_new['pruef_minuten'] = max(0, min(1440, (int) (isset($_POST['pruef_minuten']) ? $_POST['pruef_minuten'] : 5)));
    $ac_new['mindestpause'] = max(0, min(3600, (int) (isset($_POST['mindestpause']) ? $_POST['mindestpause'] : 0)));
    $ac_new['timelapse'] = isset($_POST['timelapse']) ? 1 : 0;
    $ac_new['timelapse_time'] = preg_match('/^\d{1,2}:\d{2}$/', (string) (isset($_POST['timelapse_time']) ? $_POST['timelapse_time'] : '')) ? $_POST['timelapse_time'] : '12:00';
    $ac_new['ai_url'] = trim((string) (isset($_POST['ai_url']) ? $_POST['ai_url'] : ''));
    $ac_new['ai_min'] = max(1, min(99, (int) (isset($_POST['ai_min']) ? $_POST['ai_min'] : 50)));
    $ac_new['webhook1'] = trim((string) (isset($_POST['webhook1']) ? $_POST['webhook1'] : ''));
    $ac_new['webhook2'] = trim((string) (isset($_POST['webhook2']) ? $_POST['webhook2'] : ''));
    if (cam_config_save($ac_new)) {
        $ac_saved = true;
        /* Beanstandungen werden GEMELDET, nicht verschwiegen - und sie
           verhindern das Speichern nicht. Alle auf einmal, damit niemand einen
           Fehler nach dem anderen korrigiert. */
        if ($ac_mangel) {
            $ac_err = implode(' ', $ac_mangel);
        }
    } else {
        $ac_err = 'Konfiguration konnte nicht gespeichert werden.';
    }
}

$ac_cfg = function_exists('cam_config') ? cam_config() : array();
if (!is_array($ac_cfg)) { $ac_cfg = array(); }
$ac_notify = is_array($ac_cfg['notify']) ? $ac_cfg['notify'] : array();
$ac_notify += array('push' => 1, 'push_minutes' => 2);
$ac_st = function_exists('cam_state') ? cam_state() : array();
$ac_paths = function_exists('cam_paths') ? cam_paths() : array();

$ac_loglines = array();
if (is_file($ac_logfile)) {
    $ac_loglines = array_slice(array_reverse(file($ac_logfile, FILE_IGNORE_NEW_LINES) ?: array()), 0, 300);
}
$ac_host = $_SERVER['HTTP_HOST'] ?: 'loxberry';
$ac_token = isset($ac_cfg['aktionstoken']) ? (string) $ac_cfg['aktionstoken'] : '';


function ac_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

if (function_exists('LBWeb::lbheader') || class_exists('LBWeb')) {
    LBWeb::lbheader('ACTi Kamera', 'https://wiki.loxberry.de', 'help.html');
} else {
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>ACTi Kamera</title></head><body>';
}
?>
<style>
.acw { max-width: 1100px; margin: 0 auto; padding: 0 10px 40px; font-size: 0.95em; }
.acw, .acw * { text-shadow: none !important; }
.acw h2 { color: #6dac20; margin: 18px 0 6px; font-size: 1.15em; }

/* Gleich grosse Schaltflaechen mit gleichem Abstand, egal ob Link <?php echo cam_t('TEXT.ODER'); ?> Formular */
.acw .sm-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.acw .sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.acw .sm-knopfreihe form { margin: 0; display: flex; }
.acw .sm-knopfreihe .sm-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.acw .sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.acw .sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.acw .sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.acw .sm-btn.sm-b-lesen { background: #6dac20; }
.acw .sm-btn.sm-b-technik { background: #546e7a; }
.acw .sm-btn.sm-b-aktion { background: #e0620d; }
.acw .sm-punkt.sm-b-lesen { background: #6dac20; }
.acw .sm-punkt.sm-b-technik { background: #546e7a; }
.acw .sm-punkt.sm-b-aktion { background: #e0620d; }
.acw label { display: block; font-weight: 600; margin: 8px 0 2px; }
.acw input[type=text], .acw input[type=password], .acw input[type=number], .acw select {
    width: 100%; padding: 7px 9px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; background: #fff; }
.acw .sm-row { display: flex; gap: 14px; flex-wrap: wrap; }
.acw .sm-row > div { flex: 1 1 210px; }
.acw .sm-small { color: #666; font-size: 0.88em; line-height: 1.45; }
.acw .sm-mono { font-family: monospace; background: #f4f4f4; padding: 1px 5px; border-radius: 4px; }
.acw .sm-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 8px; padding: 9px 18px;
    cursor: pointer; text-decoration: none; font-size: 0.95em; text-shadow: none !important; }
.acw .sm-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.acw .sm-ok { background: #e8f5e9; border: 1px solid #6dac20; }
.acw .sm-warn { background: #fff8e1; border: 1px solid #ffb300; }
.acw .sm-err { background: #ffebee; border: 1px solid #c62828; }
.acw .sm-info { background: #eef4fb; border: 1px solid #90a4ae; }
.acw .sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.acw .sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px;
          text-decoration: none; display: inline-block;
    cursor: pointer; color: #444 !important; text-shadow: none !important; }
.acw .sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.acw .sm-pane { display: none; padding-top: 4px; }
.acw .sm-pane.sm-active { display: block; }
.acw .sm-tbl { border-collapse: collapse; margin: 6px 0 10px; }
.acw .sm-tbl th, .acw .sm-tbl td { border: 1px solid #ddd; padding: 5px 9px; text-align: left; vertical-align: top; }
.acw .sm-tbl th { background: #f4f4f4; }
.acw .sm-log { background: #263238; color: #cfd8dc; font-family: monospace; font-size: 0.82em;
    padding: 10px; border-radius: 8px; max-height: 460px; overflow: auto; white-space: pre-wrap; box-shadow: none; }
.acw .sm-step { border-left: 4px solid #6dac20; padding: 4px 0 4px 12px; margin: 12px 0; }
.acw .sm-gal { display: flex; flex-wrap: wrap; gap: 8px; }
.acw .sm-gal figure { margin: 0; width: 190px; }
.acw .sm-gal img { width: 100%; border-radius: 6px; border: 1px solid #ccc; }
.acw .sm-gal figcaption { font-size: 0.78em; color: #666; word-break: break-all; }

/* Nachgetragene Definitionen (CSS-Luecken-Durchgang 13.08.2026):
   benutzt, aber nie definiert - wortgleich aus der Hausstandard-Vorlage
   bzw. der Referenzimplementierung uebernommen. */
.sm-hint { font-size: 0.85em; color: #555; margin: 4px 0 0; }
</style>
<div class="acw">
<h1 style="color:#6dac20;text-shadow:none;"><?php echo cam_t('TEXT.ACTI_KAMERA'); ?></h1>
<div class="sm-small"><?php echo cam_t('TEXT.HOLT_BILDER_VON_EINER_ACTI_NETZWER'); ?>
<b><?php echo cam_t('TEXT.OHNE_ZUGANGSDATEN_IN_DER_LOXONE_PR'); ?></b><?php echo cam_t('TEXT.BENUTZER_UND_PASSWORT_STEHEN_AUSSC'); ?></div>

<?php if ($ac_saved) { ?><div class="sm-alert sm-ok"><b><?php echo cam_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b></div><?php } ?>
<?php if ($ac_err !== '') { ?><div class="sm-alert sm-err"><?= ac_e($ac_err) ?></div><?php } ?>
<?php if ($ac_note !== '') { ?><div class="sm-alert <?= strpos($ac_note, 'fehlgeschlagen') !== false ? 'sm-err' : 'sm-ok' ?>"><?= ac_e($ac_note) ?></div><?php } ?>
<?php if (trim((string) $ac_cfg['user']) === '' && (string) $ac_cfg['pass'] !== '' && trim((string) $ac_cfg['snapurl']) === '') { ?>
<div class="sm-alert sm-err"><b><?php echo cam_t('TEXT.DER_BENUTZERNAME_IST_LEER'); ?></b><?php echo cam_t('TEXT.EIN_PASSWORT_IST_ABER_HINTERLEGT_D'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.ERROR_NOT_AUTHORIZED'); ?></span> <?php echo cam_t('TEXT.HTTP_401_BITTE_DEN_BENUTZERNAMEN_E'); ?></div>
<?php } ?>
<?php if (trim((string) $ac_cfg['host']) === '') { ?>
<div class="sm-alert sm-warn"><b><?php echo cam_t('TEXT.NOCH_NICHT_EINGERICHTET'); ?></b> <?php echo cam_t('TEXT.TRAGEN_SIE_UNTEN_ADRESSE_BENUTZER_'); ?></div>
<?php } ?>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. Warum beides:
     Der Link traegt die Adresse - jeder Reiter ist damit verlinkbar, die
     Zurueck-Taste tut das Erwartete, und faellt das Skript aus, bleibt die
     Seite bedienbar. -->
<div class="sm-tabs">
    <a class="sm-tab<?= $ac_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-pane="tab-settings" href="index.php?form=settings"><?php echo cam_t('REITER.EINSTELLUNGEN'); ?></a>
    <a class="sm-tab<?= $ac_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-pane="tab-mqtt"     href="index.php?form=mqtt"><?php echo cam_t('REITER.MQTT'); ?></a>
    <a class="sm-tab<?= $ac_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-pane="tab-loxone"   href="index.php?form=loxone"><?php echo cam_t('REITER.LOXONE'); ?></a>
    <a class="sm-tab<?= $ac_tab === 'tab-shots' ? ' sm-active' : '' ?>" data-pane="tab-shots"    href="index.php?form=shots"><?php echo cam_t('REITER.AUFNAHMEN'); ?></a>
    <a class="sm-tab<?= $ac_tab === 'tab-test' ? ' sm-active' : '' ?>" data-pane="tab-test"     href="index.php?form=test"><?php echo cam_t('REITER.TEST'); ?></a>
    <a class="sm-tab<?= $ac_tab === 'tab-log' ? ' sm-active' : '' ?>" data-pane="tab-log"      href="index.php?form=log"><?php echo cam_t('REITER.LOG'); ?></a>
</div>

<!-- ================= <?php echo cam_t('TEXT.EINSTELLUNG'); ?>en ================= -->
<div class="sm-pane<?= $ac_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">

<?php
/* Welche Kameras zeigt das Formular?
   Alle eingerichteten, dazu EIN leerer Platz - ohne den liesse sich nie eine
   zweite Kamera anlegen. Mehr als CAM_MAX gibt es nicht. */
$ac_zeigen = cam_kameras();
if (count($ac_zeigen) < CAM_MAX) {
    $ac_zeigen[] = max($ac_zeigen) + 1;
}
foreach ($ac_zeigen as $ac_i):
    $ac_s = $ac_i > 1 ? (string) $ac_i : '';
    $ac_k = cam_kcfg($ac_i);
    $ac_neu = trim((string) $ac_k['host']) === '' && $ac_i > 1;
?>
<h2><?= $ac_neu ? ac_e(sprintf(cam_t('TEXT.KAMERA_HINZU'), $ac_i))
       : ac_e(cam_t('TEXT.KAMERA') . ' ' . ($ac_i > 1 || count($ac_zeigen) > 1
              ? cam_kname($ac_i) : '')) ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo cam_t('TEXT.L_KAMERANAME'); ?></label>
        <input data-role="none" type="text" name="name<?= $ac_s ?>" value="<?= ac_e($ac_k['name']) ?>" placeholder="<?php echo cam_t('TEXT.P_KAMERANAME'); ?>">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.ADRESSE_IP_ODER_HOSTNAME'); ?></label>
        <input data-role="none" type="text" name="host<?= $ac_s ?>" value="<?= ac_e($ac_k['host']) ?>" placeholder="192.168.1.17">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.BENUTZER'); ?></label>
        <input data-role="none" type="text" name="user<?= $ac_s ?>" value="<?= ac_e($ac_k['user']) ?>" placeholder="admin">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.PASSWORT'); ?></label>
        <input data-role="none" type="password" name="pass<?= $ac_s ?>" value="" placeholder="<?= $ac_k['pass'] !== '' ? 'gespeichert &mdash; leer lassen = unver&auml;ndert' : 'Passwort der Kamera' ?>">
    </div>
</div>
<div class="sm-row" style="margin-top:8px;">
    <div style="max-width:340px;">
        <label><?php echo cam_t('TEXT.ANMELDEVERFAHREN'); ?></label>
        <select data-role="none" name="auth<?= $ac_s ?>">
            <option value="auto"<?= $ac_k['auth'] === 'auto' ? ' selected' : '' ?>><?php echo cam_t('TEXT.AUTOMATISCH_AUSPROBIEREN_EMPFOHLEN'); ?></option>
            <option value="url"<?= $ac_k['auth'] === 'url' ? ' selected' : '' ?>><?php echo cam_t('TEXT.NUR_USER_PWD_IN_DER_URL_ACTI_STAND'); ?></option>
            <option value="basic"<?= $ac_k['auth'] === 'basic' ? ' selected' : '' ?>><?php echo cam_t('TEXT.HTTP_BASIC'); ?></option>
            <option value="digest"<?= $ac_k['auth'] === 'digest' ? ' selected' : '' ?>><?php echo cam_t('TEXT.HTTP_DIGEST'); ?></option>
        </select>
    </div>
</div>
<?php if ($ac_i === 1) { ?>
<div class="sm-small"><b><?php echo cam_t('TEXT.BEI_ERROR_NOT_AUTHORIZED'); ?></b> <?php echo cam_t('TEXT.HIER_DIE_VERFAHREN_DURCHPROBIEREN_'); ?> <i><?php echo cam_t('TEXT.NICHT'); ?></i> <?php echo cam_t('TEXT.ZUSTZLICH_IN_DIE_URL_GESCHRIEBEN_M'); ?></div>
<div class="sm-small"><?php echo cam_t('TEXT.DAS_PASSWORT_WIRD_NIE_ANGEZEIGT_UN'); ?><span class="sm-mono"><?php echo cam_t('TEXT.CHMOD_600'); ?></span><?php echo cam_t('TEXT.FELD_LEER_LASSEN_BEDEUTET_BISHERIG'); ?></div>
<?php } ?>

<div class="sm-row" style="margin-top:10px;">
    <div style="flex:1 1 100%;">
        <label><?php echo cam_t('TEXT.VOLLSTNDIGE_SCHNAPPSCHUSS_URL_EMPF'); ?></label>
        <input data-role="none" type="text" name="snapurl<?= $ac_s ?>" value="<?= ac_e($ac_k['snapurl']) ?>" placeholder="<?php echo cam_t('TEXT.HTTP'); ?>KAMERA/cgi-bin/encoder?<?php echo cam_t('TEXT.USER_PWD'); ?>SNAPSHOT=N1920x1080,100<?php echo cam_t('TEXT.DUMMY_N'); ?>">
<?php if ($ac_i === 1) { ?>
        <div class="sm-small"><?php echo cam_t('TEXT.IST_DIESES_FELD_GEFLLT_NUTZT_DAS_P'); ?> <b><?php echo cam_t('TEXT.GENAU_DIESE_ADRESSE'); ?></b> <?php echo cam_t('TEXT.OHNE_EIGENES_ZUSAMMENBAUEN_OHNE_UM'); ?> <span class="sm-mono">ERROR: not authorized</span>.<br>
        <b><?php echo cam_t('TEXT.HINWEIS'); ?></b> <?php echo cam_t('TEXT.DIESE_URL_ENTHLT_DAS_KAMERA_PASSWO'); ?><span class="sm-mono">chmod 600</span><?php echo cam_t('TEXT.UND_WIRD_IN_PROTOKOLL_UND_DIAGNOSE'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.CAM_PHP'); ?></span> <?php echo cam_t('TEXT.OHNE_ZUGANGSDATEN'); ?></div>
<?php } ?>
    </div>
    <div style="flex:1 1 100%;">
        <label><?php echo cam_t('TEXT.SCHNAPPSCHUSS_BEFEHL_NUR_DER_TEIL_'); ?> <span class="sm-mono">USER=…&amp;PWD=…&amp;</span> <?php echo cam_t('TEXT.WIRD_IGNORIERT_WENN_OBEN_EINE_URL_'); ?></label>
        <input data-role="none" type="text" name="snapcmd<?= $ac_s ?>" value="<?= ac_e($ac_k['snapcmd']) ?>" placeholder="SNAPSHOT=N1920x1080,100&amp;DUMMY=n">
<?php if ($ac_i === 1) { ?>
        <div class="sm-small"><?php echo cam_t('TEXT.DAS_IST_DER_TEIL_HINTER'); ?> <span class="sm-mono">USER=…&amp;PWD=…&amp;</span><?php echo cam_t('TEXT.DER_VORGABEWERT_STAMMT_AUS_EINER_R'); ?><span class="sm-mono">,100</span><?php echo cam_t('TEXT.UND_DEM_ABSCHLIESSENDEN'); ?> <span class="sm-mono">&amp;DUMMY=n</span><?php echo cam_t('TEXT.DAS_MANCHE_FIRMWARE_ERWARTET_WER_E'); ?></div>
<?php } ?>
    </div>
    <div>
        <label><?php echo cam_t('TEXT.AUFLSUNG_NUR_ALS_ERSATZ'); ?></label>
        <input data-role="none" type="text" name="resolution<?= $ac_s ?>" value="<?= ac_e($ac_k['resolution']) ?>" placeholder="z. B. N1280x720">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.ZEITLIMIT_JE_BILD_SEKUNDEN'); ?></label>
        <input data-role="none" type="number" name="timeout<?= $ac_s ?>" value="<?= (int) $ac_k['timeout'] ?>" min="2" max="30">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.KANAL'); ?></label>
        <input data-role="none" type="number" name="channel<?= $ac_s ?>" value="<?= (int) $ac_k['channel'] ?>" min="1" max="16">
    </div>
</div>
<div class="sm-row" style="margin-top:8px;">
    <div style="flex:1 1 100%;">
        <label><?php echo cam_t('TEXT.ADRESSE_DES_KAMERASTROMS_LEER'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.CGI_BIN_CMD_SYSTEM_GET_STREAM'); ?></span>)</label>
        <input type="text" data-role="none" name="mjpeg_url<?= $ac_s ?>" value="<?= ac_e((string) $ac_k['mjpeg_url']) ?>">
    </div>
    <div style="flex:1 1 100%;">
        <label><?php echo cam_t('TEXT.RTSP_ADRESSE_LEER_BEI_DER_KAMERA_E'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.GET_STREAM'); ?></span>)</label>
        <input type="text" data-role="none" name="rtsp_url<?= $ac_s ?>" placeholder="rtsp://&lt;Kamera&gt;:7070//stream2" value="<?= ac_e((string) $ac_k['rtsp_url']) ?>">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.RTSP_PORT'); ?></label>
        <input type="number" min="1" max="65535" data-role="none" name="rtsp_port<?= $ac_s ?>" value="<?= ac_e((string) $ac_k['rtsp_port']) ?>">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.WELCHER_STROM'); ?></label>
        <select data-role="none" name="rtsp_stream<?= $ac_s ?>">
            <option value="2"<?= ((int) $ac_k['rtsp_stream']) !== 1 ? ' selected' : '' ?>><?php echo cam_t('TEXT.STREAM2_NEBENSTROM_KLEINER_UND_SPA'); ?></option>
            <option value="1"<?= ((int) $ac_k['rtsp_stream']) === 1 ? ' selected' : '' ?>><?php echo cam_t('TEXT.STREAM1_HAUPTSTROM_VOLLE_AUFLSUNG'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo cam_t('TEXT.BILDGTE_BEI_RTSP_2_FEIN_UND_GRO_15'); ?></label>
        <input type="number" min="2" max="15" data-role="none" name="rtsp_quality<?= $ac_s ?>" value="<?= ac_e((string) $ac_k['rtsp_quality']) ?>">
    </div>
</div>
<?php if ($ac_i === 1) { ?>
<div class="sm-small"><?php echo cam_t('TEXT.AUFLSUNG_LEER_LASSEN_HEISST_DIE_KA'); ?> <span class="sm-mono">N1280x720</span> <?php echo cam_t('TEXT.N_NORMAL'); ?></div>
<?php } ?>
<?php if ($ac_neu) { ?>
<div class="sm-small"><?php echo cam_t('TEXT.KAMERA_HINZU_HINWEIS'); ?></div>
<?php } ?>
<?php endforeach; ?>

<h2><?php echo cam_t('TEXT.AUFNAHMEN'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo cam_t('TEXT.L_PRUEF_MINUTEN'); ?></label>
        <input data-role="none" type="number" name="pruef_minuten" value="<?= (int) $ac_cfg['pruef_minuten'] ?>" min="0" max="1440">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.L_MINDESTPAUSE'); ?></label>
        <input data-role="none" type="number" name="mindestpause" value="<?= (int) $ac_cfg['mindestpause'] ?>" min="0" max="3600">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.AUFBEWAHRUNG_TAGE'); ?></label>
        <input data-role="none" type="number" name="keep_days" value="<?= (int) $ac_cfg['keep_days'] ?>" min="0" max="3650">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.CLIPLAENGE_SEKUNDEN'); ?></label>
        <input data-role="none" type="number" name="clip_seconds" value="<?= (int) $ac_cfg['clip_seconds'] ?>" min="2" max="60">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.BILDER_JE_SEKUNDE_IM_CLIP'); ?></label>
        <input data-role="none" type="number" name="clip_fps" value="<?= (int) $ac_cfg['clip_fps'] ?>" min="1" max="5">
    </div>
</div>
<div class="sm-small"><?php echo cam_t('TEXT.EIN_CLIP_IST_EINE_BILDSERIE_DAMIT_'); ?></div>
<div class="sm-small"><?php echo cam_t('TEXT.H_PRUEF_MINUTEN'); ?></div>
<div class="sm-small"><?php echo cam_t('TEXT.H_MINDESTPAUSE'); ?></div>

<div class="sm-row" style="margin-top:10px;">
    <div>
        <label><?php echo cam_t('TEXT.HCHSTZAHL_DATEIEN_JE_ARCHIV'); ?></label>
        <input data-role="none" type="number" name="keep_max" value="<?= (int) $ac_cfg['keep_max'] ?>" min="0" max="100000">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.L_KEEP_MB'); ?></label>
        <input data-role="none" type="number" name="keep_mb" value="<?= (int) $ac_cfg['keep_mb'] ?>" min="0" max="1000000">
    </div>
</div>
<div class="sm-small"><?php echo cam_t('TEXT.DIE_BEREINIGUNG_LUFT_TGLICH_UM'); ?> <b><?php echo cam_t('TEXT.03_35_UHR'); ?></b> <?php echo cam_t('TEXT.UND_GREIFT_AUF_BILDER_BILDSERIEN_U'); ?> <b><?php echo cam_t('TEXT.0_ODER_LEER_UNBEGRENZT'); ?></b> <?php echo cam_t('TEXT.BEI_ALTER'); ?> <i>und</i> <?php echo cam_t('TEXT.ANZAHL_DIE_JEWEILS_NEUESTEN_DATEIE'); ?></div>

<h2><?php echo cam_t('TEXT.ZEITRAFFER'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="timelapse" <?= !empty($ac_cfg['timelapse']) ? 'checked' : '' ?>> <?php echo cam_t('TEXT.TGLICH_EIN_ZEITRAFFERBILD_AUFNEHME'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div style="max-width:220px;">
        <label><?php echo cam_t('TEXT.UHRZEIT_HH_MM'); ?></label>
        <input data-role="none" type="text" name="timelapse_time" value="<?= ac_e($ac_cfg['timelapse_time']) ?>" placeholder="12:00">
    </div>
</div>
<div class="sm-small"><?php echo cam_t('TEXT.DIE_BILDER_LANDEN_IM_UNTERORDNER'); ?> <span class="sm-mono">timelapse</span><?php echo cam_t('TEXT.DER_DATEINAME_IST_DAS_DATUM_IST'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.FFMPEG'); ?></span> <?php echo cam_t('TEXT.AUF_DEM_LOXBERRY_VORHANDEN_WIRD_NA'); ?>
<span class="sm-mono"><?php echo cam_t('TEXT.ZEITRAFFER_MP4'); ?></span> <?php echo cam_t('TEXT.AUS_ALLEN_BILDERN_NEU_ERZEUGT_ABRU'); ?>
<span class="sm-mono">http://<?= ac_e($ac_host) ?><?php echo cam_t('TEXT.PLUGINS'); ?><?= ac_e($ac_plugin) ?><?php echo cam_t('TEXT.ZEITRAFFER_MP4_2'); ?></span><?php echo cam_t('TEXT.FEHLT_FFMPEG_BLEIBEN_DIE_EINZELBIL'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.SUDO_APT_GET_INSTALL_Y_FFMPEG'); ?></span>).</div>

<h2><?php echo cam_t('TEXT.KI_OBJEKTERKENNUNG_OPTIONAL'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo cam_t('TEXT.ERKENNUNGS_ENDPUNKT'); ?></label>
        <input data-role="none" type="text" name="ai_url" value="<?= ac_e($ac_cfg['ai_url']) ?>" placeholder="http://192.0.2.10:32168/v1/vision/detection">
    </div>
    <div style="max-width:220px;">
        <label><?php echo cam_t('TEXT.MINDEST_KONFIDENZ'); ?></label>
        <input data-role="none" type="number" name="ai_min" value="<?= (int) $ac_cfg['ai_min'] ?>" min="1" max="99">
    </div>
</div>
<div class="sm-small"><?php echo cam_t('TEXT.BENTIGT_EINEN_ERKENNUNGSDIENST_AUF'); ?>
<b><?php echo cam_t('TEXT.CODEPROJECT_AI_SERVER'); ?></b> <?php echo cam_t('TEXT.PORT_32168_ODER'); ?> <b><?php echo cam_t('TEXT.DEEPSTACK'); ?></b><?php echo cam_t('TEXT.DER_LOXBERRY_SELBST_IST_DAFR_ZU_SC'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.PERSON'); ?></span>, <span class="sm-mono">car</span><?php echo cam_t('TEXT.ERSCHEINEN_IM_JSON_IN_DEN_WEBHOOKS'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.ERKANNT'); ?></span> <?php echo cam_t('TEXT.IN_DER_LOXONE_AUSGABE_ZUSTZLICH_GI'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.PERSON_1'); ?></span> <?php echo cam_t('TEXT.ALS_FERTIGEN_SCHALTER_LEER_LASSEN_'); ?></div>

<h2><?php echo cam_t('TEXT.WEBHOOKS_OPTIONAL'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo cam_t('TEXT.WEBHOOK_1_POST_MIT_JSON'); ?></label>
        <input data-role="none" type="text" name="webhook1" value="<?= ac_e($ac_cfg['webhook1']) ?>" placeholder="https://…">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.WEBHOOK_2_GET_MIT_PARAMETERN'); ?></label>
        <input data-role="none" type="text" name="webhook2" value="<?= ac_e($ac_cfg['webhook2']) ?>" placeholder="https://…">
    </div>
</div>
<div class="sm-small"><?php echo cam_t('TEXT.BEIDE_WERDEN_NACH_JEDER_AUFNAHME_A'); ?>
<span class="sm-mono"><?php echo cam_t('TEXT.BILD_DATEI_ANLASS_OBJEKTE_ZEIT'); ?></span> <?php echo cam_t('TEXT.ALS_JSON_WEBHOOK2_HNGT'); ?>
<span class="sm-mono"><?php echo cam_t('TEXT.BILD_ANLASS_OBJEKTE'); ?></span> <?php echo cam_t('TEXT.AN_DIE_ADRESSE_AN'); ?></div>

<h2><?php echo cam_t('TEXT.BENACHRICHTIGUNG'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="notify_push" <?= !empty($ac_notify['push']) ? 'checked' : '' ?>> <?php echo cam_t('TEXT.PUSH_FREIGABE_AN_LOXONE_MELDEN'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div style="max-width:260px;">
        <label><?php echo cam_t('TEXT.MELDEFENSTER_NACH_EINER_AUFNAHME_M'); ?></label>
        <input data-role="none" type="number" name="push_minutes" value="<?= (int) $ac_notify['push_minutes'] ?>" min="1" max="30">
    </div>
</div>
<div class="sm-small"><?php echo cam_t('TEXT.NACH_JEDER_AUFNAHME_STEHT'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.PUSHAKTIV_1'); ?></span> <?php echo cam_t('TEXT.FR_DIESE_ZEITSPANNE_DEN_PUSH_SELBS'); ?></div>

<?php /* Hier standen dieselben MQTT-Felder noch einmal - Feldnamen
         mqtt_enabled und mqtt_topic gab es damit ZWEIMAL auf der Seite.
         Sie stehen im Reiter MQTT, dort gehoeren sie hin.

         Der Haken trug hier ausserdem eine verungluckte Uebersetzung: der
         Sprachschluessel TEXT.UUML_BER_DAS_LOXBERRY_MQTT_GATEWAY beginnt mit
         "> " - die schliessende Klammer des <input>-Tags war beim
         automatischen Uebersetzen in die Sprachdatei gewandert. Das ging
         zufaellig gut, waere aber beim ersten Nachbessern des Textes
         auseinandergefallen. */ ?>

<h3 class="sm-h3"><?php echo cam_t('TEXT.LIVE_BILD_FR_LOXONE_MJPEG_WEITERLE'); ?></h3>
<p class="sm-hint"><?php echo cam_t('TEXT.LOXBERRY_HOLT_DAS_BILD_BEI_DER_KAM'); ?><br>
<span class="sm-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?><?php echo cam_t('TEXT.CAM_STREAM_PHP'); ?></span> <?php echo cam_t('TEXT.LIVEBILD_INTVIDEOURL'); ?><br>
<span class="sm-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?><?php echo cam_t('TEXT.CAM_STREAM_PHP_EINZELN_1'); ?></span> <?php echo cam_t('TEXT.EINZELBILD_INTALERTIMAGE'); ?></p>
<label><?php echo cam_t('TEXT.BILDER_JE_SEKUNDE'); ?></label>
<input type="number" step="0.1" min="0.2" max="10" data-role="none" name="stream_fps" value="<?= ac_e((string) $ac_cfg['stream_fps']) ?>">
<label><?php echo cam_t('TEXT.HCHSTDAUER_EINES_STROMS_IN_SEKUNDE'); ?></label>
<input type="number" min="5" max="900" data-role="none" name="stream_maxsec" value="<?= ac_e((string) $ac_cfg['stream_maxsec']) ?>">
<label><?php echo cam_t('TEXT.BILDQUELLE'); ?></label>
<select data-role="none" name="stream_mode">
<option value="auto"<?= $ac_cfg['stream_mode'] === 'auto' ? ' selected' : '' ?>><?php echo cam_t('TEXT.AUTOMATISCH_KAMERASTROM_SONST_RTSP'); ?></option>
<option value="mjpeg"<?= $ac_cfg['stream_mode'] === 'mjpeg' ? ' selected' : '' ?>><?php echo cam_t('TEXT.NUR_KAMERASTROM_GET_STREAM_EMPFOHL'); ?></option>
<option value="rtsp"<?= $ac_cfg['stream_mode'] === 'rtsp' ? ' selected' : '' ?>><?php echo cam_t('TEXT.NUR_RTSP_FLSSIGES_VIDEO_BRAUCHT_FF'); ?></option>
<option value="jpeg"<?= $ac_cfg['stream_mode'] === 'jpeg' ? ' selected' : '' ?>><?php echo cam_t('TEXT.NUR_SCHNAPPSCHSSE'); ?></option>
</select>
<p class="sm-hint"><?php echo cam_t('TEXT.FFMPEG_AUF_DIESEM_LOXBERRY'); ?>
<?php $ac_ff = cam_ffmpeg(); ?>
<?= $ac_ff !== '' ? '<b style="color:#2e7d32;">vorhanden</b> (' . ac_e($ac_ff) . ')' : '<b style="color:#c62828;">nicht vorhanden</b> &ndash; es werden Schnappsch&uuml;sse verwendet' ?></p>
<p class="sm-hint"><?php echo cam_t('TEXT.BLEIBT_DAS_FELD_LEER_WIRD'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.RTSP_KAMERA_PORT_STREAMNR'); ?></span> <?php echo cam_t('TEXT.GEBILDET_BENUTZER_UND_PASSWORT_SET'); ?></p>
<?php $ac_rr = cam_rtsp_url(true); if ($ac_rr === '') { $ac_rr = ''; } ?>
<label><?php echo cam_t('TEXT.TOKEN_OPTIONAL_DANN_NUR_MIT'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.T_TOKEN'); ?></span> <?php echo cam_t('TEXT.ABRUFBAR'); ?></label>
<input type="text" data-role="none" name="stream_token" value="<?= ac_e((string) $ac_cfg['stream_token']) ?>">
<div style="margin-top:16px;"><button data-role="none" class="sm-btn" type="submit"><?php echo cam_t('TEXT.SPEICHERN'); ?></button></div>
</form>
</div>

<!-- ================= MQTT ================= -->
<div class="sm-pane<?= $ac_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2><?php echo cam_t('MQTT.H_TITEL'); ?></h2>
<?php $ac_mz = cam_mqtt_zustand_pruefen(); ?>
<?php if (!$ac_mz['gefunden']) { ?>
<div class="sm-alert sm-err"><?php echo cam_t('MQTT.KEIN_ABSCHNITT'); ?></div>
<?php } elseif (!$ac_mz['autostart']) { ?>
<div class="sm-alert sm-err"><?php echo cam_t('MQTT.KEIN_AUTOSTART'); ?></div>
<?php } else { ?>
<div class="sm-alert sm-ok"><?= sprintf(cam_t('MQTT.OK'), (int) $ac_mz['udpport']) ?></div>
<?php } ?>

<div class="sm-step"><?php echo cam_t('MQTT.WARUM'); ?></div>

<h3 class="sm-h3"><?php echo cam_t('MQTT.H_ABO'); ?></h3>
<div class="sm-small"><?php echo cam_t('MQTT.ABO_TEXT'); ?></div>
<div class="sm-mono" style="display:block;padding:8px;margin:6px 0;"><?= ac_e($ac_cfg['mqtt_topic']) ?>/#</div>
<div class="sm-alert sm-err"><?php echo cam_t('MQTT.ABO_WARNUNG'); ?></div>

<h3 class="sm-h3"><?php echo cam_t('MQTT.H_THEMEN'); ?></h3>
<table class="sm-tbl">
<tr><th><?php echo cam_t('MQTT.T_THEMA'); ?></th><th><?php echo cam_t('MQTT.T_BEDEUTUNG'); ?></th><th><?php echo cam_t('MQTT.T_WERT'); ?></th></tr>
<?php $ac_w = cam_werte(); ?>
<?php foreach (cam_felder() as $ac_n => $ac_d) { ?>
<tr><td><span class="sm-mono"><?= ac_e($ac_cfg['mqtt_topic']) ?>/<?= ac_e($ac_n) ?></span></td>
    <td><?php echo cam_t($ac_d[3]); ?></td>
    <td><?= isset($ac_w[$ac_n]) ? ac_e($ac_w[$ac_n]) : '&mdash;' ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo cam_t('MQTT.BILD_THEMEN'); ?></div>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-row"><label><input data-role="none" type="checkbox" name="mqtt_enabled" value="1"<?= !empty($ac_cfg['mqtt_enabled']) ? ' checked' : '' ?>> <?php echo cam_t('MQTT.L_EIN'); ?></label></div>
<div class="sm-row"><label><?php echo cam_t('MQTT.L_TOPIC'); ?></label>
<input data-role="none" type="text" name="mqtt_topic" value="<?= ac_e($ac_cfg['mqtt_topic']) ?>" size="24"></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo cam_t('LEGENDE.AKTION_AUFNAHME'); ?></span></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo cam_t('TEXT.SPEICHERN'); ?></button>
</div>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-pane<?= $ac_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?php echo cam_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<p><?php echo cam_t('TEXT.ZIEL_BEIM_KLINGELN_LOXONE_INTERCOM'); ?> <i>oder</i> <?php echo cam_t('TEXT.TASTER_AN_DER_HAUSTR_MACHT_DIE_KAM'); ?>
<b><?php echo cam_t('TEXT.OHNE_KAMERA_PASSWORT_IN_DER_PROJEK'); ?></b>.</p>

<div class="sm-step"><b><?php echo cam_t('TEXT.SCHRITT_1_VIRTUELLER_AUSGANG_LOXBE'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo cam_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo cam_t('TEXT.WERT'); ?></th></tr>
<tr><td><?php echo cam_t('TEXT.ADRESSE'); ?></td><td><span class="sm-mono">http://<?= ac_e($ac_host) ?></span></td></tr>
</table>
</div>

<div class="sm-step"><b><?php echo cam_t('TOKEN.H'); ?></b><br>
<?php echo cam_t('TOKEN.TEXT'); ?>
<div class="sm-mono" style="display:block;padding:8px;margin:6px 0;word-break:break-all;"><?= ac_e($ac_token) ?></div>
<div class="sm-small"><?php echo cam_t('TOKEN.OFFEN'); ?></div>
<div class="sm-small" style="margin-top:6px;"><b><?php echo cam_t('TOKEN.PRUEFEN'); ?></b><br>
<span class="sm-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?>/cam.php?selftest=1&amp;token=<?= ac_e($ac_token) ?></span><br>
<?php echo cam_t('TOKEN.PRUEFEN_ANTWORT'); ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo cam_t('LEGENDE.TECHNIK'); ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="neuestoken" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo cam_t('TOKEN.K_NEU'); ?></button>
</form>
</div>
</div>

<div class="sm-step"><b><?php echo cam_t('TEXT.SCHRITT_2_VIRTUELLE_AUSGANGS_BEFEH'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo cam_t('TEXT.BEFEHL_BEI_EIN'); ?></th><th><?php echo cam_t('TEXT.WIRKUNG'); ?></th></tr>
<?php /* Aus DERSELBEN Quelle wie die Importdatei: cam_ausgangsbefehle().
         Zwei Stellen, die dieselben Adressen aufzaehlen, laufen auseinander -
         und bei einer Adresse mit Token faellt das niemandem auf, weil ein
         virtueller Ausgang die Antwort nicht auswertet. */
foreach (cam_ausgangsbefehle() as $ac_bf) { ?>
<tr><td><span class="sm-mono"><?= ac_e($ac_bf['on']) ?></span></td>
    <td><?= ac_e($ac_bf['comment']) ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><b><?php echo cam_t('TEXT.AUS_DER_PRAXIS'); ?></b> <?php echo cam_t('TEXT.ZWISCHEN_KLINGELSIGNAL_UND_BILD_GE'); ?></div>
<?php if (count(cam_kameras()) > 1) { ?>
<div class="sm-small"><?php echo cam_t('TEXT.MEHRERE_BEFEHLE'); ?></div>
<?php } ?>
</div>

<div class="sm-step"><b><?php echo cam_t('TEXT.SCHRITT_3_VIRTUELLER_HTTP_EINGANG_'); ?></b> <?php echo cam_t('TEXT.ABFRAGE_60_S'); ?>
<table class="sm-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?><?php echo cam_t('TEXT.CAM_PHP_2'); ?></span></td></tr>
<tr><td><?php echo cam_t('TEXT.ABFRAGEZYKLUS'); ?></td><td><?php echo cam_t('TEXT.60_SEKUNDEN'); ?></td></tr>
</table>
<table class="sm-tbl">
<tr><th><?php echo cam_t('TEXT.BEFEHLSERKENNUNG'); ?></th><th><?php echo cam_t('TEXT.BEDEUTUNG'); ?></th></tr>
<?php /* Auch diese Tabelle entsteht aus der Feldtabelle - mit mehreren
         Kameras waeren sieben ausgeschriebene Zeilen ohnehin unvollstaendig,
         und die Befehlserkennung muss WORTGLEICH mit der Importdatei sein. */
$ac_mehr = count(cam_kameras()) > 1;
foreach (cam_felder() as $ac_fn => $ac_fd) {
    $ac_fk = isset($ac_fd[6]) ? (int) $ac_fd[6] : 1; ?>
<tr><td><span class="sm-mono"><?= ac_e('\i;' . $ac_fn . '=\i\v') ?></span></td>
    <td><?php echo cam_t($ac_fd[3]); ?><?= $ac_mehr ? ' <i>[' . ac_e(cam_kname($ac_fk)) . ']</i>' : '' ?></td></tr>
<?php } ?>
</table>
</div>

<div class="sm-step"><b><?php echo cam_t('TEXT.SCHRITT_4_KOMPLETTE_BAUSTEIN_LISTE'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo cam_t('TEXT.BAUSTEIN'); ?></th><th><?php echo cam_t('TEXT.NAME'); ?></th><th>Einstellung</th><th><?php echo cam_t('TEXT.EINGNGE'); ?></th></tr>
<tr><td><?php echo cam_t('TEXT.EINSCHALTVERZGERUNG_E1'); ?></td><td><?php echo cam_t('TEXT.KLINGEL_ENTPRELLT'); ?></td><td>3 s</td><td><?php echo cam_t('TEXT.KLINGELTASTER_INTERCOM'); ?></td></tr>
<tr><td><?php echo cam_t('TEXT.VIRTUELLER_AUSGANG'); ?></td><td><?php echo cam_t('TEXT.BILD_HOLEN'); ?></td><td><?php echo cam_t('TEXT.BEFEHL_AUS_SCHRITT_2'); ?><span class="sm-mono"><?php echo cam_t('TEXT.ANLASS_KLINGEL'); ?></span>)</td><td><?php echo cam_t('TEXT.E1'); ?></td></tr>
<tr><td><?php echo cam_t('TEXT.SCHWELLWERTSCHALTER_S1'); ?></td><td><?php echo cam_t('TEXT.AUFNAHME_ERFOLGT'); ?></td><td><?php echo cam_t('TEXT.EIN_0_5_AUS_0_4'); ?></td><td><?php echo cam_t('TEXT.PUSHAKTIV'); ?></td></tr>
<tr><td><?php echo cam_t('TEXT.SCHWELLWERTSCHALTER_S2'); ?></td><td><?php echo cam_t('TEXT.PUSH_FREIGEGEBEN'); ?></td><td>Ein 0,5 / Aus 0,4</td><td><?php echo cam_t('TEXT.PUSH'); ?></td></tr>
<tr><td><?php echo cam_t('TEXT.UND_U1'); ?></td><td><?php echo cam_t('TEXT.BESUCH_MELDEN'); ?></td><td></td><td><?php echo cam_t('TEXT.S1_S2'); ?></td></tr>
<tr><td><?php echo cam_t('TEXT.ODER_O1'); ?></td><td><?php echo cam_t('TEXT.PUSH_SAMMLER'); ?></td><td><?php echo cam_t('TEXT.EINZIGE_QUELLE_DES_BENACHRICHTIGUN'); ?></td><td>U1</td></tr>
<tr><td><?php echo cam_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN'); ?></td><td><?php echo cam_t('TEXT.PUSH_BESUCH_AN_DER_TR'); ?></td><td><?php echo cam_t('TEXT.TEXT_Z_B_JEMAND_HAT_GEKLINGELT_BIL'); ?></td><td><?php echo cam_t('TEXT.O1'); ?></td></tr>
<tr><td><?php echo cam_t('TEXT.BENACHRICHTIGUNGS_BAUSTEIN_2'); ?></td><td><?php echo cam_t('TEXT.TEST_PUSH'); ?></td><td><?php echo cam_t('TEXT.EIGENER_BAUSTEIN_NUR_FR_DEN_TEST'); ?></td><td><?php echo cam_t('TEXT.SCHWELLWERTSCHALTER_AN_PTEST'); ?></td></tr>
<tr><td><?php echo cam_t('TEXT.STATUSBAUSTEIN'); ?></td><td><?php echo cam_t('TEXT.KAMERA_KACHEL'); ?></td><td><?php echo cam_t('TEXT.TEXT_LETZTES_BILD_VOR_V1_0_MINUTEN'); ?></td><td><?php echo cam_t('TEXT.I1_ALTER'); ?></td></tr>
</table>
<div class="sm-small"><b><?php echo cam_t('TEXT.PRAXIS_ERFAHRUNG_ZUM_BENACHRICHTIG'); ?></b> <?php echo cam_t('TEXT.ER_SENDET_NUR_BEI_EINER_01_FLANKE_'); ?></div>
</div>

<div class="sm-step"><b><?php echo cam_t('TEXT.SCHRITT_5_BILD_IN_DER_APP_ANZEIGEN'); ?></b><br>
<?php echo cam_t('TEXT.DAS_JEWEILS_LETZTE_BILD_LIEGT_UNTE'); ?>
<span class="sm-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?><?php echo cam_t('TEXT.LETZTESBILD_JPG'); ?></span>
<?php echo cam_t('TEXT.ALTERNATIV'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.CAM_PHP_LETZTES_1'); ?></span><?php echo cam_t('TEXT.DIESE_ADRESSE_ENTHLT_KEINE_ZUGANGS'); ?>
<div class="sm-small" style="margin-top:4px;"><b><?php echo cam_t('TEXT.DAMIT_VERSCHWINDET_DAS_KAMERA_PASS'); ?></b>
<?php echo cam_t('TEXT.BISHER_STAND_DORT_TYPISCHERWEISE'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.HTTP_KAMERA_CGI_BIN_ENCODER_USER_A'); ?></span><?php echo cam_t('TEXT.DIESEN_EINTRAG_KANN_MAN_NACH_DEM_U'); ?></div>
</div>

<div class="sm-step"><b><?php echo cam_t('TEXT.SCHRITT_6_MQTT_ALTERNATIVE_JSON'); ?></b><br>
<?php echo cam_t('TEXT.ALLE_WERTE_GIBT_ES_AUCH_BER_DAS_LO'); ?>
<span class="sm-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?><?php echo cam_t('TEXT.CAM_PHP_JSON_1'); ?></span>
</div>

<div class="sm-step"><b><?php echo cam_t('AUSL.H'); ?></b><br>
<?php echo cam_t('AUSL.TEXT'); ?>
<?php foreach (cam_kameras() as $ac_ak):
    $ac_adr = cam_ausloeser_adresse($ac_ak, $ac_host);
    $ac_len = strlen($ac_adr);
    /* Die Zeichenzahl steht dabei, weil das Adressfeld im
       Kamera-Konfigurator sie begrenzt - gemessen 64 - und ein
       abgeschnittener Wert dort NICHT auffaellt: die Kamera wertet keine
       Antwort aus. */
?>
<div style="margin-top:8px;">
<?php if (count(cam_kameras()) > 1) { ?><b><?= ac_e(cam_kname($ac_ak)) ?></b><br><?php } ?>
<span class="sm-mono" style="display:block;padding:8px;word-break:break-all;"><?= ac_e($ac_adr) ?></span>
<span class="sm-small"><?= sprintf(ac_e(cam_t('AUSL.LAENGE')), $ac_len) ?>
<?php if ($ac_len > 64) { ?><b style="color:#c62828;"><?php echo cam_t('AUSL.ZU_LANG'); ?></b><?php } ?></span>
</div>
<?php endforeach; ?>
<div class="sm-small" style="margin-top:8px;"><?php echo cam_t('AUSL.ANMELDUNG'); ?></div>
<div class="sm-small" style="margin-top:8px;"><?php echo cam_t('AUSL.HINWEIS'); ?></div>
</div>

<h3 class="sm-h3"><?php echo cam_t('LOX.H_VORLAGE'); ?></h3>
<div class="sm-small"><?php echo cam_t('LOX.VORLAGE_TEXT'); ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo cam_t('LEGENDE.TECHNIK'); ?></span>
</div>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="download" value="xml_in">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo cam_t('LOX.K_VORLAGE'); ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="download" value="xml_out">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo cam_t('LOX.K_VORLAGE_AUS'); ?></button>
</form>
</div>
<div class="sm-small"><?php echo cam_t('LOX.VORLAGE_AUS_TEXT'); ?></div>
</div>

<!-- ================= Aufnahmen ================= -->
<div class="sm-pane<?= $ac_tab === 'tab-shots' ? ' sm-active' : '' ?>" id="tab-shots">
<h2><?php echo cam_t('TEXT.LETZTE_AUFNAHMEN'); ?></h2>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?php echo cam_t('LEGENDE.TECHNIK'); ?></span>
</div>
<?php
/* Ist ein Stromkennwort hinterlegt, tragen auch die Archivadressen es mit -
   sonst wiese der eigene Endpunkt die eigene Galerie ab. */
$ac_bt = trim((string) $ac_cfg['stream_token']) !== ''
    ? '&amp;t=' . rawurlencode((string) $ac_cfg['stream_token']) : '';
$ac_dir = function_exists('cam_datadir') ? cam_datadir() : '';
?>
<div class="sm-small" style="margin-bottom:8px;"><?php echo cam_t('TEXT.TAGE_ABLAGE'); ?>
<span class="sm-mono"><?= ac_e($ac_dir) ?></span> &mdash;
<?php echo cam_t('TEXT.BILDSERIEN_AUFBEWAHRUNG'); ?> <?= (int) $ac_cfg['keep_days'] ?> <?php echo cam_t('TEXT.TAGE'); ?></div>
<?php
/* Je Kamera ein eigener Abschnitt. Die Adressen tragen &kamera=, damit der
   Archivendpunkt im richtigen Ordner sucht - bilder, bilder2, ... */
foreach (cam_kameras() as $ac_kamgal):
    $ac_kg = $ac_kamgal > 1 ? '&amp;kamera=' . $ac_kamgal : '';
    $ac_bilder = $ac_dir !== '' ? (glob(cam_ordner($ac_kamgal, 'bilder') . '/*.jpg') ?: array()) : array();
    rsort($ac_bilder);
    $ac_clips = $ac_dir !== '' ? (glob(cam_ordner($ac_kamgal, 'clips') . '/*', GLOB_ONLYDIR) ?: array()) : array();
    rsort($ac_clips);
?>
<h2><?= ac_e(cam_kname($ac_kamgal)) ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo cam_t('TEXT.GESPEICHERT'); ?> <b><?= count($ac_bilder) ?></b> <?php echo cam_t('TEXT.BILDER'); ?>
<b><?= count($ac_clips) ?></b> <?php echo cam_t('TEXT.BILDSERIEN'); ?></div>
<?php if ($ac_bilder) { ?>
<div class="sm-gal">
<?php foreach (array_slice($ac_bilder, 0, 12) as $ac_f) {
    $ac_n = basename($ac_f); ?>
<figure>
    <a href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?bild=<?= rawurlencode($ac_n) ?><?= $ac_kg ?><?= $ac_bt ?>" target="_blank">
    <img src="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?bild=<?= rawurlencode($ac_n) ?><?= $ac_kg ?><?= $ac_bt ?>" alt="" loading="lazy"></a>
    <figcaption><?= ac_e(substr($ac_n, 6, 2) . '.' . substr($ac_n, 4, 2) . '.' . substr($ac_n, 0, 4) . ' ' . substr($ac_n, 9, 2) . ':' . substr($ac_n, 11, 2) . ':' . substr($ac_n, 13, 2)) ?><br><?= ac_e($ac_n) ?></figcaption>
</figure>
<?php } ?>
</div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo cam_t('TEXT.NOCH_KEINE_AUFNAHMEN_VORHANDEN_IM_'); ?> <b><?php echo cam_t('TEXT.TEST'); ?></b> <?php echo cam_t('TEXT.LSST_SICH_SOFORT_EINE_AUSLSEN'); ?></div>
<?php } ?>
<?php if ($ac_clips) { ?>
<table class="sm-tbl"><tr><th><?php echo cam_t('TEXT.SERIE'); ?></th><th><?php echo cam_t('TEXT.BILDER_2'); ?></th><th><?php echo cam_t('TEXT.ZEITPUNKT'); ?></th></tr>
<?php foreach (array_slice($ac_clips, 0, 10) as $ac_c) { ?>
<tr><td><a href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?serie=<?= rawurlencode(basename($ac_c)) ?>&amp;nr=1<?= $ac_kg ?><?= $ac_bt ?>" target="_blank"><?= ac_e(basename($ac_c)) ?></a></td><td><?= count(glob($ac_c . '/*.jpg') ?: array()) ?></td>
<td><?= ac_e(date('d.m.Y H:i:s', filemtime($ac_c))) ?></td></tr>
<?php } ?></table>
<?php } ?>
<?php endforeach; ?>
<div class="sm-small"><?php echo cam_t('TEXT.ANGEZEIGT_WIRD_DAS_JEWEILS_NEUESTE'); ?></div>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="cleanupnow" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-shots">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo cam_t('TEXT.ALTE_AUFNAHMEN_JETZT_AUFRUMEN'); ?></button>
</form>
</div>

<!-- ================= Test ================= -->
<div class="sm-pane<?= $ac_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?php echo cam_t('REITER.TEST'); ?></h2>

<h3 class="sm-h3"><?php echo cam_t('TEST.H_SELBSTTEST'); ?></h3>
<div class="sm-small"><?php echo cam_t('TEST.SELBSTTEST_TEXT'); ?></div>
<?php
$ac_pr = cam_pruefungen();
/* Haken, Kreuze und Hinweise getrennt zaehlen.
   Bis 1.9.7 galt alles als bestanden, was kein Kreuz war - aus 4 Haken, 3
   Kreuzen und 4 Hinweisen wurde "8 von 11". Ein Hinweis ist fuer "geht mich
   nichts an" da, nicht fuer "ich weiss es nicht", und eine Zusammenfassung
   darf nicht besser aussehen als ihr schlechtester Punkt. */
$ac_schlecht = 0;
$ac_gut = 0;
$ac_hinweis = 0;
foreach ($ac_pr as $ac_z) {
    if ($ac_z[0] === 1) { $ac_gut++; } elseif ($ac_z[0] === 0) { $ac_schlecht++; } else { $ac_hinweis++; }
}
?>
<div class="sm-alert <?= $ac_schlecht ? 'sm-err' : 'sm-ok' ?>">
<?= sprintf(cam_t($ac_schlecht ? 'TEST.SELBSTTEST_FEHL' : 'TEST.SELBSTTEST_OK'),
            $ac_gut, count($ac_pr), $ac_schlecht, $ac_hinweis) ?>
</div>
<table class="sm-tbl">
<tr><th style="width:34px;"></th><th style="width:34%;"><?php echo cam_t('TEST.T_FRAGE'); ?></th><th><?php echo cam_t('TEST.T_ANTWORT'); ?></th></tr>
<?php foreach ($ac_pr as $ac_z) { ?>
<tr><td style="text-align:center;"><?= $ac_z[0] === 1 ? '<b style="color:#1a7f1a;">&#10004;</b>'
        : ($ac_z[0] === 0 ? '<b style="color:#b00000;">&#10008;</b>' : '<b>i</b>') ?></td>
    <td><?= $ac_z[1] ?></td><td><?= $ac_z[2] ?></td></tr>
<?php } ?>
</table>

<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo cam_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo cam_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo cam_t('LEGENDE.AKTION_AUFNAHME'); ?></span>
</div>

<h3 class="sm-h3"><?php echo cam_t('TEXT.ANSEHEN'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?test=1&amp;token=<?= ac_e($ac_token) ?>" target="_blank"><?php echo cam_t('TEXT.VERBINDUNG_PRFEN_2'); ?></a>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam_stream.php" target="_blank"><?php echo cam_t('TEXT.LIVEBILD_ANSEHEN'); ?></a>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?letztes=1" target="_blank"><?php echo cam_t('TEXT.LETZTES_BILD_FFNEN'); ?></a>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php" target="_blank"><?php echo cam_t('TEXT.LOXONE_ZEILE_ABRUFEN'); ?></a>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?json=1" target="_blank"><?php echo cam_t('TEXT.JSON_ANSICHT'); ?></a>
</div>

<h3 class="sm-h3"><?php echo cam_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?diag=1&amp;token=<?= ac_e($ac_token) ?>" target="_blank"><?php echo cam_t('TEXT.DIAGNOSE_ALLE_VARIANTEN'); ?></a>
<a class="sm-btn sm-b-technik" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?sys=1&amp;token=<?= ac_e($ac_token) ?>" target="_blank"><?php echo cam_t('TEXT.KAMERA_AUSKUNFT_2'); ?></a>
</div>

<h3 class="sm-h3"><?php echo cam_t('TEXT.LST_ETWAS_AUS'); ?></h3>
<div class="sm-knopfreihe">
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="shotnow" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo cam_t('TEXT.JETZT_EIN_BILD_AUFNEHMEN'); ?></button>
</form>
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="timelapsenow" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo cam_t('TEXT.ZEITRAFFERBILD_AUFNEHMEN'); ?></button>
</form>
<a class="sm-btn sm-b-aktion" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?ptest=1&amp;token=<?= ac_e($ac_token) ?>" target="_blank"><?php echo cam_t('TEXT.TEST_PUSHNACHRICHT_2'); ?></a>
</div>

<div class="sm-small" style="margin-top:14px;">
<?php echo cam_t('TEXT.TEXT'); ?> <b><?php echo cam_t('TEXT.VERBINDUNG_PRFEN'); ?></b> <?php echo cam_t('TEXT.HOLT_EIN_BILD_UND_MELDET_GRE_UND_A'); ?><br>
&bull; <b><?php echo cam_t('TEXT.DIAGNOSE'); ?></b> <?php echo cam_t('TEXT.PROBIERT_ALLE_KOMBINATIONEN_AUS_BE'); ?> <span class="sm-mono">OK</span> <?php echo cam_t('TEXT.WIRD_AUTOMATISCH_GEMERKT'); ?><br>
&bull; <b><?php echo cam_t('TEXT.KAMERA_AUSKUNFT'); ?></b> <?php echo cam_t('TEXT.FRAGT_DIE_SYSTEM_SCHNITTSTELLE_AB_'); ?><br>
&bull; <b><?php echo cam_t('TEXT.TEST_PUSHNACHRICHT'); ?></b> <?php echo cam_t('TEXT.SETZT'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.PTEST_1'); ?></span> <?php echo cam_t('TEXT.FR_5_MINUTEN_DER_PUSH_KOMMT_BER_DE'); ?>
</div>

<h2><?php echo cam_t('TEXT.ZUSTAND'); ?></h2>
<table class="sm-tbl">
<tr><th>Wert</th><th><?php echo cam_t('TEXT.INHALT'); ?></th></tr>
<tr><td><?php echo cam_t('TEXT.KAMERA_KONFIGURIERT'); ?></td><td><?= !empty($ac_st['ok']) ? 'ja' : '<b>nein</b>' ?></td></tr>
<tr><td><?php echo cam_t('TEXT.LETZTES_BILD'); ?></td><td><?= $ac_st['letztes_bild'] !== '' ? ac_e($ac_st['letztes_bild']) . ' (Anlass: ' . ac_e($ac_st['letzter_anlass']) . ')' : '&ndash;' ?></td></tr>
<tr><td><?php echo cam_t('TEXT.ALTER'); ?></td><td><?= (int) $ac_st['alter_min'] >= 0 ? (int) $ac_st['alter_min'] . ' Minuten' : '&ndash;' ?></td></tr>
<tr><td><?php echo cam_t('TEXT.GESPEICHERTE_AUFNAHMEN'); ?></td><td><?= (int) $ac_st['bilder'] ?> Bilder, <?= (int) $ac_st['clips'] ?> <?php echo cam_t('TEXT.SERIEN'); ?> <?= (int) $ac_st['timelapse'] ?> <?php echo cam_t('TEXT.ZEITRAFFERBILDER'); ?></td></tr>
<tr><td><?php echo cam_t('TEXT.ZULETZT_ERKANNT'); ?></td><td><?= !empty($ac_st['objekte']) ? ac_e(implode(', ', $ac_st['objekte'])) : '&ndash; (keine Erkennung eingerichtet oder nichts gefunden)' ?></td></tr>
</table>
</div>

<!-- ================= <?php echo cam_t('TEXT.PROTOKOLL'); ?> ================= -->
<div class="sm-pane<?= $ac_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?php echo cam_t('REITER.LOG'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo cam_t('TEXT.PROTOKOLLIERT_WERDEN_AUFNAHMEN_AUF'); ?><br>
<?php echo cam_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= ac_e($ac_logfile) ?></span></div>
<?php if ($ac_loglines) { ?>
<div class="sm-log"><?= ac_e(implode("\n", $ac_loglines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo cam_t('TEXT.NOCH_KEINE_PROTOKOLL_EINTRGE_VORHA'); ?></div>
<?php } ?>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn" type="submit" style="background:#c62828;"><?php echo cam_t('TEXT.PROTOKOLL_LEEREN'); ?></button>
</form>
<?php if (class_exists('LBWeb', false) && method_exists('LBWeb', 'loglist_html')) { echo LBWeb::loglist_html(); } ?>
</div>

</div>
<script>
(function () {
    var aktiv = <?= json_encode($ac_tab) ?>;
    var tabs = document.querySelectorAll('.sm-tab');
    function zeige(id) {
        tabs.forEach(function (t) { t.classList.toggle('sm-active', t.dataset.pane === id); });
        document.querySelectorAll('.sm-pane').forEach(function (p) { p.classList.toggle('sm-active', p.id === id); });
    }
    tabs.forEach(function (t) { t.addEventListener('click', function () { zeige(t.dataset.pane); }); });
    zeige(aktiv);
})();
</script>
<?php
if (class_exists('LBWeb')) {
    LBWeb::lbfooter();
} else {
    echo '</body></html>';
}
