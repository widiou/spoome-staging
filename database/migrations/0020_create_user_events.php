<?php
/**
 * Realtime Phase 1 — inbox durevole per-utente (§3 realtime-spec).
 * Log append-only, un id BIGINT monotono = cursore unificato del client. Ogni riga appartiene a
 * ESATTAMENTE un destinatario (user_id): scoping per-utente = invariante di sicurezza (nessun
 * evento condiviso/globale che possa trapelare tra utenti). Additivo: nessuna tabella esistente toccata.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_events (
            id               BIGINT AUTO_INCREMENT PRIMARY KEY,   -- cursore globale monotono
            user_id          INT NOT NULL,                        -- destinatario (identità canale)
            type             VARCHAR(40) NOT NULL,                -- 'message.created', 'notification.created', ...
            actor_profile_id INT NULL,                            -- profilo attore (pubblico), opzionale
            payload          JSON NULL,                           -- id di riferimento + preview troncata (no PII)
            created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_ue_user (user_id, id),                        -- query cursore: WHERE user_id=? AND id>? ORDER BY id
            CONSTRAINT fk_ue_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS user_events');
    }
};
