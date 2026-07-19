<?php
/**
 * Sotto-entità del profilo (CV sportivo): esperienze, palmarès (risultati), link/social.
 * Ogni riga appartiene a un profilo (FK ON DELETE CASCADE). Idempotente.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS profile_experiences (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_id INT NOT NULL,
                org_name VARCHAR(160) NOT NULL,
                role VARCHAR(160) NOT NULL,
                location VARCHAR(160) NULL,
                start_year SMALLINT UNSIGNED NULL,
                end_year SMALLINT UNSIGNED NULL,
                is_current TINYINT(1) NOT NULL DEFAULT 0,
                description VARCHAR(1000) NULL,
                sort INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_exp_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
                INDEX idx_exp_profile (profile_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS profile_achievements (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_id INT NOT NULL,
                title VARCHAR(200) NOT NULL,
                year SMALLINT UNSIGNED NULL,
                description VARCHAR(500) NULL,
                sort INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_ach_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
                INDEX idx_ach_profile (profile_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS profile_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_id INT NOT NULL,
                kind VARCHAR(24) NOT NULL DEFAULT 'website',
                label VARCHAR(120) NULL,
                url VARCHAR(500) NOT NULL,
                sort INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_link_profile FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
                INDEX idx_link_profile (profile_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS profile_experiences');
        $pdo->exec('DROP TABLE IF EXISTS profile_achievements');
        $pdo->exec('DROP TABLE IF EXISTS profile_links');
    }
};
