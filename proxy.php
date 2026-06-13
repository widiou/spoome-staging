<?php
$url = $_GET['url'] ?? '';
if ($url) {
    header('Content-Type: application/json');
    echo file_get_contents($url);
}
