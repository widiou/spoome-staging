<?php

/**
 * Checkpoint 2 · F1 — Scoperta "Persone che potresti conoscere".
 * Tabella dei suggerimenti ignorati (bottone "Ignora") + indice città per il fallback
 * cold-start del discovery di 2° grado.
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS connection_dismissals (
            profile_id           INT NOT NULL,
            dismissed_profile_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (profile_id, dismissed_profile_id),
            KEY idx_dismiss_profile (profile_id, created_at),
            CONSTRAINT fk_dismiss_profile FOREIGN KEY (profile_id)           REFERENCES profiles (id) ON DELETE CASCADE,
            CONSTRAINT fk_dismiss_target  FOREIGN KEY (dismissed_profile_id) REFERENCES profiles (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Indice per il fallback "stessa città" del discovery (guardato = idempotente).
        $exists = $pdo->query("SHOW INDEX FROM profiles WHERE Key_name = 'idx_profiles_city'")->fetchAll();
        if (!$exists) {
            $pdo->exec("ALTER TABLE profiles ADD KEY idx_profiles_city (location_city)");
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS connection_dismissals');
        $exists = $pdo->query("SHOW INDEX FROM profiles WHERE Key_name = 'idx_profiles_city'")->fetchAll();
        if ($exists) {
            $pdo->exec("ALTER TABLE profiles DROP INDEX idx_profiles_city");
        }
    }
};
