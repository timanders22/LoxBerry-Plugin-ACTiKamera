<?php
/**
 * ACTi Kamera - Admin-Oberflaeche (v1.1.0)
 * Reiter: Einstellungen | Einbindung in Loxone | Aufnahmen | Test | Protokoll
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg als stdClass) und
 * wuerde gleichnamige Plugin-Variablen ueberschreiben - daher tragen hier
 * ALLE Variablen ein ac_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', '1');

$ac_lbhome = getenv('LBHOMEDIR') ?: (is_dir('/opt/loxberry') ? '/opt/loxberry' : '');
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
$ac_tab = preg_match('/^tab-(settings|loxone|shots|test|log)$/', (string) (isset($_POST['activetab']) ? $_POST['activetab'] : '')) ? $_POST['activetab'] : 'tab-settings';

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
    $ac_new['host'] = trim((string) (isset($_POST['host']) ? $_POST['host'] : ''));
    // Leeres Benutzerfeld loescht NICHT den gespeicherten Wert - genau das ist hier
    // schon einmal passiert und fuehrte zu USER=&PWD=… und damit zu HTTP 401.
    $ac_u = trim((string) (isset($_POST['user']) ? $_POST['user'] : ''));
    if ($ac_u !== '') { $ac_new['user'] = $ac_u; }
    // Passwortfeld leer lassen = bisheriges Passwort behalten
    $ac_pw = (string) (isset($_POST['pass']) ? $_POST['pass'] : '');
    if ($ac_pw !== '') { $ac_new['pass'] = $ac_pw; }
    $ac_new['channel'] = max(1, min(16, (int) (isset($_POST['channel']) ? $_POST['channel'] : 1)));
    $ac_new['resolution'] = preg_replace('/[^A-Za-z0-9x,]/', '', (string) (isset($_POST['resolution']) ? $_POST['resolution'] : ''));
    if (stripos($ac_new['resolution'], 'http') === 0) { $ac_new['resolution'] = ''; }
    // Eingaben nur von Steuerzeichen und Anfuehrungszeichen befreien - NICHT von
    // Doppelpunkt, Schraegstrich oder Punkt. Ein zu strenger Filter hat aus einer
    // eingefuegten URL frueher "http19216817817cgi-binencoder..." gemacht.
    $ac_saeubern = function ($wert) {
        $wert = preg_replace('/[\x00-\x1F\x7F"\'<>\s]/', '', (string) $wert);
        return trim((string) $wert);
    };
    $ac_cmd = $ac_saeubern(isset($_POST['snapcmd']) ? $_POST['snapcmd'] : '');
    $ac_su = $ac_saeubern(isset($_POST['snapurl']) ? $_POST['snapurl'] : '');
    // Wer die komplette Adresse ins Befehlsfeld einfuegt, meint die vollstaendige URL
    if ($ac_su === '' && stripos($ac_cmd, '://') !== false) {
        $ac_su = $ac_cmd;
        $ac_cmd = '';
        $ac_note = 'Die eingefuegte Adresse wurde als vollstaendige Schnappschuss-URL uebernommen.';
    }
    if ($ac_su !== '' && !preg_match('#^https?://#i', $ac_su)) {
        $ac_su = 'http://' . ltrim($ac_su, '/');
    }
    $ac_new['stream_fps'] = max(0.2, min(10, (float) (isset($_POST['stream_fps']) ? $_POST['stream_fps'] : 2)));
    $ac_new['stream_maxsec'] = max(5, min(21600, (int) (isset($_POST['stream_maxsec']) ? $_POST['stream_maxsec'] : 900)));
    $ac_sm = (string) (isset($_POST['stream_mode']) ? $_POST['stream_mode'] : 'auto');
    $ac_new['stream_mode'] = in_array($ac_sm, array('auto', 'mjpeg', 'jpeg', 'rtsp'), true) ? $ac_sm : 'auto';
    $ac_mu = trim((string) (isset($_POST['mjpeg_url']) ? $_POST['mjpeg_url'] : ''));
    $ac_new['mjpeg_url'] = (preg_match('#^https?://#i', $ac_mu) || $ac_mu === '') ? $ac_mu : '';
    $ac_ru = trim((string) (isset($_POST['rtsp_url']) ? $_POST['rtsp_url'] : ''));
    $ac_new['rtsp_url'] = (preg_match('#^rtsp://#i', $ac_ru) || $ac_ru === '') ? $ac_ru : '';
    $ac_new['rtsp_stream'] = ((int) (isset($_POST['rtsp_stream']) ? $_POST['rtsp_stream'] : 2)) === 1 ? 1 : 2;
    $ac_new['rtsp_port'] = max(1, min(65535, (int) (isset($_POST['rtsp_port']) ? $_POST['rtsp_port'] : 7070)));
    $ac_new['rtsp_quality'] = max(2, min(15, (int) (isset($_POST['rtsp_quality']) ? $_POST['rtsp_quality'] : 5)));
    $ac_new['stream_token'] = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) (isset($_POST['stream_token']) ? $_POST['stream_token'] : ''));
    $ac_new['snapcmd'] = $ac_cmd;
    $ac_new['snapurl'] = $ac_su;
    $ac_new['timeout'] = max(2, min(30, (int) (isset($_POST['timeout']) ? $_POST['timeout'] : 8)));
    $ac_new['clip_seconds'] = max(2, min(60, (int) (isset($_POST['clip_seconds']) ? $_POST['clip_seconds'] : 10)));
    $ac_new['clip_fps'] = max(1, min(5, (int) (isset($_POST['clip_fps']) ? $_POST['clip_fps'] : 2)));
    $ac_new['notify'] = array(
        'push' => isset($_POST['notify_push']) ? 1 : 0,
        'push_minutes' => max(1, min(30, (int) (isset($_POST['push_minutes']) ? $_POST['push_minutes'] : 2))),
    );
    $ac_new['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    $ac_new['mqtt_topic'] = preg_replace('#[^\w/\-]#', '', (string) (isset($_POST['mqtt_topic']) ? $_POST['mqtt_topic'] : 'acti')) ?: 'acti';
    $ac_a = (string) (isset($_POST['auth']) ? $_POST['auth'] : 'auto');
    $ac_new['auth'] = in_array($ac_a, array('auto', 'url', 'basic', 'digest'), true) ? $ac_a : 'auto';
    $ac_new['keep_max'] = max(0, min(100000, (int) (isset($_POST['keep_max']) ? $_POST['keep_max'] : 0)));
    $ac_new['keep_days'] = max(0, min(3650, (int) (isset($_POST['keep_days']) ? $_POST['keep_days'] : 90)));
    $ac_new['timelapse'] = isset($_POST['timelapse']) ? 1 : 0;
    $ac_new['timelapse_time'] = preg_match('/^\d{1,2}:\d{2}$/', (string) (isset($_POST['timelapse_time']) ? $_POST['timelapse_time'] : '')) ? $_POST['timelapse_time'] : '12:00';
    $ac_new['ai_url'] = trim((string) (isset($_POST['ai_url']) ? $_POST['ai_url'] : ''));
    $ac_new['ai_min'] = max(1, min(99, (int) (isset($_POST['ai_min']) ? $_POST['ai_min'] : 50)));
    $ac_new['webhook1'] = trim((string) (isset($_POST['webhook1']) ? $_POST['webhook1'] : ''));
    $ac_new['webhook2'] = trim((string) (isset($_POST['webhook2']) ? $_POST['webhook2'] : ''));
    if (cam_config_save($ac_new)) {
        $ac_saved = true;
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

function ac_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

if (function_exists('LBWeb::lbheader') || class_exists('LBWeb')) {
    LBWeb::lbheader('ACTi Kamera', 'https://wiki.loxberry.de', '');
} else {
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>ACTi Kamera</title></head><body>';
}
?>
<style>
.acw { max-width: 1100px; margin: 0 auto; padding: 0 10px 40px; font-size: 0.95em; }
.acw, .acw * { text-shadow: none !important; }
.acw h2 { color: #6dac20; margin: 18px 0 6px; font-size: 1.15em; }

/* Gleich grosse Schaltflaechen mit gleichem Abstand, egal ob Link oder Formular */
.acw .ac-h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.acw .ac-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
.acw .ac-knopfreihe form { margin: 0; display: flex; }
.acw .ac-knopfreihe .ac-btn { flex: 0 0 auto; min-width: 250px; text-align: center;
    display: inline-flex; align-items: center; justify-content: center; line-height: 1.25; }
