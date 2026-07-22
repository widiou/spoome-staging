<?php

/**
 * R4 · perf connections + retention.
 *
 * 1) UNION connections — la rilettura di `ProfileRepository::connectionsOf` come UNION ALL indicizzata
 *    (un ramo su requester_id, uno su addressee_id) richiede gli indici (requester_id, status) e
 *    (addressee_id, status). Questi ESISTONO GIÀ dalla creazione della tabella (migrazione 0008:
 *    idx_conn_requester / idx_conn_addressee): qui li si garantisce in modo idempotente (no-op sui DB
 *    correnti) per documentare la dipendenza e proteggere ambienti dove la tabella fosse nata diversa.
 *
 * 2) Retention `activities` — il job di manutenzione ora pota le attività oltre la finestra di retention
 *    con `DELETE ... WHERE created_at < ? LIMIT ?`. L'indice esistente idx_act_profile_time
 *    (profile_id, created_at) NON serve un range sul solo created_at (prefisso sbagliato) → si aggiunge
 *    idx_act_created (created_at) così la potatura a batch è un range scan invece di un full-scan.
 *
 * Idempotente: controlla information_schema prima di creare/eliminare (MySQL non supporta
 * CREATE INDEX IF NOT EXISTS). Non distruttiva.
 */
return new class () {
    private function indexExists(\PDO $pdo, string $table, string $name): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :n"
        );
        $stmt->execute(['t' => $table, 'n' => $name]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function up(\PDO $pdo): void
    {
        // (1) Indici della UNION connections — guardia idempotente (già creati da 0008).
        if (!$this->indexExists($pdo, 'connections', 'idx_conn_requester')) {
            $pdo->exec('CREATE INDEX idx_conn_requester ON connections (requester_id, status)');
        }
        if (!$this->indexExists($pdo, 'connections', 'idx_conn_addressee')) {
            $pdo->exec('CREATE INDEX idx_conn_addressee ON connections (addressee_id, status)');
        }

        // (2) Indice per la potatura retention di activities (range sul solo created_at).
        if (!$this->indexExists($pdo, 'activities', 'idx_act_created')) {
            $pdo->exec('CREATE INDEX idx_act_created ON activities (created_at)');
        }
    }

    public function down(\PDO $pdo): void
    {
        // Solo l'indice introdotto QUI viene rimosso: idx_conn_* appartengono a 0008.
        if ($this->indexExists($pdo, 'activities', 'idx_act_created')) {
            $pdo->exec('DROP INDEX idx_act_created ON activities');
        }
    }
};
