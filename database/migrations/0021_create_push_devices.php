<?php
/**
 * Realtime Phase 1 — registro device-token per il push nativo (§4.3 realtime-spec).
 * Scaffolding: nessun invio APNs/FCM ancora. UNIQUE(platform, token) per upsert idempotente.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS push_devices (
            id           BIGINT AUTO_INCREMENT PRIMARY KEY,
            user_id      INT NOT NULL,
            platform     ENUM('ios','android','web') NOT NULL,
            token        VARCHAR(255) NOT NULL,
            created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_platform_token (platform, token),
            KEY idx_pd_user (user_id),
            CONSTRAINT fk_pd_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS push_devices');
    }
};
