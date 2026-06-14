<?php
/**
 * Normalizzazione sport (stadio 1, additivo e reversibile):
 * - aggiunge athletes.sport_id (nullable, ancora senza FK)
 * - popola `sports` con gli sport distinti degli atleti non ancora presenti
 * - backfill sport_id via JOIN athletes.sport = sports.nome (collation ci)
 * - indice su sport_id
 * Dati pre-verificati via /sportcheck: 83 sport distinti, nessuna variante maiuscole/spazi.
 * La FK e la migrazione del codice a sport_id arriveranno in uno stadio successivo.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        if (!$this->hasColumn($pdo, 'athletes', 'sport_id')) {
            $pdo->exec('ALTER TABLE athletes ADD COLUMN sport_id INT NULL AFTER sport');
        }

        $slugify = static function (string $s): string {
            $conv = \iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            $s = \preg_replace('/[^a-zA-Z0-9]+/', '-', $conv !== false ? $conv : $s);
            return \strtolower(\trim((string) $s, '-'));
        };

        // Sport già presenti (confronto case-insensitive per non duplicare).
        $existingLower = \array_map(
            static fn($n) => \mb_strtolower((string) $n),
            $pdo->query('SELECT nome FROM sports')->fetchAll(\PDO::FETCH_COLUMN)
        );

        $distinct = $pdo->query("SELECT DISTINCT sport FROM athletes WHERE sport IS NOT NULL AND sport != ''")
            ->fetchAll(\PDO::FETCH_COLUMN);

        $ins = $pdo->prepare('INSERT INTO sports (nome, slug, attivo) VALUES (:nome, :slug, 1)');
        foreach ($distinct as $sport) {
            if (\in_array(\mb_strtolower((string) $sport), $existingLower, true)) {
                continue;
            }
            $slug = $slugify((string) $sport);
            try {
                $ins->execute([':nome' => $sport, ':slug' => $slug]);
            } catch (\Throwable $e) {
                // slug in conflitto: rendi univoco
                $ins->execute([':nome' => $sport, ':slug' => $slug . '-' . \substr(\md5((string) $sport), 0, 4)]);
            }
            $existingLower[] = \mb_strtolower((string) $sport);
        }

        // Backfill (match esatto su nome, collation utf8mb4_unicode_ci = case-insensitive).
        $pdo->exec('UPDATE athletes a JOIN sports s ON a.sport = s.nome SET a.sport_id = s.id WHERE a.sport_id IS NULL');

        if (!$this->hasIndex($pdo, 'athletes', 'idx_athletes_sport_id')) {
            $pdo->exec('ALTER TABLE athletes ADD INDEX idx_athletes_sport_id (sport_id)');
        }
    }

    public function down(\PDO $pdo): void
    {
        try {
            $pdo->exec('ALTER TABLE athletes DROP INDEX idx_athletes_sport_id');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE athletes DROP COLUMN sport_id');
        } catch (\Throwable $e) {
        }
        // Le righe aggiunte in `sports` restano (potrebbero essere referenziate altrove).
    }

    private function hasColumn(\PDO $pdo, string $table, string $col): bool
    {
        return (bool) $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch();
    }

    private function hasIndex(\PDO $pdo, string $table, string $index): bool
    {
        return (bool) $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = " . $pdo->quote($index))->fetch();
    }
};
