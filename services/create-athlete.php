<?php
header("Access-Control-Allow-Origin: https://www.spoome.it");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header('Content-Type: application/json');

require_once '../bootstrap.php';


if (!isset($_GET['name']) || empty($_GET['name'])) {
    echo json_encode(["success" => false, "message" => "Nome atleta mancante"]);
    exit;
}

$name = trim($_GET['name']);
$athlete = getAthleteFromWikipedia($name);
Athlete::insertInLog($name);
if ($athlete) {
    $slug = strtolower(str_replace(" ", "-", $athlete->title));
    $redirectUrl = "/network/atleti/{$athlete->getId()}-{$slug}";
    echo json_encode(["success" => true, "redirect" => $redirectUrl]);
} else {
    error_log("Atleta non trovato o errore nella creazione:" . $_GET['name'] ?? '');
    echo json_encode(["success" => false, "message" => "Atleta non trovato o errore nella creazione"]);
}
exit;
