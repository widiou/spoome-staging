<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
require_once __DIR__ . '/../models/User.php';

$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $model = new User();
    $u = $model->login($_POST['email'], $_POST['password']);

    if ($u) {
        if (!$u->active) {
            $message = 'Devi prima confermare la registrazione via email.';
        } else {
            $_SESSION['email'] = $u->email;
            $_SESSION['user_id'] = $u->id;
            $_SESSION['username'] = $u->name;
            $_SESSION['role'] = $u->role;
            $_SESSION['user_tipo'] = $u->tipo;

            // Redireziona in base al completamento profilo
            $redirect = SUB_ROOT . '/index.php'; // default

            $tipo = $u->tipo;
            $completamento = SUB_ROOT . "/profilo/completa" . ucfirst($tipo) . ".php";


            // Da implementare nel modello: controlla se ha già completato il profilo
            if (method_exists($model, 'hasCompletedProfile') && !$model->hasCompletedProfile($u->id, $u->tipo)) {
                $redirect = $completamento;
            }

            header("Location: $redirect");
            exit();
        }
    } else {
        session_destroy();
        $message = 'Nome utente o password non corretti!';
    }
}

$title = "Accedi a Spoome";
require_once 'layout/_header.php';
?>

<div class="container my-5 uac-container">
    <div class="row align-items-center">
        <div class="col-12 col-md-6 offset-md-3 ">
            <?= getTitle("Bentornato su Spoome!", 'h3') ?>
            <form class="row gy-2 form-floating" method="post" action="">
                <div class="col-12">
                    <div class="form-floating">
                        <input type="email" class="form-control" name="email" id="email" required>
                        <label for="email">E-mail</label>
                    </div>
                </div>

                <div class="col-12">
                    <div class="form-floating">
                        <input type="password" class="form-control" name="password" id="password" required>
                        <label for="password">Password</label>
                    </div>
                </div>

                <div class="col-12">
                    <a class="link-spoome" href="passwordRecovery.php">Hai dimenticato la password?</a>
                </div>

                <?php if ($message): ?>
                    <div class="col-12 text-end">
                        <span class="text-danger"> <?= $message ?></span>
                    </div>
                <?php endif; ?>

                <div class="col-12">
                    <hr>
                    <div class="row justify-content-between">
                        <div class="col-auto">
                            <a href="register.php" role="button" class="btn btn-outline-light btn-slanted">
                                <span class="btn-slanted-content">Iscriviti</span>
                            </a>
                        </div>
                        <div class="col-auto">
                            <button type='submit' class="btn btn-spoome btn-slanted">
                                <span class="btn-slanted-content">Accedi</span>
                            </button>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'layout/_footer.php'; ?>
