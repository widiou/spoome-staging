<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Reset completo della sessione se già loggati o per sicurezza in fase di registrazione
$_SESSION = [];
session_unset();
session_destroy();
session_start(); // Riavvia una sessione nuova e pulita
$title = "Registrazione a Spoome";
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
require_once 'models/User.php';
require_once 'layout/_header.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $message = '';
    $u = new User();

    $email = trim($_POST['email']);
    $name = trim($_POST['name']);
    $nickname = trim($_POST['nickname']);
    $password = $_POST['password'];
    $tipo = $_POST['tipo'];
    $privacy = isset($_POST['privacy']) ? 1 : 0;

    // Verifica se l'email o il nickname esistono già
    $checkUser = $u->getUserByEmail($email);
    $checkNickname = $u->getUserByNickname($nickname);

    if ($checkUser) {
        $message = 'Esiste già una registrazione con questa email!<br><a class="link-spoome text-decoration-none" href="<?= SUB_ROOT ?>/uac/login.php">Hai dimenticato la password?</a>';
    } elseif ($checkNickname) {
        $message = 'Questo nickname è già stato scelto da un altro utente. Scegline un altro.';
    } elseif (!$u->isValidNickname($nickname)) {
        $message = 'Il nickname non è valido. Usa solo lettere, numeri, underscore (min 3 max 30 caratteri) e scegli un nome appropriato.';
    } elseif (!User::isValidPassword($password)) {
        $message = 'La password deve avere almeno 8 caratteri, una maiuscola e un simbolo.';
    } else {
        // Creazione dell'utente
        $result = $u->createUser($email, $name, $nickname, $password, $tipo, $privacy);

        if ($result) {
            $newUser = $u->getUserByEmail($email);
            $user_id = $newUser['id'];

            // Inserimento in profili_base
            $stmt = $pdo->prepare("INSERT INTO profili_base (user_id) VALUES (:user_id)");
            $stmt->execute(['user_id' => $user_id]);

            // Inserimento nella tabella specifica in base al tipo
            switch ($tipo) {
                case 'atleta':
                    $pdo->prepare("INSERT INTO atleti (user_id) VALUES (:user_id)")->execute(['user_id' => $user_id]);
                    break;
                case 'societa':
                    $pdo->prepare("INSERT INTO societa (user_id) VALUES (:user_id)")->execute(['user_id' => $user_id]);
                    break;
                case 'agenzia':
                    $pdo->prepare("INSERT INTO agenzie (user_id) VALUES (:user_id)")->execute(['user_id' => $user_id]);
                    break;
                case 'professionista':
                    $pdo->prepare("INSERT INTO professionisti (user_id, qualifica) VALUES (:user_id, '')")->execute(['user_id' => $user_id]);
                    break;
                case 'fan':
                    $pdo->prepare("INSERT INTO fan (user_id) VALUES (:user_id)")->execute(['user_id' => $user_id]);
                    break;
            }

            // Generazione del link per la verifica dell'account
            $link = "https://www.spoome.it" . SUB_ROOT . "/uac/verify.php?token=" . $newUser['verification_token'];

            $subject = "Conferma la tua registrazione su Spoome";
            $body = "Ciao {$newUser['name']},\n\nClicca su questo link per confermare la tua registrazione:\n$link\n\nGrazie,\nTeam Spoome";

            // Invia l'email di verifica
            mail($newUser['email'], $subject, $body, "From: no-reply@spoome.it");

            $message = "Registrazione avvenuta con successo. Controlla la tua email per confermare il tuo account.";
        } else {
            $message = "OPS! Qualcosa è andato storto. Riprova tra qualche minuto!";
        }
    }
}
?>

