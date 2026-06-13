<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../layout/_header.php';

// Recupera i parametri dalla query string
$appId = isset($_GET['app_id']) ? (int)$_GET['app_id'] : null;
$appToken = $_GET['app_token'] ?? null;
$itemId = isset($_GET['item_id']) ? (int)$_GET['item_id'] : null;

echo "<div class='container'>";
echo "<div class='row mb-5'>";
echo getTitle("Form dinamico per Podio");

// ✅ Rende visibili le variabili dentro il partial
if (!$appId || !$appToken) {
    echo "<div class='alert alert-danger'>❌ Devi passare <code>?app_id=...&app_token=...</code> nella URL.</div>";
} else {
    // Rende disponibili anche a podio-form.php
    require __DIR__ . '/views/form.php';
}

echo "</div></div>";

// ✅ Script JS per submit AJAX

echo "<script src='" . SUB_ROOT . "/podio/js/podio-autocomplete.js'></script>";
echo "<script src='" . SUB_ROOT . "/podio/js/podio-form.js'></script>";

require_once __DIR__ . '/../layout/_footer.php';
