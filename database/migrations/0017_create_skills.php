<?php
/**
 * Checkpoint 2 · F2 — Competenze + Endorsement.
 * Competenze free-text per profilo (coerenti col pattern delle sotto-entità profilo)
 * con endorsement dalle connessioni; contatore denormalizzato `endorsements_count`.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS profile_skills (
            id INT AUTO_INCREMENT PRIMARY KEY,
            profile_id INT NOT NULL,
            label VARCHAR(60) NOT NULL,
            position INT NOT NULL DEFAULT 0,
            endorsements_count INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_profile_skill (profile_id, label),
            KEY idx_skill_profile (profile_id, position),
            CONSTRAINT fk_skill_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $pdo->exec("CREATE TABLE IF NOT EXISTS skill_endorsements (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            skill_id INT NOT NULL,
            endorser_profile_id INT NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_skill_endorser (skill_id, endorser_profile_id),
            KEY idx_endorse_endorser (endorser_profile_id),
            CONSTRAINT fk_endorse_skill   FOREIGN KEY (skill_id)            REFERENCES profile_skills (id) ON DELETE CASCADE,
            CONSTRAINT fk_endorse_profile FOREIGN KEY (endorser_profile_id) REFERENCES profiles (id)       ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS skill_endorsements');
        $pdo->exec('DROP TABLE IF EXISTS profile_skills');
    }
};
