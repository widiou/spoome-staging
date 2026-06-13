<?php
class UserSessionUtils
{
    /**
     * Verifica che l'utente sia loggato e che il tipo corrisponda a quello atteso.
     * Ritorna l'ID utente se tutto è valido.
     */
    public static function requireUserTipo(string $expectedTipo): int
    {
        if (!isset($_SESSION['user_id']) || $_SESSION['user_tipo'] !== $expectedTipo) {
            die("Accesso non autorizzato.");
        }
        return $_SESSION['user_id'];
    }
}
