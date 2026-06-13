<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
try {
    $query = $_GET['q'] ?? '';
    $athletes = Athlete::fetchAthleteFromDatabase($query);
    header('Content-Type: application/json');
    echo json_encode($athletes);
} catch (Exception $e) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['error' => $e->getMessage()]);
}

