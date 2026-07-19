<?php
/**
 * Recommendations (raccomandazioni LinkedIn-style) — testo libero che una persona connessa scrive
 * per un'altra, mostrato sul profilo del destinatario SOLO dopo la sua approvazione.
 *
 * Distinta dagli endorsement (che confermano una singola competenza): qui è un testimonial libero.
 * v1 persona→persona: il DESTINATARIO deve essere un profilo NON-organizzazione; l'AUTORE è la
 * persona connessa (validato a livello applicativo in RecommendationService, con difesa a più livelli).
 *
 * Modello ad APPROVAZIONE: nasce `pending` (invisibile), il destinatario `accept`→`visible` oppure
 * `hide`→`hidden`. UNA riga per coppia (author,recipient): ri-scrivere = upsert che torna `pending`.
 *
 * FK INT (profiles.id è INT firmato) ON DELETE CASCADE su entrambi i lati (coerente con
 * skill_endorsements / profile_affiliations). Un solo indice (recipient_profile_id, status) copre sia
 * "visibili di un profilo" sia "pending di un profilo" (prefisso). CHECK author<>recipient non è
 * affidabile su MySQL vecchi → enforce nel Service. Idempotente (information_schema + IF NOT EXISTS).
 */
return new class {
    private function tableExists(\PDO $pdo, string $name): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :n"
        );
        $stmt->execute(['n' => $name]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function up(\PDO $pdo): void
    {
        if ($this->tableExists($pdo, 'profile_recommendations')) {
            return;
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS profile_recommendations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                author_profile_id    INT NOT NULL,
                recipient_profile_id INT NOT NULL,
                body         VARCHAR(1000) NOT NULL,
                relationship VARCHAR(80) NULL,
                status ENUM('pending','visible','hidden') NOT NULL DEFAULT 'pending',
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                responded_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uq_reco_pair (author_profile_id, recipient_profile_id),
                KEY idx_reco_recipient_status (recipient_profile_id, status),
                KEY idx_reco_author (author_profile_id),
                CONSTRAINT fk_reco_author    FOREIGN KEY (author_profile_id)    REFERENCES profiles(id) ON DELETE CASCADE,
                CONSTRAINT fk_reco_recipient FOREIGN KEY (recipient_profile_id) REFERENCES profiles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS profile_recommendations');
    }
};
