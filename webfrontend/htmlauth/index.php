<?php
/**
 * ACTi Kamera - Admin-Oberflaeche
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Aufnahmen | Test | Protokoll
 * Seit 1.9.32 (Gruppe 25): Werte ueber das MQTT-Gateway (Namenstabelle), Befehle ueber die Befehlsbruecke,
 * HTTP-Lesezweig nur noch unter "Bestehende Anlagen", Test-Push als Formular, Ansichten ohne Token.
 *
 * WICHTIG: LBWeb::lbheader() setzt SDK-GLOBALS (u.a. $cfg als stdClass) und
 * wuerde gleichnamige Plugin-Variablen ueberschreiben - daher tragen hier
 * ALLE Variablen ein ac_-Praefix.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
/* display_errors wird NICHT erzwungen. Bis 1.9.16 stand hier
   ini_set('display_errors', '1'); damit landete bei jedem Fehler der
   absolute Serverpfad im Browser. Was der Betreiber sehen will, stellt er
   in seiner php.ini ein. */

/* Die Bibliothek liegt im anderen Baum. Installiert oder Archiv entscheidet
 * der eigene Ablageort: liegt diese Datei unter .../htmlauth/plugins/<ordner>,
 * ist sie installiert, und die Bibliothek steht unter
 * .../html/plugins/<ordner>/. Sonst ist das ein Archiv, und es gilt die
 * Bibliothek daneben.
 *
 * Bis 1.9.21 stand der installierte Kandidat auch im Archiv VOR der eigenen
 * Bibliothek - aus einem Archiv unter / war das /html/plugins/htmlauth/
 * cam_lib.php ab der Laufwerkswurzel, und was dort lag, lief als Bibliothek
 * (in WSL gemessen, Pruefung-ACTiKamera-1.9.22, Faelle T3 und T9). Dazu
 * bestimmte diese Datei die Wurzel mit einer eigenen, schwaecheren Suche
 * (ohne general.json), die wegen der gleichnamigen Wache in cam_lib.php auch
 * dort galt. Wurzel, Ordner und Protokolldatei
 * kommen jetzt allein aus cam_paths().
 */
if (basename(dirname(__DIR__)) === 'plugins' && basename(dirname(dirname(__DIR__))) === 'htmlauth') {
    $ac_kandidaten = array(dirname(dirname(dirname(__DIR__))) . '/html/plugins/'
                         . basename(__DIR__) . '/cam_lib.php');
} else {
    $ac_kandidaten = array(dirname(__DIR__) . '/html/cam_lib.php');
}
foreach ($ac_kandidaten as $ac_cand) {
    if (is_file($ac_cand)) { require_once $ac_cand; break; }
}
/* Findet keiner etwas, wird hier abgebrochen und gesagt, WO gesucht wurde.
 * Bis 1.9.16 lief die Datei weiter: die function_exists-Wachen weiter unten
 * versprachen ein sanftes Scheitern, das es nicht gab - die Seite starb an
 * einem ungesicherten Uebersetzungsaufruf mit Stapelabzug und Serverpfaden. */
