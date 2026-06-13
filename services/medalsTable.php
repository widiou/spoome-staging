<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';

$pdo = Database::getInstance()->getConnection();

// Esecuzione della query per recuperare i dati
$sql = "SELECT nation, gold, silver, bronze, (gold + silver + bronze) AS total FROM medalstable";
$stmt = $pdo->prepare($sql);
$stmt->execute();

// Costruzione dell'array di risultati
$results = [];
while ($row = $stmt->fetch()) {
    $results[] = [
        'nation' => $row['nation'],
        'gold' => (int)$row['gold'],
        'silver' => (int)$row['silver'],
        'bronze' => (int)$row['bronze'],
        'total' => (int)$row['total']
    ];
}

// Imposta l'intestazione Content-Type per il JSON e ritorna i risultati
header('Content-Type: application/json');
echo json_encode($results);
?>
