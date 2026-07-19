<?php
/**
 * FK dell'immagine di copertina sul profilo (profiles.cover_media_id → media, ON DELETE SET NULL).
 * La colonna cover_media_id è stata creata in 0001. Idempotente.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $exists = $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles'
               AND CONSTRAINT_NAME = 'fk_profile_cover'"
        )->fetchColumn();
        if ((int) $exists === 0) {
            $pdo->exec(
                'ALTER TABLE profiles
                 ADD CONSTRAINT fk_profile_cover FOREIGN KEY (cover_media_id)
                 REFERENCES media(id) ON DELETE SET NULL'
            );
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE profiles DROP FOREIGN KEY fk_profile_cover');
    }
};