if (!function_exists('cam_t') || !function_exists('cam_paths')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>ACTi Kamera</h2><p><b>Fehler:</b> cam_lib.php nicht gefunden.</p>'
       . '<p>Gesucht wurde in:</p><ul>';
    foreach ($ac_kandidaten as $ac_cand) {
        echo '<li>' . htmlspecialchars($ac_cand, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    echo '</ul>';
    exit;
}

$ac_pfade = cam_paths();
$ac_lbhome = $ac_pfade['lbhome'];
$ac_plugin = $ac_pfade['ordner'];
/* Der LoxBerry-Rahmen nur aus der Wurzel, in der das Plugin wirklich liegt
 * (oder die ausdruecklich genannt ist) - im Archivmodus gar nicht. */
if ($ac_lbhome !== '' && is_file($ac_lbhome . '/libs/phplib/loxberry_system.php')) {
    require_once $ac_lbhome . '/libs/phplib/loxberry_system.php';
    if (is_file($ac_lbhome . '/libs/phplib/loxberry_web.php')) {
        require_once $ac_lbhome . '/libs/phplib/loxberry_web.php';
    }
}
$ac_logfile = $ac_pfade['log'];

/** Einen POST-Wert holen - erst is_string, dann alles andere. */
function ac_post($name, $vorgabe = '')
{
    return (isset($_POST[$name]) && is_string($_POST[$name])) ? $_POST[$name] : $vorgabe;
}

/**
 * Ein Satz, der die drei Aufbewahrungsgrenzen gegen den Archivbestand haelt.
 *
 * Steht an zwei Stellen: dauerhaft im Reiter Aufnahmen und in der Meldung
 * nach dem Knopf "Alte Aufnahmen jetzt aufraeumen". EINE Quelle, damit beide
 * nicht auseinanderlaufen.
 */
function ac_grenzen()
{
    if (!function_exists('cam_aufraeum_lage')) {
        return '';
    }
    $l = cam_aufraeum_lage();
    $un = cam_t('TEXT.UNBEGRENZT');
    $ac_satz = sprintf(
        cam_t('TEXT.GRENZEN'),
        $l['tage'] > 0 ? sprintf(cam_t('TEXT.G_TAGE'), $l['tage']) : $un,
        $l['anzahl'] > 0 ? sprintf(cam_t('TEXT.G_DATEIEN'), $l['anzahl']) : $un,
        $l['mb'] > 0 ? sprintf(cam_t('TEXT.G_MB'), $l['mb']) : $un,
        (int) $l['dateien'],
        (int) round($l['bytes'] / 1048576),
        $l['alter_tage'] >= 0 ? sprintf(cam_t('TEXT.G_ALTER'), $l['alter_tage'])
                              : cam_t('TEXT.G_KEINE')
    );
    /* Solange nichts gespeichert ist, sind die drei Zahlen Vorschlaege: die
       Bereinigung laesst sie ausdruecklich aus (cam_config_eingerichtet).
       Ohne diesen Satz verspraeche der Reiter eine Aufbewahrung von 90 Tagen,
       die nicht stattfindet - und zwei Zahlen, die nicht zusammenpassen, sind
       ein Befund, auch wenn beide gruen aussehen. */
    if (function_exists('cam_config_eingerichtet') && !cam_config_eingerichtet()) {
        $ac_satz .= ' ' . cam_t('TEXT.G_NICHT_EINGERICHTET');
    }
    return $ac_satz;
}

$ac_saved = false; $ac_note = ''; $ac_err = '';
/* X-2: die abgewiesenen Eingaben eines Formulars (cam_eingaben_sammeln()). */
$ac_eingaben = null;
/* Ob eine Meldung rot oder gruen erscheint, entscheidet dieser Schalter -
   nicht die Suche nach einem deutschen Wort im Text (bis 1.9.16). */
$ac_note_rot = false;

/* Waehrend einer Aktualisierung speichert diese Seite NICHTS.
 *
 * Zwischen den neuen Dateien und postinstall.sh ist cam.json das "{}" aus dem
 * Archiv; die eingestellten Werte liegen nur in der Zweitschrift. Bis 1.9.19
 * schrieb ein Aufruf dieser Seite in der Zeit alle 87 Schluessel - samt einem
 * frischen Aktionstoken und keep_days 90 - nach cam.json UND in die
 * Zweitschrift (gemessen in WSL am 17.09.2026,
 * Pruefung-ACTiKamera-1.9.20/messe_oberflaeche.sh, Fall marke_frisch). Die
 * Konfiguration galt danach als eingerichtet, und die Zweitschrift trug
 * Vorgaben mit fremdem Token.
 *
 * Solange die Marke aus preupgrade.sh gilt, zeigt die Seite nur einen
 * Hinweis. Das steht VOR jedem Handler: auch ein Formular von vorher wird
 * nicht mehr angenommen. */
if (function_exists('cam_upgrade_laeuft') && cam_upgrade_laeuft()) {
    /* Kopf und Fuss wie weiter unten: EINMAL feststellen, beides daran
       haengen. Ohne den Rahmen ein vollstaendiges Dokument, sonst steht der
       Hinweis als nacktes Textstueck im Browser. */
    $ac_rahmen = class_exists('LBWeb', false);
    if ($ac_rahmen) {
        LBWeb::lbheader('ACTi Kamera', 'https://wiki.loxberry.de', 'help.html');
    } else {
        echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>ACTi Kamera</title></head><body>';
    }
    echo '<div style="max-width:1100px;margin:0 auto;padding:0 10px 40px;">' . "\n"
       . '<h2>' . ac_e(cam_t('TEXT.ACTI_KAMERA')) . '</h2>' . "\n"
       . '<p><b>' . ac_e(cam_t('TEXT.UPGRADE_LAEUFT')) . '</b> '
       . ac_e(cam_t('TEXT.UPGRADE_LAEUFT_TEXT')) . '</p>' . "\n"
       . '</div>' . "\n";
    if ($ac_rahmen) {
        LBWeb::lbfooter();
    } else {
        echo '</body></html>';
    }
    exit;
}

/* Aktionstoken beim ersten Aufruf erzeugen.
 *
 * Das gehoert in den ANGEMELDETEN Bereich - der unangemeldete Endpunkt darf
 * nichts anlegen. Erzeugt wird nur, wenn noch keines dasteht; jedes Speichern
 * traegt es weiter, weil beide Handler auf cam_config() aufbauen.
 *
 * GESCHRIEBEN WIRD AUF DEM ROHSTAND, NICHT AUF cam_config().
 * cam_config() ergaenzt alle Vorgaben; wer sie zurueckschreibt, hat beim
 * blossen Oeffnen der Seite keep_days 90 festgelegt, ohne dass jemand eine
 * Aufbewahrungsgrenze gewaehlt hat (CLAUDE.md 4: ein stiller Vorgabewert ist
 * eine Annahme). Gemessen in WSL am 17.09.2026 (messe_oberflaeche.sh, Fall
 * Neuinstallation/mit_altem_archiv): nach dem ersten Oeffnen loeschte der
 * naechste Minutentakt zwei Aufnahmen aus dem Archiv einer frueheren
 * Installation. In cam.json landen deshalb nur die beiden Token; alles
 * andere kommt erst beim ausdruecklichen Speichern hinein.
 *
 * cam_config() steht trotzdem davor: sie heilt aus der Zweitschrift, bevor
 * hier ein Token entstehen kann. */
if (function_exists('cam_config') && function_exists('cam_token_erzeugen')) {
    $ac_start = cam_config();
    $ac_roh = cam_config_roh();
    $ac_neu = false;
    if ((string) $ac_start['aktionstoken'] === '') {
        $ac_roh['aktionstoken'] = cam_token_erzeugen();
        $ac_neu = true;
    }
    /* Je Kamera ein kurzes Ausloese-Token - auch fuer Kameras, die es noch
       nicht gibt: sonst stuende die Adresse erst nach einem zweiten Speichern
       da, und niemand versteht warum. */
    for ($ac_i = 1; $ac_i <= CAM_MAX; $ac_i++) {
        $ac_sl = $ac_i > 1 ? (string) $ac_i : '';
        if ((string) $ac_start['ausloeser_token' . $ac_sl] === '') {
            $ac_roh['ausloeser_token' . $ac_sl] = cam_kurztoken();
            $ac_neu = true;
        }
    }
    if ($ac_neu) {
        cam_config_save($ac_roh);
    }
}

/* EINE zentrale Pruefung gegen fremde Absender, vor jedem Handler - einen
 * einzelnen Handler kann man vergessen. Faellt sie durch, wird der POST
 * zurueckgenommen UND gemeldet: ein Formular, das wortlos nichts tut,
 * schickt den Anwender auf die Suche nach einem Fehler, den es nicht gibt.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('cam_formtoken')) {
    $ac_fsoll = cam_formtoken();
    /* is_string ZUERST: ?formtoken[]=x waere sonst eine Umwandlung von Feld
       nach Zeichenkette - unter PHP 8 eine Warnung mitten in der Antwort. */
    $ac_fist = (isset($_POST['formtoken']) && is_string($_POST['formtoken']))
               ? $_POST['formtoken'] : '';
    if ($ac_fsoll === '' || $ac_fist === '' || !hash_equals($ac_fsoll, $ac_fist)) {
        /* Den aktiven Reiter behalten - sonst landet der Bediener nach einer
           Abweisung auf Einstellungen und sucht die Meldung dort, wo er gar
           nicht war. Danach laeuft KEIN Zweig mehr an. */
        $ac_behalten = (isset($_POST['activetab']) && is_string($_POST['activetab']))
                       ? $_POST['activetab'] : null;
        $_POST = array();
        if ($ac_behalten !== null) { $_POST['activetab'] = $ac_behalten; }
        $ac_err = cam_t('TEXT.FORMULAR_ABGEWIESEN');
    }
}

/* ---------- Ansichten des Reiters Test (seit 1.9.32, E-E17, N7) ----------
 * "Verbindung pruefen", "Diagnose" und "Kamera-Auskunft" ueber die
 * angemeldete Oberflaeche, ohne Aktionstoken in der Adresse: bis 1.9.31
 * stand das Token (das auch Aufnahmen ueber HTTP erlaubt) im Browserverlauf
 * und im Zugriffsprotokoll. Sie lesen nur (F5 schadet nicht), deshalb GET.
 * Die cam.php-Zweige mit Token bleiben fuer Altlinks. Dieselbe Ausgabe:
 * cam_test(), cam_ansicht_diag(), cam_ansicht_sys(). */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ansicht']) && is_string($_GET['ansicht'])
    && in_array($_GET['ansicht'], array('test', 'diag', 'sys'), true)) {
    $ac_ak = 1;
    if (isset($_GET['kamera']) && is_string($_GET['kamera']) && preg_match('/^[1-4]\z/', $_GET['kamera'])
        && in_array((int) $_GET['kamera'], cam_kameras(), true)) {
        $ac_ak = (int) $_GET['kamera'];
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    if ($_GET['ansicht'] === 'test') {
        cam_log('Oberflaeche: Verbindungstest (Kamera ' . $ac_ak . ')');
        echo cam_test($ac_ak) . "\n";
    } elseif ($_GET['ansicht'] === 'diag') {
        echo cam_ansicht_diag($ac_ak);
    } else {
        echo cam_ansicht_sys($ac_ak);
    }
    exit;
}

// ---------- Speichern: nur die MQTT-Werte ----------
// Bewusst ein eigener Zweig. Der grosse Speichervorgang liest zwar die alte
// Konfiguration ein, ueberschreibt danach aber JEDES Feld aus dem Formular -
// wuerde das MQTT-Formular dieselbe Taste druecken, stuenden Kameraadresse
// und Zugangsdaten anschliessend auf ihren Vorgabewerten.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_mqtt'])) {
    $ac_m = cam_config();
    $ac_m_alt_ein = !empty($ac_m['mqtt_enabled']);
    $ac_m_alt_praefix = cam_mqtt_praefix($ac_m);
    $ac_m['mqtt_enabled'] = isset($_POST['mqtt_enabled']) ? 1 : 0;
    // Seit 1.9.32 (E-E1 bis E-E5): Befehle aus Loxone ueber die Befehlsbruecke - im selben Formular.
    $ac_m['befehle_mqtt_ein'] = isset($_POST['befehle_mqtt_ein']) ? 1 : 0;
    /* Beanstanden statt still kuerzen (M5, U5). Dieselbe Regel wie beim
       Zurueckspielen: cam_thema_pruefen(). */
    $ac_mt = trim(ac_post('mqtt_topic'));
    $ac_mg = cam_thema_pruefen($ac_mt);
    if ($ac_mt === '') {
        $ac_err = cam_t('MQTT.FEHLER_TOPIC') . ' ' . cam_t('TEXT.EINGABEN_ZURUECK');
        $ac_eingaben = cam_eingaben_sammeln('mqtt', array('mqtt_topic'), cam_config());
    } elseif ($ac_mg !== '') {
        $ac_err = sprintf(cam_t('MQTT.FEHLER_TOPIC_ZEICHEN'), ac_e($ac_mt), ac_e($ac_mg), ac_e($ac_m_alt_praefix))
            . ' ' . cam_t('TEXT.EINGABEN_ZURUECK');
        $ac_eingaben = cam_eingaben_sammeln('mqtt', array('mqtt_topic'), cam_config());
    } else {
        $ac_m['mqtt_topic'] = $ac_mt;
        if (cam_config_save($ac_m)) {
            $ac_saved = true;
            /* Praefixwechsel (M2) und Abschalten (M3): die zurueckbehaltenen
               Themen unter dem BISHERIGEN Praefix leeren und beim Broker
               nachlesen, Abo-Datei nachziehen, voller Satz - seit 1.9.32 an
               EINER Stelle, die auch das Zurueckspielen ruft (A1). */
            list($ac_note, $ac_note_rot) = cam_mqtt_nach_speichern($ac_m_alt_ein, $ac_m_alt_praefix);
            /* Die Bruecke sofort schalten (E-E5): laeuft mit beiden Haken,
               sonst wird sie angehalten. */
            list($ac_bok, $ac_bfehl) = cam_bruecke_meldungen(cam_bruecke_schalten(cam_config()));
            if ($ac_bok) { $ac_note = trim($ac_note . ' ' . implode(' ', $ac_bok)); }
            if ($ac_bfehl) { $ac_err = trim($ac_err . ' ' . implode(' ', $ac_bfehl)); }
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

/* ==================================================================
 * DIE HANDLER STEHEN VOR lbheader() - DAS IST BAUVORSCHRIFT
 * ==================================================================
 *
 * Stand der Kopf davor, war er beim Aufruf von header() schon
 * geschrieben - "Cannot modify header information", und der Knopf
 * "Einstellungen sichern" lieferte eine Seite mit angehaengtem JSON
 * statt einer Datei.
 *
 * Am PHP-CLI ist das unsichtbar: header() ist dort wirkungslos und
 * headers_sent() immer falsch. Und wer OHNE gueltiges Formularmerkmal
 * misst, wird vom Wachposten abgewiesen, bevor der Handler anlaeuft.
 * Beides hat den Fehler lange verdeckt.
 *
 * Reihenfolge: Bibliothek, Konfiguration, Wachposten, Reiterwahl,
 * ALLE Handler samt Downloads, dann erst lbheader(), dann HTML.
 * ================================================================== */
// ---------- Loxone-Vorlage herunterladen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download'])) {
    $ac_host = isset($_SERVER['HTTP_HOST']) ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', $_SERVER['HTTP_HOST']) : '';
    /* Zwei Bauformen, ein Handler: der Wert des Knopfes entscheidet.
       Zwei getrennte Handler waeren zwei Stellen, die man mitpflegen muss. */
    /* Seit 1.9.32: xml_out = Brueckenvorlage VQ_actikamera.xml (UDP-Eingang des
       Gateways, E-E20), xml_out_http = alte HTTP-Vorlage mit Aktionstoken
       (VQ_actikamera_HTTP.xml, "Bestehende Anlagen"), sonst der virtuelle
       HTTP-Eingang VI_actikamera.xml (ebenfalls "Bestehende Anlagen"). */
    $ac_dl = is_string($_POST['download']) ? $_POST['download'] : '';
    if ($ac_dl === 'xml_out') {
        $ac_v = cam_vorlage_ausgang($ac_host);
    } elseif ($ac_dl === 'xml_out_http') {
        $ac_v = cam_vorlage_ausgang_http($ac_host);
    } else {
        $ac_v = cam_vorlage($ac_host);
    }
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

/* Protokoll leeren (A3, seit 1.9.32): cam.log UND cron.err auf 0 Byte, nie
   loeschen - mit Rueckmeldung "geleert" bzw. "nicht geleert". */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clearlog'])) {
    list($ac_lok, $ac_lnicht) = cam_protokoll_leeren();
    if ($ac_lok) {
        $ac_note = cam_t('TEXT.M_PROTOKOLL_GELEERT');
    } else {
        $ac_note = sprintf(cam_t('TEXT.M_PROTOKOLL_NICHT_GELEERT'), ac_e(implode(', ', $ac_lnicht)));
        $ac_note_rot = true;
    }
    $ac_tab = 'tab-log';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['shotnow']) && function_exists('cam_snapshot')) {
    list($ac_ok, $ac_info) = cam_snapshot('test');
    $ac_note = sprintf(cam_t($ac_ok ? 'TEXT.M_BILD_OK' : 'TEXT.M_BILD_FEHL'), ac_e($ac_info));
    $ac_note_rot = !$ac_ok;
    $ac_tab = 'tab-test';
}
/* Test-Push als Formular (A4, seit 1.9.32; Bauart Ferien 1.2.29): bis 1.9.31
   ein Link cam.php?ptest=1&token=... - das Token stand im Verlauf, und F5
   loeste erneut aus. cam_testpush() setzt den Merker und sendet sofort. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ptestnow'])) {
    $ac_pt = cam_testpush('knopf');
    $ac_note = cam_t($ac_pt['ok'] ? 'TEXT.M_PTEST_OK' : 'TEXT.M_PTEST_FEHL');
    $ac_note_rot = !$ac_pt['ok'];
    $ac_tab = 'tab-test';
}
/* Der HTTP-Lesezweig fuer Loxone (seit 1.9.32, E-E12): eigenes Formular im
   Reiter Einbindung, Abschnitt "Bestehende Anlagen". */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_endpunkt'])) {
    $ac_ep = cam_config();
    $ac_ep['endpunkt_ein'] = isset($_POST['endpunkt_ein']) ? 1 : 0;
    if (cam_config_save($ac_ep)) {
        $ac_saved = true;
        $ac_note = cam_t($ac_ep['endpunkt_ein'] ? 'LOX.M_ENDPUNKT_AN' : 'LOX.M_ENDPUNKT_AUS');
    } else {
        $ac_err = cam_t('TEXT.M_NICHT_GESPEICHERT');
    }
    $ac_tab = 'tab-loxone';
}
/* RTSP-Weg pruefen (ACTiKamera-b1): ffmpeg einmal je Kamera, hoechstens
   8 s, nichts wird gespeichert. Ergebnis in der Meldung und in der
   Pruefzeile "RTSP erreichbar?"; Adresse und Fehlerzeile ohne Kennwort. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rtsppruefen']) && function_exists('cam_rtsp_pruefen')) {
    @set_time_limit(20 + 12 * CAM_MAX);
    $ac_rpe = array();
    $ac_teile = array();
    $ac_rot = false;
    foreach (cam_kameras() as $ac_kid) {
        $ac_r = cam_rtsp_pruefen($ac_kid);
        $ac_rpe[$ac_kid] = $ac_r;
        if ((int) $ac_r['stand'] === 1) {
            $ac_teile[] = sprintf(cam_t('TEST.M_RTSP_JA'), ac_e(cam_kname($ac_kid)), (int) $ac_r['dauer_ms']);
        } elseif ((int) $ac_r['stand'] === 0) {
            $ac_rot = true;
            $ac_teile[] = sprintf(cam_t('TEST.M_RTSP_NEIN'), ac_e(cam_kname($ac_kid)), ac_e($ac_r['grund']));
        } else {
            $ac_teile[] = sprintf(cam_t('TEST.M_RTSP_NICHT'), ac_e(cam_kname($ac_kid)), ac_e($ac_r['grund']));
        }
        cam_log('RTSP-Pruefung ' . cam_kname($ac_kid) . ': '
            . ((int) $ac_r['stand'] === 1 ? 'erreichbar' : ((int) $ac_r['stand'] === 0 ? 'nicht erreichbar' : 'nicht pruefbar'))
            . ($ac_r['grund'] !== '' ? ' (' . $ac_r['grund'] . ')' : '')
            . ($ac_r['adresse'] !== '' ? ', ' . $ac_r['adresse'] : '') . ', ' . (int) $ac_r['dauer_ms'] . ' ms');
    }
    cam_rtsp_pruefung_merken($ac_rpe);
    $ac_note = cam_t('TEST.M_RTSP') . ' ' . implode(' ', $ac_teile);
    $ac_note_rot = $ac_rot;
    $ac_tab = 'tab-test';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['timelapsenow']) && function_exists('cam_timelapse')) {
    list($ac_ok, $ac_info) = cam_timelapse();
    $ac_note = sprintf(cam_t($ac_ok ? 'TEXT.M_ZEITRAFFER_OK' : 'TEXT.M_BILD_FEHL'), ac_e($ac_info));
    $ac_note_rot = !$ac_ok;
    $ac_tab = 'tab-test';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cleanupnow']) && function_exists('cam_cleanup')) {
    /* Eine Zahl ohne ihren Grund ist keine Auskunft: hat der Lauf nichts
       entfernt, sagt die Meldung, gegen welche Grenzen gemessen wurde und was
       im Archiv liegt. Anlass: Frage des Hausherrn vom 08.09.2026. */
    $ac_weg = (int) cam_cleanup();
    $ac_note = $ac_weg > 0
        ? sprintf(cam_t('TEXT.M_AUFGERAEUMT'), $ac_weg)
        : cam_t('TEXT.M_AUFGERAEUMT_NICHTS') . ' ' . ac_grenzen();
    $ac_tab = 'tab-shots';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save']) && function_exists('cam_config')) {
    $ac_new = cam_config();
    $ac_alt = $ac_new;
    /* Adressfelder: Leerraum am RAND wird still abgeschnitten (Nr. 19 laesst
       das ausdruecklich zu). Leerraum, Anfuehrungszeichen und < > MITTEN im
       Wert werden beanstandet. Bis 1.9.26 entfernte $ac_saeubern() sie still
       ("http://kamera/a b" wurde "http://kamera/ab", ein Anfuehrungszeichen
       im Kennwort verschwand) - gespeichert wurde eine andere Adresse als die
       eingetippte (B-Nachzug 01.10.2026, Entscheidung 19). Doppelpunkt,
       Schraegstrich und Punkt bleiben selbstverstaendlich erlaubt: ein zu
       strenger Filter hat aus einer eingefuegten URL frueher
       "http19216817817cgi-binencoder..." gemacht. */
    $ac_saeubern = function ($wert) {
        return trim((string) $wert);
    };
    $ac_zeichen_falsch = function ($wert) {
        return preg_match('/[\x00-\x1F\x7F"\'<>\s]/', (string) $wert) === 1;
    };

    /* BEANSTANDEN STATT ZURECHTBIEGEN (U3, U5; Pruefung 29.09.2026).
     *
     * Bis 1.9.22 klemmte dieser Handler jede Zahl still an ihre Grenzen
     * (keep_days 5000 -> 3650, rtsp_port 99999 -> 65535, push_minutes 999 -> 30),
     * entfernte Zeichen aus Stromkennwort und Aufloesung (strom.1 -> strom1)
     * und kannte fuer Name und Schnappschuss-URL keine Laengengrenze - die
     * eigene Sicherung wurde danach abgewiesen. Jetzt prueft jedes Feld gegen
     * DIESELBE Regel wie das Zurueckspielen (cam_wert_pruefen). Was nicht
     * passt, wird gemeldet, und gespeichert wird NICHTS (Entscheidung 16,
     * X-2; seit 1.9.25). Seit dem B-Nachzug (01.10.2026, Entscheidung 19)
     * zaehlt auch das stille Zurechtbiegen als Beanstandung: Vorgabe fuer ein
     * geleertes Feld, "http://" vorangesetzt, Zeichen entfernt, leerer
     * Benutzer behaelt den alten. Still bleibt nur das Abschneiden von
     * Leerraum am Rand. */
    $ac_fehler = array();
    $ac_hinweise = array();
    // X-2: die beanstandeten Felder, fuer die Markierung nach der Umleitung.
    $ac_bean = array();
    $ac_setzen = function ($schluessel, $wert) use (&$ac_new, &$ac_fehler, &$ac_bean) {
        $g = cam_wert_pruefen($schluessel, $wert);
        if ($g !== '') {
            $ac_bean[] = $schluessel;
            $zeige = (strpos($schluessel, 'pass') === 0) ? '***' : cam_zugang_maske((string) $wert);
            $ac_fehler[] = sprintf(cam_t('TEXT.WERT_ABGEWIESEN'), ac_e($schluessel), ac_e($zeige), ac_e($g));
            return false;
        }
        $ac_new[$schluessel] = $wert;
        return true;
    };
    /* Zahlen: leer heisst $leer (null = leer ist unzulaessig). */
    $ac_zahl = function ($schluessel, $roh, $leer) use ($ac_setzen, &$ac_fehler, &$ac_bean) {
        $roh = trim((string) $roh);
        if ($roh === '' && $leer === null) {
            $ac_bean[] = $schluessel;
            $ac_fehler[] = sprintf(cam_t('TEXT.WERT_ABGEWIESEN'), ac_e($schluessel), '&laquo;&raquo;', 'leer');
            return false;
        }
        $w = ($roh === '') ? (string) $leer : $roh;
        if (cam_wert_pruefen($schluessel, $w) === '') {
            $r = cam_regel($schluessel);
            $w = ($r !== null && $r['art'] === 'komma') ? (float) $w : (int) $w;
        }
        return $ac_setzen($schluessel, $w);
    };

    /* Eine Schleife ueber alle Kameras statt eines Blocks je Kamera.
       Kamera 1 traegt die Feldnamen ohne Nummer - so, wie sie seit jeher
       heissen. */
    $ac_namen = array();
    for ($ac_i = 1; $ac_i <= CAM_MAX; $ac_i++) {
        $ac_s = $ac_i > 1 ? (string) $ac_i : '';
        /* is_string ZUERST - ein Feld statt einer Zeichenkette (?host[]=x)
           gaebe unter PHP 8 sonst eine Warnung mitten in der Seite. */
        $ac_f = function ($feld) use ($ac_s) {
            $k = $feld . $ac_s;
            return (isset($_POST[$k]) && is_string($_POST[$k])) ? $_POST[$k] : '';
        };
        /* Wurde das Feld GESENDET? Ein Kameraplatz ohne Block im Formular
           (nicht eingerichtet und nicht der naechste freie) schickt gar
           nichts; dort gilt die Vorgabe wie bisher. Ein gesendetes, aber
           leeres Feld dagegen hat jemand geleert - das wird beanstandet,
           nicht still durch die Vorgabe ersetzt (Entscheidung 19). */
        $ac_da = function ($feld) use ($ac_s) {
            $k = $feld . $ac_s;
            return isset($_POST[$k]) && is_string($_POST[$k]);
        };

        $ac_setzen('host' . $ac_s, trim((string) $ac_f('host')));

        /* Leeres Benutzerfeld loescht NICHT den gespeicherten Wert - genau das
           ist hier schon einmal passiert und fuehrte zu USER=&PWD=… und damit
           zu HTTP 401. Geloescht wird ueber den Haken daneben (U7). */
        $ac_u = trim((string) $ac_f('user'));
        if ($ac_u !== '') {
            $ac_setzen('user' . $ac_s, $ac_u);
        } elseif ($ac_da('user') && (string) $ac_alt['user' . $ac_s] !== ''
                  && $ac_f('zugang_loeschen') === '') {
            /* Das Feld zeigt den gespeicherten Benutzer; leer heisst also,
               jemand hat ihn geloescht. Bis 1.9.26 blieb er dann still stehen
               und "gespeichert" stand daneben (Entscheidung 19). Geloescht
               wird ueber den Haken daneben (U7) - die Meldung sagt es. */
            $ac_bean[] = 'user' . $ac_s;
            $ac_fehler[] = sprintf(cam_t('TEXT.BENUTZER_LEER'), $ac_i);
        }

        /* Passwortfeld leer lassen = bisheriges Passwort behalten.
           trim() ist kein Schoenheitsfehler: Passwortverwaltungen im Browser
           schreiben gelegentlich ein einzelnes Leerzeichen in ein leer
           gelassenes Feld. Ohne trim() wuerde damit das gespeicherte Passwort
           durch ein Leerzeichen ersetzt - und die Kamera meldet nur noch 401. */
        $ac_pw = trim((string) $ac_f('pass'));
        if ($ac_pw !== '') { $ac_setzen('pass' . $ac_s, $ac_pw); }

        /* Loesch-Haken (U7, Regeln/04 "geloescht wird ueber einen Haken
           daneben"): bis 1.9.22 liess sich ein gespeichertes Kennwort ueber die
           Oberflaeche nie mehr entfernen. Er wirkt nur in diesem Formular. */
        if ($ac_f('zugang_loeschen') !== '') {
            $ac_new['user' . $ac_s] = '';
            $ac_new['pass' . $ac_s] = '';
            $ac_hinweise[] = sprintf(cam_t('TEXT.M_ZUGANG_GELOESCHT'), $ac_i);
        }

        /* Doppelte Namen weist die Hausform ab. Abgewiesen wird der NAME, nicht
           das ganze Formular: ein Speichern, das wegen einer Kleinigkeit gar
           nichts uebernimmt, hat hier schon einmal Schaden angerichtet. */
        $ac_nm = trim((string) $ac_f('name'));
        if ($ac_nm !== '' && in_array(strtolower($ac_nm), $ac_namen, true)) {
            $ac_fehler[] = sprintf(cam_t('TEXT.NAME_DOPPELT'), $ac_i, ac_e($ac_nm));
            $ac_bean[] = 'name' . $ac_s;
        } elseif ($ac_setzen('name' . $ac_s, $ac_nm) && $ac_nm !== '') {
            $ac_namen[] = strtolower($ac_nm);
        }

        $ac_zahl('channel' . $ac_s, $ac_f('channel'), $ac_da('channel') ? null : 1);
        $ac_res = trim((string) $ac_f('resolution'));
        if ($ac_res !== '' && (!preg_match('/^[A-Za-z0-9x,]+\z/', $ac_res) || stripos($ac_res, 'http') === 0)) {
            $ac_fehler[] = sprintf(cam_t('TEXT.WERT_ABGEWIESEN'), ac_e('resolution' . $ac_s), ac_e($ac_res),
                                   cam_t('TEXT.RES_ABGEWIESEN'));
            $ac_bean[] = 'resolution' . $ac_s;
        } else {
            $ac_setzen('resolution' . $ac_s, $ac_res);
        }

        /* Die Masken aus dem Formular (U6) werden hier wieder durch den
           gespeicherten Wert ersetzt. */
        $ac_cmd_roh = $ac_saeubern($ac_f('snapcmd'));
        $ac_su_roh = $ac_saeubern($ac_f('snapurl'));
        $ac_snap_ok = true;
        foreach (array('snapcmd' => $ac_cmd_roh, 'snapurl' => $ac_su_roh) as $ac_sf => $ac_sw) {
            if ($ac_zeichen_falsch($ac_sw)) {
                $ac_snap_ok = false;
                $ac_bean[] = $ac_sf . $ac_s;
                $ac_fehler[] = sprintf(cam_t('TEXT.WERT_ABGEWIESEN'), ac_e($ac_sf . $ac_s),
                    ac_e(cam_zugang_maske($ac_sw)), cam_t('TEXT.ADRESSE_ZEICHEN'));
            }
        }
        if ($ac_snap_ok) {
            $ac_cmd = cam_zugang_entmaske($ac_cmd_roh, $ac_alt['snapcmd' . $ac_s]);
            $ac_su = cam_zugang_entmaske($ac_su_roh, $ac_alt['snapurl' . $ac_s]);
            // Das Feld, in das die Adresse eingetippt wurde - es wird markiert.
            $ac_su_feld = 'snapurl' . $ac_s;
            // Wer die komplette Adresse ins Befehlsfeld einfuegt, meint die vollstaendige URL
            // (angekuendigt mit eigener Meldung, also kein stilles Zurechtbiegen).
            if ($ac_su === '' && stripos($ac_cmd, '://') !== false) {
                $ac_su = $ac_cmd;
                $ac_cmd = '';
                $ac_su_feld = 'snapcmd' . $ac_s;
                $ac_note = cam_t('TEXT.M_ADRESSE_UEBERNOMMEN');
            }
            /* Ohne http:// bzw. https:// wird beanstandet, wie bei
               mjpeg_url und rtsp_url. Bis 1.9.26 wurde hier still "http://"
               vorangesetzt: aus "ftp://kamera/x" wurde http://ftp://kamera/x,
               aus "//kamera/x" http://kamera/x - gespeichert und als
               "gespeichert" gemeldet (B-Nachzug 01.10.2026, Entscheidung 19).
               Eine Kurzform ohne Anfang taugt nicht: cURL raet dann http,
               der Rueckfall ohne cURL (fopen) liest dieselbe Zeichenkette
               als Dateipfad auf dem LoxBerry. */
            if ($ac_su !== '' && !preg_match('#^https?://#i', $ac_su)) {
                $ac_fehler[] = sprintf(cam_t('TEXT.ADRESSE_VERWORFEN'), $ac_i, 'http:// / https://',
                                       ac_e(cam_zugang_maske($ac_su)));
                $ac_bean[] = $ac_su_feld;
            } else {
                $ac_setzen('snapcmd' . $ac_s, $ac_cmd);
                $ac_setzen('snapurl' . $ac_s, $ac_su);
            }
        }

        // Nicht gesendet: Vorgabe "auto"; gesendet und leer: die Auswahlregel weist es ab.
        $ac_setzen('auth' . $ac_s, $ac_da('auth') ? (string) $ac_f('auth') : 'auto');
        $ac_zahl('timeout' . $ac_s, $ac_f('timeout'), $ac_da('timeout') ? null : 8);

        /* Eine Adresse, die dem Muster nicht entspricht, wird gemeldet statt
           stillschweigend geleert - bis 1.9.8 verschwand sie wortlos. In der
           Meldung steht sie maskiert (U6). */
        $ac_mu = cam_zugang_entmaske(trim((string) $ac_f('mjpeg_url')), $ac_alt['mjpeg_url' . $ac_s]);
        if ($ac_mu !== '' && !preg_match('#^https?://#i', $ac_mu)) {
            $ac_fehler[] = sprintf(cam_t('TEXT.ADRESSE_VERWORFEN'), $ac_i, 'http://', ac_e(cam_zugang_maske($ac_mu)));
            $ac_bean[] = 'mjpeg_url' . $ac_s;
        } else {
            $ac_setzen('mjpeg_url' . $ac_s, $ac_mu);
        }
        $ac_ru = cam_zugang_entmaske(trim((string) $ac_f('rtsp_url')), $ac_alt['rtsp_url' . $ac_s]);
        if ($ac_ru !== '' && !preg_match('#^rtsp://#i', $ac_ru)) {
            $ac_fehler[] = sprintf(cam_t('TEXT.ADRESSE_VERWORFEN'), $ac_i, 'rtsp://', ac_e(cam_zugang_maske($ac_ru)));
            $ac_bean[] = 'rtsp_url' . $ac_s;
        } else {
            $ac_setzen('rtsp_url' . $ac_s, $ac_ru);
        }
        $ac_zahl('rtsp_stream' . $ac_s, $ac_f('rtsp_stream'), $ac_da('rtsp_stream') ? null : 2);
        $ac_zahl('rtsp_port' . $ac_s, $ac_f('rtsp_port'), $ac_da('rtsp_port') ? null : 7070);
        $ac_zahl('rtsp_quality' . $ac_s, $ac_f('rtsp_quality'), $ac_da('rtsp_quality') ? null : 5);
    }
    $ac_zahl('stream_fps', ac_post('stream_fps'), null);
    // 900 s statt 21600: jeder offene Strom belegt einen PHP-Arbeitsprozess,
    // und davon hat ein LoxBerry nur eine Handvoll. Siehe cam_stream.php.
    $ac_zahl('stream_maxsec', ac_post('stream_maxsec'), null);
    // Leer (nur von Hand erreichbar) weist die Auswahlregel ab, statt still "auto" zu setzen.
    $ac_setzen('stream_mode', ac_post('stream_mode', 'auto'));
    /* EINE Positivliste fuer das Stromkennwort (U3, B7): die Regel "marke"
       aus cam_wertregeln(). Bis 1.9.22 entfernte das Formular den Punkt, die
       Sicherung liess ihn zu - aus strom.1 wurde beim naechsten Speichern
       still strom1, und jede Loxone-Adresse mit t=strom.1 bekam 403. */
    $ac_setzen('stream_token', trim(ac_post('stream_token')));
    $ac_zahl('clip_seconds', ac_post('clip_seconds'), null);
    $ac_zahl('clip_fps', ac_post('clip_fps'), null);
    $ac_notify = is_array($ac_alt['notify']) ? $ac_alt['notify'] : array();
    $ac_notify['push'] = isset($_POST['notify_push']) ? 1 : 0;
    $ac_pm = trim(ac_post('push_minutes'));
    $ac_pmg = cam_wert_pruefen('push_minutes', $ac_pm);
    if ($ac_pmg === '') {
        $ac_notify['push_minutes'] = (int) $ac_pm;
    } else {
        $ac_fehler[] = sprintf(cam_t('TEXT.WERT_ABGEWIESEN'), 'push_minutes', ac_e($ac_pm), ac_e($ac_pmg));
        $ac_bean[] = 'push_minutes';
    }
    $ac_new['notify'] = $ac_notify;
    /* mqtt_enabled und mqtt_topic werden hier NICHT mehr angefasst: die
     * Felder stehen nur noch im Reiter MQTT und haben dort einen eigenen
     * Handler (save_mqtt). $ac_new kommt aus cam_config(), die Werte
     * ueberleben also unveraendert. Stuende die Zeile hier weiter, schaltete
     * jedes Speichern der Einstellungen MQTT stillschweigend ab. */
    /* "0 oder leer = unbegrenzt" steht auf der Seite fuer Alter und Anzahl
       (TEXT.0_ODER_LEER_UNBEGRENZT): dort heisst leer weiter 0. Bei
       Hoechstgroesse, Pruefabstand und Mindestpause sagt die Seite nur
       "0 = unbegrenzt" bzw. "0 = aus"; ein geleertes Feld wurde bis 1.9.26
       still 0 - die Erreichbarkeitspruefung war damit aus, ohne dass es
       jemand gewaehlt hatte. Jetzt wird es beanstandet (Entscheidung 19). */
    $ac_zahl('keep_max', ac_post('keep_max'), 0);
    $ac_zahl('keep_mb', ac_post('keep_mb'), null);
    $ac_zahl('keep_days', ac_post('keep_days'), 0);
    $ac_zahl('pruef_minuten', ac_post('pruef_minuten'), null);
    $ac_zahl('mindestpause', ac_post('mindestpause'), null);
    $ac_new['timelapse'] = isset($_POST['timelapse']) ? 1 : 0;
    /* Kaestchen an = feste Adresse bedienen, wie seit jeher. Das Kaestchen
       steht im selben Formular wie timelapse; ein fehlendes Feld heisst
       deshalb wirklich "abgewaehlt" und nicht "anderes Formular". */
    $ac_new['bild_fest'] = isset($_POST['bild_fest']) ? 1 : 0;
    /* 00:00 bis 23:59 - dieselbe Pruefung wie beim Zurueckspielen (U3, B6).
       Bis 1.9.22 nahm das Formular 25:77 an, und der Zeitraffer lief nie. */
    $ac_setzen('timelapse_time', trim(ac_post('timelapse_time')));
    $ac_setzen('ai_url', trim(ac_post('ai_url')));
    $ac_zahl('ai_min', ac_post('ai_min'), null);
    $ac_setzen('webhook1', trim(ac_post('webhook1')));
    $ac_setzen('webhook2', trim(ac_post('webhook2')));
    if ($ac_fehler) {
        /* Beanstandungen werden GEMELDET, alle auf einmal, damit niemand einen
           Fehler nach dem anderen korrigiert - und GESPEICHERT WIRD NICHTS
           (X-2, Regeln/04: "die Konfiguration ist unveraendert"; so fuer
           Heimkino am 30.09.2026 entschieden, Entscheidung 15). Bis 1.9.25
           wurden die uebrigen Felder trotzdem gespeichert, damit niemand alles
           neu tippt; das leistet jetzt die Rueckreise der Eingaben, und
           "gespeichert" neben "beanstandet" hatte nur verwirrt. */
        $ac_eingaben = cam_eingaben_sammeln('settings', $ac_bean, $ac_alt);
        $ac_err = implode(' ', $ac_fehler) . ' ' . cam_t('TEXT.EINGABEN_ZURUECK');
        if ($ac_eingaben['neu_eintragen']) {
            $ac_err .= ' ' . sprintf(cam_t('TEXT.EINGABEN_NEU_EINTRAGEN'),
                                     ac_e(implode(', ', $ac_eingaben['neu_eintragen'])));
        }
        $ac_note = '';
    } elseif (cam_config_save($ac_new)) {
        $ac_saved = true;
        if ($ac_hinweise) {
            $ac_note = trim($ac_note . ' ' . implode(' ', $ac_hinweise));
        }
    } else {
        $ac_err = cam_t('TEXT.M_NICHT_GESPEICHERT');
    }
}

