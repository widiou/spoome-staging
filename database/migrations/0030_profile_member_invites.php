<?php
/**
 * Inviti a diventare MEMBRO di una pagina (org): "invita per handle" del profilo personale.
 *
 * Tabella SEPARATA da `profile_members` di proposito: un invito pendente NON è una membership.
 * Tenerlo qui evita di inquinare `profile_members` con uno `status` che ActingContext/roleOf
 * leggono per l'authz (un invito "pending" scritto lì diventerebbe una escalation di privilegio).
 * Solo `accept` materializza la riga in `profile_members`.
 *
 * SCHEMA / scelte:
 * - `id` BIGINT UNSIGNED AUTO_INCREMENT: coerente con la scelta BIGINT recente (0029) per le
 *   tabelle a crescita social; è la PK della tabella (nessuna FK entrante, nessun vincolo di match).
 * - Le colonne FK (`profile_id`, `invited_user_id`, `invited_by_user_id`) sono INT firmato perché
 *   `profiles.id` e `users.id` sono ancora INT firmato: InnoDB richiede tipo E signedness combacianti.
 * - `role` ENUM('admin','editor'): un invito NON conferisce mai 'owner' (owner solo via transfer).
 * - `status` ENUM('pending','accepted','declined','revoked') DEFAULT 'pending'.
 * - UNIQUE(profile_id, invited_user_id): UNA sola riga-invito per (pagina, invitato) a prescindere
 *   dallo stato. Il re-invito dopo un declined/revoked RIUSA la stessa riga (upsert → torna 'pending'),
 *   così non proliferano righe storiche e il vincolo di "un solo invito attivo" è garantito dal DB.
 *   (Un `UNIQUE(profile_id, invited_user_id, status)` non reggerebbe più righe 'declined'.)
 * - `token` CHAR(32) NULL: opzionale, per un futuro accept via link email; l'accept in-app è già
 *   autorizzato dall'identità dell'invitato, quindi non è sulla via critica.
 *
 * Non distruttivo e reversibile (down = DROP). Idempotente (CREATE TABLE IF NOT EXISTS + guardia
 * information_schema): rieseguibile senza errori.
 */
return new class {
    private function tableExists(\PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
        );
        $stmt->execute(['t' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function up(\PDO $pdo): void
    {
        if ($this->tableExists($pdo, 'profile_member_invites')) {
            return;
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS profile_member_invites (
            id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            profile_id          INT          NOT NULL,
            invited_user_id     INT          NOT NULL,
            invited_by_user_id  INT          NOT NULL,
            role                ENUM('admin','editor')                          NOT NULL DEFAULT 'editor',
            status              ENUM('pending','accepted','declined','revoked') NOT NULL DEFAULT 'pending',
            token               CHAR(32)     NULL,
            created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            responded_at        TIMESTAMP    NULL     DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_invite (profile_id, invited_user_id),
            KEY idx_invitee_status (invited_user_id, status),
            KEY idx_profile_status (profile_id, status),
            CONSTRAINT fk_invite_profile FOREIGN KEY (profile_id)         REFERENCES profiles (id) ON DELETE CASCADE,
            CONSTRAINT fk_invite_invitee FOREIGN KEY (invited_user_id)    REFERENCES users (id)    ON DELETE CASCADE,
            CONSTRAINT fk_invite_inviter FOREIGN KEY (invited_by_user_id) REFERENCES users (id)    ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS profile_member_invites');
    }
};
