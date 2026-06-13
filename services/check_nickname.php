<?php
require_once __DIR__. '/../bootstrap.php';
require_once __DIR__. '/../models/User.php';

header('Content-Type: application/json');

$nickname = $_GET['nickname'] ?? '';

if (!$nickname) {
    echo json_encode(['valid' => false, 'message' => 'Nickname mancante']);
    exit;
}

$userModel = new User();
if (!$userModel->isValidNickname($nickname)) {
    echo json_encode(['valid' => false, 'message' => 'Formato non valido']);
    exit;
}

$exists = $userModel->getUserByNickname($nickname);
if ($exists) {
    echo json_encode(['valid' => false, 'message' => 'Nickname già in uso']);
} else {
    echo json_encode(['valid' => true, 'message' => 'Nickname disponibile']);
}
