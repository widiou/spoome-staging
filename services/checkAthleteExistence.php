<?php

$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once "models/Athlete.php";

try {
    $nomeCognome = $_GET['name'] ?? '';

    // Cerchiamo l'atleta nel database
    $athlete = Athlete::findByTitle($nomeCognome);
    if(!$athlete){
        echo json_encode([
            'exists' => false
        ]);
        exit();
    }

    header('Content-Type: application/json');

    if ($athlete->getId()) {
        echo json_encode([
            'exists' => true,
            'id' => $athlete->getId(), // Restituiamo anche l'ID
        ]);
        exit();
    } else {
        echo json_encode([
            'exists' => false
        ]);
        exit();
    }
} catch (Exception $e) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['error' => $e->getMessage()]);
    exit();
}
