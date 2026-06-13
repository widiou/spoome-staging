<?php
require_once __DIR__ . '/../../db/Database.php';

$pdo = Database::getInstance()->getConnection();
$userId = $_SESSION['user_id'] ?? null;

$stmt = $pdo->prepare("SELECT * FROM atleti WHERE user_id = ?");
$stmt->execute([$userId]);
$atleta = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$atleta) {
    echo "<div class='alert alert-warning'>Profilo atleta non trovato.</div>";
    return;
}
?>

<div class="card mt-4">
    <div class="card-header bg-dark text-white">
        Profilo Atleta
    </div>
    <div class="card-body">
        <p><strong>Categoria:</strong> <?= htmlspecialchars($atleta['categoria']) ?></p>
        <p><strong>Ruolo/Posizione:</strong> <?= htmlspecialchars($atleta['ruolo']) ?></p>
        <p><strong>Club Attuale:</strong> <?= htmlspecialchars($atleta['club_attuale']) ?></p>
        <p><strong>Altezza:</strong> <?= htmlspecialchars($atleta['altezza']) ?> cm</p>
        <p><strong>Peso:</strong> <?= htmlspecialchars($atleta['peso']) ?> kg</p>
        <p><strong>Mano dominante:</strong> <?= htmlspecialchars($atleta['mano_dominante']) ?></p>
        <p><strong>Team o individuale:</strong> <?= ucfirst($atleta['tipo_sportivo']) ?></p>
    </div>
</div>
