<?php
/**
 * Modello di rivendicazione (claim) dei profili.
 * - `profiles.user_id` diventa NULLABLE: un profilo "non rivendicato" (seed della piattaforma)
 *   non ha proprietario. L'indice UNIQUE uq_profiles_user resta valido perché MySQL non considera
 *   uguali due NULL → resta "un solo profilo posseduto per utente", ma ammette molti profili senza owner.
 * - `profiles.claim_status`: unclaimed | claimed (default claimed: le righe esistenti sono già possedute).
 * - `claim_requests`: richieste utente→profilo con esito moderato dall'admin.
 */
return new class {
    public function up(\PDO $pdo): void
    {
        // user_id nullable (l'FK fk_profiles_user resta: la colonna resta INT).
        $pdo->exec("ALTER TABLE profiles MODIFY user_id INT NULL");

        // Stato di rivendicazione. Le righe correnti (tutte possedute) restano 'claimed' per default.
        $cols = $pdo->query("SHOW COLUMNS FROM profiles LIKE 'claim_status'")->fetchAll();
        if (!$cols) {
            $pdo->exec(
                "ALTER TABLE profiles
                 ADD COLUMN claim_status ENUM('unclaimed','claimed') NOT NULL DEFAULT 'claimed' AFTER user_id,
                 ADD KEY idx_profiles_claim (claim_status)"
            );
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS claim_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            profile_id INT NOT NULL,
            user_id INT NOT NULL,
            message VARCHAR(1000) NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            review_note VARCHAR(500) NULL,
            reviewed_by_user_id INT NULL,
            reviewed_at TIMESTAMP NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_claim_profile (profile_id, status),
            KEY idx_claim_user (user_id, status),
            KEY idx_claim_status (status, created_at),
            CONSTRAINT fk_claim_profile FOREIGN KEY (profile_id) REFERENCES profiles (id) ON DELETE CASCADE,
            CONSTRAINT fk_claim_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
            CONSTRAINT fk_claim_reviewer FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS claim_requests');
        $pdo->exec("ALTER TABLE profiles DROP COLUMN claim_status");
        // Nota: non si ripristina NOT NULL su user_id per non fallire se esistono profili senza owner.
    }
};
