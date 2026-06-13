<?php
session_start();
$title = "Modifica profilo";
chdir(__DIR__ . '/../');
require_once 'bootstrap.php';
require_once 'layout/_header.php';
require_once 'models/UserSessionUtils.php';
require_once 'models/ProfiloUtils.php';

// Verifica accesso
UserSessionUtils::checkAuthenticated();

$userId = $_SESSION['user_id'];
$tipo = $_SESSION['user_tipo'];
$pdo = Database::getInstance()->getConnection();

// Recupero profilo base
$profiloBase = ProfiloUtils::getOrCreateProfilo($pdo, $tipo, $userId);

// Percorsi partial e save
$formPath = __DIR__ . "/partial/{$tipo}-form.php";
$savePath = __DIR__ . "/save/{$tipo}.php";

$success = false;
$error = null;

// Gestione salvataggio
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (file_exists($savePath)) {
        try {
            require $savePath;
            $success = true;
            $profiloBase = ProfiloUtils::getProfilo($pdo, $tipo, $userId);
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = "Salvataggio non disponibile per questo profilo.";
    }
}
?>

<div class="container my-5 uac-container">
    <h3 class="mb-4">Modifica Profilo <?= ucfirst($tipo) ?></h3>

    <?php if ($success): ?>
        <div class="alert alert-success">Modifiche salvate con successo!</div>
    <?php elseif ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <?php
        if (file_exists($formPath)) {
            require $formPath;
        } else {
            echo "<p>Form di modifica non disponibile per questo profilo.</p>";
        }
        ?>
        <button type="submit" class="btn btn-success mt-3">Salva modifiche</button>
    </form>
</div>

<?php require_once 'layout/_footer.php'; ?>
