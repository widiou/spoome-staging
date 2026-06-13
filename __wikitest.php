<?php
// DIAGNOSTICO TEMPORANEO. Da cancellare. (puo' creare/aggiornare la riga dell'atleta su staging)
chdir(__DIR__);
require_once __DIR__ . '/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

$name = $_GET['name'] ?? 'Federica Pellegrini';

$wdata = getWikipediaContent($name);
echo 'wdata_is_array=' . (is_array($wdata) ? '1' : '0') . "\n";
echo "wdata_title='" . ($wdata['title'] ?? 'MISSING') . "'\n";
echo 'wdata_has_data=' . (!empty($wdata['data']) ? '1' : '0') . "\n";
echo "wdata_photo='" . ($wdata['photo'] ?? '') . "'\n";

$bio   = extractBioData($wdata['data'] ?? '');
$sport = extractSportData($wdata['data'] ?? '');
echo 'bio_count=' . count($bio) . ' sport_count=' . count($sport) . "\n";
echo 'empty_check=' . ((empty($bio) || empty($sport)) ? 'RETURN NULL (bio/sport vuoti)' : 'ok') . "\n";

$obja = new Athlete();
$obja->title       = $name;
$obja->photo       = !empty($wdata['photo']) ? trim($wdata['photo']) : SQUARE_PLACEHOLDER;
$obja->name        = $bio['Nome'] ?? '';
$obja->surname     = $bio['Cognome'] ?? '';
$obja->birthplace  = $bio['LuogoNascita'] ?? '';
$obja->birthdate   = $bio['GiornoMeseNascita'] ?? '';
$obja->birthyear   = $bio['AnnoNascita'] ?? '';
$obja->activity    = $sport['Attività'] ?? '';
$obja->nationality = $bio['Nazionalità'] ?? '';
$obja->bio         = $wdata['description'] ?? '';
$obja->sport       = $sport['Disciplina'] ?? $sport['Sport'] ?? '';
$obja->sex         = $bio['Sesso'] ?? '';

echo "obja: title='" . $obja->title . "' sport='" . $obja->sport . "' birthyear='" . $obja->birthyear . "' sex='" . $obja->sex . "'\n";
echo "fields_keys=" . implode(',', array_keys($obja->getFields())) . "\n---\n";

try {
    $obja->save();
    echo 'SAVE OK id=' . $obja->getId() . "\n";
} catch (\Throwable $e) {
    echo 'SAVE EXCEPTION: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
