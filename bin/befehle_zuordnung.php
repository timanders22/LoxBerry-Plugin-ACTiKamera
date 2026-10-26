<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie ACTi-Kamera (linieneigen).
 * Seit 1.9.32 (Gruppe 25, 26.10.2026; Entscheidung 2, Entwurf E-E1 bis E-E4). Bauart Ferien 1.2.29 /
 * Beschattungswaechter 0.9.31. Die Bruecke selbst (bin/befehlsbruecke.php, .sh, Stammfassung 1.0.1) ist
 * byte-gleich und wird hier nicht veraendert.
 *
 * Befehle (keiner eingreifend - Aufnehmen bewegt und schaltet nichts; einen zweiten Haken gibt es nicht, E-E2):
 *   foto            auswahl klingel|tuer|bewegung|alarm|manuell  Kamera 1, Wert = Anlass       quittung uebergeben
 *   foto/{kamera}   dasselbe fuer Kamera 1-4 (Kamera aus dem Thema)                            quittung uebergeben
 *   clip, clip/{kamera}  Bildserie, sonst wie foto                                             quittung uebergeben
 *   testpush        puls: PTEST fuer 5 Minuten und sofort ueber MQTT (dieselbe Funktion wie der Knopf)  ausgefuehrt
 * Annehmen nur mit beiden Haken: "Befehle ueber MQTT annehmen" (befehle_mqtt_ein, ab Werk aus) UND "Werte ueber
 * MQTT veroeffentlichen" (mqtt_enabled).
 * 'tun' fuer foto/clip prueft synchron (Kamera eingerichtet, Anlass, laufende Bildserie, Mindestpause) und startet
 * die Aufnahme ABGEKOPPELT (bin/cam_cron.php --aufnahme ..., cam_aufnahme_starten()): der Dauerdienst wartet nie
 * auf die Kamera, eine laufende Bildserie haelt kein Klingelfoto auf, und BB_WACHE_GRENZE (180 s) greift nie.
 * Sperre 5 s statt der Vorgabe 60 s fuer foto/clip (E-E4): ein zweites Klingeln nach 5 s soll ein zweites Bild
 * bekommen; bremsen tut die Mindestpause im Plugin, und die gilt fuer alle Wege (HTTP, e.php, MQTT).
 */
if (!function_exists('cam_config')) {
    $ac_kand = array();
    $ac_lb = (string) getenv('LBHOMEDIR');
    $ac_ordner = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    if ($ac_lb !== '' && $ac_ordner !== ''
        && !in_array($ac_ordner, array('.', '..', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend'), true)) {
        $ac_kand[] = $ac_lb . '/webfrontend/html/plugins/' . $ac_ordner . '/cam_lib.php';
    }
    if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'bin') {
        $ac_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/cam_lib.php';
    } else {
        $ac_kand[] = dirname(__DIR__) . '/webfrontend/html/cam_lib.php';
    }
    foreach ($ac_kand as $ac_k) {
        if (is_file($ac_k)) { require_once $ac_k; break; }
    }
}
$ac_um = function ($r) {
    return array(!empty($r['ok']), isset($r['grund']) ? (string) $r['grund'] : '');
};
$ac_anlaesse = array('klingel', 'tuer', 'bewegung', 'alarm', 'manuell');
return array(
    'fassung' => 1,
    'linie'   => 'actikamera',
    'plugin'  => 'ACTi Kamera',
    'geladen' => function_exists('cam_config') && function_exists('cam_mqtt_praefix')
                 && function_exists('cam_aufnahme_starten') && function_exists('cam_testpush'),
    'teile'   => array('kamera' => '[1-4]'),
    'einstellungen' => function () {
        $c = cam_config();
        return array('praefix'     => cam_mqtt_praefix($c),
                     'annehmen'    => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_enabled']),
                     'eingreifend' => false,
                     'mqtt_ein'    => !empty($c['mqtt_enabled']));
    },
    'protokoll' => function ($stufe, $text) { cam_log('Befehlsbruecke: ' . ($stufe === 'INFO' ? '' : $stufe . ': ') . $text); },
    'befehle' => array(
        'foto' => array('art' => 'auswahl', 'werte' => $ac_anlaesse, 'eingreifend' => false,
            'sperre_s' => 5, 'begruendung' => 'Klingel: ein zweites Klingeln nach 5 s soll ein zweites Bild bekommen; bremsen tut die Mindestpause im Plugin',
            'titel' => 'ACTi Kamera Bild aufnehmen (MQTT)', 'kachel' => 'BEFEHL.K_FOTO', 'quittung' => 'uebergeben',
            'tun' => function ($w, $t) use ($ac_um) { return $ac_um(cam_aufnahme_starten('foto', 1, $w, 'mqtt')); }),
        'foto/{kamera}' => array('art' => 'auswahl', 'werte' => $ac_anlaesse, 'eingreifend' => false,
            'sperre_s' => 5, 'begruendung' => 'wie foto',
            'titel' => 'ACTi Kamera Bild aufnehmen, Kamera n (MQTT)', 'kachel' => 'BEFEHL.K_FOTO_N', 'quittung' => 'uebergeben',
            'tun' => function ($w, $t) use ($ac_um) { return $ac_um(cam_aufnahme_starten('foto', (int) $t['kamera'], $w, 'mqtt')); }),
        'clip' => array('art' => 'auswahl', 'werte' => $ac_anlaesse, 'eingreifend' => false,
            'sperre_s' => 5, 'begruendung' => 'wie foto; eine zweite Bildserie derselben Kamera lehnt das Plugin ab, solange eine laeuft',
            'titel' => 'ACTi Kamera Bildserie aufnehmen (MQTT)', 'kachel' => 'BEFEHL.K_CLIP', 'quittung' => 'uebergeben',
            'tun' => function ($w, $t) use ($ac_um) { return $ac_um(cam_aufnahme_starten('clip', 1, $w, 'mqtt')); }),
        'clip/{kamera}' => array('art' => 'auswahl', 'werte' => $ac_anlaesse, 'eingreifend' => false,
            'sperre_s' => 5, 'begruendung' => 'wie clip',
            'titel' => 'ACTi Kamera Bildserie aufnehmen, Kamera n (MQTT)', 'kachel' => 'BEFEHL.K_CLIP_N', 'quittung' => 'uebergeben',
            'tun' => function ($w, $t) use ($ac_um) { return $ac_um(cam_aufnahme_starten('clip', (int) $t['kamera'], $w, 'mqtt')); }),
        'testpush' => array('art' => 'puls', 'eingreifend' => false,
            'titel' => 'ACTi Kamera Test-Push ausloesen (MQTT)', 'kachel' => 'BEFEHL.K_TESTPUSH', 'quittung' => 'ausgefuehrt',
            'tun' => function ($w, $t) use ($ac_um) { return $ac_um(cam_testpush('mqtt')); }),
    ),
);
