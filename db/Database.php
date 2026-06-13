<?php

require_once dirname(__DIR__) . '/config/env.php';

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;

    private function __construct()
    {
        $host     = env('DB_HOST', 'localhost');
        $database = env('DB_NAME');
        $username = env('DB_USER');
        $password = env('DB_PASS');
        $charset  = env('DB_CHARSET', 'utf8mb4');

        if (empty($database) || empty($username)) {
            throw new RuntimeException(
                'Configurazione DB mancante: definisci DB_NAME/DB_USER/DB_PASS nel file .env. '
                . 'Nessun fallback hardcoded per evitare connessioni accidentali a un DB errato.'
            );
        }

        $dsn = "mysql:host={$host};dbname={$database};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $this->connection = new PDO($dsn, $username, $password, $options);
    }

    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    private function __clone()
    {
    }

    private function __wakeup()
    {
    }
}