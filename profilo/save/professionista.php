<?php
require_once __DIR__ . '/../../models/ImageUploader.php';

if (!isset($pdo)) {
    throw new Exception("Connessione PDO non trovata");
}

$qualifica = trim($_POST['qualifica'] ?? '');
$settore = trim($_POST['settore'] ?? '');
$bio = trim($_POST['bio'] ?? '');
$esperienza = trim($_POST['esperienza'] ?? '');
$certificazioni = trim($_POST['certificazioni'] ?? '');
$linkedin = trim($_POST['linkedin'] ?? '');
$sito_web = trim($_POST['sito_web'] ?? '');
$foto_profilo_data = $_POST['foto_profilo'] ?? null;
$immagine_cover_data = $_POST['immagine_cover'] ?? null;

// Validazione base
if (!$qualifica || !$settore) {
    throw new Exception("Qualifica e settore sono obbligatori.");
}

// Upload immagini se presenti
$imgUploader = new ImageUploader();
$foto_profilo_path = null;
$cover_path = null;

if ($foto_profilo_data) {
    $foto_profilo_path = $imgUploader->saveBase64Image($foto_profilo_data, "profile", $userId, 800, 800);
}
if ($immagine_cover_data) {
    $cover_path = $imgUploader->saveBase64Image($immagine_cover_data, "cover", $userId, 1920, 1080);
}

// Aggiorna professionisti
$stmt = $pdo->prepare("UPDATE professionisti SET qualifica = :qualifica, settore = :settore, descrizione = :bio,
    esperienza = :esperienza, certificazioni = :certificazioni, linkedin = :linkedin, sito_web = :sito_web
    WHERE user_id = :user_id");
$stmt->execute([
    'qualifica' => $qualifica,
    'settore' => $settore,
    'bio' => $bio,
    'esperienza' => $esperienza,
    'certificazioni' => $certificazioni,
    'linkedin' => $linkedin,
    'sito_web' => $sito_web,
    'user_id' => $userId
]);

// Aggiorna profili_base con immagini
if ($foto_profilo_path || $cover_path) {
    $updates = [];
    $params = ['user_id' => $userId];
    if ($foto_profilo_path) {
        $updates[] = "foto_profilo = :foto_profilo";
        $params['foto_profilo'] = $foto_profilo_path;
    }
    if ($cover_path) {
        $updates[] = "immagine_cover = :immagine_cover";
        $params['immagine_cover'] = $cover_path;
    }

    $query = "UPDATE profili_base SET " . implode(", ", $updates) . " WHERE user_id = :user_id";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
}
