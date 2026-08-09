<?php
/**
 * Wird jede Minute aufgerufen und entscheidet selbst, was ansteht:
 *   - Zeitraffer-Bild zur eingestellten Uhrzeit (einmal taeglich)
 *   - Archiv-Bereinigung taeglich um 03:35
 *
 * WARUM NICHT AUF DIE MINUTE GENAU VERGLICHEN WIRD
 * Frueher stand hier  if ($jetzt === $soll ...)  - also ein Vergleich auf die
 * exakte Minute. Faellt der Minutentakt einmal aus, weil der LoxBerry gerade
 * ein Backup schreibt, ein anderes Plugin den Rechner beschaeftigt, die Uhr
 * per NTP springt oder das Geraet in dieser Minute gar nicht lief, dann ist
 * die Sollminute vorbei, bevor jemand hinsieht - und der Zeitraffer faellt
 * fuer den GANZEN TAG aus. Aufgefallen ist das nie, weil nichts fehlschlaegt;
 * es passiert einfach nichts.
 *
 * Deshalb wird jetzt nachgeholt: sobald die Sollzeit heute ueberschritten ist
 * und der Merker nicht auf dem heutigen Datum steht, wird ausgefuehrt. Der
 * Merker verhindert weiterhin die Mehrfachausfuehrung. Ein verpasster Takt
 * kostet damit hoechstens eine Minute Verspaetung statt eines Tages.
 */
require_once __DIR__ . '/cam_lib.php';

$cfg = cam_config();
$p = cam_paths();
if (!is_dir($p['tmp'])) {
    @mkdir($p['tmp'], 0775, true);
}
$heute = date('Y-m-d');
$jetzt_min = (int) date('H') * 60 + (int) date('i');

/**
 * Ist die Aufgabe heute faellig und noch nicht gelaufen?
 * Setzt den Merker gleich mit, damit zwei gleichzeitige Laeufe nicht beide
 * loslegen - der Merker wird VOR der Arbeit geschrieben, nicht danach.
 */
function cam_faellig($merkerdatei, $soll_min, $jetzt_min, $heute)
{
    if ($jetzt_min < $soll_min) {
        return false;
    }
    $letzter = is_file($merkerdatei) ? trim((string) @file_get_contents($merkerdatei)) : '';
    if ($letzter === $heute) {
        return false;
    }
    return @file_put_contents($merkerdatei, $heute, LOCK_EX) !== false;
}

/* ---------------- Zeitraffer ---------------- */
if (!empty($cfg['timelapse']) && preg_match('/^\d{1,2}:\d{2}$/', (string) $cfg['timelapse_time'])) {
    $t = array_map('intval', explode(':', (string) $cfg['timelapse_time']));
    $soll_min = min(23, $t[0]) * 60 + min(59, $t[1]);
    if (cam_faellig($p['tmp'] . '/timelapse_am.txt', $soll_min, $jetzt_min, $heute)) {
        cam_timelapse();
    }
}

/* ---------------- Archiv-Bereinigung um 03:35 ---------------- */
if (cam_faellig($p['tmp'] . '/cleanup_am.txt', 3 * 60 + 35, $jetzt_min, $heute)) {
    cam_cleanup();
}

/* ---------------- Zustand nach MQTT ----------------
 * Der Minutentakt ist auch der Herzschlag fuer die Statuswerte: nur so
 * bekommt Loxone mit, dass die Kamera seit einer Stunde schweigt. Gesendet
 * wird ausschliesslich, was sich geaendert hat (siehe cam_mqtt_zustand). */
cam_mqtt_zustand();
