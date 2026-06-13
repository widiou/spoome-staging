<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/**
 * Verifica se l'utente è loggato.
 * Se non lo è, reindirizza alla pagina di login.
 */
function checkLoggedIn(): void
{
    if (!isset($_SESSION['user_id'])) {
        header("Location: /network/uac/login.php");
        exit();
    }
}

function checkLoggedInAdmin(): void
{
    if (!isset($_SESSION['role'])) {
        header("Location: /network/uac/login.php");
        exit();
    } else {
        if ($_SESSION['role'] != 'admin') {
            header("Location: /network/uac/permission.php");
            exit();
        }
    }
}

function checkAdmin(): bool
{
    if ($_SESSION['role'] == 'admin') {
        return true;
    }
    return false;
}

