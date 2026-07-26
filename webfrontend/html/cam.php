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

$anlass = isset($_GET['anlass']) ? preg_replace('/[^a-z0-9]/i', '', (string) $_GET['anlass']) : 'manuell';
if ($anlass === '') {
    $anlass = 'manuell';
}

if (isset($_GET['letztes'])) {
    $p = cam_paths();
    $f = $p['web'] . '/letztesbild.jpg';
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
        list($ac_a, $ac_c, $ac_e2) = cam_system($ac_b);
        $ac_t = $ac_a === false ? ('FEHLER: ' . $ac_e2) : trim(cam_maske((string) $ac_a));
        if (strlen($ac_t) > 400) { $ac_t = substr($ac_t, 0, 400) . ' …'; }
        printf("%-22s HTTP %-4d %s\n", $ac_b, $ac_c, str_replace(array("\r", "\n"), ' ', $ac_t));
    }
    echo str_repeat('-', 78) . "\n";
    $ac_ff = cam_ffmpeg();
    echo 'ffmpeg: ' . ($ac_ff !== '' ? $ac_ff : 'nicht vorhanden') . "\n";
    echo 'Kamerastrom: ' . cam_maske(cam_mjpeg_url()) . "\n";
    echo 'RTSP-Adresse: ' . (cam_rtsp_maske(cam_rtsp_url()) ?: 'nicht ermittelbar') . "\n";
    exit;
}

if (isset($_GET['diag'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $d_cfg = cam_config();
    echo "DIAGNOSE\n";
    echo 'Gebaute URL: ' . cam_maske(cam_url('SNAPSHOT')) . "\n";
    echo 'Benutzer:    "' . $d_cfg['user'] . '" (' . strlen($d_cfg['user']) . " Zeichen)\n";
    echo 'Passwort:    ' . strlen($d_cfg['pass']) . ' Zeichen'
       . ($d_cfg['pass'] !== '' ? ', beginnt mit "' . substr($d_cfg['pass'], 0, 1) . '", endet auf "'
         . substr($d_cfg['pass'], -1) . '"' : ' - LEER!') . "\n";
    echo "\nVergleichen Sie diese Angaben mit einer nachweislich funktionierenden URL\n"
       . "(z. B. aus einer bestehenden Kamera-Einbindung). Weichen Benutzer oder Laenge ab,\n"
       . "ist das die Ursache - dann die vollstaendige URL in den Einstellungen hinterlegen.\n";
    echo str_repeat('-', 100) . "\n";
    foreach (cam_diag() as $z) {
        printf("%-4s HTTP %-4s %5s ms  %-9s %-42s %s\n",
            $z['ok'] ? 'OK' : '--', $z['http'], $z['ms'], $z['auth'],
            substr($z['befehl'], 0, 42), $z['antwort']);
    }
    echo "\nEine Zeile mit OK zeigt den Weg, der funktioniert - er wird automatisch gemerkt.\n";
    exit;
}

if (isset($_GET['test'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo cam_test() . "\n";
    exit;
}

if (isset($_GET['ptest'])) {
    @mkdir(cam_paths()['tmp'], 0775, true);
    @file_put_contents(cam_paths()['tmp'] . '/ptest', time());
    header('Content-Type: text/plain; charset=utf-8');
    echo "PTEST;OK=1;DAUER=300\nHinweis: Loxone pollt zyklisch - die Push-Nachricht kommt innerhalb von 5 Minuten,\n"
       . "sofern der Test-Benachrichtigungsbaustein laut Anleitung verdrahtet ist.\n";
    exit;
}

function cam_ptest_active()
{
    $f = cam_paths()['tmp'] . '/ptest';
    if (!is_file($f)) {
        return 0;
    }
    if (time() - (int) file_get_contents($f) > 300) {
        @unlink($f);
        return 0;
    }
    return 1;
}

$ergebnis = '';
if (isset($_GET['foto'])) {
    list($ok, $info) = cam_snapshot($anlass);
    $ergebnis = 'FOTO;OK=' . $ok . ';INFO=' . $info;
}
if (isset($_GET['clip'])) {
    list($ok, $info) = cam_clip($anlass);
    $ergebnis = 'CLIP;OK=' . $ok . ';INFO=' . $info;
}
if (isset($_GET['timelapse'])) {
    list($ok, $info) = cam_timelapse();
    $ergebnis = 'TIMELAPSE;OK=' . $ok . ';INFO=' . $info;
}
if (isset($_GET['cleanup'])) {
    $ergebnis = 'CLEANUP;OK=1;INFO=' . cam_cleanup() . ' Aufnahmen entfernt';
}

$st = cam_state();

if (isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    $st['ptest'] = cam_ptest_active();
    $st['push_aktiv'] = cam_push_active();
    echo json_encode($st, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
if ($ergebnis !== '') {
    echo $ergebnis . "\n";
}
printf("ACTI;OK=%d;ALTER=%d;BILDER=%d;CLIPS=%d;ZEITRAFFER=%d;PERSON=%d;OBJEKTE=%d;PUSH=%d;PUSHAKTIV=%d;PTEST=%d\n",
    $st['ok'], $st['alter_min'], $st['bilder'], $st['clips'], $st['timelapse'],
    $st['person'], count($st['objekte']),
    $st['push'], cam_push_active(), cam_ptest_active());
if ($st['objekte']) {
    echo 'ERKANNT=' . implode(',', $st['objekte']) . "\n";
}
