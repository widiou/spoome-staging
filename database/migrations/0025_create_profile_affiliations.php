<?php

/**
 * Checkpoint 3 · P2 — Affiliazioni atleta↔organizzazione (la keystone del network sportivo).
 *
 * Collega in modo STRUTTURATO una persona (atleta) a una organizzazione (società / associazione /
 * federazione): alimenta il Roster/Membri dell'org e la "Militanza / Carriera" dell'atleta. A
 * differenza di `profile_experiences` (testo libero, storia off-platform), qui entrambi i lati sono
 * profili reali con FK.
 *
 * Conferma BILATERALE (come una connessione): un lato propone (`pending`, requested_by = sé),
 * l'altro conferma (`confirmed` + confirmed_at). Solo `confirmed` è visibile su entrambe le pagine →
 * valore di verifica sociale (un roster confermato non è auto-dichiarato).
 *
 * Authz al livello dati: il lato org agisce via `ActingContext::canActAs(admin)` sulla propria pagina;
 * il lato atleta agisce come proprio profilo personale. La validazione "l'org è davvero
 * is_organization=1" vive a livello applicativo (AffiliationService).
 *
 * UNIQUE(member_profile_id, org_profile_id): una persona ha al più UNA riga di affiliazione per org
 * (v1). Indici dedicati per le due query calde: roster (per org) e militanza (per membro).
 * FK ON DELETE CASCADE su entrambi i lati (coerente con connections/follows).
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS profile_affiliations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_profile_id INT NOT NULL,
                org_profile_id INT NOT NULL,
                role VARCHAR(80) NULL,
                team VARCHAR(80) NULL,
                jersey VARCHAR(10) NULL,
                start_year SMALLINT NULL,
                end_year SMALLINT NULL,
                is_current TINYINT(1) NOT NULL DEFAULT 1,
                status ENUM('pending','confirmed') NOT NULL DEFAULT 'pending',
                requested_by_profile_id INT NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                confirmed_at TIMESTAMP NULL DEFAULT NULL,
                UNIQUE KEY uq_aff (member_profile_id, org_profile_id),
                KEY idx_aff_org (org_profile_id, status, is_current),
                KEY idx_aff_member (member_profile_id, status, is_current),
                CONSTRAINT fk_aff_member FOREIGN KEY (member_profile_id) REFERENCES profiles(id) ON DELETE CASCADE,
                CONSTRAINT fk_aff_org FOREIGN KEY (org_profile_id) REFERENCES profiles(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS profile_affiliations');
    }
};
