<?php
/**
 * Aggiunge le PRIMARY KEY mancanti (dati verificati puliti: item_id non-null e univoco).
 * - organizations: item_id INT UNSIGNED NULL -> NOT NULL + PRIMARY KEY
 * - bigevents: item_id già NOT NULL -> PRIMARY KEY
 * Idempotente: salta se la PK esiste già.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        if (!$this->hasPrimaryKey($pdo, 'organizations')) {
            $pdo->exec('ALTER TABLE organizations MODIFY item_id INT UNSIGNED NOT NULL');
            $pdo->exec('ALTER TABLE organizations ADD PRIMARY KEY (item_id)');
        }
        if (!$this->hasPrimaryKey($pdo, 'bigevents')) {
            $pdo->exec('ALTER TABLE bigevents ADD PRIMARY KEY (item_id)');
        }
    }

    public function down(\PDO $pdo): void
    {
        try {
            $pdo->exec('ALTER TABLE organizations DROP PRIMARY KEY');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE bigevents DROP PRIMARY KEY');
        } catch (\Throwable $e) {
        }
    }

    private function hasPrimaryKey(\PDO $pdo, string $table): bool
    {
        return (bool) $pdo->query("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'")->fetch();
    }
};
