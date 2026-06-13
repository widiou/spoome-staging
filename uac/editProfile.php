<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$title = "Modifica profilo";
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
require_once 'layout/_header.php';

if (!isset($_SESSION['user_id'], $_SESSION['user_tipo'])) {
    die("Accesso non autorizzato.");
}

$pdo = Database::getInstance()->getConnection();
$utenteId = $_SESSION['user_id'];
$tipo = $_SESSION['user_tipo'];
$messaggio = '';
$errore = false;

// Mapping per sicurezza
$mapping = [
    'atleta' => 'atleti',
    'societa' => 'societa',
    'professionista' => 'professionisti',
    'agenzia' => 'agenzie',
    'fan' => 'fan'
];

$tabella = $mapping[$tipo] ?? null;

if (!$tabella) {
    die("Tipo profilo non riconosciuto.");
}

// Recupera dati esistenti
$stmt = $pdo->prepare("SELECT * FROM $tabella WHERE user_id = ?");
$stmt->execute([$utenteId]);
$dati = $stmt->fetch();

// Gestione salvataggio
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($tipo) {
            case 'atleta':
                $sql = "UPDATE atleti SET sport = ?, categoria = ?, squadra_attuale = ? WHERE user_id = ?";
                $pdo->prepare($sql)->execute([
                    $_POST['sport'], $_POST['categoria'], $_POST['squadra_attuale'], $utenteId
                ]);
                break;

            case 'societa':
                $sql = "UPDATE societa SET nome_societa = ?, federazione_appartenenza = ?, telefono = ? WHERE user_id = ?";
                $pdo->prepare($sql)->execute([
                    $_POST['nome_societa'], $_POST['federazione_appartenenza'], $_POST['telefono'], $utenteId
                ]);
                break;

            case 'professionista':
                $sql = "UPDATE professionisti SET qualifica = ?, descrizione = ? WHERE user_id = ?";
                $pdo->prepare($sql)->execute([
                    $_POST['qualifica'], $_POST['descrizione'], $utenteId
                ]);
                break;

            case 'agenzia':
                $sql = "UPDATE agenzie SET nome_agenzia = ?, telefono = ? WHERE user_id = ?";
                $pdo->prepare($sql)->execute([
                    $_POST['nome_agenzia'], $_POST['telefono'], $utenteId
                ]);
                break;

            case 'fan':
                $sql = "UPDATE fan SET nickname = ?, sport_preferito = ? WHERE user_id = ?";
                $pdo->prepare($sql)->execute([
                    $_POST['nickname'], $_POST['sport_preferito'], $utenteId
                ]);
                break;
        }

        $messaggio = "Profilo aggiornato con successo!";
        $stmt = $pdo->prepare("SELECT * FROM $tabella WHERE user_id = ?");
        $stmt->execute([$utenteId]);
        $dati = $stmt->fetch();

    } catch (PDOException $e) {
        $errore = true;
        $messaggio = "Errore: " . $e->getMessage();
    }
}
?>

<div class="container my-5 uac-container">
    <h3>Modifica il tuo profilo: <?= ucfirst($tipo) ?></h3>

    <?php if ($messaggio): ?>
        <div class="alert alert-<?= $errore ? 'danger' : 'success' ?>">
            <?= $messaggio ?>
        </div>
    <?php endif; ?>

    <form method="post" class="mt-4">

        <?php if ($tipo === 'atleta') : ?>
            <div class="mb-3"><label>Sport</label><input type="text" name="sport" value="<?= $dati['sport'] ?? '' ?>" class="form-control"></div>
            <div class="mb-3"><label>Categoria</label><input type="text" name="categoria" value="<?= $dati['categoria'] ?? '' ?>" class="form-control"></div>
            <div class="mb-3"><label>Squadra attuale</label><input type="text" name="squadra_attuale" value="<?= $dati['squadra_attuale'] ?? '' ?>" class="form-control"></div>

        <?php elseif ($tipo === 'societa') : ?>
            <div class="mb-3"><label>Nome società</label><input type="text" name="nome_societa" value="<?= $dati['nome_societa'] ?? '' ?>" class="form-control"></div>
            <div class="mb-3"><label>Federazione</label><input type="text" name="federazione_appartenenza" value="<?= $dati['federazione_appartenenza'] ?? '' ?>" class="form-control"></div>
            <div class="mb-3"><label>Telefono</label><input type="text" name="telefono" value="<?= $dati['telefono'] ?? '' ?>" class="form-control"></div>

        <?php elseif ($tipo === 'professionista') : ?>
            <div class="mb-3"><label>Qualifica</label><input type="text" name="qualifica" value="<?= $dati['qualifica'] ?? '' ?>" class="form-control"></div>
            <div class="mb-3"><label>Descrizione</label><textarea name="descrizione" class="form-control"><?= $dati['descrizione'] ?? '' ?></textarea></div>

        <?php elseif ($tipo === 'agenzia') : ?>
            <div class="mb-3"><label>Nome agenzia</label><input type="text" name="nome_agenzia" value="<?= $dati['nome_agenzia'] ?? '' ?>" class="form-control"></div>
            <div class="mb-3"><label>Telefono</label><input type="text" name="telefono" value="<?= $dati['telefono'] ?? '' ?>" class="form-control"></div>

        <?php elseif ($tipo === 'fan') : ?>
            <div class="mb-3"><label>Nickname</label><input type="text" name="nickname" value="<?= $dati['nickname'] ?? '' ?>" class="form-control"></div>
            <div class="mb-3"><label>Sport preferito</label><input type="text" name="sport_preferito" value="<?= $dati['sport_preferito'] ?? '' ?>" class="form-control"></div>
        <?php endif; ?>

        <button type="submit" class="btn btn-spoome">Salva modifiche</button>
    </form>
</div>

<?php require_once 'layout/_footer.php'; ?>
