<?php
/**
 * Script di pulizia cache per Spoome.it
 * Elimina i file JSON più vecchi di 24 ore dalla cartella cache atleti
 */

$cacheDir = __DIR__ . '/../helpers/cache/atleti/';
$scadenzaOre = 24;

if (!is_dir($cacheDir)) {
    exit("Cartella cache non trovata: $cacheDir\n");
}

$files = glob($cacheDir . '*.json');
$now = time();
$rimossi = 0;

foreach ($files as $file) {
    $lastModified = filemtime($file);
    $oreTrascorse = ($now - $lastModified) / 3600;

    if ($oreTrascorse >= $scadenzaOre) {
        if (unlink($file)) {
            $rimossi++;
        } else {
            echo "Errore nella rimozione di: $file\n";
        }
    }
}

echo "✔ Pulizia completata. File rimossi: $rimossi\n";