/* ---------------- Einstellungen sichern ----------------
 *
 * Ausgegeben wird die VOLLE Konfiguration - samt Aktionstoken. Ohne ihn
 * stuenden nach dem Zurueckspielen alle Felder richtig, und das Plugin
 * kaeme trotzdem nicht an die Anlage; die Datei waere wertlos. Damit
 * traegt sie ein Geheimnis, und der Hinweis am Knopf sagt das.
 *
 * Seit 1.9.23 mit lesbarem Kopf (U14, Regeln/05 Punkt 2): _hinweis, _plugin,
 * _stand. Das Zurueckspielen uebergeht Schluessel, die mit _ beginnen.
 *
 * Sichern und Zurueckspielen stehen seit 1.9.23 VOR dem Lesen der
 * Konfiguration fuer die Seite (U2, B2): bis 1.9.22 las die Seite sie vorher,
 * zeigte nach dem Zurueckspielen die ALTEN Werte und das alte Token, und ein
 * anschliessendes "Speichern" machte das Zurueckspielen still rueckgaengig
 * (keep_days fiel zurueck, stream_token wurde leer). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cam_sichern'])) {
    $ac_fass = function_exists('cam_fassung') ? cam_fassung() : '';
    $ac_kopf = array(
        '_hinweis' => cam_t('TEXT.SICH_KOPF_HINWEIS'),
        '_plugin'  => 'ACTi Kamera (' . $ac_plugin . ')' . ($ac_fass !== '' ? ', Fassung ' . $ac_fass : ''),
        '_stand'   => date('c'),
    );
    /* X-3: Wuerde das Zurueckspielen diese Datei abweisen, sagt es der Kopf -
       mit den Namen, nie mit Werten. Geliefert wird sie trotzdem. */
    $ac_x3 = cam_rueckspiel_befund();
    if ($ac_x3) {
        $ac_kopf['_warnung'] = sprintf(cam_t('TEXT.SICH_X3_KOPF'), implode(', ', $ac_x3));
    }
    $cam_js = json_encode($ac_kopf + cam_config(),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($cam_js !== false) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="actikamera_einstellungen_'
               . date('Ymd_His') . '.json"');
        echo $cam_js;
        exit;
    }
    $ac_note = cam_t('TEXT.SICH_SCHREIBFEHLER');
    $ac_note_rot = true;
}

/* ---------------- Einstellungen zurueckspielen ----------------
 *
 * is_uploaded_file() ZUERST: ohne diese Pruefung liesse sich jede Datei
 * des Servers unterschieben. Dann die Groessengrenze - eine Sicherung
 * dieses Plugins ist wenige Kilobyte gross; alles darueber wird gar
 * nicht erst gelesen. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cam_zurueck'])) {
    if (!isset($_FILES['cam_sicherung']) || !is_array($_FILES['cam_sicherung'])
        || !isset($_FILES['cam_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['cam_sicherung']['tmp_name'])) {
        $ac_note = cam_t('TEXT.SICH_KEINE_DATEI');
        $ac_note_rot = true;
    } elseif ((int) $_FILES['cam_sicherung']['size'] > 262144) {
        $ac_note = cam_t('TEXT.SICH_ZU_GROSS');
        $ac_note_rot = true;
    } else {
        /* A1 (seit 1.9.32): der Stand VOR dem Zurueckspielen - fuer das Leeren
           unter dem alten Praefix und das Schalten der Bruecke danach. */
        $ac_zr_alt = cam_config();
        $ac_zr_alt_ein = !empty($ac_zr_alt['mqtt_enabled']);
        $ac_zr_alt_praefix = cam_mqtt_praefix($ac_zr_alt);
        $ac_sl = cam_sicherung_lesen(
            (string) @file_get_contents($_FILES['cam_sicherung']['tmp_name']));
        list($cam_neu, $cam_fehler, $cam_n) = $ac_sl;
        $cam_hinw = isset($ac_sl[3]) ? (array) $ac_sl[3] : array();
        if ($cam_neu === null) {
            /* ALLE Beanstandungen, nicht nur die erste - und geaendert
             * wird nichts. */
            $ac_note = cam_t('TEXT.SICH_ABGELEHNT') . ' ' . implode(' ', array_map('ac_e', $cam_fehler));
            $ac_note_rot = true;
        } elseif (!cam_config_save($cam_neu)) {
            $ac_note = cam_t('TEXT.SICH_SCHREIBFEHLER');
            $ac_note_rot = true;
        } else {
            /* Erfolg erst nach dem ZURUECKLESEN (C1, CLAUDE.md 2: Wirkung statt
             * Rueckgabewert). Bis 1.9.22 stand hier "uebernommen", sobald
             * cam_config_save() true lieferte - mit leerem Token heilte
             * cam_config() im selben Aufruf aus der Zweitschrift, und alle
             * Werte fielen still auf den alten Stand zurueck. Verglichen wird
             * die Datei (roh) gegen den geschriebenen Stand, dann noch einmal
             * nach cam_config(), die heilen koennte. */
            $ac_rueck = cam_config_roh();
            $ac_rueck2 = cam_config();
            $ac_angekommen = ($ac_rueck == $cam_neu)
                && (string) $ac_rueck2['aktionstoken'] === (string) $cam_neu['aktionstoken']
                && cam_config_roh() == $cam_neu;
            if ($ac_angekommen) {
                $ac_note = sprintf(cam_t('TEXT.SICH_UEBERNOMMEN'), $cam_n)
                    . ($cam_hinw ? ' ' . implode(' ', array_map('ac_e', $cam_hinw)) : '');
                /* A1 (seit 1.9.32): dieselben Folgen wie das Speichern im Reiter
                   MQTT - Praefixwechsel bzw. Abschalten leert unter dem alten
                   Praefix, Abo-Datei und voller Satz sofort - und die Bruecke
                   folgt dem zurueckgespielten Haken. */
                list($ac_zr_note, $ac_zr_rot) = cam_mqtt_nach_speichern($ac_zr_alt_ein, $ac_zr_alt_praefix);
                if ($ac_zr_note !== '') {
                    $ac_note .= ' ' . $ac_zr_note;
                    $ac_note_rot = $ac_note_rot || $ac_zr_rot;
                }
                list($ac_bok, $ac_bfehl) = cam_bruecke_meldungen(cam_bruecke_schalten(cam_config()));
                if ($ac_bok) { $ac_note .= ' ' . implode(' ', $ac_bok); }
                if ($ac_bfehl) { $ac_note .= ' ' . implode(' ', $ac_bfehl); $ac_note_rot = true; }
            } else {
                $ac_note = cam_t('TEXT.SICH_NICHT_ANGEKOMMEN');
                $ac_note_rot = true;
                cam_log('Zurueckspielen: geschrieben, aber beim Zuruecklesen nicht so angekommen.');
            }
        }
    }
}

/* ==================================================================
 * JEDER POST ENDET MIT EINER UMLEITUNG (U1, Regeln/04)
 *
 * Bis 1.9.22 antwortete jeder der sieben Zweige mit 200: F5 auf "Jetzt ein
 * Bild aufnehmen" nahm erneut auf (Protokollzeilen 3 -> 4 -> 5 gemessen),
 * ebenso Aufraeumen, Zeitraffer und Protokoll leeren. Jetzt reist das
 * Ergebnis als Einmalmeldung (cam_einmal_schreiben, 0600, 120 s, ohne
 * Geheimnisse), und die Antwort ist 303 auf den Reiter. Die Downloads
 * (Vorlagen, Sicherung) haben oben schon mit exit geendet. Liess sich die
 * Meldung nicht ablegen, wird wie bisher ohne Umleitung gezeigt - eine
 * verlorene Meldung waere schlimmer als ein wiederholtes Absenden.
 * ================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('cam_einmal_schreiben')) {
    if (cam_einmal_schreiben(array('saved' => $ac_saved, 'note' => $ac_note,
                                   'note_rot' => $ac_note_rot, 'err' => $ac_err,
                                   'eingaben' => $ac_eingaben))) {
        header('Location: index.php?form=' . substr($ac_tab, 4), true, 303);
        exit;
    }
    // Ohne Umleitung (Meldung nicht ablegbar): die Eingaben gleich zeigen.
    if (is_array($ac_eingaben)) {
        cam_eingaben_aktiv($ac_eingaben);
    }
} elseif (function_exists('cam_einmal_lesen')) {
    $ac_em = cam_einmal_lesen();
    if ($ac_em !== null) {
        $ac_saved = !empty($ac_em['saved']);
        $ac_note = (string) $ac_em['note'];
        $ac_note_rot = !empty($ac_em['note_rot']);
        $ac_err = (string) $ac_em['err'];
        // X-2: nur nach einer Beanstandung traegt die Meldung Eingaben.
        if (is_array($ac_em['eingaben'])) {
            cam_eingaben_aktiv($ac_em['eingaben']);
        }
    }
}

/* Die Konfiguration fuer die Seite wird NACH allen Handlern gelesen (U2),
   ebenso das Formularmerkmal (cam_formtoken() in jedem Formular unten). */
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
$ac_host = (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '')
           ? $_SERVER['HTTP_HOST'] : 'loxberry';
