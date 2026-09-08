<?php
/**
 * Absichtlich ohne Inhalt.
 *
 * Diese Datei verhindert, dass der Apache bei einem Aufruf von
 * /plugins/<ordner>/ das Verzeichnis auflistet - dort liegen die Endpunkte
 * und das jeweils letzte Bild jeder Kamera (letztesbild.jpg bis
 * letztesbild4.jpg).
 *
 * Anlass: am 06.09.2026 am Geraet gemessen - der Aufruf des Webordners
 * antwortete mit HTTP 200 und einer "Index of"-Liste, weil der html-Baum von
 * LoxBerry mit "Options +Indexes" laeuft und keine .htaccess hat. Muster
 * wortgleich aus LoxBerry-Plugin-Intercom.
 *
 * Die eigentliche Oberflaeche liegt unter webfrontend/htmlauth/.
 */
header('HTTP/1.1 403 Forbidden');
header('Content-Type: text/plain; charset=utf-8');
echo "Hier gibt es nichts zu sehen. Die Oberflaeche des Plugins liegt im\n"
   . "angemeldeten Bereich von LoxBerry unter Plugins.\n";
