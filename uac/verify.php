<?php
session_start();
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
require_once 'models/User.php';

$userModel = new User();
$messaggio = "";

if (!isset($_GET['token'])) {
    $messaggio = "Link non valido.";
} else {
    $token = $_GET['token'];
    $user = $userModel->getUserByToken($token);

    if ($user) {
        $userModel->activateUser($user['id']);
        $userModel->clearVerificationToken($user['id']); // opzionale ma consigliato
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_tipo'] = $user['tipo'];

        header("Location: /network/profilo/inizia.php");
        exit;

    } else {
        $messaggio = "Questo link non è valido oppure l'account è già stato attivato.";
    }
}

// A questo punto possiamo includere l’HTML
$title = "Verifica Account";
require_once 'layout/_header.php';
?>

<div class="container my-5 uac-container text-center">
    <h3 class="mb-4">Verifica account</h3>
    <div class="alert alert-warning">
        <?= $messaggio ?>
    </div>
    <a href="/network/uac/login.php" class="btn btn-outline-light btn-slanted mt-3">
        <span class="btn-slanted-content">Vai al login</span>
    </a>
</div>

<?php require_once 'layout/_footer.php'; ?>
