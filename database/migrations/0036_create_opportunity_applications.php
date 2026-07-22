<?php

/**
 * R-Moat · M2 — Candidature alle Opportunities (chiude il loop org↔atleta).
 *
 * Un profilo-ATLETA (persona, non-organizzazione) si candida a un'opportunità aperta. L'org che ha
 * pubblicato l'opportunità VEDE e GESTISCE le candidature ricevute (accetta / non-seleziona) —
 * requisito esplicito di Steve: il ricevente non le riceve nel vuoto.
 *
 * ── Stato candidatura ────────────────────────────────────────────────────────────────────────
 * `status ENUM('submitted','accepted','rejected')`: nasce `submitted`; l'org la porta a `accepted`
 * (accettata) o `rejected` (non selezionata). Un ritiro lato atleta (withdraw) è un'estensione
 * futura → si aggiunge un valore o un `withdrawn_at` quando servirà, senza rompere questo scheletro.
 *
 * ── Dedupe & authz al livello dati ───────────────────────────────────────────────────────────
 * UNIQUE(opportunity_id, applicant_profile_id): una candidatura per atleta per opportunità (ri-invio
 * bloccato a livello DB, oltre che applicativo). L'ownership org è verificata SEMPRE via join su
 * opportunities.org_profile_id (niente IDOR): l'org gestisce solo le candidature alle PROPRIE
 * opportunità; l'atleta vede solo le PROPRIE candidature.
 *
 * ── Contatore denormalizzato ─────────────────────────────────────────────────────────────────
 * L'inserimento incrementa `opportunities.applications_count` nella STESSA transazione (no drift).
 *
 * FK ON DELETE CASCADE su entrambi i lati (coerente con affiliations/recommendations): eliminata
 * l'opportunità o il profilo, le candidature collegate spariscono. Idempotente. NON registrata.
 * Target: MySQL 8.4 / InnoDB. applicant_profile_id → profiles(id) INT; opportunity_id → BIGINT UNSIGNED.
 */
return new class () {
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
        if ($this->tableExists($pdo, 'opportunity_applications')) {
            return;
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS opportunity_applications (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                opportunity_id       BIGINT UNSIGNED NOT NULL,
                applicant_profile_id INT NOT NULL,
                cover_message VARCHAR(1000) NULL,
                status ENUM('submitted','accepted','rejected') NOT NULL DEFAULT 'submitted',
                created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                responded_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uq_app (opportunity_id, applicant_profile_id),
                -- gestione candidature di un'opportunità (lato org), per stato
                KEY idx_app_opp (opportunity_id, status, id),
                -- 'le mie candidature' (lato atleta), per stato
                KEY idx_app_applicant (applicant_profile_id, status, id),
                CONSTRAINT fk_app_opp       FOREIGN KEY (opportunity_id)       REFERENCES opportunities(id) ON DELETE CASCADE,
                CONSTRAINT fk_app_applicant FOREIGN KEY (applicant_profile_id) REFERENCES profiles(id)      ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS opportunity_applications');
    }
};