.acw .ac-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.acw .ac-legende span { display: inline-flex; align-items: center; gap: 6px; }
.acw .ac-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.acw .ac-btn.ac-b-lesen { background: #6dac20; }
.acw .ac-btn.ac-b-technik { background: #546e7a; }
.acw .ac-btn.ac-b-aktion { background: #e0620d; }
.acw .ac-punkt.ac-b-lesen { background: #6dac20; }
.acw .ac-punkt.ac-b-technik { background: #546e7a; }
.acw .ac-punkt.ac-b-aktion { background: #e0620d; }
.acw label { display: block; font-weight: 600; margin: 8px 0 2px; }
.acw input[type=text], .acw input[type=password], .acw input[type=number], .acw select {
    width: 100%; padding: 7px 9px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; background: #fff; }
.acw .ac-row { display: flex; gap: 14px; flex-wrap: wrap; }
.acw .ac-row > div { flex: 1 1 210px; }
.acw .ac-small { color: #666; font-size: 0.88em; line-height: 1.45; }
.acw .ac-mono { font-family: monospace; background: #f4f4f4; padding: 1px 5px; border-radius: 4px; }
.acw .ac-btn { background: #6dac20; color: #fff !important; border: 0; border-radius: 8px; padding: 9px 18px;
    cursor: pointer; text-decoration: none; font-size: 0.95em; text-shadow: none !important; }
.acw .ac-alert { border-radius: 8px; padding: 10px 14px; margin: 12px 0; }
.acw .ac-ok { background: #e8f5e9; border: 1px solid #6dac20; }
.acw .ac-warn { background: #fff8e1; border: 1px solid #ffb300; }
.acw .ac-err { background: #ffebee; border: 1px solid #c62828; }
.acw .ac-info { background: #eef4fb; border: 1px solid #90a4ae; }
.acw .ac-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.acw .ac-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0; padding: 9px 18px;
    cursor: pointer; color: #444 !important; text-shadow: none !important; }
.acw .ac-tab.ac-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.acw .ac-pane { display: none; padding-top: 4px; }
.acw .ac-pane.ac-active { display: block; }
.acw .ac-tbl { border-collapse: collapse; margin: 6px 0 10px; }
.acw .ac-tbl th, .acw .ac-tbl td { border: 1px solid #ddd; padding: 5px 9px; text-align: left; vertical-align: top; }
.acw .ac-tbl th { background: #f4f4f4; }
.acw .ac-log { background: #263238; color: #cfd8dc; font-family: monospace; font-size: 0.82em;
    padding: 10px; border-radius: 8px; max-height: 460px; overflow: auto; white-space: pre-wrap; box-shadow: none; }
.acw .ac-step { border-left: 4px solid #6dac20; padding: 4px 0 4px 12px; margin: 12px 0; }
.acw .ac-gal { display: flex; flex-wrap: wrap; gap: 8px; }
.acw .ac-gal figure { margin: 0; width: 190px; }
.acw .ac-gal img { width: 100%; border-radius: 6px; border: 1px solid #ccc; }
.acw .ac-gal figcaption { font-size: 0.78em; color: #666; word-break: break-all; }
</style>
<div class="acw">
<h1 style="color:#6dac20;text-shadow:none;">ACTi Kamera</h1>
<div class="ac-small">Holt Bilder von einer ACTi-Netzwerkkamera und stellt sie Loxone bereit &mdash;
<b>ohne Zugangsdaten in der Loxone-Projektdatei</b>. Benutzer und Passwort stehen ausschlie&szlig;lich hier.</div>

<?php if ($ac_saved) { ?><div class="ac-alert ac-ok"><b>Konfiguration gespeichert.</b></div><?php } ?>
<?php if ($ac_err !== '') { ?><div class="ac-alert ac-err"><?= ac_e($ac_err) ?></div><?php } ?>
<?php if ($ac_note !== '') { ?><div class="ac-alert <?= strpos($ac_note, 'fehlgeschlagen') !== false ? 'ac-err' : 'ac-ok' ?>"><?= ac_e($ac_note) ?></div><?php } ?>
<?php if (trim((string) $ac_cfg['user']) === '' && (string) $ac_cfg['pass'] !== '' && trim((string) $ac_cfg['snapurl']) === '') { ?>
<div class="ac-alert ac-err"><b>Der Benutzername ist leer</b>, ein Passwort ist aber hinterlegt.
Die Kamera antwortet dann mit <span class="ac-mono">ERROR: not authorized</span> (HTTP 401).
Bitte den Benutzernamen eintragen &mdash; oder oben die vollst&auml;ndige URL hinterlegen.</div>
<?php } ?>
<?php if (trim((string) $ac_cfg['host']) === '') { ?>
<div class="ac-alert ac-warn"><b>Noch nicht eingerichtet.</b> Tragen Sie unten Adresse, Benutzer und Passwort der Kamera ein und speichern Sie.</div>
<?php } ?>

<div class="ac-tabs">
    <div class="ac-tab" data-pane="tab-settings">Einstellungen</div>
    <div class="ac-tab" data-pane="tab-loxone">Einbindung in Loxone</div>
    <div class="ac-tab" data-pane="tab-shots">Aufnahmen</div>
    <div class="ac-tab" data-pane="tab-test">Test</div>
    <div class="ac-tab" data-pane="tab-log">Protokoll</div>
</div>

<!-- ================= Einstellungen ================= -->
<div class="ac-pane" id="tab-settings">
<form method="post">
<input data-role="none" type="hidden" name="save" value="1">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">

<h2>Kamera</h2>
<div class="ac-row">
    <div>
        <label>Adresse (IP oder Hostname)</label>
        <input data-role="none" type="text" name="host" value="<?= ac_e($ac_cfg['host']) ?>" placeholder="192.168.1.17">
    </div>
    <div>
        <label>Benutzer</label>
        <input data-role="none" type="text" name="user" value="<?= ac_e($ac_cfg['user']) ?>" placeholder="admin">
    </div>
    <div>
        <label>Passwort</label>
        <input data-role="none" type="password" name="pass" value="" placeholder="<?= $ac_cfg['pass'] !== '' ? 'gespeichert &mdash; leer lassen = unver&auml;ndert' : 'Passwort der Kamera' ?>">
    </div>
</div>
<div class="ac-row" style="margin-top:8px;">
    <div style="max-width:340px;">
        <label>Anmeldeverfahren</label>
        <select data-role="none" name="auth">
            <option value="auto"<?= $ac_cfg['auth'] === 'auto' ? ' selected' : '' ?>>Automatisch ausprobieren (empfohlen)</option>
            <option value="url"<?= $ac_cfg['auth'] === 'url' ? ' selected' : '' ?>>Nur USER/PWD in der URL (ACTi-Standard)</option>
            <option value="basic"<?= $ac_cfg['auth'] === 'basic' ? ' selected' : '' ?>>HTTP Basic</option>
            <option value="digest"<?= $ac_cfg['auth'] === 'digest' ? ' selected' : '' ?>>HTTP Digest</option>
        </select>
    </div>
</div>
<div class="ac-small"><b>Bei &bdquo;ERROR: not authorized&ldquo;</b> hier die Verfahren durchprobieren.
&bdquo;Automatisch&ldquo; testet der Reihe nach URL-Parameter, Basic und Digest und merkt sich den Weg, der
funktioniert hat. Wichtig: Bei Basic/Digest werden die Zugangsdaten <i>nicht</i> zus&auml;tzlich in die URL
geschrieben &mdash; manche Kameras weisen genau diese Kombination ab.</div>
<div class="ac-small">Das Passwort wird nie angezeigt und nie ins Protokoll geschrieben. Die Konfigurationsdatei
ist nur f&uuml;r den Besitzer lesbar (<span class="ac-mono">chmod 600</span>).
Feld leer lassen bedeutet: bisheriges Passwort beibehalten.</div>

<div class="ac-row" style="margin-top:10px;">
    <div style="flex:1 1 100%;">
        <label>Vollst&auml;ndige Schnappschuss-URL (empfohlen, wenn eine funktionierende vorliegt)</label>
        <input data-role="none" type="text" name="snapurl" value="<?= ac_e($ac_cfg['snapurl']) ?>" placeholder="http://KAMERA/cgi-bin/encoder?USER=…&amp;PWD=…&amp;SNAPSHOT=N1920x1080,100&amp;DUMMY=n">
        <div class="ac-small">Ist dieses Feld gef&uuml;llt, nutzt das Plugin <b>genau diese Adresse</b> &mdash;
        ohne eigenes Zusammenbauen, ohne Umkodieren, ohne Rateversuche. Wer eine Kamera bereits anderswo eingebunden
        hat (z.&nbsp;B. in einer Visualisierung), kopiert die dort funktionierende URL einfach hier herein.
        Das ist der sicherste Weg bei <span class="ac-mono">ERROR: not authorized</span>.<br>
        <b>Hinweis:</b> Diese URL enth&auml;lt das Kamera-Passwort. Sie steht nur in der Plugin-Konfiguration
        (<span class="ac-mono">chmod 600</span>) und wird in Protokoll und Diagnose maskiert.
        Nach au&szlig;en gibt das Plugin weiterhin nur <span class="ac-mono">cam.php</span> ohne Zugangsdaten.</div>
    </div>
    <div style="flex:1 1 100%;">
        <label>Schnappschuss-Befehl &mdash; nur der Teil hinter <span class="ac-mono">USER=…&amp;PWD=…&amp;</span> (wird ignoriert, wenn oben eine URL steht)</label>
        <input data-role="none" type="text" name="snapcmd" value="<?= ac_e($ac_cfg['snapcmd']) ?>" placeholder="SNAPSHOT=N1920x1080,100&amp;DUMMY=n">
        <div class="ac-small">Das ist der Teil hinter <span class="ac-mono">USER=…&amp;PWD=…&amp;</span>.
        Der Vorgabewert stammt aus einer real funktionierenden ACTi-Konfiguration &mdash; inklusive Bildqualit&auml;t
        (<span class="ac-mono">,100</span>) und dem abschliessenden <span class="ac-mono">&amp;DUMMY=n</span>,
        das manche Firmware erwartet. Wer eine funktionierende URL hat (z.&nbsp;B. aus einer bestehenden
        Kamera-Einbindung), kopiert hier einfach den Teil dahinter herein.</div>
    </div>
    <div>
        <label>Aufl&ouml;sung (nur als Ersatz)</label>
        <input data-role="none" type="text" name="resolution" value="<?= ac_e($ac_cfg['resolution']) ?>" placeholder="z. B. N1280x720">
    </div>
    <div>
        <label>Zeitlimit je Bild (Sekunden)</label>
        <input data-role="none" type="number" name="timeout" value="<?= (int) $ac_cfg['timeout'] ?>" min="2" max="30">
    </div>
    <div>
        <label>Kanal</label>
        <input data-role="none" type="number" name="channel" value="<?= (int) $ac_cfg['channel'] ?>" min="1" max="16">
    </div>
</div>
<div class="ac-small">Aufl&ouml;sung leer lassen heisst: die Kamera liefert ihre eingestellte Gr&ouml;&szlig;e.
Bei ACTi lautet die Schreibweise <span class="ac-mono">N1280x720</span> (N = normal).</div>

<h2>Aufnahmen</h2>
<div class="ac-row">
    <div>
        <label>Aufbewahrung (Tage)</label>
        <input data-role="none" type="number" name="keep_days" value="<?= (int) $ac_cfg['keep_days'] ?>" min="1" max="3650">
    </div>
    <div>
        <label>Cliplänge (Sekunden)</label>
        <input data-role="none" type="number" name="clip_seconds" value="<?= (int) $ac_cfg['clip_seconds'] ?>" min="2" max="60">
    </div>
    <div>
        <label>Bilder je Sekunde im Clip</label>
        <input data-role="none" type="number" name="clip_fps" value="<?= (int) $ac_cfg['clip_fps'] ?>" min="1" max="5">
    </div>
</div>
<div class="ac-small">Ein &bdquo;Clip&ldquo; ist eine Bildserie &mdash; damit braucht das Plugin kein ffmpeg und keine
Zusatzpakete. F&uuml;r die Frage &bdquo;wer stand vor der T&uuml;r?&ldquo; reichen 2 Bilder je Sekunde v&ouml;llig.
&Auml;ltere Aufnahmen l&ouml;scht das Plugin automatisch.</div>

<div class="ac-row" style="margin-top:10px;">
    <div>
        <label>H&ouml;chstzahl Dateien je Archiv</label>
        <input data-role="none" type="number" name="keep_max" value="<?= (int) $ac_cfg['keep_max'] ?>" min="0" max="100000">
    </div>
</div>
<div class="ac-small">Die Bereinigung l&auml;uft t&auml;glich um <b>03:35 Uhr</b> und greift auf Bilder, Bildserien und
Zeitraffer. <b>0 oder leer = unbegrenzt</b> &mdash; bei Alter <i>und</i> Anzahl. Die jeweils neuesten Dateien bleiben
immer erhalten, damit der Speicher nicht vollläuft.</div>

<h2>Zeitraffer</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="timelapse" <?= !empty($ac_cfg['timelapse']) ? 'checked' : '' ?>> T&auml;glich ein Zeitrafferbild aufnehmen
</label>
<div class="ac-row" style="margin-top:6px;">
    <div style="max-width:220px;">
        <label>Uhrzeit (HH:MM)</label>
        <input data-role="none" type="text" name="timelapse_time" value="<?= ac_e($ac_cfg['timelapse_time']) ?>" placeholder="12:00">
    </div>
</div>
<div class="ac-small">Die Bilder landen im Unterordner <span class="ac-mono">timelapse</span>, der Dateiname ist das Datum.
Ist <span class="ac-mono">ffmpeg</span> auf dem LoxBerry vorhanden, wird nach jeder Aufnahme
<span class="ac-mono">zeitraffer.mp4</span> aus allen Bildern neu erzeugt &mdash; abrufbar unter
<span class="ac-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?>/zeitraffer.mp4</span>.
Fehlt ffmpeg, bleiben die Einzelbilder erhalten und alles andere funktioniert weiter
(nachinstallieren: <span class="ac-mono">sudo apt-get install -y ffmpeg</span>).</div>

<h2>KI-Objekterkennung (optional)</h2>
<div class="ac-row">
    <div>
        <label>Erkennungs-Endpunkt</label>
        <input data-role="none" type="text" name="ai_url" value="<?= ac_e($ac_cfg['ai_url']) ?>" placeholder="http://192.0.2.10:32168/v1/vision/detection">
    </div>
    <div style="max-width:220px;">
        <label>Mindest-Konfidenz (%)</label>
        <input data-role="none" type="number" name="ai_min" value="<?= (int) $ac_cfg['ai_min'] ?>" min="1" max="99">
    </div>
</div>
<div class="ac-small">Ben&ouml;tigt einen Erkennungsdienst auf st&auml;rkerer Hardware, z.&nbsp;B.
<b>CodeProject.AI Server</b> (Port 32168) oder <b>DeepStack</b>. Der LoxBerry selbst ist daf&uuml;r zu schwach.
Erkannte Objekte (z.&nbsp;B. <span class="ac-mono">person</span>, <span class="ac-mono">car</span>) erscheinen im JSON,
in den Webhooks, per MQTT und als Zeile <span class="ac-mono">ERKANNT=</span> in der Loxone-Ausgabe;
zus&auml;tzlich gibt es <span class="ac-mono">PERSON=1</span> als fertigen Schalter.
Leer lassen schaltet die Erkennung ab.</div>

<h2>Webhooks (optional)</h2>
<div class="ac-row">
    <div>
        <label>Webhook 1 &mdash; POST mit JSON</label>
        <input data-role="none" type="text" name="webhook1" value="<?= ac_e($ac_cfg['webhook1']) ?>" placeholder="https://…">
    </div>
    <div>
        <label>Webhook 2 &mdash; GET mit Parametern</label>
        <input data-role="none" type="text" name="webhook2" value="<?= ac_e($ac_cfg['webhook2']) ?>" placeholder="https://…">
    </div>
</div>
<div class="ac-small">Beide werden nach jeder Aufnahme ausgel&ouml;st. Webhook&nbsp;1 sendet
<span class="ac-mono">{bild, datei, anlass, objekte, zeit}</span> als JSON, Webhook&nbsp;2 h&auml;ngt
<span class="ac-mono">?bild=…&amp;anlass=…&amp;objekte=…</span> an die Adresse an.</div>

<h2>Benachrichtigung</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="notify_push" <?= !empty($ac_notify['push']) ? 'checked' : '' ?>> Push-Freigabe an Loxone melden
</label>
<div class="ac-row" style="margin-top:6px;">
    <div style="max-width:260px;">
        <label>Meldefenster nach einer Aufnahme (Minuten)</label>
        <input data-role="none" type="number" name="push_minutes" value="<?= (int) $ac_notify['push_minutes'] ?>" min="1" max="30">
    </div>
</div>
<div class="ac-small">Nach jeder Aufnahme steht <span class="ac-mono">PUSHAKTIV=1</span> f&uuml;r diese Zeitspanne.
Den Push selbst verschickt der Miniserver &mdash; so landet er in der Loxone-App.</div>

<h2>MQTT (optional)</h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="mqtt_enabled" <?= !empty($ac_cfg['mqtt_enabled']) ? 'checked' : '' ?>> &Uuml;ber das LoxBerry MQTT Gateway ver&ouml;ffentlichen
</label>
<div class="ac-row" style="margin-top:6px;">
    <div style="max-width:320px;">
        <label>Topic-Pr&auml;fix</label>
        <input data-role="none" type="text" name="mqtt_topic" value="<?= ac_e($ac_cfg['mqtt_topic']) ?>" placeholder="acti">
    </div>
</div>
<div class="ac-small">Beispiel: <span class="ac-mono">acti/letztes_bild</span>, <span class="ac-mono">acti/anlass</span>, <span class="ac-mono">acti/zeit</span>.</div>

<h3 class="ac-h3">Live-Bild f&uuml;r Loxone (MJPEG-Weiterleitung)</h3>
<p class="ac-hint">LoxBerry holt das Bild bei der Kamera und reicht es weiter. In der Loxone-Projektdatei
tr&auml;gt man dann diese Adressen ein &mdash; ohne Benutzer und Passwort:<br>
<span class="ac-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?>/cam_stream.php</span> &nbsp;(Livebild, IntVideoUrl)<br>
<span class="ac-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?>/cam_stream.php?einzeln=1</span> &nbsp;(Einzelbild, IntAlertImage)</p>
<label>Bilder je Sekunde</label>
<input type="number" step="0.1" min="0.2" max="10" data-role="none" name="stream_fps" value="<?= ac_e((string) $ac_cfg['stream_fps']) ?>">
<label>H&ouml;chstdauer eines Stroms in Sekunden (Notbremse gegen Dauerlast)</label>
<input type="number" min="5" max="21600" data-role="none" name="stream_maxsec" value="<?= ac_e((string) $ac_cfg['stream_maxsec']) ?>">
<label>Bildquelle</label>
<select data-role="none" name="stream_mode">
<option value="auto"<?= $ac_cfg['stream_mode'] === 'auto' ? ' selected' : '' ?>>Automatisch &mdash; Kamerastrom, sonst RTSP, sonst Schnappsch&uuml;sse</option>
<option value="mjpeg"<?= $ac_cfg['stream_mode'] === 'mjpeg' ? ' selected' : '' ?>>Nur Kamerastrom (GET_STREAM &mdash; empfohlen)</option>
<option value="rtsp"<?= $ac_cfg['stream_mode'] === 'rtsp' ? ' selected' : '' ?>>Nur RTSP (fl&uuml;ssiges Video, braucht ffmpeg)</option>
<option value="jpeg"<?= $ac_cfg['stream_mode'] === 'jpeg' ? ' selected' : '' ?>>Nur Schnappsch&uuml;sse</option>
</select>
<p class="ac-hint">ffmpeg auf diesem LoxBerry:
<?php $ac_ff = cam_ffmpeg(); ?>
<?= $ac_ff !== '' ? '<b style="color:#2e7d32;">vorhanden</b> (' . ac_e($ac_ff) . ')' : '<b style="color:#c62828;">nicht vorhanden</b> &ndash; es werden Schnappsch&uuml;sse verwendet' ?></p>
<label>Adresse des Kamerastroms (leer = <span class="ac-mono">/cgi-bin/cmd/system?…&amp;GET_STREAM</span>)</label>
<input type="text" data-role="none" name="mjpeg_url" value="<?= ac_e((string) $ac_cfg['mjpeg_url']) ?>">
<label>RTSP-Adresse (leer = bei der Kamera erfragen &uuml;ber <span class="ac-mono">GET_STREAM</span>)</label>
<input type="text" data-role="none" name="rtsp_url" placeholder="rtsp://&lt;Kamera&gt;:7070//stream2" value="<?= ac_e((string) $ac_cfg['rtsp_url']) ?>">
<p class="ac-hint">Bleibt das Feld leer, wird <span class="ac-mono">rtsp://&lt;Kamera&gt;:&lt;Port&gt;//stream&lt;Nr&gt;</span> gebildet.
Benutzer und Passwort setzt das Plugin selbst ein &mdash; sie geh&ouml;ren nicht in dieses Feld.</p>
<label>RTSP-Port</label>
<input type="number" min="1" max="65535" data-role="none" name="rtsp_port" value="<?= ac_e((string) $ac_cfg['rtsp_port']) ?>">
<label>Welcher Strom</label>
<select data-role="none" name="rtsp_stream">
<option value="2"<?= ((int) $ac_cfg['rtsp_stream']) !== 1 ? ' selected' : '' ?>>stream2 &mdash; Nebenstrom, kleiner und sparsam (empfohlen)</option>
<option value="1"<?= ((int) $ac_cfg['rtsp_stream']) === 1 ? ' selected' : '' ?>>stream1 &mdash; Hauptstrom, volle Aufl&ouml;sung</option>
</select>
<?php $ac_rr = cam_rtsp_url(true); if ($ac_rr === '') { $ac_rr = ''; } ?>
<label>Bildg&uuml;te bei RTSP (2 = fein und gro&szlig;, 15 = grob und sparsam)</label>
<input type="number" min="2" max="15" data-role="none" name="rtsp_quality" value="<?= ac_e((string) $ac_cfg['rtsp_quality']) ?>">
<label>Token (optional &mdash; dann nur mit <span class="ac-mono">?t=Token</span> abrufbar)</label>
<input type="text" data-role="none" name="stream_token" value="<?= ac_e((string) $ac_cfg['stream_token']) ?>">
<div style="margin-top:16px;"><button data-role="none" class="ac-btn" type="submit">Speichern</button></div>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="ac-pane" id="tab-loxone">
<h2>Einbindung in Loxone &mdash; Schritt f&uuml;r Schritt</h2>
<p>Ziel: Beim Klingeln (Loxone Intercom <i>oder</i> Taster an der Haust&uuml;r) macht die Kamera ein Bild,
Loxone schickt einen Push, und das Bild ist &uuml;ber eine feste URL abrufbar &mdash;
<b>ohne Kamera-Passwort in der Projektdatei</b>.</p>

<div class="ac-step"><b>Schritt 1: Virtueller Ausgang &bdquo;LoxBerry ACTi&ldquo;</b>
<table class="ac-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>Adresse</td><td><span class="ac-mono">http://<?= ac_e($ac_host) ?></span></td></tr>
</table>
</div>

<div class="ac-step"><b>Schritt 2: Virtuelle Ausgangs-Befehle</b>
<table class="ac-tbl">
<tr><th>Befehl bei EIN</th><th>Wirkung</th></tr>
<tr><td><span class="ac-mono">/plugins/<?= ac_e($ac_plugin) ?>/cam.php?foto=1&amp;anlass=klingel</span></td><td>Bild beim Klingeln</td></tr>
<tr><td><span class="ac-mono">/plugins/<?= ac_e($ac_plugin) ?>/cam.php?foto=1&amp;anlass=tuer</span></td><td>Bild beim T&uuml;rtaster</td></tr>
<tr><td><span class="ac-mono">/plugins/<?= ac_e($ac_plugin) ?>/cam.php?foto=1&amp;anlass=bewegung</span></td><td>Bild bei Bewegungsmelder</td></tr>
<tr><td><span class="ac-mono">/plugins/<?= ac_e($ac_plugin) ?>/cam.php?clip=1&amp;anlass=klingel</span></td><td>Bildserie statt Einzelbild</td></tr>
</table>
<div class="ac-small"><b>Aus der Praxis:</b> Zwischen Klingelsignal und Bild geh&ouml;rt eine Einschaltverz&ouml;gerung von
2&ndash;3 Sekunden &mdash; sonst steht der Besucher noch nicht im Bild. In Loxone also:
Klingel &rarr; Einschaltverz&ouml;gerung 3 s &rarr; virtueller Ausgang.</div>
</div>

<div class="ac-step"><b>Schritt 3: Virtueller HTTP-Eingang &bdquo;ACTi Kamera&ldquo;</b> (Abfrage 60 s)
<table class="ac-tbl">
<tr><th>Eigenschaft</th><th>Wert</th></tr>
<tr><td>URL</td><td><span class="ac-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?>/cam.php</span></td></tr>
<tr><td>Abfragezyklus</td><td>60 Sekunden</td></tr>
</table>
<table class="ac-tbl">
<tr><th>Befehlserkennung</th><th>Bedeutung</th></tr>
<tr><td><span class="ac-mono">\iOK=\i\v</span></td><td>1 = Kamera konfiguriert</td></tr>
<tr><td><span class="ac-mono">\iALTER=\i\v</span></td><td>Minuten seit dem letzten Bild (&minus;1 = noch keins)</td></tr>
<tr><td><span class="ac-mono">\iBILDER=\i\v</span> / <span class="ac-mono">\iCLIPS=\i\v</span></td><td>Anzahl gespeicherter Aufnahmen</td></tr>
<tr><td><span class="ac-mono">\iPUSHAKTIV=\i\v</span></td><td><b>1 = gerade wurde ein Bild aufgenommen</b> &mdash; Ausl&ouml;ser f&uuml;r den Push</td></tr>
<tr><td><span class="ac-mono">\iPERSON=\i\v</span></td><td>1 = auf dem letzten Bild wurde eine <b>Person</b> erkannt (KI)</td></tr>
<tr><td><span class="ac-mono">\iOBJEKTE=\i\v</span> / <span class="ac-mono">\iZEITRAFFER=\i\v</span></td><td>Anzahl erkannter Objekte / Zeitrafferbilder</td></tr>
<tr><td><span class="ac-mono">\iPUSH=\i\v</span> / <span class="ac-mono">\iPTEST=\i\v</span></td><td>Freigabe aus der Konfiguration / Test-Push</td></tr>
</table>
</div>

<div class="ac-step"><b>Schritt 4: Komplette Baustein-Liste zum 1:1-Nachbauen</b>
<table class="ac-tbl">
<tr><th>Baustein</th><th>Name</th><th>Einstellung</th><th>Eing&auml;nge</th></tr>
<tr><td>Einschaltverz&ouml;gerung E1</td><td>Klingel entprellt</td><td>3 s</td><td>&larr; Klingeltaster / Intercom</td></tr>
<tr><td>Virtueller Ausgang</td><td>Bild holen</td><td>Befehl aus Schritt 2 (<span class="ac-mono">anlass=klingel</span>)</td><td>&larr; E1</td></tr>
<tr><td>Schwellwertschalter S1</td><td>Aufnahme erfolgt</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; PUSHAKTIV</td></tr>
<tr><td>Schwellwertschalter S2</td><td>Push freigegeben</td><td>Ein 0,5 / Aus 0,4</td><td>&larr; PUSH</td></tr>
<tr><td>UND U1</td><td>Besuch melden</td><td></td><td>S1 &amp; S2</td></tr>
<tr><td>ODER O1</td><td>Push-Sammler</td><td>einzige Quelle des Benachrichtigungs-Bausteins!</td><td>U1</td></tr>
<tr><td>Benachrichtigungs-Baustein</td><td>Push &bdquo;Besuch an der T&uuml;r&ldquo;</td><td>Text z. B. &bdquo;Jemand hat geklingelt &mdash; Bild in der App.&ldquo;</td><td>&larr; O1</td></tr>
<tr><td>Benachrichtigungs-Baustein 2</td><td>Test-Push</td><td>eigener Baustein NUR f&uuml;r den Test</td><td>&larr; Schwellwertschalter an PTEST</td></tr>
<tr><td>Statusbaustein</td><td>Kamera-Kachel</td><td>Text: &bdquo;Letztes Bild vor &lt;v1.0&gt; Minuten&ldquo;</td><td>I1 &larr; ALTER</td></tr>
</table>
<div class="ac-small"><b>Praxis-Erfahrung zum Benachrichtigungs-Baustein:</b> Er sendet nur bei einer 0&rarr;1-Flanke.
Niemals mehrere Quellen direkt an den Eingang legen &mdash; eine dauerhaft aktive Quelle verschluckt alle weiteren
Ausl&ouml;ser. Immer erst im ODER sammeln. F&uuml;r den Test einen EIGENEN Baustein verwenden.</div>
</div>

<div class="ac-step"><b>Schritt 5: Bild in der App anzeigen</b><br>
Das jeweils letzte Bild liegt unter
<span class="ac-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?>/letztesbild.jpg</span>
(alternativ <span class="ac-mono">cam.php?letztes=1</span>). Diese Adresse enth&auml;lt keine Zugangsdaten und
l&auml;sst sich gefahrlos in einen Loxone-Webseiten-Baustein oder eine Kamera-Kachel eintragen.
<div class="ac-small" style="margin-top:4px;"><b>Damit verschwindet das Kamera-Passwort aus der Projektdatei:</b>
Bisher stand dort typischerweise <span class="ac-mono">http://KAMERA/cgi-bin/encoder?USER=admin&amp;PWD=…</span>.
Diesen Eintrag kann man nach dem Umstellen entfernen.</div>
</div>

<div class="ac-step"><b>Schritt 6: MQTT-Alternative + JSON</b><br>
Alle Werte gibt es auch &uuml;ber das LoxBerry MQTT Gateway (Reiter Einstellungen &rarr; MQTT) und als JSON:
<span class="ac-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?>/cam.php?json=1</span>
</div>
</div>

<!-- ================= Aufnahmen ================= -->
<div class="ac-pane" id="tab-shots">
<h2>Letzte Aufnahmen</h2>
<?php
$ac_dir = function_exists('cam_datadir') ? cam_datadir() : '';
$ac_bilder = $ac_dir !== '' ? (glob($ac_dir . '/bilder/*.jpg') ?: array()) : array();
rsort($ac_bilder);
$ac_clips = $ac_dir !== '' ? (glob($ac_dir . '/clips/*', GLOB_ONLYDIR) ?: array()) : array();
rsort($ac_clips);
?>
<div class="ac-small" style="margin-bottom:8px;">Gespeichert: <b><?= count($ac_bilder) ?></b> Bilder,
<b><?= count($ac_clips) ?></b> Bildserien. Aufbewahrung: <?= (int) $ac_cfg['keep_days'] ?> Tage.
Ablage: <span class="ac-mono"><?= ac_e($ac_dir) ?></span></div>
<?php if ($ac_bilder) { ?>
<div class="ac-gal">
<?php foreach (array_slice($ac_bilder, 0, 12) as $ac_f) {
    $ac_n = basename($ac_f); ?>
<figure>
    <img src="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?letztes=1&amp;t=<?= (int) filemtime($ac_f) ?>" alt="" style="<?= $ac_f === $ac_bilder[0] ? '' : 'display:none;' ?>">
    <figcaption><?= ac_e(substr($ac_n, 6, 2) . '.' . substr($ac_n, 4, 2) . '.' . substr($ac_n, 0, 4) . ' ' . substr($ac_n, 9, 2) . ':' . substr($ac_n, 11, 2) . ':' . substr($ac_n, 13, 2)) ?><br><?= ac_e($ac_n) ?></figcaption>
</figure>
<?php } ?>
</div>
<div class="ac-small">Angezeigt wird das jeweils neueste Bild; die &uuml;brigen Dateinamen stehen darunter.
Die vollst&auml;ndige Ablage erreichen Sie &uuml;ber den LoxBerry-Dateimanager.</div>
<?php } else { ?>
<div class="ac-alert ac-info">Noch keine Aufnahmen vorhanden. Im Reiter <b>Test</b> l&auml;sst sich sofort eine ausl&ouml;sen.</div>
<?php } ?>
<?php if ($ac_clips) { ?>
<h2>Bildserien</h2>
<table class="ac-tbl"><tr><th>Serie</th><th>Bilder</th><th>Zeitpunkt</th></tr>
<?php foreach (array_slice($ac_clips, 0, 10) as $ac_c) { ?>
<tr><td><?= ac_e(basename($ac_c)) ?></td><td><?= count(glob($ac_c . '/*.jpg') ?: array()) ?></td>
<td><?= ac_e(date('d.m.Y H:i:s', filemtime($ac_c))) ?></td></tr>
<?php } ?></table>
<?php } ?>
<form method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="cleanupnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-shots">
    <button data-role="none" class="ac-btn" type="submit" style="background:#607d8b;">Alte Aufnahmen jetzt aufr&auml;umen</button>
</form>
</div>

<!-- ================= Test ================= -->
<div class="ac-pane" id="tab-test">
<h2>Test</h2>
<div class="ac-legende">
<span><i class="ac-punkt ac-b-lesen"></i> Ansehen &mdash; fragt nur ab, ver&auml;ndert nichts</span>
<span><i class="ac-punkt ac-b-technik"></i> Technische Auskunft &mdash; f&uuml;r die Fehlersuche</span>
<span><i class="ac-punkt ac-b-aktion"></i> L&ouml;st etwas aus &mdash; nimmt auf oder verschickt</span>
</div>

<h3 class="ac-h3">Ansehen</h3>
<div class="ac-knopfreihe">
<a class="ac-btn ac-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?test=1" target="_blank">Verbindung pr&uuml;fen</a>
<a class="ac-btn ac-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam_stream.php" target="_blank">Livebild ansehen</a>
<a class="ac-btn ac-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?letztes=1" target="_blank">Letztes Bild &ouml;ffnen</a>
<a class="ac-btn ac-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php" target="_blank">Loxone-Zeile abrufen</a>
<a class="ac-btn ac-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?json=1" target="_blank">JSON-Ansicht</a>
</div>

<h3 class="ac-h3">Technische Auskunft</h3>
<div class="ac-knopfreihe">
<a class="ac-btn ac-b-technik" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?diag=1" target="_blank">Diagnose (alle Varianten)</a>
<a class="ac-btn ac-b-technik" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?sys=1" target="_blank">Kamera-Auskunft</a>
</div>

<h3 class="ac-h3">L&ouml;st etwas aus</h3>
<div class="ac-knopfreihe">
<form method="post">
    <input data-role="none" type="hidden" name="shotnow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="ac-btn ac-b-aktion" type="submit">Jetzt ein Bild aufnehmen</button>
</form>
<form method="post">
    <input data-role="none" type="hidden" name="timelapsenow" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="ac-btn ac-b-aktion" type="submit">Zeitrafferbild aufnehmen</button>
</form>
<a class="ac-btn ac-b-aktion" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?ptest=1" target="_blank">Test-Pushnachricht</a>
</div>

<div class="ac-small" style="margin-top:14px;">
&bull; <b>Verbindung pr&uuml;fen</b> holt ein Bild und meldet Gr&ouml;&szlig;e und Antwortzeit &mdash; der schnellste Weg,
Adresse, Benutzer und Passwort zu kontrollieren.<br>
&bull; <b>Diagnose</b> probiert alle Kombinationen aus Befehl und Anmeldeverfahren durch und zeigt f&uuml;r jede,
was die Kamera geantwortet hat. Die erste Zeile mit <span class="ac-mono">OK</span> wird automatisch gemerkt.<br>
&bull; <b>Kamera-Auskunft</b> fragt die System-Schnittstelle ab (Modell, Firmware, Stromadressen).<br>
&bull; <b>Test-Pushnachricht</b> setzt <span class="ac-mono">PTEST=1</span> f&uuml;r 5 Minuten; der Push kommt
&uuml;ber den Test-Benachrichtigungsbaustein in Loxone (Schritt 4).
</div>

<h2>Zustand</h2>
<table class="ac-tbl">
<tr><th>Wert</th><th>Inhalt</th></tr>
<tr><td>Kamera konfiguriert</td><td><?= !empty($ac_st['ok']) ? 'ja' : '<b>nein</b>' ?></td></tr>
<tr><td>Letztes Bild</td><td><?= $ac_st['letztes_bild'] !== '' ? ac_e($ac_st['letztes_bild']) . ' (Anlass: ' . ac_e($ac_st['letzter_anlass']) . ')' : '&ndash;' ?></td></tr>
<tr><td>Alter</td><td><?= (int) $ac_st['alter_min'] >= 0 ? (int) $ac_st['alter_min'] . ' Minuten' : '&ndash;' ?></td></tr>
<tr><td>Gespeicherte Aufnahmen</td><td><?= (int) $ac_st['bilder'] ?> Bilder, <?= (int) $ac_st['clips'] ?> Serien, <?= (int) $ac_st['timelapse'] ?> Zeitrafferbilder</td></tr>
<tr><td>Zuletzt erkannt</td><td><?= !empty($ac_st['objekte']) ? ac_e(implode(', ', $ac_st['objekte'])) : '&ndash; (keine Erkennung eingerichtet oder nichts gefunden)' ?></td></tr>
</table>
</div>

<!-- ================= Protokoll ================= -->
<div class="ac-pane" id="tab-log">
<h2>Protokoll</h2>
<div class="ac-small" style="margin-bottom:8px;">Protokolliert werden Aufnahmen, Aufr&auml;uml&auml;ufe und Fehler.
Passw&ouml;rter werden dabei maskiert. Neueste Eintr&auml;ge oben (max. 300).<br>
Datei: <span class="ac-mono"><?= ac_e($ac_logfile) ?></span></div>
<?php if ($ac_loglines) { ?>
<div class="ac-log"><?= ac_e(implode("\n", $ac_loglines)) ?></div>
<?php } else { ?>
<div class="ac-alert ac-info">Noch keine Protokoll-Eintr&auml;ge vorhanden.</div>
<?php } ?>
<form method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="ac-btn" type="submit" style="background:#c62828;">Protokoll leeren</button>
</form>
</div>

</div>
<script>
(function () {
    var aktiv = <?= json_encode($ac_tab) ?>;
    var tabs = document.querySelectorAll('.ac-tab');
    function zeige(id) {
        tabs.forEach(function (t) { t.classList.toggle('ac-active', t.dataset.pane === id); });
        document.querySelectorAll('.ac-pane').forEach(function (p) { p.classList.toggle('ac-active', p.id === id); });
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
