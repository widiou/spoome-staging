<?php
require_once '../../bootstrap.php';

header('Content-Type: application/json');

if (!isset($_GET['name']) || empty($_GET['name'])) {
    echo json_encode(['success' => false, 'message' => 'Nome atleta mancante.']);
    exit;
}

$athleteName = trim($_GET['name']);
die($athleteName);
// Creiamo l'atleta nel database
$athlete = getAthleteFromWikipedia($athleteName);

if ($athlete && !empty($athlete->id)) { // Assicuriamoci che l'atleta abbia un ID reale nel database
    $slug = strtolower(str_replace(" ", "-", preg_replace('/[^a-z0-9]+/i', '-', $athlete->title)));
    $redirectUrl = "/network/atleti/{$athlete->id}-{$slug}";
    echo json_encode(['success' => true, 'redirect' => $redirectUrl]);
} else {
    echo json_encode(['success' => false, 'message' => 'Impossibile creare l’atleta.']);
}
?>
