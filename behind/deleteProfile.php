<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
require_once 'bootstrap.php';
checkLoggedInAdmin();
$search = $_GET['a'] ?? '';
if ($search) {
    if (Athlete::deleteAthlete($search)) {
        //echo "Atleta cancellato con successo.";
    } else {
        //echo "Errore durante la cancellazione dell'atleta.";
    }

}
