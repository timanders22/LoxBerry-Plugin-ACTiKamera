<?php
/**
 * ACTi Kamera - Endpunkt fuer Loxone und Drittsoftware
 *
 *   (ohne Parameter)   -> ACTI;OK=..;ALTER=..;BILDER=..;CLIPS=..;PUSH=..;PTEST=..
 *   ?foto=1            -> Schnappschuss aufnehmen (Anlass ueber &anlass=)
 *   ?clip=1            -> kurze Bildserie aufnehmen
 *   ?json=1            -> Zustand als JSON
 *   ?letztes=1         -> das letzte Bild direkt ausliefern (image/jpeg)
 *   ?test=1            -> Verbindungstest im Klartext
 *   ?diag=1            -> Diagnose: alle Befehls- und Anmeldevarianten durchprobieren
 *   ?timelapse=1       -> Zeitrafferbild jetzt aufnehmen
 *   ?cleanup=1         -> Archiv jetzt aufraeumen
 *   ?ptest=1           -> Test-Pushnachricht ausloesen (PTEST=1 fuer 5 Minuten)
 *
 * Der Aufruf enthaelt bewusst KEINE Zugangsdaten - die liegen nur in der
 * Plugin-Konfiguration. Damit verschwindet das Kamera-Passwort aus der
 * Loxone-Projektdatei.
 */

require_once __DIR__ . '/cam_lib.php';

/* Diese Datei liegt im UNANGEMELDETEN Baum. Sie liest die Konfiguration,
 * sie legt keine an: bis 1.9.16 stellte ein Aufruf ohne Token - korrekt
 * mit 403 beantwortet - die Konfiguration aus der Zweitschrift wieder her. */
cam_selbstheilung(false);

/* Wer hat angerufen. Aus dem Webserver, nicht aus der Anfrage, und auf die
   Zeichen beschraenkt, die in einer Adresse vorkommen duerfen - sonst
   liesse sich eine zweite Zeile ins Protokoll schreiben. */
$ac_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$ac_ip = preg_replace('/[^0-9A-Fa-f:.]/', '', $ac_ip);
if ($ac_ip === '') {
    $ac_ip = 'unbekannt';
}

/**
 * Einen Parameter aus $_GET holen - erst is_string, dann alles andere.
 *
 * Bis 1.9.16 stand an elf Stellen (string) $_GET[...] ohne Wache. Unter
 * PHP 8 erzeugt ?token[]=x dort die Warnung "Array to string conversion";
 * bei eingeschaltetem display_errors steht sie VOR dem header()-Aufruf,
 * der 403 kommt nicht mehr zustande, und der absolute Serverpfad geht an
 * einen unangemeldeten Aufrufer hinaus (gemessen, PHP 8.4.24).
 */
function ac_get($name, $vorgabe = '')
{
    return (isset($_GET[$name]) && is_string($_GET[$name])) ? $_GET[$name] : $vorgabe;
}

/** Jeder Weg durch diese Datei schreibt eine Zeile - auch die Abweisung. */
function ac_weg($text)
{
    global $ac_ip;
    cam_log('cam.php: ' . $text . ' (Aufrufer ' . $ac_ip . ')');
}


/* ?selftest=1&token=... - beantwortet die Tokenfrage, ohne etwas auszuloesen.
 *
 * Ohne diesen Zweig gibt es nur zwei schlechte Moeglichkeiten: entweder man
 * loest wirklich aus, um die Adresse im Miniserver zu pruefen - dann klingelt
 * das Telefon -, oder man erfaehrt nie, ob sie noch stimmt.
 *
 * Kein Kamerakontakt, kein Schreibzugriff. Ein falsches Token bekommt dieselbe
 * Abweisung wie sonst auch; der Selbsttest ist keine Abkuerzung an der
 * Sicherheit vorbei.
 */
