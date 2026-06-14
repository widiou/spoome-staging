<?php

namespace Spoome\Core;

use PDO;

/**
 * Runner di migrazioni minimale (niente Composer/CLI sul server).
 * Tiene traccia in tabella `migrations`. Ogni file in database/migrations/
 * ritorna un oggetto con up(PDO) e down(PDO). I file si applicano in ordine di nome.
 *
 * NB: in MySQL i comandi DDL fanno commit implicito (non sono transazionali):
 * scrivere migrazioni idempotenti/difensive.
 */
final class Migrator
{
    private PDO $pdo;
    private string $dir;

    public function __construct(PDO $pdo, string $dir)
    {
        $this->pdo = $pdo;
        $this->dir = $dir;
        $this->ensureTable();
    }

    private function ensureTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    }

    /** @return string[] nomi migrazioni già applicate */
    private function applied(): array
    {
        return $this->pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string,string> [path => nome] delle migrazioni non ancora applicate */
    public function pending(): array
    {
        $applied = $this->applied();
        $files   = \glob($this->dir . '/*.php') ?: [];
        \sort($files);

        $pending = [];
        foreach ($files as $file) {
            $name = \basename($file, '.php');
            if (!\in_array($name, $applied, true)) {
                $pending[$file] = $name;
            }
        }
        return $pending;
    }

    /** Applica le migrazioni pendenti. @return string[] log */
    public function migrate(): array
    {
        $log = [];
        foreach ($this->pending() as $file => $name) {
            $migration = require $file;
            try {
                $migration->up($this->pdo);
                $this->pdo->prepare('INSERT INTO migrations (migration) VALUES (?)')->execute([$name]);
                $log[] = "OK: $name";
            } catch (\Throwable $e) {
                $log[] = "FAIL: $name -> " . $e->getMessage();
                break;
            }
        }
        return $log ?: ['Nessuna migrazione pendente'];
    }
}
