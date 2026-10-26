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
 *   ?video=1           -> das Zeitraffer-Video (video/mp4, seit 1.9.32; Stromkennwort wie ?bild)
 *
 * Seit 1.9.32 (Entscheidung 2, E-E12): die Statuszeile und ?json=1 - der
 * Lesezweig fuer Loxone - antworten nur mit dem Haken endpunkt_ein (Update:
 * an, Neuinstallation: aus, Werte dann ueber MQTT); sonst 403
 * ACTI;OK=0;ERR=ENDPUNKT_AUS. Aktionen mit Aktionstoken, der Bildabruf, das
 * Video, cam_stream.php und e.php haengen nicht an diesem Haken.
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

/* Eine gespeicherte Aufnahme oder das letzte Bild ausliefern.
 *
 * Lesender Aufruf, deshalb ohne Aktionstoken - er aendert nichts. Ist aber ein
 * Stromkennwort hinterlegt, gilt es hier ebenso: es sind Bilder der eigenen
 * Haustuer, und wer den Strom schuetzt, will auch das Archiv geschuetzt haben.
 *
 * ?letztes=1 stand bis 1.9.18 AUSSERHALB dieses Tors. Am 06.09.2026 am Geraet
 * gemessen: bei gesetztem Stromkennwort antwortete ?bild=... mit 403,
 * ?letztes=1 aber mit 200 und 704 137 Byte - dem aktuellen Bild der Haustuer,
 * von jedem Geraet im Heimnetz, ohne Anmeldung. Die Sprachdatei hielt die
 * Adresse mit Token (TEXT.CAM_PHP_LETZTES_1T) schon bereit, benutzt hat sie
 * niemand. Das Tor gilt weiter nur, WENN ein Stromkennwort gesetzt ist - ohne
 * eines aendert sich nichts.
 */
if (isset($_GET['bild']) || isset($_GET['serie']) || isset($_GET['zeitraffer'])
    || isset($_GET['letztes']) || isset($_GET['video'])) {
    $ac_scfg = cam_config();
    $ac_stok = (string) $ac_scfg['stream_token'];
    if ($ac_stok !== '' && !hash_equals($ac_stok, ac_get('t'))) {
        ac_weg('Bildabruf abgewiesen, Stromkennwort falsch oder fehlend');
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Zugriff verweigert: falsches oder fehlendes Token.\n";
        exit;
    }
    /* Das letzte Bild ist keine Archivdatei: es liegt unter der festen Adresse
       im Webordner und wird bei jeder Aufnahme ueberschrieben. Deshalb hier
       heraus, bevor cam_archivdatei() danach sucht. */
    if (isset($_GET['letztes'])) {
        /* Massgeblich ist seit 1.9.19 die Ablage im Archiv - sie ueberlebt das
           Update. Die Datei im Webordner ist der Rueckfall fuer den ersten
           Lauf nach der Aktualisierung, solange noch keine neue Aufnahme
           entstanden ist. */
        $ac_f = cam_letztesbild_archiv($ac_kam);
        if (!is_file($ac_f)) {
            $ac_f = cam_letztesbild_web($ac_kam);
        }
        if (is_file($ac_f)) {
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . filesize($ac_f));
            header('Cache-Control: no-store');
            readfile($ac_f);
            exit;
        }
        ac_weg('Letztes Bild abgerufen, es gibt noch keines (Kamera ' . $ac_kam . ')');
        header('HTTP/1.1 404 Not Found');
        header('Content-Type: text/plain; charset=utf-8');
        echo "Noch kein Bild vorhanden.\n";
        exit;
    }
    /* Das Zeitraffer-Video (seit 1.9.32, A9/E3): es liegt im Archiv, nicht mehr
       offen im Webordner - die alte Adresse /plugins/<ordner>/zeitraffer.mp4
       antwortet seit dem Update mit 404. Hier gilt das Stromkennwort wie fuer
       jede Aufnahme. */
    if (isset($_GET['video'])) {
        $ac_v = cam_zeitraffer_video($ac_kam);
        if ($ac_v === '') {
            ac_weg('Zeitraffer-Video abgerufen, es gibt noch keines (Kamera ' . $ac_kam . ')');
            header('HTTP/1.1 404 Not Found');
            header('Content-Type: text/plain; charset=utf-8');
            echo "Noch kein Zeitraffer-Video vorhanden.\n";
            exit;
        }
        header('Content-Type: video/mp4');
        header('Content-Length: ' . filesize($ac_v));
        header('Cache-Control: private');
        readfile($ac_v);
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

/* sys und diag: dieselbe Ausgabe wie der Reiter Test (index.php?ansicht=...,
   angemeldet, ohne Token in der Adresse - seit 1.9.32, E-E17). Die Zweige hier
   bleiben fuer Altlinks, mit Aktionstoken. */
if (isset($_GET['sys'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo cam_ansicht_sys($ac_kam);
    exit;
}

if (isset($_GET['diag'])) {
    header('Content-Type: text/plain; charset=utf-8');
    echo cam_ansicht_diag($ac_kam);
    exit;
}

if (isset($_GET['test'])) {
    ac_weg('Verbindungstest (Kamera ' . $ac_kam . ')');
    header('Content-Type: text/plain; charset=utf-8');
    echo cam_test($ac_kam) . "\n";
    exit;
}

/* Seit 1.9.32 ueber cam_testpush() - dieselbe Stelle wie der Knopf im Reiter
   Test und die Befehlsbruecke: Merker, Protokollzeile, sofort ueber MQTT
   (A10). */
if (isset($_GET['ptest'])) {
    $ac_pt = cam_testpush('http');
    header('Content-Type: text/plain; charset=utf-8');
    if (!$ac_pt['ok']) {
        header('HTTP/1.1 500 Internal Server Error');
        echo "PTEST;OK=0;ERR=MERKER\n";
        exit;
    }
    echo "PTEST;OK=1;DAUER=300\nHinweis: ueber MQTT kommt PTEST sofort; der HTTP-Eingang in Loxone liest es mit\n"
       . "der naechsten Abfrage. Die Push-Nachricht kommt, sofern der Test-Benachrichtigungsbaustein laut\n"
       . "Anleitung verdrahtet ist.\n";
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

/* Das Tor des Lesezweigs (seit 1.9.32, Entscheidung 2, E-E12): ohne den Haken
   endpunkt_ein antworten Statuszeile und ?json nicht - die Werte kommen ueber
   MQTT. Eine Aktion mit Aktionstoken antwortet dann nur mit ihrer
   Ergebniszeile, ohne den Statusanhang. Die Protokollzeile ist gebremst
   (einmal je Stunde), sonst schriebe ein weiter abfragender virtueller
   HTTP-Eingang jede Minute eine. */
$ac_c = cam_config();
if (!cam_endpunkt_ein($ac_c)) {
    if ($ergebnis !== '') {
        header('Content-Type: text/plain; charset=utf-8');
        echo $ergebnis . "\n";
        exit;
    }
    cam_log_selten('endpunkt_aus', 'cam.php: Lesezweig abgewiesen (endpunkt_ein = 0; Aufrufer ' . $ac_ip
        . ') - die Werte kommen ueber MQTT; einschalten im Reiter Einbindung, Abschnitt Bestehende Anlagen.');
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain; charset=utf-8');
    echo (isset($_GET['json']) ? '{"OK":0,"ERR":"ENDPUNKT_AUS"}' : 'ACTI;OK=0;ERR=ENDPUNKT_AUS') . "\n";
    exit;
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
