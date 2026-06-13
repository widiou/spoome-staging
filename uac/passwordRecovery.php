<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$title = "Recupera la password";
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
require_once 'models/User.php';
require_once 'layout/_header.php';

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Indirizzo email non valido.";
    } else {
        $u = new User();
        $user = $u->getUserByEmail($email);

        if ($user) {
            // Genera token sicuro
            $token = bin2hex(random_bytes(32));
            $u->setPasswordResetToken($user['id'], $token);

            // Link di reset completo con /network/
            $resetLink = "https://spoome.it/network/uac/resetPassword.php?token=$token";

            // Email
            $subject = "Reset della tua password su Spoome";
            $body = "Ciao {$user['name']},\n\nHai richiesto il reset della password. Clicca sul link qui sotto per crearne una nuova:\n\n$resetLink\n\nSe non sei stato tu, ignora questo messaggio.";
            mail($email, $subject, $body, "From: no-reply@spoome.it");

            $message = "Ti abbiamo inviato un’email con il link per reimpostare la password.";
        } else {
            $message = "Indirizzo email non trovato nei nostri archivi.";
        }
    }
}
?>

<div class="container my-5 uac-container">
    <h3 class="mb-4">Recupera la password</h3>

    <?php if ($message): ?>
        <div class="alert alert-info"><?= $message ?></div>
    <?php endif; ?>

    <form method="post" class="mt-3">
        <div class="mb-3">
            <label for="email" class="form-label">Inserisci la tua email</label>
            <input type="email" name="email" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-spoome">Invia il link di recupero</button>
    </form>
</div>

<?php require_once 'layout/_footer.php'; ?>
