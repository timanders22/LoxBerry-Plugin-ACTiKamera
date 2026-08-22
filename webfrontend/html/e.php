<?php
/**
 * ACTi Kamera - kurzer Ausloeser fuer Geraete, die selbst aufrufen
 *
 *   e.php?t=<Token>            Bild aufnehmen, Anlass "bewegung"
 *   e.php?t=<Token>&a=klingel  anderer Anlass
 *   e.php?t=<Token>&c=1        Bildserie statt Einzelbild
 *
 * WARUM ES DIESE DATEI GIBT
 * Nicht wegen der Laenge. Der ACTi-Web-Konfigurator verteilt die Adresse auf
 * zwei Felder, und das zweite ist grosszuegig (am Geraet gemessen):
 *
 *   Ereignis - Ereignis-Server - HTTP-Server 1
 *       nur der Rechner, z. B. 192.168.178.14      64 Zeichen
 *   Ereignis - Ereignis-Setup - URL-Befehle senden
 *       Pfad und Anhang                           255 Zeichen
 *
 * Auch /plugins/actikamera/cam.php?foto=1&anlass=bewegung&token=<32> passt
 * mit 89 Zeichen also hinein. Wer frueher die ganze Adresse in das erste Feld
 * schrieb, sah sie nach 64 Zeichen abbrechen - daher der alte Irrtum.
 *
 * WARUM ES DIESE DATEI TROTZDEM GIBT
 * Das Ausloese-Token darf ausschliesslich aufnehmen. Es kann das Archiv nicht
 * aufraeumen und nicht die Diagnose lesen, die Laenge sowie erstes und letztes
 * Zeichen des Kamerapassworts nennt. Wer ein Token in ein fremdes Geraet legt,
 * gibt ihm nur, was es braucht. Das Aktionstoken koennte beides.
 *
 * Und es gehoert je Kamera einzeln: das Token sagt selbst, welche gemeint ist,
 * ohne dass ein &k=2 in der Adresse steht.
 *
 * PROTOKOLL
 * Jeder Weg durch diese Datei schreibt eine Zeile - auch die Abweisung. Ein
 * fremdes Geraet kann nicht klagen; bleibt eine Aufnahme aus, ist das
 * Protokoll die einzige Stelle, an der steht, ob es angerufen hat und was
 * ihm geantwortet wurde. Das Token steht nie darin.
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

/* Wer hat angerufen. Aus dem Webserver, nicht aus der Anfrage - und trotzdem
   auf die Zeichen beschraenkt, die in einer Adresse vorkommen duerfen. */
$ac_ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
$ac_ip = preg_replace('/[^0-9A-Fa-f:.]/', '', $ac_ip);
if ($ac_ip === '') {
    $ac_ip = 'unbekannt';
}

$ac_kam = cam_ausloeser_kamera(isset($_GET['t']) ? (string) $_GET['t'] : '');
if ($ac_kam < 1) {
    /* Fail closed, und gegenueber dem Anrufer ohne Auskunft darueber, WAS
       falsch war: wer raten muss, erfaehrt hier nicht, ob es die Kamera oder
       das Token war. Im Protokoll steht es deutlich - dort liest der
       Betreiber mit, nicht der Fremde. */
    cam_log('e.php: abgewiesen, Token unbekannt oder nicht eingerichtet'
            . ' (Aufrufer ' . $ac_ip . ')');
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
        /* Der abgewiesene Wert kommt von aussen und gehoert nicht ungeprueft
           in eine Datei - die Laenge sagt genug, um den Fehler zu finden. */
        cam_log('e.php: abgewiesen, Anlass unzulaessig, ' . strlen($ac_roh)
                . ' Zeichen (Kamera ' . $ac_kam . ', Aufrufer ' . $ac_ip . ')');
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
    cam_log('e.php: Mindestpause, noch ' . (int) $ac_rest . ' s'
            . ' (Kamera ' . $ac_kam . ', Anlass ' . $ac_anlass
            . ', Aufrufer ' . $ac_ip . ')');
    echo 'ACTI;OK=0;ERR=PAUSE;REST=' . (int) $ac_rest . "\n";
    exit;
}
cam_pause_setzen($ac_kam);

if (isset($_GET['c'])) {
    list($ac_ok, $ac_info) = cam_clip($ac_anlass, $ac_kam);
    cam_log('e.php: Bildserie OK=' . (int) $ac_ok . ' ' . $ac_info
            . ' (Kamera ' . $ac_kam . ', Anlass ' . $ac_anlass
            . ', Aufrufer ' . $ac_ip . ')');
    echo 'CLIP;OK=' . (int) $ac_ok . ';INFO=' . $ac_info . "\n";
    exit;
}

list($ac_ok, $ac_info) = cam_snapshot($ac_anlass, $ac_kam);
cam_log('e.php: Bild OK=' . (int) $ac_ok . ' ' . $ac_info
        . ' (Kamera ' . $ac_kam . ', Anlass ' . $ac_anlass
        . ', Aufrufer ' . $ac_ip . ')');
echo 'FOTO;OK=' . (int) $ac_ok . ';INFO=' . $ac_info . "\n";
