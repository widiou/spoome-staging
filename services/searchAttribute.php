<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
//checkLocal();

$term = isset($_GET['term']) ? $_GET['term'] : '';
$attr = isset($_GET['attr']) ? $_GET['attr'] : '';

if ($term !== '' && $attr !== '') {
    $results = [];

    switch ($attr) {
        case 'sport':
            $results = Athlete::searchSport($term);
            break;
        case 'activity':
            $results = Athlete::searchActivity($term);
            break;
        case 'year':
            $results = Athlete::searchYear($term);
            break;
        case 'nationality':
            $results = Athlete::searchNationality($term);
            break;
        case 'birthplace':
            $results = Athlete::searchBirthplace($term);
            break;
        case 'atleti': // ✅ Nuova gestione per gli atleti
            $athletes = Athlete::searchByName($term); // Metodo da implementare nel modello Athlete
            $results = array_map(function ($athlete) {
                return [
                    'id' => $athlete->id,
                    'title' => $athlete->title
                ];
            }, $athletes);
            break;
        default:
            break;
    }

    echo json_encode($results);
}
