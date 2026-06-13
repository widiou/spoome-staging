<?php

function handleImageUpload(string $fieldName, int $userId, string $prefix = 'img_', string $dest = '/uploads/profili/', array $allowedTypes = ['image/jpeg', 'image/png'], int $maxSizeMB = 3): ?string
{
    if (!isset($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null; // Nessun file caricato o errore
    }

    $file = $_FILES[$fieldName];

    // Controllo dimensione
    if ($file['size'] > $maxSizeMB * 1024 * 1024) {
        throw new Exception("Il file '$fieldName' supera il limite di {$maxSizeMB}MB.");
    }

    // Controllo tipo MIME
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!in_array($mime, $allowedTypes)) {
        throw new Exception("Il file '$fieldName' deve essere JPG o PNG.");
    }

    // Estensione da MIME
    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        default => throw new Exception("Formato file non supportato per '$fieldName'.")
    };

    // Percorso assoluto di salvataggio
    $uploadPath = $_SERVER['DOCUMENT_ROOT'] . $dest;
    if (!is_dir($uploadPath)) mkdir($uploadPath, 0755, true);

    // Costruzione nome file
    $filename = "{$prefix}{$userId}_" . time() . ".$ext";
    $targetFile = $uploadPath . $filename;

    if (!move_uploaded_file($file['tmp_name'], $targetFile)) {
        throw new Exception("Errore nel salvataggio del file '$fieldName'.");
    }

    // Ritorna percorso relativo
    return ltrim($dest, '/') . $filename;
}