if (isset($_GET['selftest'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $ac_fehl = cam_token_pruefen(ac_get('token'));
    if ($ac_fehl === '') {
        ac_weg('Selbsttest, Token in Ordnung');
        echo "SELFTEST;OK=1;TOKEN=OK\n";
        exit;
    }
    ac_weg('Selbsttest abgewiesen: ' . $ac_fehl);
    header('HTTP/1.1 403 Forbidden');
    echo 'SELFTEST;OK=0;ERR=' . $ac_fehl . "\n";
    exit;
}

/* Alles, was ausloest oder etwas ueber die Zugangsdaten verraet, verlangt das
 * Token. Offen bleiben die Statuszeile, ?json=1 und ?letztes=1 - sie aendern
 * nichts, und an ?letztes=1 haengt auf jeder bestehenden Anlage die
 * Kamera-Kachel.
 */
foreach (array('foto', 'clip', 'timelapse', 'cleanup', 'ptest', 'diag', 'sys', 'test') as $ac_g) {
    if (!isset($_GET[$ac_g])) {
        continue;
    }
    $ac_fehl = cam_token_pruefen(ac_get('token'));
    if ($ac_fehl !== '') {
        ac_weg('abgewiesen bei ?' . $ac_g . '=, ' . $ac_fehl);
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ACTI;OK=0;ERR=' . $ac_fehl . "\n";
        exit;
    }
    break;
}

/* Welche Kamera ist gemeint? Ohne Angabe die erste - damit bleibt jede
   Adresse gueltig, die vor der Erweiterung eingetragen wurde. Ein Wert
   ausserhalb des Bereichs wird abgewiesen, nicht zurechtgebogen: eine
   Aufnahme mit der falschen Kamera faellt niemandem auf. */
$ac_kam = 1;
if (isset($_GET['kamera'])) {
    $ac_roh = ac_get('kamera');
    if (!preg_match('/^[0-9]{1,2}$/', $ac_roh)
        || (int) $ac_roh < 1 || (int) $ac_roh > CAM_MAX
        || !in_array((int) $ac_roh, cam_kameras(), true)) {
        ac_weg('abgewiesen, unbekannte Kamera, ' . strlen($ac_roh) . ' Zeichen');
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=utf-8');
        echo "ACTI;OK=0;ERR=KAMERA\n";
        exit;
    }
    $ac_kam = (int) $ac_roh;
}

$anlass = preg_replace('/[^a-z0-9]/i', '', ac_get('anlass', 'manuell'));
if ($anlass === '') {
    $anlass = 'manuell';
}

/* Eine bestimmte gespeicherte Aufnahme ausliefern.
 *
 * Lesender Aufruf, deshalb ohne Aktionstoken - er aendert nichts. Ist aber ein
 * Stromkennwort hinterlegt, gilt es hier ebenso: es sind Bilder der eigenen
 * Haustuer, und wer den Strom schuetzt, will auch das Archiv geschuetzt haben.
 */
if (isset($_GET['bild']) || isset($_GET['serie']) || isset($_GET['zeitraffer'])) {
    $ac_scfg = cam_config();
    $ac_stok = (string) $ac_scfg['stream_token'];
    if ($ac_stok !== '' && !hash_equals($ac_stok, ac_get('t'))) {
        ac_weg('Archivabruf abgewiesen, Stromkennwort falsch oder fehlend');
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Zugriff verweigert: falsches oder fehlendes Token.\n";
        exit;
    }
    if (isset($_GET['serie'])) {
        $ac_d = cam_archivdatei('serie', ac_get('serie'),
            (int) ac_get('nr', '0'), $ac_kam);
    } elseif (isset($_GET['zeitraffer'])) {
        $ac_d = cam_archivdatei('zeitraffer', ac_get('zeitraffer'), 0, $ac_kam);
    } else {
        $ac_d = cam_archivdatei('bild', ac_get('bild'), 0, $ac_kam);
    }
    if ($ac_d === '') {
        ac_weg('Archivabruf: diese Aufnahme gibt es nicht');
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Diese Aufnahme gibt es nicht.\n";
        exit;
    }
    header('Content-Type: image/jpeg');
    header('Content-Length: ' . filesize($ac_d));
    // Eine Archivdatei aendert sich nicht mehr - der Browser darf sie behalten.
    header('Cache-Control: private, max-age=86400');
    readfile($ac_d);
    exit;
}

if (isset($_GET['letztes'])) {
    $p = cam_paths();
    $f = $p['web'] . '/letztesbild' . cam_sx($ac_kam) . '.jpg';
    if (is_file($f)) {
        header('Content-Type: image/jpeg');
        header('Cache-Control: no-store');
        readfile($f);
        exit;
    }
    header('HTTP/1.1 404 Not Found');
    header('Content-Type: text/plain; charset=utf-8');
    echo "Noch kein Bild vorhanden.\n";
    exit;
}

if (isset($_GET['sys'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "AUSKUNFT DER SYSTEM-SCHNITTSTELLE  /cgi-bin/cmd/system\n";
    echo str_repeat('-', 78) . "\n";
    foreach (array('GET_STREAM', 'GET_MODEL', 'GET_FIRMWARE_VERSION', 'GET_MAC',
                   'VIDEO_RESOLUTION', 'CHANNEL_ENABLE') as $ac_b) {
        list($ac_a, $ac_c, $ac_e2) = cam_system($ac_b, $ac_kam);
        $ac_t = $ac_a === false ? ('FEHLER: ' . $ac_e2) : trim(cam_maske((string) $ac_a));
        if (strlen($ac_t) > 400) { $ac_t = substr($ac_t, 0, 400) . ' …'; }
        printf("%-22s HTTP %-4d %s\n", $ac_b, $ac_c, str_replace(array("\r", "\n"), ' ', $ac_t));
    }
    echo str_repeat('-', 78) . "\n";
    $ac_ff = cam_ffmpeg();
    echo 'ffmpeg: ' . ($ac_ff !== '' ? $ac_ff : 'nicht vorhanden') . "\n";
    echo 'Kamerastrom: ' . cam_maske(cam_mjpeg_url($ac_kam)) . "\n";
    echo 'RTSP-Adresse: ' . (cam_rtsp_maske(cam_rtsp_url(false, $ac_kam)) ?: 'nicht ermittelbar') . "\n";
    exit;
}

if (isset($_GET['diag'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $d_cfg = cam_kcfg($ac_kam);
    echo 'DIAGNOSE - ' . cam_kname($ac_kam) . "\n";
    echo 'Gebaute URL: ' . cam_maske(cam_url('SNAPSHOT', true, $ac_kam)) . "\n";
    echo 'Benutzer:    "' . $d_cfg['user'] . '" (' . strlen($d_cfg['user']) . " Zeichen)\n";
    echo 'Passwort:    ' . strlen($d_cfg['pass']) . ' Zeichen'
       . ($d_cfg['pass'] !== '' ? ', beginnt mit "' . substr($d_cfg['pass'], 0, 1) . '", endet auf "'
         . substr($d_cfg['pass'], -1) . '"' : ' - LEER!') . "\n";
    echo "\nVergleichen Sie diese Angaben mit einer nachweislich funktionierenden URL\n"
       . "(z. B. aus einer bestehenden Kamera-Einbindung). Weichen Benutzer oder Laenge ab,\n"
       . "ist das die Ursache - dann die vollstaendige URL in den Einstellungen hinterlegen.\n";
    echo str_repeat('-', 100) . "\n";
    foreach (cam_diag($ac_kam) as $z) {
        printf("%-4s HTTP %-4s %5s ms  %-9s %-42s %s\n",
            $z['ok'] ? 'OK' : '--', $z['http'], $z['ms'], $z['auth'],
            substr($z['befehl'], 0, 42), $z['antwort']);
    }
    echo "\nEine Zeile mit OK zeigt den Weg, der funktioniert - er wird automatisch gemerkt.\n";
    exit;
}

if (isset($_GET['test'])) {
    ac_weg('Verbindungstest (Kamera ' . $ac_kam . ')');
    header('Content-Type: text/plain; charset=utf-8');
    echo cam_test($ac_kam) . "\n";
    exit;
}

if (isset($_GET['ptest'])) {
    ac_weg('Test-Pushnachricht ausgeloest');
    if (!is_dir(cam_paths()['tmp'])) { @mkdir(cam_paths()['tmp'], 0775, true); }
    @file_put_contents(cam_paths()['tmp'] . '/ptest', time());
    header('Content-Type: text/plain; charset=utf-8');
    echo "PTEST;OK=1;DAUER=300\nHinweis: Loxone pollt zyklisch - die Push-Nachricht kommt innerhalb von 5 Minuten,\n"
       . "sofern der Test-Benachrichtigungsbaustein laut Anleitung verdrahtet ist.\n";
    exit;
}

/* cam_ptest_active() ist in cam_lib.php umgezogen: cam_werte() braucht sie,
   und die wird auch aus cam_cron.php aufgerufen - dort ist cam.php nicht
   geladen, ein Aufruf haette den Cron mit einem Fatal Error beendet. */

$ergebnis = '';
/* Mindestpause fuer Aufrufe von aussen - dieselbe Regel wie in e.php.
   Gemeldet statt verschwiegen: ein Endpunkt, der wortlos nichts tut, schickt
   den Anwender auf die Suche nach einem Fehler, den es nicht gibt. */
if (isset($_GET['foto']) || isset($_GET['clip'])) {
    $ac_rest = cam_pause_nehmen($ac_kam);
    if ($ac_rest > 0) {
        ac_weg('Mindestpause, noch ' . (int) $ac_rest . ' s (Kamera ' . $ac_kam . ')');
        /* 429 statt 200: ein abgewiesener Aufruf sah auf der Leitung aus wie
           ein geglueckter, und jede Zwischenstelle, die auf den Code sieht,
           zaehlte ihn als Erfolg. Der Text bleibt unveraendert - Loxone liest
           ihn, nicht den Code. */
        header('HTTP/1.1 429 Too Many Requests');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'ACTI;OK=0;ERR=PAUSE;REST=' . (int) $ac_rest . "\n";
        exit;
    }
}
if (isset($_GET['foto'])) {
    list($ok, $info) = cam_snapshot($anlass, $ac_kam);
    ac_weg('Bild OK=' . (int) $ok . ' ' . $info . ' (Kamera ' . $ac_kam . ', Anlass ' . $anlass . ')');
    $ergebnis = 'FOTO;OK=' . $ok . ';INFO=' . $info;
}
if (isset($_GET['clip'])) {
    list($ok, $info) = cam_clip($anlass, $ac_kam);
    ac_weg('Bildserie OK=' . (int) $ok . ' ' . $info . ' (Kamera ' . $ac_kam . ', Anlass ' . $anlass . ')');
    $ergebnis = 'CLIP;OK=' . $ok . ';INFO=' . $info;
}
if (isset($_GET['timelapse'])) {
    list($ok, $info) = cam_timelapse($ac_kam);
    ac_weg('Zeitraffer OK=' . (int) $ok . ' ' . $info . ' (Kamera ' . $ac_kam . ')');
    $ergebnis = 'TIMELAPSE;OK=' . $ok . ';INFO=' . $info;
}
if (isset($_GET['cleanup'])) {
    $ac_weg_n = cam_cleanup();
    ac_weg('Archiv aufgeraeumt, ' . (int) $ac_weg_n . ' Aufnahmen entfernt');
    $ergebnis = 'CLEANUP;OK=1;INFO=' . $ac_weg_n . ' Aufnahmen entfernt';
}

$st = cam_state($ac_kam);

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    /* Ohne &kamera alle eingerichteten Kameras - eine Anlage mit zwei Kameras
       will beide sehen, und ein Aufrufer, der nur eine braucht, nennt sie. */
    if (!isset($_GET['kamera']) && count(cam_kameras()) > 1) {
        $ac_alle = array('kameras' => array(), 'ptest' => cam_ptest_active(),
                         'zeit' => date('c'));
        foreach (cam_kameras() as $ac_i) {
            $ac_s = cam_state($ac_i);
            $ac_s['push_aktiv'] = cam_push_active($ac_i);
            $ac_alle['kameras'][$ac_i] = $ac_s;
        }
        echo json_encode($ac_alle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }
    $st['ptest'] = cam_ptest_active();
    $st['push_aktiv'] = cam_push_active($ac_kam);
    echo json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
if ($ergebnis !== '') {
    echo $ergebnis . "\n";
}
// Eine Quelle fuer die Zeile: cam_zeile() baut sie aus cam_felder(), und
// dieselbe Tabelle erzeugt MQTT-Themen und Importdatei. Zwei Stellen, die
// dasselbe Format bauen, laufen sonst irgendwann auseinander.
echo cam_zeile($st, $ac_kam) . "\n";
if ($st['objekte']) {
    echo 'ERKANNT=' . implode(',', $st['objekte']) . "\n";
}
