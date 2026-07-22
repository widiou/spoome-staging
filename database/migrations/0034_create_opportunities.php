<?php

/**
 * R-Moat · M2 — Opportunities (bacheca di reclutamento sportivo). MVP SENZA pagamenti.
 *
 * Un profilo-ORGANIZZAZIONE (società / associazione / federazione) pubblica un'opportunità; un
 * atleta si candida (vedi 0035 opportunity_applications). È il primo dei JTBD monetizzabili
 * (scoperta lato-domanda + efficienza di recruiting) — qui solo lo scheletro dati.
 *
 * ── Vocabolario NON hardcodato (`kind`) ──────────────────────────────────────────────────────
 * `kind` è un VARCHAR governato da una whitelist APPLICATIVA (OpportunityService::KINDS), non un
 * ENUM: aggiungere/rinominare un tipo = una riga di codice, ZERO migrazione (M6 finalizzerà il
 * vocabolario — selezione/raduno, ingaggio stagionale, posizione tecnica, ... — senza toccare lo
 * schema). Volutamente NON esiste un "provino" hardcodato: la forma reale della domanda la fissa M6.
 *
 * ── Sport di prima classe, orizzontale ───────────────────────────────────────────────────────
 * `sport_id` → `sports` (la tassonomia esistente): l'opportunità vale per QUALSIASI sport
 * olimpico/paralimpico, non per un verticale. NULL = trasversale/non specificato.
 *
 * ── Stato & "Scaduta" derivata (niente cron) ─────────────────────────────────────────────────
 * `status ENUM('open','closed')`. "Scaduta" NON è uno stato memorizzato: è derivata a lettura
 * (`status='open' AND deadline IS NOT NULL AND deadline < CURDATE()`), così non serve alcun job di
 * chiusura (i cron sono bloccati su SiteGround). Un'estensione futura (es. 'review' per il gate di
 * verifica di M3) si aggiunge con un ALTER mirato quando servirà.
 *
 * ── Contatore denormalizzato ─────────────────────────────────────────────────────────────────
 * `applications_count` è mantenuto nella STESSA transazione dell'inserimento candidatura
 * (ApplicationRepository), come i contatori follow — niente COUNT(*) live nelle liste calde.
 *
 * Idempotente (information_schema + IF NOT EXISTS). NON registrata/applicata: la esegue Massimo.
 * Target: MySQL 8.4 / InnoDB / utf8mb4_unicode_ci. FK a profiles(id) INT firmato (come tutto lo schema).
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
        if ($this->tableExists($pdo, 'opportunities')) {
            return;
        }
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS opportunities (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                org_profile_id     INT NOT NULL,
                created_by_user_id INT NULL,
                title           VARCHAR(160) NOT NULL,
                kind            VARCHAR(40)  NOT NULL,
                sport_id        INT NULL,
                location_region VARCHAR(80)  NULL,
                location_city   VARCHAR(120) NULL,
                description     TEXT NOT NULL,
                event_date      DATE NULL,
                deadline        DATE NULL,
                status ENUM('open','closed') NOT NULL DEFAULT 'open',
                applications_count INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                closed_at  TIMESTAMP NULL DEFAULT NULL,
                -- 'le mie opportunità' di un'org (gestione), ordinate per id
                KEY idx_opp_org (org_profile_id, status, id),
                -- browse pubblico filtrato per disciplina
                KEY idx_opp_browse (status, sport_id, id),
                -- browse pubblico filtrato per zona
                KEY idx_opp_region (status, location_region, id),
                CONSTRAINT fk_opp_org     FOREIGN KEY (org_profile_id)     REFERENCES profiles(id) ON DELETE CASCADE,
                CONSTRAINT fk_opp_sport   FOREIGN KEY (sport_id)           REFERENCES sports(id)   ON DELETE SET NULL,
                CONSTRAINT fk_opp_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id)    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS opportunities');
    }
};
