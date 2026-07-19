<?php
/**
 * Notifiche in-app (riusabili per qualsiasi evento: claim, follow, connessioni, DM…).
 * Ogni riga è una notifica destinata a un utente, con tipo, testo e link di destinazione.
 * `read_at` traccia la lettura (badge non-letti in nav).
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            type VARCHAR(40) NOT NULL,
            title VARCHAR(200) NOT NULL,
            body VARCHAR(500) NULL,
            url VARCHAR(255) NULL,
            read_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_notif_user_unread (user_id, read_at),
            KEY idx_notif_user_time (user_id, created_at),
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS notifications');
    }
};
