<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';

checkLoggedIn();
$userId = $_GET['user_id'] ?? null;
if (!$userId) {
    header("Location: /index.php");
    exit();
}
$user = new User();
$du = $user->getUserById($userId);
if ($du->deleteUser($userId)) {
    if ($_SESSION['user_id'] == $userId) {
        session_destroy();
    }
    header("Location: /index.php");
    exit();
}

