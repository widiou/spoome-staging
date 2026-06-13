<?php
session_start();
$title = "Completa il tuo profilo";
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
require_once 'layout/_header.php';

// Verifica accesso
if (!isset($_SESSION['user_id'], $_GET['tipo'])) {
    header("Location: /network/uac/login.php");
    exit();
}

$pdo = Database::getInstance()->getConnection();
$utenteId = $_SESSION['user_id'];
$tipo = $_GET['tipo'];
?>

<div class="container my-5 uac-container">
    <h3>Completa il tuo profilo: <?= ucfirst($tipo) ?></h3>

    <?php
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        try {
            switch ($tipo) {
                case 'atleta':
                    $sport = $_POST['sport'];
                    $categoria = $_POST['categoria'];
                    $squadra = $_POST['squadra'];
                    $stmt = $pdo->prepare("INSERT INTO atleti (user_id, sport, categoria, squadra_attuale) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$utenteId, $sport, $categoria, $squadra]);
                    break;

                case 'societa':
                    $nome = $_POST['nome'];
                    $federazione = $_POST['federazione'];
                    $telefono = $_POST['telefono'];
                    $stmt = $pdo->prepare("INSERT INTO societa (user_id, nome_societa, federazione_appartenenza, telefono) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$utenteId, $nome, $federazione, $telefono]);
                    break;

                case 'professionista':
                    $ruolo = $_POST['ruolo'];
                    $bio = $_POST['bio'];
                    $stmt = $pdo->prepare("INSERT INTO professionisti (user_id, qualifica, descrizione) VALUES (?, ?, ?)");
                    $stmt->execute([$utenteId, $ruolo, $bio]);
                    break;

                case 'agenzia':
                    $nomeAgenzia = $_POST['nome_agenzia'];
                    $telefono = $_POST['telefono'];
                    $stmt = $pdo->prepare("INSERT INTO agenzie (user_id, nome_agenzia, telefono) VALUES (?, ?, ?)");
                    $stmt->execute([$utenteId, $nomeAgenzia, $telefono]);
                    break;

                case 'fan':
                    $nickname = $_POST['nickname'] ?? null;
                    $sport = $_POST['sport'] ?? null;
                    $stmt = $pdo->prepare("INSERT INTO fan (user_id, nickname, sport_preferito) VALUES (?, ?, ?)");
                    $stmt->execute([$utenteId, $nickname, $sport]);
                    break;
            }

            echo "<div class='alert alert-success'>Profilo completato con successo!</div>";
            echo "<a href='/network/profilo/dashboard.php' class='btn btn-spoome mt-3'>Vai alla dashboard</a>";
            require_once 'layout/_footer.php';
            exit;

        } catch (PDOException $e) {
            echo "<div class='alert alert-danger'>Errore nel salvataggio del profilo: " . $e->getMessage() . "</div>";
        }
    }
    ?>

    <form method="post" class="mt-4">
        <?php if ($tipo === 'atleta') : ?>
            <div class="mb-3"><label>Sport</label><input type="text" name="sport" class="form-control" required></div>
            <div class="mb-3"><label>Categoria</label><input type="text" name="categoria" class="form-control"></div>
            <div class="mb-3"><label>Squadra attuale</label><input type="text" name="squadra" class="form-control"></div>

        <?php elseif ($tipo === 'societa') : ?>
            <div class="mb-3"><label>Nome società</label><input type="text" name="nome" class="form-control" required></div>
            <div class="mb-3"><label>Federazione di appartenenza</label><input type="text" name="federazione" class="form-control"></div>
            <div class="mb-3"><label>Telefono</label><input type="text" name="telefono" class="form-control"></div>

        <?php elseif ($tipo === 'professionista') : ?>
            <div class="mb-3"><label>Qualifica</label><input type="text" name="ruolo" class="form-control" required></div>
            <div class="mb-3"><label>Descrizione</label><textarea name="bio" class="form-control" rows="3"></textarea></div>

        <?php elseif ($tipo === 'agenzia') : ?>
            <div class="mb-3"><label>Nome agenzia</label><input type="text" name="nome_agenzia" class="form-control" required></div>
            <div class="mb-3"><label>Telefono</label><input type="text" name="telefono" class="form-control"></div>

        <?php elseif ($tipo === 'fan') : ?>
            <div class="mb-3"><label>Nickname</label><input type="text" name="nickname" class="form-control"></div>
            <div class="mb-3"><label>Sport preferito</label><input type="text" name="sport" class="form-control"></div>
        <?php endif; ?>

        <button type="submit" class="btn btn-success mt-3">Salva</button>
    </form>
</div>

<?php require_once 'layout/_footer.php'; ?>
