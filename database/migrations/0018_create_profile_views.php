<?php

/**
 * Checkpoint 2 · F3 — "Chi ha visto il tuo profilo".
 * Roll-up (una riga per coppia viewer→viewed, upsert ON DUPLICATE KEY): crescita limitata
 * O(coppie distinte), non O(visite). PK (viewed, viewer) copre la query calda del proprietario.
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS profile_views (
            viewer_profile_id INT NOT NULL,
            viewed_profile_id INT NOT NULL,
            first_viewed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_viewed_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            view_count INT NOT NULL DEFAULT 1,
            PRIMARY KEY (viewed_profile_id, viewer_profile_id),
            KEY idx_pv_viewed_recent (viewed_profile_id, last_viewed_at),
            CONSTRAINT fk_pv_viewer FOREIGN KEY (viewer_profile_id) REFERENCES profiles (id) ON DELETE CASCADE,
            CONSTRAINT fk_pv_viewed FOREIGN KEY (viewed_profile_id) REFERENCES profiles (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS profile_views');
    }
};
