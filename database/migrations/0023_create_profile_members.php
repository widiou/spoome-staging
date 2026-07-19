<?php
/**
 * Checkpoint 3 · R0 — Modello multi-profilo / page-admin.
 * Aggiunge `profile_members` come sorgente di verità dell'AUTHZ "chi può agire come quale profilo".
 * `profiles.user_id` RESTA come primary owner denormalizzato (back-compat + claim flow), tenuto in sync.
 *
 * Non distruttivo e reversibile (down = DROP). Il backfill è idempotente (INSERT IGNORE su UNIQUE):
 * ogni profilo già posseduto (user_id non-null) diventa un membro `owner`. I profili unclaimed
 * (user_id NULL) non generano membri — corretto (nessun controllore).
 *
 * NB (R1): la logica authz (`ActingContext::canActAs`) legge questa tabella con dual-read fallback su
 * `profiles.user_id`; NON è ancora cablata in alcun controller. R5 cablerà i call-site alle write.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS profile_members (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id  INT          NOT NULL,
            user_id     INT          NOT NULL,
            role        ENUM('owner','admin','editor') NOT NULL DEFAULT 'owner',
            invited_by  INT          NULL,
            created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_member (profile_id, user_id),
            KEY idx_member_user (user_id),
            CONSTRAINT fk_member_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE CASCADE,
            CONSTRAINT fk_member_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Backfill idempotente: ogni profilo posseduto → un membro 'owner'.
        $pdo->exec("INSERT IGNORE INTO profile_members (profile_id, user_id, role)
                    SELECT id, user_id, 'owner' FROM profiles WHERE user_id IS NOT NULL");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS profile_members');
    }
};