$ac_token = isset($ac_cfg['aktionstoken']) ? (string) $ac_cfg['aktionstoken'] : '';

/* Ist ein Token fuer den Bildstrom hinterlegt, gilt es fuer JEDE Adresse, die
   auf cam_stream.php oder das Archiv zeigt - auch fuer die beiden, die weiter
   unten nur zum Abschreiben nach Loxone angezeigt werden, und fuer den Knopf
   "Livebild ansehen". Fehlt es dort, weist der eigene Endpunkt die eigene
   Anleitung ab: Loxone zeigt dann still kein Bild, ohne Fehlermeldung.
   Zwei Formen, weil eine Adresse schon ein Fragezeichen traegt und die
   andere nicht. */
$ac_stok = isset($ac_cfg['stream_token']) ? trim((string) $ac_cfg['stream_token']) : '';
$ac_bt  = $ac_stok !== '' ? '&amp;t=' . rawurlencode($ac_stok) : '';
$ac_bt1 = $ac_stok !== '' ? '?t=' . rawurlencode($ac_stok) : '';


/* Einmal feststellen, Kopf UND Fuss daran haengen. function_exists() kennt
   keine Methodenschreibweise und war immer falsch; class_exists() ohne das
   zweite Argument stiesse ausserdem den Autolader an. */
$ac_rahmen = class_exists('LBWeb', false);
if ($ac_rahmen) {
    LBWeb::lbheader('ACTi Kamera', 'https://wiki.loxberry.de', 'help.html');
} else {
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>ACTi Kamera</title></head><body>';
}

