<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
$output = [];
$result = Athlete::getLastTen();

foreach ($result as $row) {
    $output[] = [
        'id' => $row->id,
        'title' => $row->title,
        'photo' => $row->photo,
    ];
}
header('Content-Type: application/json');
echo json_encode($output);
exit();



