<?php
/**
 * ACTi Kamera - kurzer Ausloeser fuer Geraete mit knappem Adressfeld
 *
 *   e.php?t=<Token>            Bild aufnehmen, Anlass "bewegung"
 *   e.php?t=<Token>&a=klingel  anderer Anlass
 *   e.php?t=<Token>&c=1        Bildserie statt Einzelbild
 *
 * WARUM ES DIESE DATEI GIBT
 * Der ACTi-Web-Konfigurator nimmt unter Ereignis - Ereignis-Server -
 * HTTP-Server nur eine begrenzte Adresse entgegen: gemessen brach die Eingabe
 * nach 63 Zeichen ab, das Feld fasst also 64. Die Adresse ueber cam.php mit
 * dem 32-stelligen Aktionstoken braucht 110 Zeichen und passt nie hinein.
 *
 *   http://192.168.178.14/plugins/actikamera/e.php?t=abcdefghjkmn
 *   `-------------------------- 61 Zeichen --------------------------'
 *
 * WARUM EIN EIGENES TOKEN
 * Das Ausloese-Token darf ausschliesslich aufnehmen. Es kann das Archiv nicht
 * aufraeumen und nicht die Diagnose lesen, die Laenge sowie erstes und letztes
 * Zeichen des Kamerapassworts nennt. Wer ein Token in ein fremdes Geraet legt,
 * gibt ihm nur, was es braucht.
 *
 * Und es gehoert je Kamera einzeln: das Token sagt selbst, welche gemeint ist.
 * Ein zusaetzliches &k=2 haette die Adresse auf 65 Zeichen gebracht - eins zu
 * viel.
 */

require_once __DIR__ . '/cam_lib.php';

/* Die Aufnahme zu Ende bringen, auch wenn der Anrufer sofort auflegt.
 * Der ACTi-Konfigurator bietet eine Max. Verbindungszeit von 0 Sekunden an;
 * ein Geraet, das ausloest und die Verbindung gleich wieder zumacht, wuerde
 * sonst mitten in der Objekterkennung oder im Webhook abgeschnitten. Die
 * Laufzeit ist ohnehin gedeckelt: die Bildsuche bricht nach dem Dreifachen
 * des Zeitlimits ab. */
ignore_user_abort(true);

header('Content-Type: text/plain; charset=utf-8');

$ac_kam = cam_ausloeser_kamera(isset($_GET['t']) ? (string) $_GET['t'] : '');
if ($ac_kam < 1) {
    /* Fail closed, und ohne Auskunft darueber, WAS falsch war: ein Aufrufer,
       der raten muss, erfaehrt hier nicht, ob es die Kamera oder das Token
       war. */
    header('HTTP/1.1 403 Forbidden');
    echo "ACTI;OK=0;ERR=TOKEN\n";
    exit;
}

/* Anlass: dasselbe enge Muster wie in cam.php. Was nicht hineinpasst, wird
   abgewiesen und nicht zurechtgebogen - ein stillschweigend geaenderter
   Anlass stuende spaeter im Dateinamen und niemand wuesste warum. */
$ac_anlass = 'bewegung';
if (isset($_GET['a'])) {
    $ac_roh = (string) $_GET['a'];
    if (!preg_match('/^[a-z0-9]{1,20}$/i', $ac_roh)) {
        header('HTTP/1.1 400 Bad Request');
        echo "ACTI;OK=0;ERR=ANLASS\n";
        exit;
    }
    $ac_anlass = $ac_roh;
}

/* Mindestpause. Eine Kamera, die selbst ausloest, kann das im Sekundentakt
   tun - der ACTi-Konfigurator schlaegt ein Ausloesungsintervall von einer
   Sekunde vor. Die Pause wird GEMELDET, nicht verschwiegen: ein Endpunkt, der
   wortlos nichts tut, schickt den Anwender auf die Suche nach einem Fehler,
   den es nicht gibt. */
$ac_rest = cam_pause_rest($ac_kam);
if ($ac_rest > 0) {
    echo 'ACTI;OK=0;ERR=PAUSE;REST=' . (int) $ac_rest . "\n";
    exit;
}
cam_pause_setzen($ac_kam);

if (isset($_GET['c'])) {
    list($ac_ok, $ac_info) = cam_clip($ac_anlass, $ac_kam);
    echo 'CLIP;OK=' . (int) $ac_ok . ';INFO=' . $ac_info . "\n";
    exit;
}

list($ac_ok, $ac_info) = cam_snapshot($ac_anlass, $ac_kam);
echo 'FOTO;OK=' . (int) $ac_ok . ';INFO=' . $ac_info . "\n";
