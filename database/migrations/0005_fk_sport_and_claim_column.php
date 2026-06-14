<?php
/**
 * - FK athletes.sport_id -> sports.id (backfill già 100%, nessun valore orfano).
 * - athletes.claimed_by_user_id (FK -> users.id, ON DELETE SET NULL): fondamenta del claiming
 *   (NULL = scheda non reclamata; valorizzato = gestita dall'utente atleta verificato).
 * Idempotente.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        // Normalizzazione sport: FK
        if (!$this->hasFk($pdo, 'athletes', 'fk_athletes_sport')) {
            $pdo->exec('ALTER TABLE athletes ADD CONSTRAINT fk_athletes_sport
                        FOREIGN KEY (sport_id) REFERENCES sports (id)
                        ON DELETE RESTRICT ON UPDATE CASCADE');
        }

        // Claiming: colonna + indice + FK
        if (!$this->hasColumn($pdo, 'athletes', 'claimed_by_user_id')) {
            $pdo->exec('ALTER TABLE athletes ADD COLUMN claimed_by_user_id INT NULL AFTER query');
        }
        if (!$this->hasIndex($pdo, 'athletes', 'idx_athletes_claimed_by')) {
            $pdo->exec('ALTER TABLE athletes ADD INDEX idx_athletes_claimed_by (claimed_by_user_id)');
        }
        if (!$this->hasFk($pdo, 'athletes', 'fk_athletes_claimed_by')) {
            $pdo->exec('ALTER TABLE athletes ADD CONSTRAINT fk_athletes_claimed_by
                        FOREIGN KEY (claimed_by_user_id) REFERENCES users (id)
                        ON DELETE SET NULL ON UPDATE CASCADE');
        }
    }

    public function down(\PDO $pdo): void
    {
        foreach (['fk_athletes_sport', 'fk_athletes_claimed_by'] as $fk) {
            try {
                $pdo->exec("ALTER TABLE athletes DROP FOREIGN KEY $fk");
            } catch (\Throwable $e) {
            }
        }
        try {
            $pdo->exec('ALTER TABLE athletes DROP INDEX idx_athletes_claimed_by');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE athletes DROP COLUMN claimed_by_user_id');
        } catch (\Throwable $e) {
        }
    }

    private function hasColumn(\PDO $pdo, string $table, string $col): bool
    {
        return (bool) $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch();
    }

    private function hasIndex(\PDO $pdo, string $table, string $index): bool
    {
        return (bool) $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = " . $pdo->quote($index))->fetch();
    }

    private function hasFk(\PDO $pdo, string $table, string $name): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        $stmt->execute([$table, $name]);
        return (bool) $stmt->fetch();
    }
};
