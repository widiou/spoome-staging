<?php
session_start();
$title = "Profilo Atleta";

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../layout/_header.php';
require_once __DIR__ . '/../models/User.php';

// Verifica accesso
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . SUB_ROOT . '/uac/login.php');
    exit();
}

$model = new User();
$user = $model->getUserById($_SESSION['user_id']);
$pdo = Database::getInstance()->getConnection();

if (!$user || $user['tipo'] !== 'atleta') {
    echo "<div class='container my-5'><div class='alert alert-danger'>Profilo non valido.</div></div>";
    require_once __DIR__ . '/../layout/_footer.php';
    exit();
}

// Dati profilo base
$stmt = $pdo->prepare("SELECT * FROM profili_base WHERE user_id = ?");
$stmt->execute([$user['id']]);
$profilo = $stmt->fetch(PDO::FETCH_ASSOC);

// Dati estesi atleta
$stmt = $pdo->prepare("SELECT * FROM atleti WHERE user_id = ?");
$stmt->execute([$user['id']]);
$atleta = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<div class="container my-5 uac-container">
    <?php if ($profilo['immagine_cover']): ?>
        <div class="mb-3 text-center">
            <img src="/<?= $profilo['immagine_cover'] ?>" class="img-fluid rounded" style="max-height: 300px; width: 100%; object-fit: cover;" alt="Cover">
        </div>
    <?php endif; ?>

    <div class="card p-4">
        <?php if ($profilo['foto_profilo']): ?>
            <div class="mb-3 text-center">
                <img src="/<?= $profilo['foto_profilo'] ?>" class="rounded-circle" width="120" height="120" alt="Foto profilo">
            </div>
        <?php endif; ?>

        <h4 class="text-center"><?= htmlspecialchars($user['name']) ?> (@<?= htmlspecialchars($user['nickname']) ?>)</h4>
        <p class="text-center text-muted"><?= ucfirst($profilo['qualifica']) ?> - <?= ucfirst($profilo['area_geografica']) ?></p>

        <hr>

        <p><strong>Email:</strong> <?= htmlspecialchars($user['email']) ?></p>
        <p><strong>Telefono:</strong> <?= htmlspecialchars($profilo['telefono']) ?></p>
        <p><strong>Sesso:</strong> <?= ucfirst($profilo['sesso']) ?></p>
        <p><strong>Data di nascita:</strong> <?= htmlspecialchars($profilo['data_nascita']) ?></p>

        <?php if ($atleta): ?>
            <hr>
            <h5>Profilo Atleta</h5>
            <p><strong>Sport secondari:</strong> <?= htmlspecialchars($atleta['sport_secondari']) ?></p>
            <p><strong>Ruolo / Posizione:</strong> <?= htmlspecialchars($atleta['posizione_ruolo']) ?></p>
            <p><strong>Team attuale:</strong> <?= htmlspecialchars($atleta['team_attuale']) ?></p>
            <p><strong>Agente:</strong> <?= htmlspecialchars($atleta['agente_nome']) ?></p>
            <p><strong>Email agente:</strong> <?= htmlspecialchars($atleta['agente_email']) ?></p>
            <p><strong>Telefono agente:</strong> <?= htmlspecialchars($atleta['agente_telefono']) ?></p>
            <p><strong>Professionista:</strong> <?= $atleta['is_professionista'] ? 'Sì' : 'No' ?></p>
            <p><strong>Bio:</strong> <?= nl2br(htmlspecialchars($atleta['bio'])) ?></p>
        <?php endif; ?>

        <div class="mt-4 text-center">
            <a href="<?= SUB_ROOT ?>/profilo/inizia.php" class="btn btn-outline-light btn-slanted me-2">
                <span class="btn-slanted-content">Modifica Profilo</span>
            </a>
            <a href="<?= SUB_ROOT ?>/profilo/modifica.php" class="btn btn-outline-primary btn-slanted me-2">
                <span class="btn-slanted-content">Modifica Atleta</span>
            </a>
            <a href="<?= SUB_ROOT ?>/uac/logout.php" class="btn btn-danger btn-slanted">
                <span class="btn-slanted-content">Esci</span>
            </a>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../layout/_footer.php'; ?>
