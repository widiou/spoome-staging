<?php

/**
 * R-Moat · M4 — Analytics d'uso (log eventi append-only, instrumentazione sync/pull, NO cron).
 *
 * Nuova tabella `analytics_events`: log ANALITICO orientato all'attore per le aggregazioni admin
 * on-demand (funnel, serie temporale, timeline, conversione). Distinta da `user_events` (0020, inbox
 * realtime per-destinatario) e da `profile_views` (0018, roll-up "chi ha visto il tuo profilo"): qui
 * ogni riga è un EVENTO grezzo, immutabile, non scoping-per-destinatario.
 *
 * Scelte di modellazione:
 *  - Append-only: nessun lock in scrittura (INSERT singolo, fire-and-forget fail-safe), potatura sicura.
 *  - `event_type` VARCHAR (non ENUM): il vocabolario cresce con M2 (opportunity_publish/apply) e M6
 *    → evita un ALTER su tabella grande a ogni nuovo evento.
 *  - Target polimorfico `subject_type`/`subject_id` SENZA FK: una sola tabella per profilo/opportunità/
 *    candidatura/ricerca; nessun controllo vincolo a ogni INSERT, nessun churn quando M2 introduce
 *    entità nuove. L'integrità referenziale non è un requisito di un log analitico.
 *  - `actor_user_id` FK ON DELETE SET NULL: alla cancellazione utente l'evento si ANONIMIZZA
 *    conservando i conteggi aggregati (GDPR-friendly). NULL = attore anonimo.
 *  - `anon_id BINARY(16)`: hash di sessione troncato (NO PII) per correlare il funnel anonimo.
 *    Opzionale e disattivabile lato recorder (in attesa del sign-off privacy — vedi #44).
 *  - `meta JSON`: contesto PII-light (es. numero risultati di una ricerca). Mai il testo grezzo della query.
 *
 * Indici (anti over-index, tetto 3):
 *  - idx_ae_type_created(event_type, created_at): workhorse per le query type-scoped e i funnel.
 *  - idx_ae_actor_created(actor_user_id, created_at): timeline attore; copre anche la FK (prefisso sx).
 *  - idx_ae_created(created_at): range-scan per il DELETE di retention e le finestre cross-tipo.
 *  NON indicizzati di proposito: subject_*, anon_id, meta.
 *
 * Idempotente e additiva: CREATE TABLE IF NOT EXISTS con indici e FK inline; nessuna tabella
 * esistente toccata. In MySQL i DDL fanno commit implicito → resta a esecuzione singola non distruttiva.
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS analytics_events (
                id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                event_type    VARCHAR(40)          NOT NULL,          -- search|profile_open|opportunity_publish|apply|...
                actor_user_id INT                  NULL,              -- NULL = anonimo; FK ON DELETE SET NULL
                subject_type  VARCHAR(24)          NULL,              -- target polimorfico: 'profile'|'opportunity'|...
                subject_id    BIGINT UNSIGNED      NULL,              -- id del target (nessuna FK, per scelta)
                anon_id       BINARY(16)           NULL,              -- hash sessione troncato (no PII), funnel anon
                meta          JSON                 NULL,              -- contesto PII-light (mai testo query grezzo)
                created_at    TIMESTAMP            NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_ae_type_created  (event_type, created_at),
                KEY idx_ae_actor_created (actor_user_id, created_at),
                KEY idx_ae_created       (created_at),
                CONSTRAINT fk_ae_actor FOREIGN KEY (actor_user_id)
                    REFERENCES users(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS analytics_events');
    }
};
