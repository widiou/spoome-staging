<?php

/**
 * Registro d'azione dell'area amministrativa (audit trail).
 * Ogni azione sensibile compiuta da un admin (cambio ruolo/stato, rimozione contenuti,
 * accesso step-up) viene tracciata qui: chi, cosa, su quale bersaglio, quando, da quale IP.
 * Append-only per policy: nessuna UPDATE/DELETE dal codice applicativo.
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_audit_log (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            admin_user_id INT NOT NULL,
            action VARCHAR(60) NOT NULL,
            target_type VARCHAR(40) NULL,
            target_id BIGINT NULL,
            meta JSON NULL,
            ip VARCHAR(45) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_audit_admin (admin_user_id, created_at),
            KEY idx_audit_action (action, created_at),
            KEY idx_audit_target (target_type, target_id),
            CONSTRAINT fk_audit_admin FOREIGN KEY (admin_user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS admin_audit_log');
    }
};
