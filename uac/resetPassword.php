<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$title = "Reimposta la password";
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
require_once 'models/User.php';
require_once 'layout/_header.php';

$u = new User();
$token = $_GET['token'] ?? null;
$message = '';
$errore = false;

if (!$token) {
    $message = "Token non valido.";
    $errore = true;
} else {
    $user = $u->getUserByResetToken($token);
    if (!$user) {
        $message = "Questo link non è valido o è già stato utilizzato.";
        $errore = true;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $password = $_POST['password'];
        $confirm = $_POST['confirm'];

        if ($password !== $confirm) {
            $message = "Le password non coincidono.";
        } elseif (!preg_match('/^(?=.*[A-Z])(?=.*[!@#$%^&*])(?=.*[a-z]).{8,}$/', $password)) {
            $message = "La password deve contenere almeno 8 caratteri, una lettera maiuscola e un simbolo.";
        } else {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $u->updatePassword($user['id'], $hash);
            $u->clearPasswordResetToken($user['id']);

            echo "<div class='container my-5 uac-container'>
                    <div class='alert alert-success'>Password aggiornata con successo!</div>
                    <a href='/network/uac/login.php' class='btn btn-spoome'>Accedi ora</a>
                  </div>";
            require_once 'layout/_footer.php';
            exit;
        }
    }
}
?>

<div class="container my-5 uac-container">
    <h3 class="mb-4">Reimposta la password</h3>

    <?php if ($message): ?>
        <div class="alert <?= $errore ? 'alert-danger' : 'alert-warning' ?>"><?= $message ?></div>
    <?php endif; ?>

    <?php if (!$errore): ?>
        <form method="post">
            <div class="mb-3">
                <label for="password" class="form-label">Nuova password</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="mb-3">
                <label for="confirm" class="form-label">Conferma password</label>
                <input type="password" name="confirm" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-success">Aggiorna password</button>
        </form>
    <?php endif; ?>
</div>

<?php require_once 'layout/_footer.php'; ?>
