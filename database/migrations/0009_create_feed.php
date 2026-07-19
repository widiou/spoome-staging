<?php

/**
 * Feed ibrido: `posts` (contenuti scritti dagli utenti) + `activities` (eventi automatici:
 * palmarès/esperienza aggiunti, nuovo follow, nuova connessione).
 * `activities.meta` è denormalizzato (testo pronto) così il rendering è robusto anche se
 * l'entità collegata viene poi modificata/eliminata. Idempotente.
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_id INT NOT NULL,
                body VARCHAR(2000) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_post_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
                INDEX idx_post_profile_time (profile_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS activities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_id INT NOT NULL,
                type VARCHAR(32) NOT NULL,
                subject_id INT NULL,
                meta VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_act_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
                INDEX idx_act_profile_time (profile_id, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS posts');
        $pdo->exec('DROP TABLE IF EXISTS activities');
    }
};