?>
<style>
.acw { max-width: 1100px; margin: 0 auto; padding: 0 10px 40px; font-size: 0.95em; }
.acw, .acw * { text-shadow: none !important; }
.acw h2 { color: #6dac20; margin: 18px 0 6px; font-size: 1.15em; }

/* Gleich grosse Schaltflaechen mit gleichem Abstand, ob Link oder Formular.
   Hier stand bis 1.9.16 ein Uebersetzungsaufruf mitten im CSS-Kommentar:
   der Schluesselpruefer hielt den Schluessel fuer benutzt, und ein Wert mit
   Stern-Schraegstrich haette den ganzen Stilblock beendet. */
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
/* Je Knopfgruppe eine eigene Hover- und Fokusfarbe, mit !important.
   Die Rahmen-CSS von jQuery Mobile setzt sonst ihre eigene, und bei einer
   hellen Vorgabe steht Weiss auf Weiss - der Knopf verschwindet unter dem
   Zeiger. Bis 1.9.16 gab es in dieser Datei keine einzige :hover-Regel. */
.acw .sm-btn:hover, .acw .sm-btn:focus { color: #fff !important; text-shadow: none !important;
    box-shadow: none !important; outline: 2px solid #33691e; outline-offset: 1px; }
.acw .sm-btn.sm-b-lesen:hover, .acw .sm-btn.sm-b-lesen:focus { background: #5c9219 !important; }
.acw .sm-btn.sm-b-technik:hover, .acw .sm-btn.sm-b-technik:focus { background: #435862 !important; }
.acw .sm-btn.sm-b-aktion:hover, .acw .sm-btn.sm-b-aktion:focus { background: #b94e0a !important; }
/* Hinweis und Warnung. Beide gehoeren zum Hausstandard, und sie heissen SO.
   Bis 1.9.16 benutzte das HTML sm-hinweis und sm-warnung, waehrend dieser
   Block nur sm-hint und sm-warn kannte: der einzige Warnkasten des Plugins -
   der Satz, dass die Sicherungsdatei ein Geheimnis traegt - stand als nackter
   Fliesstext da. hausstandard_pruefen.py meldet das als Zusatzzeile, waehrend
   alle elf Spalten gross bleiben. */
.acw .sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.acw .sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.acw label { display: block; font-weight: 600; margin: 8px 0 2px; }
.acw input[type=text], .acw input[type=password], .acw input[type=number], .acw select {
    width: 100%; padding: 7px 9px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; background: #fff; }
/* Formularzeilen als Raster, nicht als Flussreihe.

   Bis 1.9.18 war das eine flex-Reihe mit "flex: 1 1 210px". Zwei Folgen, am
   08.09.2026 am Geraet gesehen: erstens wuchsen die Felder unterschiedlich
   breit, und ein umgebrochenes fuenftes Feld ("Bilder je Sekunde im Clip")
   zog sich ueber die ganze Zeile; zweitens standen die Eingabefelder auf
   verschiedenen Hoehen, weil die Beschriftungen darueber ein, zwei oder drei
   Zeilen lang sind.

   Das Raster gibt allen Zellen dieselbe Breite (auto-fit klappt leere Spuren
   weg, eine einzelne Zelle bekommt also weiter die volle Breite). Die Zellen
   werden auf die Hoehe der Rasterzeile gedehnt (die Ausrichtung bleibt auf
   dem Vorgabewert), und "margin-top: auto" am Eingabefeld schiebt es an den
   unteren Rand seiner Zelle - damit stehen alle Eingabefelder einer Zeile auf
   derselben Grundlinie, gleich wie hoch die Beschriftung darueber ist. Die
   Zellen am unteren Rand auszurichten waere hier falsch: dann ist die Zelle
   nur so hoch wie ihr Inhalt, und margin-top:auto hat nichts, wogegen es
   druecken koennte (im Browser gemessen: bis zu 77 px Versatz, danach 0). */
.acw .sm-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 14px; }
.acw .sm-row > div { display: flex; flex-direction: column; }
/* Die Beschriftung nimmt die ueberschuessige Hoehe auf, damit das Eingabefeld
   darunter auf der Grundlinie der Nachbarzellen landet - auch wenn die
   Beschriftung ein, zwei oder drei Zeilen lang ist. */
.acw .sm-row > div > label { margin-bottom: 4px; flex: 1 1 auto; }
.acw .sm-row > div > input, .acw .sm-row > div > select { flex: 0 0 auto; }
/* Eine Zelle ueber die ganze Zeile - fuer lange Adressen. Bis 1.9.18 stand
   dafuer ein "flex: 1 1 100%" als Attribut im HTML; im Raster wirkt das
   nicht mehr, die betroffenen Felder rutschten in eine gewoehnliche Spalte. */
.acw .sm-row > div.acw-breit { grid-column: 1 / -1; }
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
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Nachgezogen am
   05.09.2026 nach Regeln/04; Wortlaut aus VORLAGE_hausstandard.css.html.

   Am Geraet gemessen (LoxBerry 4.0.0.15, components.css): die Rahmen-CSS
   zeichnet seit der neuen Oberflaeche selbst einen Pfeil - Regel
   ".lb-content select". Darauf kann sich eine Plugin-Oberflaeche nicht
   verlassen: die Regel gibt es erst seit dieser Fassung, und die eigene
   Feldregel loescht sie, sobald sie die Kurzform "background:" benutzt.
   Dann steht ein Auswahlfeld da, das aussieht wie ein Textfeld.

   Die Raute im SVG wird als %23 geschrieben: eine rohe Raute beendet in
   einer CSS-Adresse den Wert. */
.acw select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* Eigene Ergaenzung, nicht aus der Vorlage (X-2, Regeln/04): das Feld, das
   nach einer Abweisung beanstandet ist. */
.acw .sm-beanstandet { border: 2px solid #c62828 !important; background: #fff5f5 !important; }
/* Welle Bild 20 (1.9.30): zwei Seiten aus dem Musterprojekt in Schritt 4 - Bild, Ueberschrift, Schritte und
   Hinweise je Seite (wie Govee 0.9.27). */
.acw .sm-bild { margin: 12px 0; }
.acw .sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.acw .sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
.acw h4.sm-h4 { color: #4f7d17; font-size: 0.98em; font-weight: 700; margin: 22px 0 4px;
    padding-top: 10px; border-top: 1px solid #e0e0e0; }
.acw .sm-schritte { margin: 6px 0 0 18px; padding: 0; }
.acw .sm-schritte li { margin: 3px 0; }

</style>
<div class="acw">
<h1 style="color:#6dac20;text-shadow:none;"><?php echo cam_t('TEXT.ACTI_KAMERA'); ?></h1>
<div class="sm-small"><?php echo cam_t('TEXT.HOLT_BILDER_VON_EINER_ACTI_NETZWER'); ?> 
<b><?php echo cam_t('TEXT.OHNE_ZUGANGSDATEN_IN_DER_LOXONE_PR'); ?></b><?php echo cam_t('TEXT.BENUTZER_UND_PASSWORT_STEHEN_AUSSC'); ?></div>

<?php if ($ac_saved) { ?><div class="sm-alert sm-ok"><b><?php echo cam_t('TEXT.KONFIGURATION_GESPEICHERT'); ?></b></div><?php } ?>
<?php /* Meldungen gehen ROH hinaus - die Sprachdatei ist plugin-eigen und
        traegt Auszeichnung (<b>nicht</b>). Maskiert wird das EINGESETZTE
        ARGUMENT an seiner Quelle, nicht die fertige Meldung; bis 1.9.16 lief
        beides durch ac_e() und der Anwender las die Tags als Text.
        Die Farbe entscheidet ein Schalter, keine Wortsuche: bis 1.9.16
        stand hier strpos($ac_note, 'fehlgeschlagen') - jede Rueckweisung
        erschien gruen, und in der englischen Fassung ausnahmslos jede. */ ?>
<?php if ($ac_err !== '') { ?><div class="sm-alert sm-err"><?= $ac_err ?></div><?php } ?>
<?php if ($ac_note !== '') { ?><div class="sm-alert <?= $ac_note_rot ? 'sm-err' : 'sm-ok' ?>"><?= $ac_note ?></div><?php } ?>
<?php if (trim((string) $ac_cfg['user']) === '' && (string) $ac_cfg['pass'] !== '' && trim((string) $ac_cfg['snapurl']) === '') { ?>
<div class="sm-alert sm-err"><b><?php echo cam_t('TEXT.DER_BENUTZERNAME_IST_LEER'); ?></b><?php echo cam_t('TEXT.EIN_PASSWORT_IST_ABER_HINTERLEGT_D'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.ERROR_NOT_AUTHORIZED'); ?></span> <?php echo cam_t('TEXT.HTTP_401_BITTE_DEN_BENUTZERNAMEN_E'); ?></div>
<?php } ?>
<?php if (trim((string) $ac_cfg['host']) === '') { ?>
<div class="sm-alert sm-warn"><b><?php echo cam_t('TEXT.NOCH_NICHT_EINGERICHTET'); ?></b> <?php echo cam_t('TEXT.TRAGEN_SIE_UNTEN_ADRESSE_BENUTZER_'); ?></div>
<?php } ?>

<?php
/* Kopf (Entscheidung Nr. 43, seit 1.9.28): Statusuebersicht ueber den
 * Reitern, immer sichtbar. Nur, was die Seite schon gelesen hat: $ac_st aus
 * cam_state() (erste Kamera; Dateien unter data/ und der Merker des
 * Minutentakts - keine Anfrage an die Kamera) und $ac_cfg. Einen Dienst hat
 * das Plugin nicht, es arbeitet im Minutentakt (cron.01min); die Grenze von
 * 5 Minuten ist dieselbe wie in der Pruefzeile TEST.F_HERZ. */
$ac_k = (is_array($ac_st) ? $ac_st : array()) + array('herz_min' => -1, 'erreichbar' => -1,
    'alter_min' => -1, 'name' => '');
$ac_k_n = count(cam_kameras()) - (trim((string) $ac_cfg['host']) === '' ? 1 : 0);
$ac_k_herz = (int) $ac_k['herz_min'];
?>
<table class="sm-tbl" style="max-width:620px">
<tr><th><?php echo cam_t('TEXT.KOPF_EIGENSCHAFT'); ?></th><th><?php echo cam_t('TEXT.WERT'); ?></th></tr>
<tr><td><?php echo cam_t('TEXT.KOPF_TAKT'); ?></td>
    <td><?= $ac_k_herz < 0 ? '<b>' . cam_t('TEXT.KOPF_TAKT_NIE') . '</b>'
        : ($ac_k_herz <= 5 ? sprintf(cam_t('TEXT.KOPF_TAKT_OK'), $ac_k_herz)
                          : '<b>' . sprintf(cam_t('TEXT.KOPF_TAKT_ALT'), $ac_k_herz) . '</b>') ?></td></tr>
<tr><td><?php echo cam_t('TEXT.KOPF_KAMERAS'); ?></td>
    <td><?= (int) max(0, $ac_k_n) ?></td></tr>
<tr><td><?= sprintf(cam_t('TEXT.KOPF_ANTWORTET'), ac_e((string) $ac_k['name'])) ?></td>
    <td><?= trim((string) $ac_cfg['host']) === '' ? '&ndash;'
        : ((int) $ac_cfg['pruef_minuten'] <= 0 ? cam_t('TEXT.KOPF_PRUEFUNG_AUS')
        : ((int) $ac_k['erreichbar'] === -1 ? cam_t('TEXT.KOPF_UNGEPRUEFT')
        : ((int) $ac_k['erreichbar'] === 1 ? cam_t('ALLGEMEIN.JA') : '<b>' . cam_t('ALLGEMEIN.NEIN') . '</b>'))) ?></td></tr>
<tr><td><?php echo cam_t('TEXT.LETZTES_BILD'); ?></td>
    <td><?= (int) $ac_k['alter_min'] >= 0 ? sprintf(cam_t('TEXT.KOPF_VOR_MIN'), (int) $ac_k['alter_min']) : '&ndash;' ?></td></tr>
</table>

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

<!-- ================= Einstellungen ================= -->
<div class="sm-pane<?= $ac_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?php echo cam_t('TEXT.WAS_IST_DAS'); ?></div>
<?php /* Seit 1.9.32 (E-E22, N8): EINE Legende oben im Reiter, direkt nach dem Kasten - bis 1.9.31 stand sie unter
         dem ersten Knopf. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo cam_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo cam_t('LEGENDE.AKTION'); ?></span>
</div>
<?php
/* X-2: nach einer Beanstandung zeigt das Formular die eingetippten Werte -
   nur bis </form>, danach gelten wieder die gespeicherten. */
$ac_cfg_gespeichert = $ac_cfg;
$ac_notify_gespeichert = $ac_notify;
$ac_cfg = cam_eingaben_ueberlagern($ac_cfg, '', array('pruef_minuten', 'mindestpause', 'keep_days',
    'clip_seconds', 'clip_fps', 'keep_max', 'keep_mb', 'timelapse', 'timelapse_time', 'ai_url', 'ai_min',
    'webhook1', 'webhook2', 'stream_fps', 'stream_maxsec', 'stream_mode', 'bild_fest'));
$ac_notify['push'] = cam_eingabe('notify_push', $ac_notify['push']);
$ac_notify['push_minutes'] = cam_eingabe('push_minutes', $ac_notify['push_minutes']);
?>
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
    // Der Loesch-Haken haengt am GESPEICHERTEN Zugang, nicht an der Eingabe.
    $ac_zugang_da = (string) $ac_k['user'] !== '' || (string) $ac_k['pass'] !== '';
    $ac_k = cam_eingaben_ueberlagern($ac_k, $ac_s, array('name', 'host', 'user', 'auth', 'snapurl',
        'snapcmd', 'resolution', 'timeout', 'channel', 'mjpeg_url', 'rtsp_url', 'rtsp_port',
        'rtsp_stream', 'rtsp_quality'));
?>
<h2><?= $ac_neu ? ac_e(sprintf(cam_t('TEXT.KAMERA_HINZU'), $ac_i))
       : ac_e(cam_t('TEXT.KAMERA') . ' ' . ($ac_i > 1 || count($ac_zeigen) > 1
              ? cam_kname($ac_i) : '')) ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo cam_t('TEXT.L_KAMERANAME'); ?></label>
        <input data-role="none" type="text" name="name<?= $ac_s ?>"<?= cam_markierung('name' . $ac_s) ?> value="<?= ac_e($ac_k['name']) ?>" placeholder="<?php echo cam_t('TEXT.P_KAMERANAME'); ?>">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.ADRESSE_IP_ODER_HOSTNAME'); ?></label>
        <input data-role="none" type="text" name="host<?= $ac_s ?>"<?= cam_markierung('host' . $ac_s) ?> value="<?= ac_e($ac_k['host']) ?>" placeholder="192.168.1.17">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.BENUTZER'); ?></label>
        <input data-role="none" type="text" name="user<?= $ac_s ?>"<?= cam_markierung('user' . $ac_s) ?> value="<?= ac_e($ac_k['user']) ?>" placeholder="admin">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.PASSWORT'); ?></label>
        <input data-role="none" type="password" name="pass<?= $ac_s ?>"<?= cam_markierung('pass' . $ac_s) ?> value="" placeholder="<?= ac_e($ac_k['pass'] !== '' ? cam_t('TEXT.P_PASS_GESETZT') : cam_t('TEXT.P_PASS_LEER')) ?>">
    </div>
<?php if ($ac_zugang_da) { ?>
    <div>
        <label style="font-weight:400;"><input data-role="none" type="checkbox" name="zugang_loeschen<?= $ac_s ?>" value="1"<?= cam_eingabe('zugang_loeschen' . $ac_s, '0') === '1' ? ' checked' : '' ?>> <?php echo cam_t('TEXT.L_ZUGANG_LOESCHEN'); ?></label>
    </div>
<?php } ?>
</div>
<div class="sm-row" style="margin-top:8px;">
    <div style="max-width:340px;">
        <label><?php echo cam_t('TEXT.ANMELDEVERFAHREN'); ?></label>
        <select data-role="none" name="auth<?= $ac_s ?>"<?= cam_markierung('auth' . $ac_s) ?>>
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
    <div class="acw-breit">
        <label><?php echo cam_t('TEXT.VOLLSTNDIGE_SCHNAPPSCHUSS_URL_EMPF'); ?></label>
        <input data-role="none" type="text" name="snapurl<?= $ac_s ?>"<?= cam_markierung('snapurl' . $ac_s) ?> value="<?= ac_e(cam_zugang_maske($ac_k['snapurl'])) ?>" placeholder="<?php echo cam_t('TEXT.HTTP'); ?>KAMERA/cgi-bin/encoder?<?php echo cam_t('TEXT.USER_PWD'); ?>SNAPSHOT=N1920x1080,100<?php echo cam_t('TEXT.DUMMY_N'); ?>">
<?php if ($ac_i === 1) { ?>
        <div class="sm-small"><?php echo cam_t('TEXT.IST_DIESES_FELD_GEFLLT_NUTZT_DAS_P'); ?> <b><?php echo cam_t('TEXT.GENAU_DIESE_ADRESSE'); ?></b> <?php echo cam_t('TEXT.OHNE_EIGENES_ZUSAMMENBAUEN_OHNE_UM'); ?> <span class="sm-mono">ERROR: not authorized</span>.<br>
        <b><?php echo cam_t('TEXT.HINWEIS'); ?></b> <?php echo cam_t('TEXT.DIESE_URL_ENTHLT_DAS_KAMERA_PASSWO'); ?><span class="sm-mono">chmod 600</span><?php echo cam_t('TEXT.UND_WIRD_IN_PROTOKOLL_UND_DIAGNOSE'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.CAM_PHP'); ?></span> <?php echo cam_t('TEXT.OHNE_ZUGANGSDATEN'); ?></div>
        <div class="sm-small"><?php echo cam_t('TEXT.H_SNAPURL_SCHEMA'); ?></div>
<?php } ?>
    </div>
    <div class="acw-breit">
        <label><?php echo cam_t('TEXT.SCHNAPPSCHUSS_BEFEHL_NUR_DER_TEIL_'); ?> <span class="sm-mono">USER=…&amp;PWD=…&amp;</span> <?php echo cam_t('TEXT.WIRD_IGNORIERT_WENN_OBEN_EINE_URL_'); ?></label>
        <input data-role="none" type="text" name="snapcmd<?= $ac_s ?>"<?= cam_markierung('snapcmd' . $ac_s) ?> value="<?= ac_e(cam_zugang_maske($ac_k['snapcmd'])) ?>" placeholder="SNAPSHOT=N1920x1080,100&amp;DUMMY=n">
<?php if ($ac_i === 1) { ?>
        <div class="sm-small"><?php echo cam_t('TEXT.DAS_IST_DER_TEIL_HINTER'); ?> <span class="sm-mono">USER=…&amp;PWD=…&amp;</span><?php echo cam_t('TEXT.DER_VORGABEWERT_STAMMT_AUS_EINER_R'); ?><span class="sm-mono">,100</span><?php echo cam_t('TEXT.UND_DEM_ABSCHLIESSENDEN'); ?> <span class="sm-mono">&amp;DUMMY=n</span><?php echo cam_t('TEXT.DAS_MANCHE_FIRMWARE_ERWARTET_WER_E'); ?></div>
<?php } ?>
    </div>
    <div>
        <label><?php echo cam_t('TEXT.AUFLSUNG_NUR_ALS_ERSATZ'); ?></label>
        <input data-role="none" type="text" name="resolution<?= $ac_s ?>"<?= cam_markierung('resolution' . $ac_s) ?> value="<?= ac_e($ac_k['resolution']) ?>" placeholder="<?php echo ac_e(cam_t('TEXT.P_AUFLOESUNG')); ?>">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.ZEITLIMIT_JE_BILD_SEKUNDEN'); ?></label>
        <input data-role="none" type="number" name="timeout<?= $ac_s ?>"<?= cam_markierung('timeout' . $ac_s) ?> value="<?= ac_e((string) $ac_k['timeout']) ?>" min="2" max="30">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.KANAL'); ?></label>
        <input data-role="none" type="number" name="channel<?= $ac_s ?>"<?= cam_markierung('channel' . $ac_s) ?> value="<?= ac_e((string) $ac_k['channel']) ?>" min="1" max="16">
    </div>
</div>
<div class="sm-row" style="margin-top:8px;">
    <div class="acw-breit">
        <label><?php echo cam_t('TEXT.ADRESSE_DES_KAMERASTROMS_LEER'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.CGI_BIN_CMD_SYSTEM_GET_STREAM'); ?></span>)</label>
        <input type="text" data-role="none" name="mjpeg_url<?= $ac_s ?>"<?= cam_markierung('mjpeg_url' . $ac_s) ?> value="<?= ac_e(cam_zugang_maske((string) $ac_k['mjpeg_url'])) ?>">
    </div>
    <div class="acw-breit">
        <label><?php echo cam_t('TEXT.RTSP_ADRESSE_LEER_GEBILDET'); ?></label>
        <input type="text" data-role="none" name="rtsp_url<?= $ac_s ?>"<?= cam_markierung('rtsp_url' . $ac_s) ?> placeholder="<?php echo ac_e(cam_t('TEXT.P_RTSP')); ?>" value="<?= ac_e(cam_zugang_maske((string) $ac_k['rtsp_url'])) ?>">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.RTSP_PORT'); ?></label>
        <input type="number" min="1" max="65535" data-role="none" name="rtsp_port<?= $ac_s ?>"<?= cam_markierung('rtsp_port' . $ac_s) ?> value="<?= ac_e((string) $ac_k['rtsp_port']) ?>">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.WELCHER_STROM'); ?></label>
        <select data-role="none" name="rtsp_stream<?= $ac_s ?>"<?= cam_markierung('rtsp_stream' . $ac_s) ?>>
            <option value="2"<?= ((int) $ac_k['rtsp_stream']) !== 1 ? ' selected' : '' ?>><?php echo cam_t('TEXT.STREAM2_NEBENSTROM_KLEINER_UND_SPA'); ?></option>
            <option value="1"<?= ((int) $ac_k['rtsp_stream']) === 1 ? ' selected' : '' ?>><?php echo cam_t('TEXT.STREAM1_HAUPTSTROM_VOLLE_AUFLSUNG'); ?></option>
        </select>
    </div>
    <div>
        <label><?php echo cam_t('TEXT.BILDGTE_BEI_RTSP_2_FEIN_UND_GRO_15'); ?></label>
        <input type="number" min="2" max="15" data-role="none" name="rtsp_quality<?= $ac_s ?>"<?= cam_markierung('rtsp_quality' . $ac_s) ?> value="<?= ac_e((string) $ac_k['rtsp_quality']) ?>">
    </div>
</div>
<div class="sm-small"><?php echo cam_t('TEXT.H_RTSP_MJPEG'); ?></div>
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
        <input data-role="none" type="number" name="pruef_minuten"<?= cam_markierung('pruef_minuten') ?> value="<?= ac_e((string) $ac_cfg['pruef_minuten']) ?>" min="0" max="1440">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.L_MINDESTPAUSE'); ?></label>
        <input data-role="none" type="number" name="mindestpause"<?= cam_markierung('mindestpause') ?> value="<?= ac_e((string) $ac_cfg['mindestpause']) ?>" min="0" max="3600">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.AUFBEWAHRUNG_TAGE'); ?></label>
        <input data-role="none" type="number" name="keep_days"<?= cam_markierung('keep_days') ?> value="<?= ac_e((string) $ac_cfg['keep_days']) ?>" min="0" max="3650">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.CLIPLAENGE_SEKUNDEN'); ?></label>
        <input data-role="none" type="number" name="clip_seconds"<?= cam_markierung('clip_seconds') ?> value="<?= ac_e((string) $ac_cfg['clip_seconds']) ?>" min="2" max="60">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.BILDER_JE_SEKUNDE_IM_CLIP'); ?></label>
        <input data-role="none" type="number" name="clip_fps"<?= cam_markierung('clip_fps') ?> value="<?= ac_e((string) $ac_cfg['clip_fps']) ?>" min="1" max="5">
    </div>
</div>
<div class="sm-small"><?php echo cam_t('TEXT.EIN_CLIP_IST_EINE_BILDSERIE_DAMIT_'); ?></div>
<div class="sm-small"><?php echo cam_t('TEXT.H_PRUEF_MINUTEN'); ?></div>
<div class="sm-small"><?php echo cam_t('TEXT.H_MINDESTPAUSE'); ?></div>

<div class="sm-row" style="margin-top:10px;">
    <div>
        <label><?php echo cam_t('TEXT.HCHSTZAHL_DATEIEN_JE_ARCHIV'); ?></label>
        <input data-role="none" type="number" name="keep_max"<?= cam_markierung('keep_max') ?> value="<?= ac_e((string) $ac_cfg['keep_max']) ?>" min="0" max="100000">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.L_KEEP_MB'); ?></label>
        <input data-role="none" type="number" name="keep_mb"<?= cam_markierung('keep_mb') ?> value="<?= ac_e((string) $ac_cfg['keep_mb']) ?>" min="0" max="1000000">
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
        <input data-role="none" type="text" name="timelapse_time"<?= cam_markierung('timelapse_time') ?> value="<?= ac_e($ac_cfg['timelapse_time']) ?>" placeholder="12:00">
    </div>
</div>
<div class="sm-small"><?php echo cam_t('TEXT.DIE_BILDER_LANDEN_IM_UNTERORDNER'); ?> <span class="sm-mono">timelapse</span><?php echo cam_t('TEXT.DER_DATEINAME_IST_DAS_DATUM_IST'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.FFMPEG'); ?></span> <?php echo cam_t('TEXT.AUF_DEM_LOXBERRY_VORHANDEN_WIRD_NA'); ?> 
<span class="sm-mono"><?php echo cam_t('TEXT.ZEITRAFFER_MP4'); ?></span> <?php echo cam_t('TEXT.AUS_ALLEN_BILDERN_NEU_ERZEUGT_ABRU'); ?> 
<span class="sm-mono">http://<?= ac_e($ac_host) ?><?php echo cam_t('TEXT.PLUGINS'); ?><?= ac_e($ac_plugin) ?><?php echo cam_t('TEXT.ZEITRAFFER_MP4_2'); ?><?= $ac_bt ?></span><?php echo cam_t('TEXT.FEHLT_FFMPEG_BLEIBEN_DIE_EINZELBIL'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.SUDO_APT_GET_INSTALL_Y_FFMPEG'); ?></span>).</div>

<h2><?php echo cam_t('TEXT.KI_OBJEKTERKENNUNG_OPTIONAL'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo cam_t('TEXT.ERKENNUNGS_ENDPUNKT'); ?></label>
        <input data-role="none" type="text" name="ai_url"<?= cam_markierung('ai_url') ?> value="<?= ac_e($ac_cfg['ai_url']) ?>" placeholder="http://192.0.2.10:32168/v1/vision/detection">
    </div>
    <div style="max-width:220px;">
        <label><?php echo cam_t('TEXT.MINDEST_KONFIDENZ'); ?></label>
        <input data-role="none" type="number" name="ai_min"<?= cam_markierung('ai_min') ?> value="<?= ac_e((string) $ac_cfg['ai_min']) ?>" min="1" max="99">
    </div>
</div>
<div class="sm-small"><?php echo cam_t('TEXT.BENTIGT_EINEN_ERKENNUNGSDIENST_AUF'); ?> 
<b><?php echo cam_t('TEXT.CODEPROJECT_AI_SERVER'); ?></b> <?php echo cam_t('TEXT.PORT_32168_ODER'); ?> <b><?php echo cam_t('TEXT.DEEPSTACK'); ?></b><?php echo cam_t('TEXT.DER_LOXBERRY_SELBST_IST_DAFR_ZU_SC'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.PERSON'); ?></span>, <span class="sm-mono">car</span><?php echo cam_t('TEXT.ERSCHEINEN_IM_JSON_IN_DEN_WEBHOOKS'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.ERKANNT'); ?></span> <?php echo cam_t('TEXT.IN_DER_LOXONE_AUSGABE_ZUSTZLICH_GI'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.PERSON_1'); ?></span> <?php echo cam_t('TEXT.ALS_FERTIGEN_SCHALTER_LEER_LASSEN_'); ?></div>

<h2><?php echo cam_t('TEXT.WEBHOOKS_OPTIONAL'); ?></h2>
<div class="sm-row">
    <div>
        <label><?php echo cam_t('TEXT.WEBHOOK_1_POST_MIT_JSON'); ?></label>
        <input data-role="none" type="text" name="webhook1"<?= cam_markierung('webhook1') ?> value="<?= ac_e($ac_cfg['webhook1']) ?>" placeholder="https://…">
    </div>
    <div>
        <label><?php echo cam_t('TEXT.WEBHOOK_2_GET_MIT_PARAMETERN'); ?></label>
        <input data-role="none" type="text" name="webhook2"<?= cam_markierung('webhook2') ?> value="<?= ac_e($ac_cfg['webhook2']) ?>" placeholder="https://…">
    </div>
</div>
<div class="sm-small"><?php echo cam_t('TEXT.BEIDE_WERDEN_NACH_JEDER_AUFNAHME_A'); ?> 
<span class="sm-mono"><?= ac_e('{' . implode(', ', cam_webhook_felder()) . '}') ?></span> <?php echo cam_t('TEXT.ALS_JSON_WEBHOOK2_HNGT'); ?> 
<span class="sm-mono"><?= ac_e('?' . implode('=…&', cam_webhook2_parameter()) . '=…') ?></span> <?php echo cam_t('TEXT.AN_DIE_ADRESSE_AN'); ?></div>

<h2><?php echo cam_t('TEXT.BENACHRICHTIGUNG'); ?></h2>
<label style="display:inline-flex;align-items:center;gap:6px;">
    <input data-role="none" type="checkbox" name="notify_push" <?= !empty($ac_notify['push']) ? 'checked' : '' ?>> <?php echo cam_t('TEXT.PUSH_FREIGABE_AN_LOXONE_MELDEN'); ?>
</label>
<div class="sm-row" style="margin-top:6px;">
    <div style="max-width:260px;">
        <label><?php echo cam_t('TEXT.MELDEFENSTER_NACH_EINER_AUFNAHME_M'); ?></label>
        <input data-role="none" type="number" name="push_minutes"<?= cam_markierung('push_minutes') ?> value="<?= ac_e((string) $ac_notify['push_minutes']) ?>" min="1" max="30">
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
<span class="sm-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?><?php echo cam_t('TEXT.CAM_STREAM_PHP'); ?><?= $ac_bt1 ?></span> <?php echo cam_t('TEXT.LIVEBILD_INTVIDEOURL'); ?><br>
<span class="sm-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?><?php echo cam_t('TEXT.CAM_STREAM_PHP_EINZELN_1'); ?><?= $ac_bt ?></span> <?php echo cam_t('TEXT.EINZELBILD_INTALERTIMAGE'); ?></p>
<label><?php echo cam_t('TEXT.BILDER_JE_SEKUNDE'); ?></label>
<input type="number" step="0.1" min="0.2" max="10" data-role="none" name="stream_fps"<?= cam_markierung('stream_fps') ?> value="<?= ac_e((string) $ac_cfg['stream_fps']) ?>">
<label><?php echo cam_t('TEXT.HCHSTDAUER_EINES_STROMS_IN_SEKUNDE'); ?></label>
<input type="number" min="5" max="900" data-role="none" name="stream_maxsec"<?= cam_markierung('stream_maxsec') ?> value="<?= ac_e((string) $ac_cfg['stream_maxsec']) ?>">
<label><?php echo cam_t('TEXT.BILDQUELLE'); ?></label>
<select data-role="none" name="stream_mode"<?= cam_markierung('stream_mode') ?>>
<option value="auto"<?= $ac_cfg['stream_mode'] === 'auto' ? ' selected' : '' ?>><?php echo cam_t('TEXT.AUTOMATISCH_KAMERASTROM_SONST_RTSP'); ?></option>
<option value="mjpeg"<?= $ac_cfg['stream_mode'] === 'mjpeg' ? ' selected' : '' ?>><?php echo cam_t('TEXT.NUR_KAMERASTROM_GET_STREAM_EMPFOHL'); ?></option>
<option value="rtsp"<?= $ac_cfg['stream_mode'] === 'rtsp' ? ' selected' : '' ?>><?php echo cam_t('TEXT.NUR_RTSP_FLSSIGES_VIDEO_BRAUCHT_FF'); ?></option>
<option value="jpeg"<?= $ac_cfg['stream_mode'] === 'jpeg' ? ' selected' : '' ?>><?php echo cam_t('TEXT.NUR_SCHNAPPSCHSSE'); ?></option>
</select>
<p class="sm-hint"><?php echo cam_t('TEXT.FFMPEG_AUF_DIESEM_LOXBERRY'); ?>
<?php $ac_ff = cam_ffmpeg(); ?>
<?= $ac_ff !== '' ? '<b style="color:#2e7d32;">' . cam_t('TEXT.VORHANDEN') . '</b> (' . ac_e($ac_ff) . ')' : '<b style="color:#c62828;">' . cam_t('TEXT.NICHT_VORHANDEN') . '</b> &ndash; ' . cam_t('TEXT.L_OHNE_FFMPEG') ?></p>
<p class="sm-hint"><?php echo cam_t('TEXT.BLEIBT_DAS_FELD_LEER_WIRD'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.RTSP_KAMERA_PORT_STREAMNR'); ?></span> <?php echo cam_t('TEXT.GEBILDET_BENUTZER_UND_PASSWORT_SET'); ?></p>
<?php $ac_rr = cam_rtsp_url(true); if ($ac_rr === '') { $ac_rr = ''; } ?>
<label><?php echo cam_t('TEXT.TOKEN_OPTIONAL_DANN_NUR_MIT'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.T_TOKEN'); ?></span> <?php echo cam_t('TEXT.ABRUFBAR'); ?></label>
<input type="text" data-role="none" name="stream_token"<?= cam_markierung('stream_token') ?> value="<?= ac_e((string) $ac_cfg['stream_token']) ?>">
<p class="sm-hint"><?php echo cam_t('TEXT.TOKEN_HINWEIS_LOXONE'); ?></p>
<div class="sm-row"><label><input data-role="none" type="checkbox" name="bild_fest" value="1"<?= !empty($ac_cfg['bild_fest']) ? ' checked' : '' ?>> <?php echo cam_t('TEXT.L_BILD_FEST'); ?></label></div>
<p class="sm-hint"><?php echo cam_t('TEXT.H_BILD_FEST'); ?></p>
<?php if (!empty($ac_cfg['bild_fest']) && trim((string) $ac_cfg['stream_token']) !== '') { ?>
<div class="sm-warnung"><?php echo cam_t('TEXT.W_BILD_FEST'); ?></div>
<?php } ?>
<div style="margin-top:16px;"><button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo cam_t('TEXT.SPEICHERN'); ?></button></div>
</form>
<?php $ac_cfg = $ac_cfg_gespeichert; $ac_notify = $ac_notify_gespeichert; ?>

<h2><?= cam_t('TEXT.H_SICHERUNG') ?></h2>
<div class="sm-hinweis"><?= cam_t('TEXT.SICH_ERKLAERUNG') ?></div>
<div class="sm-warnung"><?= cam_t('TEXT.SICH_WARNUNG') ?></div>
<?php /* X-3: dieselbe Pruefung wie das Zurueckspielen, nur Namen. */
$ac_x3 = cam_rueckspiel_befund();
if ($ac_x3) { ?>
<div class="sm-alert sm-warn"><?= sprintf(cam_t('TEXT.SICH_X3_WARNUNG'), ac_e(implode(', ', $ac_x3))) ?></div>
<?php } ?>
<div class="sm-knopfreihe">
  <!-- ZWEI GETRENNTE Formulare. Das Sichern schickt einen Download und ruft
       exit auf; das Zurueckspielen braucht enctype="multipart/form-data".
       Wer beides in ein Formular legt, bekommt entweder keinen Upload oder
       einen Download, der das Speichern verschluckt. -->
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="cam_sichern" value="1"><?= cam_t('TEXT.K_SICHERN') ?></button>
  </form>
  <form action="index.php" method="post" enctype="multipart/form-data">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="file" name="cam_sicherung" accept=".json">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="cam_zurueck" value="1"><?= cam_t('TEXT.K_ZURUECK') ?></button>
  </form>
</div>
</div>

<!-- ================= MQTT ================= -->
<div class="sm-pane<?= $ac_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2><?php echo cam_t('MQTT.H_TITEL'); ?></h2>
<?php /* Seit 1.9.32 (E-E22): EINE Legende oben im Reiter - hier steht nur der orange Knopf Speichern. */ ?>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?php echo cam_t('LEGENDE.AKTION'); ?></span></div>
<?php $ac_mz = cam_mqtt_zustand_pruefen(); ?>
<?php if (!$ac_mz['gefunden']) { ?>
<div class="sm-alert sm-err"><?php echo cam_t('MQTT.KEIN_ABSCHNITT'); ?></div>
<?php } elseif (!$ac_mz['autostart']) { ?>
<div class="sm-alert sm-err"><?php echo cam_t('MQTT.KEIN_AUTOSTART'); ?></div>
<?php } else { ?>
<div class="sm-alert sm-ok"><?= sprintf(cam_t('MQTT.OK'), (int) $ac_mz['udpport']) ?></div>
<?php } ?>
<?php /* Seit 1.9.32 (E-E11): MQTT ist fuer neue Anlagen ab Werk an. Steht es hier aus (bestehende Anlage), ein
         grauer Satz - kein Fehler, der HTTP-Endpunkt bleibt. */
if (empty($ac_cfg['mqtt_enabled'])) { ?>
<div class="sm-small" style="color:#666;margin:6px 0;"><?php echo cam_t('MQTT.AUS_HINWEIS'); ?></div>
<?php } ?>

<div class="sm-step"><?php echo cam_t('MQTT.WARUM'); ?></div>

<h3 class="sm-h3"><?php echo cam_t('MQTT.H_ABO'); ?></h3>
<div class="sm-small"><?php echo cam_t('MQTT.ABO_TEXT'); ?></div>
<div class="sm-mono" style="display:block;padding:8px;margin:6px 0;"><?= ac_e($ac_cfg['mqtt_topic']) ?>/#</div>
<?php
/* Vier Ausgaenge, nicht einer. Der V1-Satz gilt nur fuer Gateway V1; ab V2
 * erscheint die Themengruppe von selbst. Ist die Fassung nicht feststellbar,
 * werden BEIDE Faelle genannt statt einer behauptet.
 *
 * Die Abo-Datei zaehlt mit (ACTiKamera-a1, X-6): seit 1.9.23 schreibt das
 * Plugin mqtt_subscriptions.cfg, und das Gateway abonniert daraus selbst
 * (Regeln/07). Rot ist der Satz nur, wenn die Datei fehlt oder ein anderes
 * Praefix traegt. */
$ac_gwf = (int) $ac_mz['fassung'];
list($ac_abo_pfad, $ac_abo_da) = cam_mqtt_abo_datei(false);
if ($ac_gwf >= 2) { ?>
<div class="sm-alert sm-ok"><?php echo cam_t('MQTT.ABO_V2'); ?>
<span class="sm-mono"><?= sprintf(cam_t('MQTT.ABO_GEMESSEN'), $ac_gwf) ?></span></div>
<?php } elseif ($ac_abo_da) { ?>
<div class="sm-alert sm-ok"><?php echo cam_t('MQTT.ABO_V1_DATEI'); ?><?php if ($ac_gwf === 1) { ?>
<span class="sm-mono"><?= sprintf(cam_t('MQTT.ABO_GEMESSEN'), $ac_gwf) ?></span><?php } ?></div>
<?php } elseif ($ac_gwf === 1) { ?>
<div class="sm-alert sm-err"><?php echo cam_t('MQTT.ABO_WARNUNG'); ?>
<span class="sm-mono"><?= sprintf(cam_t('MQTT.ABO_GEMESSEN'), $ac_gwf) ?></span></div>
<?php } else { ?>
<div class="sm-alert sm-err"><?php echo cam_t('MQTT.ABO_UNBEKANNT'); ?></div>
<?php } ?>
<div class="sm-small"><?= sprintf(cam_t($ac_abo_da ? 'MQTT.ABO_DATEI_JA' : 'MQTT.ABO_DATEI_NEIN'),
    '<span class="sm-mono">' . ac_e($ac_abo_pfad) . '</span>',
    '<span class="sm-mono">' . ac_e(cam_mqtt_praefix($ac_cfg)) . '/#</span>') ?></div>

<?php
/* Die V2-Hakenliste (seit 1.9.32, E-E10): nur bei Gateway-Fassung 2 oder wenn
 * die Fassung nicht feststellbar ist. Dieselbe Quelle wie die Namenstabelle im
 * Reiter Einbindung (cam_mqtt_namenstabelle()). */
$ac_nt = cam_mqtt_namenstabelle($ac_cfg);
if ($ac_gwf >= 2 || $ac_gwf === 0) { ?>
<h3 class="sm-h3"><?php echo cam_t('MQTT.H_V2'); ?></h3>
<div class="sm-small"><?php echo cam_t('MQTT.V2_TEXT'); ?></div>
<table class="sm-tbl">
<tr><th><?php echo cam_t('MQTT.T_THEMA'); ?></th><th><?php echo cam_t('MQTT.T_GATEWAYNAME'); ?></th><th><?php echo cam_t('MQTT.T_NONCACHED'); ?></th></tr>
<?php foreach ($ac_nt as $ac_ne) {
    if (!$ac_ne['haken']) { continue; } ?>
<tr><td><span class="sm-mono"><?= ac_e($ac_ne['voll']) ?></span></td><td><span class="sm-mono"><?= ac_e($ac_ne['name']) ?></span></td>
    <td><?php echo $ac_ne['noncached'] ? cam_t('ALLGEMEIN.JA') : '&ndash;'; ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo cam_t('MQTT.V2_OHNE'); ?></div>
<div class="sm-small"><?= sprintf(cam_t('MQTT.V2_HAUS'), '<span class="sm-mono">haus/anwesenheit/jemand</span>',
    '<span class="sm-mono">haus/anwesenheit/zaehler</span>', '<span class="sm-mono">' . ac_e(cam_mqtt_praefix($ac_cfg)) . '/status/befehle</span>') ?></div>
<?php } ?>

<h3 class="sm-h3"><?php echo cam_t('MQTT.H_THEMEN'); ?></h3>
<table class="sm-tbl">
<tr><th><?php echo cam_t('MQTT.T_THEMA'); ?></th><th><?php echo cam_t('MQTT.T_BEDEUTUNG'); ?></th><th><?php echo cam_t('MQTT.T_RETAINED'); ?></th><th><?php echo cam_t('MQTT.T_WERT'); ?></th></tr>
<?php /* Die Liste kommt aus cam_mqtt_themenliste(), nicht aus cam_felder():
         cam_felder() fuehrt auch Felder, die gar nicht ueber MQTT gehen
         (HERZ, ZAEHLER), und es fehlten umgekehrt die Themen jeder Aufnahme
         und das Lebenszeichen. Der Reiter Test haelt diese Liste gegen die
         cam_mqtt()-Aufrufe im Quelltext. */ ?>
<?php $ac_w = cam_werte();
foreach (cam_kameras() as $ac_wk) {
    foreach (cam_mqtt_zustand_zusatz($ac_wk) as $ac_wn => $ac_wv) { $ac_w[$ac_wn . cam_sx($ac_wk)] = $ac_wv; }
}
$ac_w['zaehler'] = cam_herzzaehler(); ?>
<?php foreach (cam_mqtt_themenliste() as $ac_n => $ac_d) { ?>
<tr><td><span class="sm-mono"><?= ac_e($ac_cfg['mqtt_topic']) ?>/<?= ac_e($ac_n) ?></span></td>
    <td><?php echo cam_t($ac_d[1]); ?></td>
    <td><?php echo cam_t($ac_d[0] ? 'MQTT.RET_JA' : 'MQTT.RET_NEIN'); ?></td>
    <td><?= isset($ac_w[$ac_n]) ? ac_e($ac_w[$ac_n]) : '&mdash;' ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo cam_t('MQTT.BILD_THEMEN'); ?></div>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save_mqtt" value="1">
<input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<div class="sm-row"><label><input data-role="none" type="checkbox" name="mqtt_enabled" value="1"<?= cam_eingabe('mqtt_enabled', !empty($ac_cfg['mqtt_enabled']) ? '1' : '0') === '1' ? ' checked' : '' ?>> <?php echo cam_t('MQTT.L_EIN'); ?></label></div>
<div class="sm-row"><label><?php echo cam_t('MQTT.L_TOPIC'); ?></label>
<input data-role="none" type="text" name="mqtt_topic"<?= cam_markierung('mqtt_topic') ?> value="<?= ac_e(cam_eingabe('mqtt_topic', $ac_cfg['mqtt_topic'])) ?>" size="24"></div>
<?php /* Befehle aus Loxone (seit 1.9.32, Entscheidung 2, E-E1 bis E-E5): ein Haken, ab Werk aus, gespeichert mit
         demselben Formular. Danach schaltet die Seite die Bruecke sofort (cam_bruecke_schalten()). */ ?>
<h3 class="sm-h3"><?php echo cam_t('BEFEHL.H_BEFEHLE'); ?></h3>
<div class="sm-row"><label><input data-role="none" type="checkbox" name="befehle_mqtt_ein" value="1"<?= cam_eingabe('befehle_mqtt_ein', !empty($ac_cfg['befehle_mqtt_ein']) ? '1' : '0') === '1' ? ' checked' : '' ?>> <?php echo cam_t('BEFEHL.L_ANNEHMEN'); ?></label></div>
<div class="sm-small"><?php echo cam_t('BEFEHL.H_ANNEHMEN'); ?></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo cam_t('TEXT.SPEICHERN'); ?></button>
</div>
</form>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-pane<?= $ac_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?php echo cam_t('TEXT.EINBINDUNG_IN_LOXONE_SCHRITT_FR_SC'); ?></h2>
<?php /* Seit 1.9.32 (E-E22, N8): EINE Legende oben im Reiter - bis 1.9.31 standen hier zwei (Token, Vorlagen).
         Gruen: Kopierknoepfe der Namenstabelle; grau: Vorlagen; orange: Token neu, Speichern des Lesezweigs. */ ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?php echo cam_t('LEGENDE.LESEN'); ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?php echo cam_t('LEGENDE.TECHNIK'); ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo cam_t('LEGENDE.AKTION'); ?></span>
</div>
<p><?php echo cam_t('TEXT.ZIEL_BEIM_KLINGELN_LOXONE_INTERCOM'); ?> <i><?php echo cam_t('TEXT.ODER'); ?></i> <?php echo cam_t('TEXT.TASTER_AN_DER_HAUSTR_MACHT_DIE_KAM'); ?>
<b><?php echo cam_t('TEXT.OHNE_KAMERA_PASSWORT_IN_DER_PROJEK'); ?></b>.</p>

<?php
/* Schritt 1 (seit 1.9.32, Entscheidung 2, E-E10): Werte ueber das MQTT-Gateway. Die Namenstabelle kommt aus
 * cam_mqtt_namenstabelle() - dieselbe Quelle wie Sendeliste, Hakenliste (Reiter MQTT) und Bausteinliste. */
$ac_nt = cam_mqtt_namenstabelle($ac_cfg);
?>
<div class="sm-step"><b><?php echo cam_t('LOX.S_WERTE'); ?></b><br>
<?php echo cam_t('LOX.WERTE_TEXT'); ?>
<table class="sm-tbl">
<tr><th><?php echo cam_t('LOX.T_NAME'); ?></th><th><?php echo cam_t('LOX.T_BAUFORM'); ?></th><th><?php echo cam_t('MQTT.T_BEDEUTUNG'); ?></th>
    <th><?php echo cam_t('LOX.T_WERTE'); ?></th><th><?php echo cam_t('MQTT.T_RETAINED'); ?></th><th><?php echo cam_t('LOX.T_MUSTER'); ?></th><th></th></tr>
<?php foreach ($ac_nt as $ac_ne) { ?>
<tr><td><span class="sm-mono"><?= ac_e($ac_ne['name']) ?></span><br><span class="sm-small"><?= ac_e($ac_ne['voll']) ?></span></td>
    <td><?php echo cam_t($ac_ne['text'] ? 'LOX.B_TEXTEINGANG' : 'LOX.B_EINGANG'); ?></td>
    <td><?php echo cam_t($ac_ne['bez']); ?><?= count(cam_kameras()) > 1 ? ' <i>[' . ac_e(cam_kname($ac_ne['kamera'])) . ']</i>' : '' ?></td>
    <td><?= ac_e($ac_ne['bereich']) ?></td>
    <td><?php echo cam_t($ac_ne['retain'] ? 'MQTT.RET_JA' : 'MQTT.RET_NEIN'); ?><?php echo $ac_ne['noncached'] ? ', ' . cam_t('LOX.NONCACHED') : ''; ?></td>
    <td><?= $ac_ne['mp'] !== '' ? ac_e($ac_ne['mp']) : '&ndash;' ?></td>
    <td><button data-role="none" type="button" class="sm-btn sm-b-lesen ac-kopie" data-kopie="<?= ac_e($ac_ne['name']) ?>"><?php echo cam_t('LOX.K_KOPIEREN'); ?></button></td></tr>
<?php } ?>
<?php foreach (array(array('haus_anwesenheit_jemand', 'haus/anwesenheit/jemand', 'LOX.HAUS_JEMAND', '0 … 1', true, 'Aufnahmen #23'),
                     array('haus_anwesenheit_zaehler', 'haus/anwesenheit/zaehler', 'LOX.HAUS_ZAEHLER', '0 … 999', false, 'Aufnahmen #24')) as $ac_hw) { ?>
<tr><td><span class="sm-mono"><?= ac_e($ac_hw[0]) ?></span><br><span class="sm-small"><?= ac_e($ac_hw[1]) ?></span></td>
    <td><?php echo cam_t('LOX.B_EINGANG'); ?></td><td><?php echo cam_t($ac_hw[2]); ?></td><td><?= ac_e($ac_hw[3]) ?></td>
    <td><?php echo cam_t($ac_hw[4] ? 'MQTT.RET_JA' : 'MQTT.RET_NEIN'); ?></td><td><?= ac_e($ac_hw[5]) ?></td>
    <td><button data-role="none" type="button" class="sm-btn sm-b-lesen ac-kopie" data-kopie="<?= ac_e($ac_hw[0]) ?>"><?php echo cam_t('LOX.K_KOPIEREN'); ?></button></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo cam_t('LOX.WERTE_RECHTE'); ?></div>
<div class="sm-small"><?php echo cam_t('LOX.WERTE_HAUS'); ?></div>
<div class="sm-small"><?php echo cam_t('LOX.WERTE_OBJEKTE'); ?></div>
<div class="sm-small"><?php echo cam_t('LOX.WERTE_BILDEREIGNIS'); ?></div>
</div>

<?php
/* Schritt 2 (seit 1.9.32, E-E1 bis E-E5, E-E20): Befehle ueber die Befehlsbruecke. Die Tabelle kommt aus
 * bb_liste(), die Befehle der Vorlage aus cam_bruecke_vorlagenbefehle() - eine Quelle. */
list($ac_bli, $ac_blf) = cam_bruecke_liste();
?>
<div class="sm-step"><b><?php echo cam_t('BEFEHL.S_BEFEHLE'); ?></b><br>
<?php echo cam_t('BEFEHL.S_TEXT'); ?>
<?php if ($ac_bli === null) { ?>
<div class="sm-alert sm-err"><?= sprintf(cam_t('BEFEHL.T_LISTE_FEHLT'), ac_e($ac_blf)) ?></div>
<?php } else { ?>
<table class="sm-tbl">
<tr><th><?php echo cam_t('MQTT.T_THEMA'); ?></th><th><?php echo cam_t('BEFEHL.T_ART'); ?></th><th><?php echo cam_t('BEFEHL.T_WIRKUNG'); ?></th><th><?php echo cam_t('BEFEHL.T_SPERRE'); ?></th></tr>
<?php foreach ($ac_bli['befehle'] as $ac_bb) { ?>
<tr><td><span class="sm-mono"><?= ac_e($ac_bb['thema']) ?></span></td>
    <td><?= ac_e($ac_bb['art'] === 'auswahl' ? implode(' | ', $ac_bb['werte']) : '1') ?></td>
    <td><?php echo cam_t($ac_bb['kachel']); ?></td><td><?= (int) $ac_bb['sperre_s'] ?> s</td></tr>
<?php } ?>
</table>
<?php } ?>
<table class="sm-tbl">
<tr><th><?php echo cam_t('LOX.T_NAME'); ?></th><th><?php echo cam_t('TEXT.BEFEHL_BEI_EIN'); ?></th><th><?php echo cam_t('TEXT.WIRKUNG'); ?></th></tr>
<?php foreach (cam_bruecke_vorlagenbefehle() as $ac_bv) { ?>
<tr><td><span class="sm-mono"><?= ac_e($ac_bv['title']) ?></span></td><td><span class="sm-mono"><?= ac_e($ac_bv['on']) ?></span></td>
    <td><?= ac_e($ac_bv['hint']) ?></td></tr>
<?php } ?>
<tr><td><span class="sm-mono">ACTI_TestPush</span></td><td><span class="sm-mono"><?= ac_e(cam_mqtt_praefix($ac_cfg)) ?>/befehl/testpush 1</span></td>
    <td><?php echo cam_t('BEFEHL.K_TESTPUSH'); ?></td></tr>
</table>
<div class="sm-small"><?= sprintf(cam_t('BEFEHL.S_AUSGANG'), '<span class="sm-mono">/dev/udp/' . ac_e(preg_replace('/:\d+$/', '', $ac_host)) . '/' . (int) max(1, (int) cam_mqtt_zustand_pruefen()['udpport'] ?: 11884) . '</span>') ?></div>
<div class="sm-small"><b><?php echo cam_t('TEXT.AUS_DER_PRAXIS'); ?></b> <?php echo cam_t('TEXT.ZWISCHEN_KLINGELSIGNAL_UND_BILD_GE'); ?></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="download" value="xml_out">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo cam_t('LOX.K_VORLAGE_BRUECKE'); ?></button>
</form>
</div>
<div class="sm-small"><?php echo cam_t('LOX.VORLAGE_BRUECKE_TEXT'); ?></div>
</div>

<div class="sm-step"><b><?php echo cam_t('TOKEN.H'); ?></b><br>
<?php echo cam_t('TOKEN.TEXT'); ?>
<div class="sm-mono" style="display:block;padding:8px;margin:6px 0;word-break:break-all;"><?= ac_e($ac_token) ?></div>
<div class="sm-small"><?php echo cam_t('TOKEN.OFFEN'); ?></div>
<div class="sm-small" style="margin-top:6px;"><b><?php echo cam_t('TOKEN.PRUEFEN'); ?></b><br>
<span class="sm-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?>/cam.php?selftest=1&amp;token=<?= ac_e($ac_token) ?></span><br>
<?php echo cam_t('TOKEN.PRUEFEN_ANTWORT'); ?></div>
<div class="sm-warnung"><?php echo cam_t('TOKEN.K_NEU_WARNUNG'); ?></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="neuestoken" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo cam_t('TOKEN.K_NEU'); ?></button>
</form>
</div>
</div>

<?php
/**
 * Die Baustein-Listen (Welle Bild 20, 1.9.30; seit 1.9.32 Gruppe 25, Entwurf Abschnitt 9): zwei Seiten aus dem
 * LoxBerry-Plugins Musterprojekt (Musterprojekt/baustein_listen.txt, Abschnitte "ACTi-Kamera" 17 und "ACTi-Kamera
 * Aufnahmen" 26 Zeilen, wortgleich). Je Tabellenzeile EIN Sprachschluessel BAUSTEIN.Z<seite>_<nn> = "Typ | Name |
 * Parameter | Eingaenge" ohne Auszeichnung; jede Zelle und jeder Satz wird maskiert ausgegeben.
 *
 * Seit 1.9.32 stehen dort Virtuelle Eingaenge mit Gateway-Namen (acti_OK ...) und die Befehle am Ausgang
 * "LoxBerry MQTT-Gateway" statt des HTTP-Eingangs "ACTi Kamera" und des Ausgangs mit Aktionstoken.
 *
 * Rueckgabe: je Seite 'kopf' (Name der Musterseite), 'bild', 'schritte' und 'hinweise' (Saetze), 'zeilen'
 * (je ein Text mit vier Zellen) - alles reiner Text; ac_bs_text() rechnet die Namen.
 */
function ac_bausteinseiten()
{
    $z1 = array(
        cam_t('BAUSTEIN.Z1_01'),
        cam_t('BAUSTEIN.Z1_02'),
        cam_t('BAUSTEIN.Z1_03'),
        cam_t('BAUSTEIN.Z1_04'),
        cam_t('BAUSTEIN.Z1_05'),
        cam_t('BAUSTEIN.Z1_06'),
        cam_t('BAUSTEIN.Z1_07'),
        cam_t('BAUSTEIN.Z1_08'),
        cam_t('BAUSTEIN.Z1_09'),
        cam_t('BAUSTEIN.Z1_10'),
        cam_t('BAUSTEIN.Z1_11'),
        cam_t('BAUSTEIN.Z1_12'),
        cam_t('BAUSTEIN.Z1_13'),
        cam_t('BAUSTEIN.Z1_14'),
        cam_t('BAUSTEIN.Z1_15'),
        cam_t('BAUSTEIN.Z1_16'),
        cam_t('BAUSTEIN.Z1_17'));
    $z2 = array(
        cam_t('BAUSTEIN.Z2_01'),
        cam_t('BAUSTEIN.Z2_02'),
        cam_t('BAUSTEIN.Z2_03'),
        cam_t('BAUSTEIN.Z2_04'),
        cam_t('BAUSTEIN.Z2_05'),
        cam_t('BAUSTEIN.Z2_06'),
        cam_t('BAUSTEIN.Z2_07'),
        cam_t('BAUSTEIN.Z2_08'),
        cam_t('BAUSTEIN.Z2_09'),
        cam_t('BAUSTEIN.Z2_10'),
        cam_t('BAUSTEIN.Z2_11'),
        cam_t('BAUSTEIN.Z2_12'),
        cam_t('BAUSTEIN.Z2_13'),
        cam_t('BAUSTEIN.Z2_14'),
        cam_t('BAUSTEIN.Z2_15'),
        cam_t('BAUSTEIN.Z2_16'),
        cam_t('BAUSTEIN.Z2_17'),
        cam_t('BAUSTEIN.Z2_18'),
        cam_t('BAUSTEIN.Z2_19'),
        cam_t('BAUSTEIN.Z2_20'),
        cam_t('BAUSTEIN.Z2_21'),
        cam_t('BAUSTEIN.Z2_22'),
        cam_t('BAUSTEIN.Z2_23'),
        cam_t('BAUSTEIN.Z2_24'),
        cam_t('BAUSTEIN.Z2_25'),
        cam_t('BAUSTEIN.Z2_26'));
    return array(
        array('kopf' => 'ACTi-Kamera', 'bild' => 'einbindung_loxone.png',
              'schritte' => array(
                  sprintf(cam_t('BAUSTEIN.S1_SCHRITT1'), cam_t('REITER.EINSTELLUNGEN'), cam_t('TEXT.L_PRUEF_MINUTEN'), cam_t('REITER.TEST'), cam_t('TEXT.VERBINDUNG_PRFEN'), cam_t('TEXT.JETZT_EIN_BILD_AUFNEHMEN')),
                  sprintf(cam_t('BAUSTEIN.S1_SCHRITT2'), cam_t('REITER.MQTT'), cam_t('MQTT.L_EIN')),
                  cam_t('BAUSTEIN.S1_SCHRITT3'),
                  cam_t('BAUSTEIN.S1_SCHRITT4'),
                  cam_t('BAUSTEIN.S1_SCHRITT5')),
              'zeilen' => $z1,
              'hinweise' => array(
                  cam_t('BAUSTEIN.S1_H1'),
                  cam_t('BAUSTEIN.S1_H2'),
                  sprintf(cam_t('BAUSTEIN.S1_H3'), cam_t('TEXT.L_PRUEF_MINUTEN')),
                  cam_t('BAUSTEIN.S1_H4'),
                  cam_t('BAUSTEIN.S1_H5'))),
        array('kopf' => 'ACTi-Kamera Aufnahmen', 'bild' => 'einbindung_loxone_aufnahmen.png',
              'schritte' => array(
                  sprintf(cam_t('BAUSTEIN.S2_SCHRITT1'), cam_t('REITER.MQTT'), cam_t('BEFEHL.L_ANNEHMEN'), cam_t('REITER.EINSTELLUNGEN'), cam_t('TEXT.PUSH_FREIGABE_AN_LOXONE_MELDEN'), cam_t('TEXT.MELDEFENSTER_NACH_EINER_AUFNAHME_M')),
                  cam_t('BAUSTEIN.S2_SCHRITT2'),
                  cam_t('BAUSTEIN.S2_SCHRITT3'),
                  cam_t('BAUSTEIN.S2_SCHRITT4'),
                  sprintf(cam_t('BAUSTEIN.S2_SCHRITT5'), cam_t('REITER.AUFNAHMEN'), cam_t('REITER.TEST'), cam_t('TEXT.TEST_PUSHNACHRICHT'))),
              'zeilen' => $z2,
              'hinweise' => array(
                  cam_t('BAUSTEIN.S2_H1'),
                  cam_t('BAUSTEIN.S2_H2'),
                  sprintf(cam_t('BAUSTEIN.S2_H3'), cam_t('TEXT.L_MINDESTPAUSE')),
                  cam_t('BAUSTEIN.S2_H4'),
                  cam_t('BAUSTEIN.S2_H5'),
                  sprintf(cam_t('BAUSTEIN.S2_H6'), cam_t('TEXT.KI_OBJEKTERKENNUNG_OPTIONAL')))),
    );
}

/**
 * Ein Name der Baustein-Seiten fuer die Ausgabe: die Gateway-Namen (acti_OK ...) und die Befehlsnamen
 * (ACTI_Bild_Klingel ...) kommen aus DENSELBEN Funktionen wie Namenstabelle und Vorlage - cam_gateway_name()
 * ueber cam_mqtt_namenstabelle() und cam_bruecke_vorlagenbefehle(), jeweils Kamera 1. Beim Praefix "acti" sind sie
 * gleich den Namen des Musterprojekts; ein anderes Praefix aendert die Titel mit. $karte: Name im Musterprojekt =>
 * Name aus der Quelle.
 */
function ac_bs_text($s, array $karte)
{
    $s = (string) $s;
    return isset($karte[$s]) ? $karte[$s] : $s;
}

$ac_bs_karte = array();
foreach ($ac_nt as $ac_ne) {
    if ((int) $ac_ne['kamera'] === 1) { $ac_bs_karte['acti_' . $ac_ne['thema']] = $ac_ne['name']; }
}
foreach (cam_bruecke_vorlagenbefehle() as $ac_bc) {
    if ((int) $ac_bc['kamera'] === 1) { $ac_bs_karte['ACTI_' . $ac_bc['art']] = $ac_bc['title']; }
}
?>
<div class="sm-step"><b><?php echo cam_t('LOX.S_BAUSTEINE'); ?></b><br>
<?php echo cam_t('BAUSTEIN.TEXT'); ?>
<div class="sm-small" style="margin-top:6px;"><b><?php echo cam_t('TEXT.PRAXIS_ERFAHRUNG_ZUM_BENACHRICHTIG'); ?></b> <?php echo cam_t('TEXT.ER_SENDET_NUR_BEI_EINER_01_FLANKE_'); ?></div>
<?php foreach (ac_bausteinseiten() as $ac_bsei) { ?>
<h4 class="sm-h4"><?= ac_e($ac_bsei['kopf']) ?></h4>
<div class="sm-hinweis"><b><?= ac_e(cam_t('BAUSTEIN.SCHRITTE_TITEL')) ?></b>
<ol class="sm-schritte">
<?php foreach ($ac_bsei['schritte'] as $ac_bsx) { ?>
<li><?= ac_e($ac_bsx) ?></li>
<?php } ?>
</ol></div>
<table class="sm-tbl">
<tr><th style="width:28px;">#</th><th><?= ac_e(cam_t('BAUSTEIN.T_TYP')) ?></th><th><?= ac_e(cam_t('BAUSTEIN.T_NAME')) ?></th>
    <th><?= ac_e(cam_t('BAUSTEIN.T_PARAM')) ?></th><th><?= ac_e(cam_t('BAUSTEIN.T_VERB')) ?></th></tr>
<?php foreach ($ac_bsei['zeilen'] as $ac_bsi => $ac_bsz) {
    $ac_bsc = array_pad(explode(' | ', $ac_bsz, 4), 4, ''); ?>
<tr><td><?= (int) $ac_bsi + 1 ?></td><td><?= ac_e($ac_bsc[0]) ?></td><td><span class="sm-mono"><?= ac_e(ac_bs_text($ac_bsc[1], $ac_bs_karte)) ?></span></td>
    <td><?= ac_e($ac_bsc[2]) ?></td><td><?= ac_e($ac_bsc[3]) ?></td></tr>
<?php } ?>
</table>
<div class="sm-warnung"><b><?= ac_e(cam_t('BAUSTEIN.HINWEISE_TITEL')) ?></b>
<ul class="sm-schritte">
<?php foreach ($ac_bsei['hinweise'] as $ac_bsx) { ?>
<li><?= ac_e($ac_bsx) ?></li>
<?php } ?>
</ul></div>
<figure class="sm-bild">
<img src="<?= ac_e($ac_bsei['bild']) ?>" alt="<?= ac_e(cam_t('LOX.BILD_ALT')) ?>" loading="lazy">
<figcaption><?= ac_e(sprintf(cam_t('LOX.BILD_UNTERSCHRIFT'), $ac_bsei['kopf'])) ?></figcaption>
</figure>
<div class="sm-small"><?php echo cam_t('LOX.MUSTERPROJEKT'); ?></div>
<?php } ?>
</div>

<div class="sm-step"><b><?php echo cam_t('LOX.S_BILD'); ?></b><br>
<?php
/* Die Adresse haengt seit 1.9.19 an zwei Schaltern: ist die feste Adresse
   abgeschaltet, gibt es sie nicht mehr, und ist ein Stromkennwort gesetzt,
   verlangt cam.php?letztes=1 das Token. Beide haengen NICHT am Haken
   endpunkt_ein (E-E12). */
$ac_stok_l = trim((string) $ac_cfg['stream_token']);
$ac_letztes_adr = 'http://' . ac_e($ac_host) . '/plugins/' . ac_e($ac_plugin)
    . ($ac_stok_l !== ''
        ? cam_t('TEXT.CAM_PHP_LETZTES_1T') . ac_e($ac_stok_l)
        : '/' . cam_t('TEXT.CAM_PHP_LETZTES_1'));
?>
<?php if (!empty($ac_cfg['bild_fest'])) { ?>
<?php echo cam_t('TEXT.DAS_JEWEILS_LETZTE_BILD_LIEGT_UNTE'); ?>
<span class="sm-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?><?php echo cam_t('TEXT.LETZTESBILD_JPG'); ?></span>
<?php echo cam_t('TEXT.ALTERNATIV'); ?> <span class="sm-mono"><?= $ac_letztes_adr ?></span><?php echo cam_t('TEXT.DIESE_ADRESSE_ENTHLT_KEINE_ZUGANGS'); ?>
<?php } else { ?>
<?php echo cam_t('TEXT.BILD_NUR_MIT_TOKEN'); ?>
<span class="sm-mono"><?= $ac_letztes_adr ?></span>
<?php } ?>
<div class="sm-small" style="margin-top:4px;"><b><?php echo cam_t('TEXT.DAMIT_VERSCHWINDET_DAS_KAMERA_PASS'); ?></b>
<?php echo cam_t('TEXT.BISHER_STAND_DORT_TYPISCHERWEISE'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.HTTP_KAMERA_CGI_BIN_ENCODER_USER_A'); ?></span><?php echo cam_t('TEXT.DIESEN_EINTRAG_KANN_MAN_NACH_DEM_U'); ?></div>
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

<?php
/* "Bestehende Anlagen: HTTP-Endpunkt" (seit 1.9.32, E-E12): zugeklappt, offen bei endpunkt_ein = 1. Der Haken
 * sperrt nur den Lesezweig fuer Loxone (Statuszeile, ?json); die alten Vorlagen und Adressen stehen hier. */
$ac_ep_ein = cam_endpunkt_ein($ac_cfg);
?>
<details<?= $ac_ep_ein ? ' open' : '' ?> style="margin-top:16px;">
<summary><b><?php echo cam_t('LOX.H_BESTEHEND'); ?></b></summary>
<div class="sm-hinweis"><?php echo cam_t('LOX.BESTEHEND_TEXT'); ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="save_endpunkt" value="1">
<input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
<div class="sm-row"><label><input data-role="none" type="checkbox" name="endpunkt_ein" value="1"<?= $ac_ep_ein ? ' checked' : '' ?>> <?php echo cam_t('LOX.L_ENDPUNKT'); ?></label></div>
<div class="sm-knopfreihe">
<button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo cam_t('TEXT.SPEICHERN'); ?></button>
</div>
</form>

<div class="sm-step"><b><?php echo cam_t('TEXT.SCHRITT_1_VIRTUELLER_AUSGANG_LOXBE'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo cam_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo cam_t('TEXT.WERT'); ?></th></tr>
<tr><td><?php echo cam_t('TEXT.ADRESSE'); ?></td><td><span class="sm-mono">http://<?= ac_e($ac_host) ?></span></td></tr>
</table>
</div>

<div class="sm-step"><b><?php echo cam_t('TEXT.SCHRITT_2_VIRTUELLE_AUSGANGS_BEFEH'); ?></b>
<table class="sm-tbl">
<tr><th><?php echo cam_t('TEXT.BEFEHL_BEI_EIN'); ?></th><th><?php echo cam_t('TEXT.WIRKUNG'); ?></th></tr>
<?php /* Aus DERSELBEN Quelle wie die Importdatei VQ_actikamera_HTTP.xml: cam_ausgangsbefehle(). */
foreach (cam_ausgangsbefehle() as $ac_bf) { ?>
<tr><td><span class="sm-mono"><?= ac_e($ac_bf['on']) ?></span></td>
    <td><?= ac_e(isset($ac_bf['hint']) ? $ac_bf['hint'] : $ac_bf['comment']) ?></td></tr>
<?php } ?>
</table>
<?php if (count(cam_kameras()) > 1) { ?>
<div class="sm-small"><?php echo cam_t('TEXT.MEHRERE_BEFEHLE'); ?></div>
<?php } ?>
</div>

<div class="sm-step"><b><?php echo cam_t('TEXT.SCHRITT_3_VIRTUELLER_HTTP_EINGANG_'); ?></b> <?php echo cam_t('TEXT.ABFRAGE_60_S'); ?>
<table class="sm-tbl">
<tr><th><?php echo cam_t('TEXT.EIGENSCHAFT'); ?></th><th><?php echo cam_t('TEXT.WERT'); ?></th></tr>
<tr><td>URL</td><td><span class="sm-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?><?php echo cam_t('TEXT.CAM_PHP_2'); ?></span></td></tr>
<tr><td><?php echo cam_t('TEXT.ABFRAGEZYKLUS'); ?></td><td><?php echo cam_t('TEXT.60_SEKUNDEN'); ?></td></tr>
</table>
<table class="sm-tbl">
<tr><th><?php echo cam_t('TEXT.BEFEHLSERKENNUNG'); ?></th><th><?php echo cam_t('TEXT.BEDEUTUNG'); ?></th></tr>
<?php $ac_mehr = count(cam_kameras()) > 1;
foreach (cam_felder() as $ac_fn => $ac_fd) {
    $ac_fk = isset($ac_fd[6]) ? (int) $ac_fd[6] : 1; ?>
<tr><td><span class="sm-mono"><?= ac_e('\i;' . $ac_fn . '=\i\v') ?></span></td>
    <td><?php echo cam_t($ac_fd[3]); ?><?= $ac_mehr ? ' <i>[' . ac_e(cam_kname($ac_fk)) . ']</i>' : '' ?></td></tr>
<?php } ?>
</table>
<div class="sm-small"><?php echo cam_t('TEXT.ALLE_WERTE_GIBT_ES_AUCH_BER_DAS_LO'); ?>
<span class="sm-mono">http://<?= ac_e($ac_host) ?>/plugins/<?= ac_e($ac_plugin) ?><?php echo cam_t('TEXT.CAM_PHP_JSON_1'); ?></span></div>
</div>

<h3 class="sm-h3"><?php echo cam_t('LOX.H_VORLAGE'); ?></h3>
<div class="sm-small"><?= sprintf(cam_t('LOX.VORLAGE_TEXT'), count(cam_felder())) ?></div>
<div class="sm-knopfreihe">
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="download" value="xml_in">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo cam_t('LOX.K_VORLAGE'); ?></button>
</form>
<form action="index.php" method="post" style="margin:0;">
    <input data-role="none" type="hidden" name="download" value="xml_out_http">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo cam_t('LOX.K_VORLAGE_AUS'); ?></button>
</form>
</div>
<div class="sm-small"><?php echo cam_t('LOX.VORLAGE_AUS_TEXT'); ?></div>
</details>
</div>

<!-- ================= Aufnahmen ================= -->
<div class="sm-pane<?= $ac_tab === 'tab-shots' ? ' sm-active' : '' ?>" id="tab-shots">
<h2><?php echo cam_t('TEXT.LETZTE_AUFNAHMEN'); ?></h2>
<?php
/* Ist ein Token fuer den Bildstrom hinterlegt, tragen auch die Archivadressen
   es mit - sonst wiese der eigene Endpunkt die eigene Galerie ab. $ac_bt wird
   einmal oben gesetzt, gleich nach dem Aktionstoken. */
$ac_dir = function_exists('cam_datadir') ? cam_datadir() : '';
?>
<div class="sm-small" style="margin-bottom:8px;"><?php echo cam_t('TEXT.TAGE_ABLAGE'); ?> <span class="sm-mono"><?= ac_e($ac_dir) ?></span><br>
<?= ac_grenzen() ?></div>
<?php
/* Wie viele Kacheln? Bis 1.9.18 stand hier fest die 12, ohne Weg zu mehr -
   und die Zeile darunter sagte "die zwoelf neuesten", ohne zu sagen, dass es
   dabei bleibt. Grund fuer die kleine Zahl: die Galerie hat keine
   Vorschaubilder, jede Kachel laedt ueber cam.php?bild= die VOLLE Datei
   (auf der gemessenen Anlage rund 690 kB). Deshalb bleibt 12 die Vorgabe,
   und mehr gibt es auf Wunsch. loading="lazy" laedt nur, was sichtbar ist. */
$ac_stufen = array(12, 48, 200);
$ac_zeige = 12;
if (isset($_GET['zeige']) && in_array((int) $_GET['zeige'], $ac_stufen, true)) {
    $ac_zeige = (int) $_GET['zeige'];
}
?>
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
<div class="sm-small" style="margin-bottom:8px;"><?php echo cam_t('TEXT.GESPEICHERT'); ?> <b><?= count($ac_bilder) ?></b> <?php echo cam_t('TEXT.BILDER'); ?> <b><?= count($ac_clips) ?></b> <?php echo cam_t('TEXT.BILDSERIEN'); ?></div>
<?php if ($ac_bilder) { ?>
<div class="sm-gal">
<?php foreach (array_slice($ac_bilder, 0, $ac_zeige) as $ac_f) {
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
<div class="sm-small"><?= sprintf(cam_t('TEXT.ANGEZEIGT_WIRD_DAS_JEWEILS_NEUESTE'), (int) $ac_zeige) ?>
<?php echo cam_t('TEXT.MEHR_ZEIGEN'); ?>
<?php /* NICHT $ac_st als Schleifenvariable: das ist der Zustand aus
         cam_state(), weiter unten im Reiter Test gelesen. */ ?>
<?php foreach ($ac_stufen as $ac_stufe) {
    if ($ac_stufe === $ac_zeige) { ?><b><?= (int) $ac_stufe ?></b>
<?php } else { ?><a href="index.php?form=shots&amp;zeige=<?= (int) $ac_stufe ?>"><?= (int) $ac_stufe ?></a>
<?php } } ?></div>
<div class="sm-warnung"><?php echo cam_t('TEXT.AUFRAEUMEN_WARNUNG'); ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo cam_t('LEGENDE.AKTION'); ?></span>
</div>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="cleanupnow" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-shots">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo cam_t('TEXT.ALTE_AUFNAHMEN_JETZT_AUFRUMEN'); ?></button>
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
<?php /* Seit 1.9.32 (E-E17): ohne Aktionstoken in der Adresse, ueber die angemeldete Seite. */ ?>
<a class="sm-btn sm-b-lesen" href="index.php?ansicht=test" target="_blank"><?php echo cam_t('TEXT.VERBINDUNG_PRFEN_2'); ?></a>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam_stream.php<?= $ac_bt1 ?>" target="_blank"><?php echo cam_t('TEXT.LIVEBILD_ANSEHEN'); ?></a>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?letztes=1<?= $ac_bt ?>" target="_blank"><?php echo cam_t('TEXT.LETZTES_BILD_FFNEN'); ?></a>
<?php if (cam_endpunkt_ein($ac_cfg)) { ?>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php" target="_blank"><?php echo cam_t('TEXT.LOXONE_ZEILE_ABRUFEN'); ?></a>
<a class="sm-btn sm-b-lesen" href="/plugins/<?= ac_e($ac_plugin) ?>/cam.php?json=1" target="_blank"><?php echo cam_t('TEXT.JSON_ANSICHT'); ?></a>
<?php } ?>
</div>
<?php if (!cam_endpunkt_ein($ac_cfg)) { ?>
<div class="sm-small" style="color:#666;"><?php echo cam_t('TEST.LESEZWEIG_AUS_GRAU'); ?></div>
<?php } ?>

<h3 class="sm-h3"><?php echo cam_t('TEXT.TECHNISCHE_AUSKUNFT'); ?></h3>
<div class="sm-knopfreihe">
<a class="sm-btn sm-b-technik" href="index.php?ansicht=diag" target="_blank"><?php echo cam_t('TEXT.DIAGNOSE_ALLE_VARIANTEN'); ?></a>
<a class="sm-btn sm-b-technik" href="index.php?ansicht=sys" target="_blank"><?php echo cam_t('TEXT.KAMERA_AUSKUNFT_2'); ?></a>
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="rtsppruefen" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit"><?php echo cam_t('TEST.K_RTSP'); ?></button>
</form>
</div>
<div class="sm-small"><?php echo cam_t('TEST.H_RTSP'); ?></div>

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
<form action="index.php" method="post">
    <input data-role="none" type="hidden" name="ptestnow" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo cam_t('TEXT.TEST_PUSHNACHRICHT_2'); ?></button>
</form>
</div>

<div class="sm-small" style="margin-top:14px;">
<?php echo cam_t('TEXT.TEXT'); ?> <b><?php echo cam_t('TEXT.VERBINDUNG_PRFEN'); ?></b> <?php echo cam_t('TEXT.HOLT_EIN_BILD_UND_MELDET_GRE_UND_A'); ?><br>
&bull; <b><?php echo cam_t('TEXT.DIAGNOSE'); ?></b> <?php echo cam_t('TEXT.PROBIERT_ALLE_KOMBINATIONEN_AUS_BE'); ?> <span class="sm-mono">OK</span> <?php echo cam_t('TEXT.WIRD_AUTOMATISCH_GEMERKT'); ?><br>
&bull; <b><?php echo cam_t('TEXT.KAMERA_AUSKUNFT'); ?></b> <?php echo cam_t('TEXT.FRAGT_DIE_SYSTEM_SCHNITTSTELLE_AB_'); ?><br>
&bull; <b><?php echo cam_t('TEXT.TEST_PUSHNACHRICHT'); ?></b> <?php echo cam_t('TEXT.SETZT'); ?> <span class="sm-mono"><?php echo cam_t('TEXT.PTEST_1'); ?></span> <?php echo cam_t('TEXT.FR_5_MINUTEN_DER_PUSH_KOMMT_BER_DE'); ?>
</div>

<h2><?php echo cam_t('TEXT.ZUSTAND'); ?></h2>
<table class="sm-tbl">
<tr><th><?php echo cam_t('TEXT.WERT'); ?></th><th><?php echo cam_t('TEXT.INHALT'); ?></th></tr>
<tr><td><?php echo cam_t('TEXT.KAMERA_KONFIGURIERT'); ?></td><td><?= !empty($ac_st['ok']) ? cam_t('ALLGEMEIN.JA') : '<b>' . cam_t('ALLGEMEIN.NEIN') . '</b>' ?></td></tr>
<tr><td><?php echo cam_t('TEXT.LETZTES_BILD'); ?></td><td><?= $ac_st['letztes_bild'] !== '' ? ac_e($ac_st['letztes_bild']) . ' (' . cam_t('TEXT.L_ANLASS') . ': ' . ac_e($ac_st['letzter_anlass']) . ')' : '&ndash;' ?></td></tr>
<tr><td><?php echo cam_t('TEXT.ALTER'); ?></td><td><?= (int) $ac_st['alter_min'] >= 0 ? (int) $ac_st['alter_min'] . ' ' . cam_t('TEXT.L_MINUTEN') : '&ndash;' ?></td></tr>
<tr><td><?php echo cam_t('TEXT.GESPEICHERTE_AUFNAHMEN'); ?></td><td><?= (int) $ac_st['bilder'] ?> <?php echo cam_t('TEXT.BILDER'); ?> <?= (int) $ac_st['clips'] ?> <?php echo cam_t('TEXT.SERIEN'); ?> <?= (int) $ac_st['timelapse'] ?> <?php echo cam_t('TEXT.ZEITRAFFERBILDER'); ?></td></tr>
<tr><td><?php echo cam_t('TEXT.ZULETZT_ERKANNT'); ?></td><td><?= !empty($ac_st['objekte']) ? ac_e(implode(', ', $ac_st['objekte'])) : '&ndash; ' . cam_t('TEXT.L_KEINE_ERKENNUNG') ?></td></tr>
</table>
</div>

<!-- ================= Logdateien ================= -->
<div class="sm-pane<?= $ac_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?php echo cam_t('REITER.LOG'); ?></h2>
<div class="sm-small" style="margin-bottom:8px;"><?php echo cam_t('TEXT.PROTOKOLLIERT_WERDEN_AUFNAHMEN_AUF'); ?><br>
<?php echo cam_t('TEXT.LOG_NEUSTART'); ?><br>
<?php echo cam_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= ac_e($ac_logfile) ?></span></div>
<?php if ($ac_loglines) { ?>
<div class="sm-log"><?= ac_e(implode("\n", $ac_loglines)) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo cam_t('TEXT.NOCH_KEINE_PROTOKOLL_EINTRGE_VORHA'); ?></div>
<?php } ?>
<?php /* A3 (seit 1.9.32): die Fehlerausgabe des Minutentakts - bis 1.9.31 nirgends zu sehen. Die letzten 80
         Zeilen, gelesen ohne die ganze Datei (cam_datei_ende()). */
$ac_cerr = cam_cron_err_pfad();
$ac_cerr_z = cam_datei_ende($ac_cerr, 80); ?>
<h3 class="sm-h3"><?php echo cam_t('TEXT.H_CRON_ERR'); ?></h3>
<div class="sm-small"><?php echo cam_t('TEXT.DATEI'); ?> <span class="sm-mono"><?= ac_e($ac_cerr) ?></span></div>
<?php if ($ac_cerr_z) { ?>
<div class="sm-log"><?= ac_e(implode("\n", array_reverse($ac_cerr_z))) ?></div>
<?php } else { ?>
<div class="sm-alert sm-info"><?php echo cam_t('TEXT.CRON_ERR_LEER'); ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?php echo cam_t('LEGENDE.AKTION'); ?></span>
</div>
<form action="index.php" method="post" style="margin-top:10px;">
    <input data-role="none" type="hidden" name="clearlog" value="1">
    <input data-role="none" type="hidden" name="formtoken" value="<?= ac_e(cam_formtoken()) ?>">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit"><?php echo cam_t('TEXT.PROTOKOLL_LEEREN'); ?></button>
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
    /* preventDefault: ohne das folgt der Browser dem href, die Seite laedt
       neu, und alles im Reiter Einstellungen, was noch nicht gespeichert
       war, ist weg. Der href bleibt stehen - ohne JavaScript ist er der Weg. */
    tabs.forEach(function (t) { t.addEventListener('click', function (e) {
        e.preventDefault();
        zeige(t.dataset.pane);
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, '', t.getAttribute('href'));
        }
        var felder = document.querySelectorAll('input[name=activetab]');
        Array.prototype.forEach.call(felder, function (f) { f.value = t.dataset.pane; });
    }); });
    zeige(aktiv);
    /* Kopierknoepfe der Namenstabelle (seit 1.9.32): den Gateway-Namen in die
       Zwischenablage; ohne Zwischenablage (http, alter Browser) wird er
       markiert. */
    document.querySelectorAll('.ac-kopie').forEach(function (k) { k.addEventListener('click', function () {
        var t = k.getAttribute('data-kopie') || '';
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t); return; }
        var f = document.createElement('textarea'); f.value = t; document.body.appendChild(f); f.select();
        try { document.execCommand('copy'); } catch (e) {} document.body.removeChild(f);
    }); });
})();
</script>
<?php
if ($ac_rahmen) {
    LBWeb::lbfooter();
} else {
    echo '</body></html>';
}