<div class="container my-5 uac-container">
    <?php if ($_SERVER['REQUEST_METHOD'] == 'POST'): ?>
        <div class="row text-center align-items-center">
            <div class="col-12">
                <h3><?= $message ?></h3>
            </div>
        </div>
    <?php else: ?>
        <div class="row align-items-center">
            <div class="col-12 col-md-6 offset-md-3">
                <?= getTitle("Iscriviti a Spoome!", 'h3') ?>
                <form class="row gy-2 form-floating needs-validation" method="post" action="" id="registrationForm" novalidate>
                    <div class="col-12">
                        <div class="form-floating">
                            <input type="text" class="form-control" name="name" id="name" maxlength="50" required>
                            <label for="name">Nome</label>
                            <div class="invalid-feedback">Inserisci il tuo nome.</div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-floating">
                            <input type="text" class="form-control" name="nickname" id="nickname" maxlength="30" required>
                            <label for="nickname">Nickname</label>
                            <div class="invalid-feedback">Usa solo lettere, numeri o underscore (min 3, max 30 caratteri).</div>
                            <div class="form-text text-muted" id="nicknameFeedback"></div>

                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-floating">
                            <input type="email" class="form-control" name="email" id="email" required>
                            <label for="email">E-mail</label>
                            <div class="invalid-feedback">Inserisci una email valida.</div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-floating">
                            <input type="password" class="form-control" name="password" id="password" required>
                            <label for="password">Password</label>
                            <div class="invalid-feedback">La password deve avere almeno 8 caratteri, una maiuscola e un simbolo.</div>
                        </div>
                    </div>

                    <div class="col-12">
                        <div class="form-floating">
                            <input type="password" class="form-control" id="passwordConfirm" required>
                            <label for="passwordConfirm">Conferma Password</label>
                            <div class="invalid-feedback">Le password non coincidono.</div>
                        </div>
                    </div>

                    <div class="col-12">
                        <label for="tipo" class="form-label">Chi sei?</label>
                        <select class="form-select" name="tipo" id="tipo" required>
                            <option value="">Scegli un profilo...</option>
                            <option value="atleta">Atleta</option>
                            <option value="societa">Società</option>
                            <option value="professionista">Professionista</option>
                            <option value="agenzia">Agenzia sportiva</option>
                            <option value="fan">Fan</option>
                        </select>
                        <div class="invalid-feedback">Seleziona il tuo profilo.</div>
                    </div>

                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="privacy" id="privacyCheck" required>
                            <label class="form-check-label" for="privacyCheck">
                                Dichiaro di essere maggiorenne e di aver letto l’<a class="link-spoome" href="https://www.iubenda.com/privacy-policy/79604585" target="_blank">Informativa Privacy</a>.
                            </label>
                            <div class="invalid-feedback">Devi accettare la privacy policy.</div>
                        </div>
                    </div>

                    <div class="col-12">
                        <hr>
                        <div class="row justify-content-between">
                            <div class="col-auto">
                                <a href="login.php" class="btn btn-outline-light btn-slanted"><span class="btn-slanted-content">Accedi</span></a>
                            </div>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-spoome btn-slanted"><span class="btn-slanted-content">Iscriviti</span></button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <script>
            document.getElementById('registrationForm').addEventListener('submit', function (e) {
                let form = this;
                let isValid = true;

                const name = document.getElementById('name');
                const nickname = document.getElementById('nickname');
                const email = document.getElementById('email');
                const password = document.getElementById('password');
                const passwordConfirm = document.getElementById('passwordConfirm');
                const tipo = document.getElementById('tipo');
                const privacyCheck = document.getElementById('privacyCheck');

                [name, nickname, email, password, passwordConfirm, tipo, privacyCheck].forEach(el => el.classList.remove('is-invalid'));

                const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                const passwordPattern = /^(?=.*[A-Z])(?=.*[!@#$%^&*])[A-Za-z\d!@#$%^&*]{8,}$/;
                const nicknamePattern = /^[a-zA-Z0-9_]{3,30}$/;

                if (name.value.trim() === '') isValid = false, name.classList.add('is-invalid');
                if (!nicknamePattern.test(nickname.value.trim())) isValid = false, nickname.classList.add('is-invalid');
                if (!emailPattern.test(email.value.trim())) isValid = false, email.classList.add('is-invalid');
                if (!passwordPattern.test(password.value)) isValid = false, password.classList.add('is-invalid');
                if (password.value !== passwordConfirm.value) isValid = false, passwordConfirm.classList.add('is-invalid');
                if (!tipo.value) isValid = false, tipo.classList.add('is-invalid');
                if (!privacyCheck.checked) isValid = false, privacyCheck.classList.add('is-invalid');

                if (!isValid) e.preventDefault();
                else form.classList.add('was-validated');
            });

            document.getElementById('nickname').addEventListener('input', function () {
                const feedback = document.getElementById('nicknameFeedback');
                const nickname = this.value.trim();

                if (nickname.length < 3) {
                    feedback.textContent = 'Il nickname deve avere almeno 3 caratteri.';
                    feedback.className = 'form-text text-danger';
                    return;
                }

                fetch(`<?= SUB_ROOT ?>/services/check_nickname.php?nickname=${encodeURIComponent(nickname)}`)
                    .then(res => res.json())
                    .then(data => {
                        feedback.textContent = data.message;
                        feedback.className = 'form-text ' + (data.valid ? 'text-success' : 'text-danger');
                    });
            });

        </script>
    <?php endif; ?>
</div>

<?php require_once 'layout/_footer.php'; ?>
