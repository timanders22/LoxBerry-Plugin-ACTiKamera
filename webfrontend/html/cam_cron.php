<?php
/**
 * Wird jede Minute aufgerufen und entscheidet selbst, was ansteht:
 *   - Zeitraffer-Bild zur eingestellten Uhrzeit (einmal taeglich)
 *   - Archiv-Bereinigung taeglich um 03:35
 */
require_once __DIR__ . '/cam_lib.php';

$cfg = cam_config();
$p = cam_paths();
if (!is_dir($p['tmp'])) {
    @mkdir($p['tmp'], 0775, true);
}
$jetzt = date('H:i');
$heute = date('Y-m-d');

// Zeitraffer
if (!empty($cfg['timelapse']) && preg_match('/^\d{1,2}:\d{2}$/', (string) $cfg['timelapse_time'])) {
    $soll = sprintf('%02d:%02d', ...array_map('intval', explode(':', (string) $cfg['timelapse_time'])));
    $merker = $p['tmp'] . '/timelapse_am.txt';
    $letzter = is_file($merker) ? trim((string) file_get_contents($merker)) : '';
    if ($jetzt === $soll && $letzter !== $heute) {
        @file_put_contents($merker, $heute);
        cam_timelapse();
    }
}

// Archiv-Bereinigung um 03:35
$merker2 = $p['tmp'] . '/cleanup_am.txt';
$letzter2 = is_file($merker2) ? trim((string) file_get_contents($merker2)) : '';
if ($jetzt === '03:35' && $letzter2 !== $heute) {
    @file_put_contents($merker2, $heute);
    cam_cleanup();
}
