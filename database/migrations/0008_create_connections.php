<?php

/**
 * Connessioni reciproche (rete professionale, stile LinkedIn): una riga per coppia con stato.
 * requester → addressee, status pending/accepted. L'unicità della coppia (in un verso) è a livello DB;
 * l'assenza del verso inverso è garantita dall'applicazione (si controllano entrambe le direzioni).
 * Idempotente.
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS connections (
                id INT AUTO_INCREMENT PRIMARY KEY,
                requester_id INT NOT NULL,
                addressee_id INT NOT NULL,
                status ENUM('pending','accepted') NOT NULL DEFAULT 'pending',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                responded_at TIMESTAMP NULL,
                CONSTRAINT fk_conn_requester FOREIGN KEY (requester_id) REFERENCES profiles(id) ON DELETE CASCADE,
                CONSTRAINT fk_conn_addressee FOREIGN KEY (addressee_id) REFERENCES profiles(id) ON DELETE CASCADE,
                UNIQUE KEY uniq_pair (requester_id, addressee_id),
                INDEX idx_conn_addressee (addressee_id, status),
                INDEX idx_conn_requester (requester_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS connections');
    }
};
