<?php

require_once __DIR__ . '/../bootstrap.php';
header('Content-Type: application/json');

$query = $_GET['q'] ?? '';
$type = $_GET['t'] ?? ''; // news, video, posts

if (strlen($query) < 3 || !in_array($type, ['news', 'video', 'posts'])) {
    echo json_encode([]);
    exit;
}

// Cerchiamo l'atleta per titolo (assumendo che il titolo sia univoco)
$athlete = Athlete::findByTitle($query);

if (!$athlete) {
    echo json_encode([]);
    exit;
}

// In base al tipo richiesto chiamiamo la funzione corretta (con cache 12h già integrata)
switch ($type) {
    case 'news':
        $result = getNews($athlete);
        break;
    case 'video':
        $result = getVideo($athlete);
        break;
    case 'posts':
        $result = getSocialStream($athlete);
        break;
    default:
        $result = [];
}

echo json_encode($result);
exit;
