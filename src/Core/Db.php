<?php

namespace Spoome\Core;

use PDO;
use RuntimeException;

/**
 * Connessione PDO singleton a MySQL. Credenziali solo da .env (nessun fallback hardcoded).
 */
final class Db
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $host    = (string) Config::get('DB_HOST', 'localhost');
        $name    = (string) Config::get('DB_NAME', '');
        $user    = (string) Config::get('DB_USER', '');
        $pass    = (string) Config::get('DB_PASS', '');
        $charset = (string) Config::get('DB_CHARSET', 'utf8mb4');
        $port    = (string) Config::get('DB_PORT', '');
        $socket  = (string) Config::get('DB_SOCKET', '');

        if ($name === '' || $user === '') {
            throw new RuntimeException(
                'Configurazione DB mancante: definisci DB_NAME/DB_USER/DB_PASS in .env.'
            );
        }

        // Socket e host/port sono mutuamente esclusivi lato PDO MySQL: se è valorizzato
        // un unix_socket, ha priorità (host/port verrebbero comunque ignorati dal driver).
        // Se né DB_PORT né DB_SOCKET sono in .env, il DSN resta identico a prima (retro-compatibile).
        if ($socket !== '') {
            $dsn = "mysql:unix_socket={$socket};dbname={$name};charset={$charset}";
        } else {
            $dsn = $port !== ''
                ? "mysql:host={$host};port={$port};dbname={$name};charset={$charset}"
                : "mysql:host={$host};dbname={$name};charset={$charset}";
        }
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        return self::$pdo;
    }

    /**
     * Esegue $fn dentro una transazione sul $pdo dato, con commit/rollback automatici.
     * Nesting-safe: se una transazione è già aperta (es. un Service esterno), esegue $fn
     * inline senza aprirne un'altra (MySQL non ha vere transazioni annidate) — così i
     * repository possono garantire l'atomicità coppia-sorgente↔contatore anche quando sono
     * richiamati dentro una transazione più ampia. Rilancia sempre l'eccezione dopo il rollback.
     *
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public static function transaction(PDO $pdo, callable $fn)
    {
        if ($pdo->inTransaction()) {
            return $fn($pdo);
        }
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
