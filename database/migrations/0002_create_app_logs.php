<?php

/**
 * Tabella dei log applicativi (error/warning persistiti per analisi e storico consolidabile).
 * Il fingerprint (sha1 di livello|canale|file:line) raggruppa gli eventi ricorrenti.
 * I log completi di tutti i livelli restano anche su file JSONL (storage/logs/app.log).
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS app_logs (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            level VARCHAR(10) NOT NULL,
            channel VARCHAR(40) NOT NULL DEFAULT 'app',
            message VARCHAR(1000) NOT NULL,
            context JSON NULL,
            fingerprint CHAR(40) NOT NULL,
            exception_class VARCHAR(190) NULL,
            file VARCHAR(255) NULL,
            line INT NULL,
            request_id CHAR(16) NULL,
            user_id INT NULL,
            ip VARCHAR(45) NULL,
            method VARCHAR(10) NULL,
            path VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_logs_level_time (level, created_at),
            KEY idx_logs_fingerprint (fingerprint, created_at),
            KEY idx_logs_channel (channel, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS app_logs');
    }
};
