<?php
/**
 * Tabella `media`: file caricati dagli utenti (avatar, cover, documenti…). Idempotente.
 * L'avatar del profilo è referenziato da profiles.avatar_media_id (FK, ON DELETE SET NULL).
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS media (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                kind VARCHAR(24) NOT NULL DEFAULT 'other',
                disk_path VARCHAR(255) NOT NULL,
                mime VARCHAR(100) NOT NULL,
                width INT UNSIGNED NULL,
                height INT UNSIGNED NULL,
                size_bytes INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_media_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                INDEX idx_media_user_kind (user_id, kind)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // FK dell'avatar sul profilo (se non già presente). Colonna avatar_media_id creata in 0001.
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles'
               AND CONSTRAINT_NAME = 'fk_profile_avatar'"
        )->fetchColumn();
        if ((int) $exists === 0) {
            $pdo->exec(
                'ALTER TABLE profiles
                 ADD CONSTRAINT fk_profile_avatar FOREIGN KEY (avatar_media_id)
                 REFERENCES media(id) ON DELETE SET NULL'
            );
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE profiles DROP FOREIGN KEY fk_profile_avatar');
        $pdo->exec('DROP TABLE IF EXISTS media');
    }
};
