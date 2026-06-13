<?php
// Carica i dati estesi dell’atleta
$stmt = $pdo->prepare("SELECT * FROM atleti WHERE user_id = ?");
$stmt->execute([$userId]);
$datiAtleta = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<h4 class="mb-4">Profilo Atleta</h4>

<div class="mb-3">
    <label>Sport secondari</label>
    <input type="text" name="sport_secondari" class="form-control" value="<?= htmlspecialchars($datiAtleta['sport_secondari'] ?? '') ?>">
</div>

<div class="mb-3">
    <label>Ruolo/Posizione</label>
    <input type="text" name="posizione_ruolo" class="form-control" value="<?= htmlspecialchars($datiAtleta['posizione_ruolo'] ?? '') ?>">
</div>

<div class="mb-3">
    <label>Team attuale</label>
    <input type="text" name="team_attuale" class="form-control" value="<?= htmlspecialchars($datiAtleta['team_attuale'] ?? '') ?>">
</div>

<div class="mb-3">
    <label>Agente - Nome</label>
    <input type="text" name="agente_nome" class="form-control" value="<?= htmlspecialchars($datiAtleta['agente_nome'] ?? '') ?>">
</div>

<div class="mb-3">
    <label>Agente - Email</label>
    <input type="email" name="agente_email" class="form-control" value="<?= htmlspecialchars($datiAtleta['agente_email'] ?? '') ?>">
</div>

<div class="mb-3">
    <label>Agente - Telefono</label>
    <input type="text" name="agente_telefono" class="form-control" value="<?= htmlspecialchars($datiAtleta['agente_telefono'] ?? '') ?>">
</div>

<div class="mb-3">
    <label>Bio</label>
    <textarea name="bio" class="form-control"><?= htmlspecialchars($datiAtleta['bio'] ?? '') ?></textarea>
</div>

<div class="form-check mb-2">
    <input type="checkbox" class="form-check-input" name="is_professionista" id="is_professionista" <?= !empty($datiAtleta['is_professionista']) ? 'checked' : '' ?>>
    <label class="form-check-label" for="is_professionista">Sono un professionista</label>
</div>

<div class="form-check mb-4">
    <input type="checkbox" class="form-check-input" name="visibile" id="visibile" <?= !empty($datiAtleta['visibile']) ? 'checked' : '' ?>>
    <label class="form-check-label" for="visibile">Rendi il profilo visibile</label>
</div>
