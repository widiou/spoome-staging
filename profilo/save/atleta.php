<?php
if (!isset($pdo, $userId)) {
    throw new Exception("Contesto non valido per il salvataggio del profilo atleta.");
}

// Recupero e sanificazione dei dati
$sport_secondari   = $_POST['sport_secondari'] ?? null;
$posizione_ruolo   = $_POST['posizione_ruolo'] ?? null;
$team_attuale      = $_POST['team_attuale'] ?? null;
$agente_nome       = $_POST['agente_nome'] ?? null;
$agente_email      = $_POST['agente_email'] ?? null;
$agente_telefono   = $_POST['agente_telefono'] ?? null;
$bio               = $_POST['bio'] ?? null;
$is_professionista = isset($_POST['is_professionista']) ? 1 : 0;
$visibile          = isset($_POST['visibile']) ? 1 : 0;

// Prepara e esegue la query
$stmt = $pdo->prepare("
    UPDATE atleti
    SET sport_secondari   = :sport_secondari,
        posizione_ruolo   = :posizione_ruolo,
        team_attuale      = :team_attuale,
        agente_nome       = :agente_nome,
        agente_email      = :agente_email,
        agente_telefono   = :agente_telefono,
        bio               = :bio,
        is_professionista = :is_professionista,
        visibile          = :visibile
    WHERE user_id = :user_id
");

$stmt->execute([
    'sport_secondari'   => $sport_secondari,
    'posizione_ruolo'   => $posizione_ruolo,
    'team_attuale'      => $team_attuale,
    'agente_nome'       => $agente_nome,
    'agente_email'      => $agente_email,
    'agente_telefono'   => $agente_telefono,
    'bio'               => $bio,
    'is_professionista' => $is_professionista,
    'visibile'          => $visibile,
    'user_id'           => $userId
]);
