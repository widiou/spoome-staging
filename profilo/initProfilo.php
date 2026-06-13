<?php
session_start();

// Imposta il titolo della pagina prima di includere l'header
if (!isset($title)) {
    $title = "Modifica Profilo";
}

chdir(__DIR__ . '/../');
require_once 'bootstrap.php';
require_once 'layout/_header.php';
require_once 'models/ImageUploader.php';
require_once 'models/UserSessionUtils.php';
require_once 'models/ProfiloUtils.php';

// Imposta il tipo utente richiesto in ogni file, ad es. $tipoRichiesto = 'professionista';
if (!isset($tipoRichiesto)) {
    die("Tipo utente non specificato.");
}

// Verifica la sessione e ottieni l'user ID
$userId = UserSessionUtils::requireUserTipo($tipoRichiesto);

// Ottieni il profilo (viene creato se non esiste)
$pdo = Database::getInstance()->getConnection();
$profilo = ProfiloUtils::getOrCreateProfilo($pdo, $tipoRichiesto, $userId);
