<?php
/**
 * athletes: rimuove indici ridondanti e aggiunge FULLTEXT per la ricerca.
 * - athletes_id_index: ridondante (id è PRIMARY KEY)
 * - athletes_title_index: ridondante (title è già UNIQUE)
 * - ft_athlete_name: FULLTEXT(title,name,surname) per sostituire i LIKE '%...%' (full scan)
 */
return new class {
    public function up(\PDO $pdo): void
    {
        foreach (['athletes_id_index', 'athletes_title_index'] as $idx) {
            try {
                $pdo->exec("ALTER TABLE athletes DROP INDEX $idx");
            } catch (\Throwable $e) {
                // indice già assente: ok
            }
        }

        // Aggiunge il FULLTEXT solo se non esiste già.
        $exists = $pdo->query("SHOW INDEX FROM athletes WHERE Key_name = 'ft_athlete_name'")->fetch();
        if (!$exists) {
            $pdo->exec('ALTER TABLE athletes ADD FULLTEXT INDEX ft_athlete_name (title, name, surname)');
        }
    }

    public function down(\PDO $pdo): void
    {
        try {
            $pdo->exec('ALTER TABLE athletes DROP INDEX ft_athlete_name');
        } catch (\Throwable $e) {
        }
        try {
            $pdo->exec('ALTER TABLE athletes ADD INDEX athletes_id_index (id)');
            $pdo->exec('ALTER TABLE athletes ADD INDEX athletes_title_index (title)');
        } catch (\Throwable $e) {
        }
    }
};
