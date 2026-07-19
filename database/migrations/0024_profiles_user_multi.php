<?php

/**
 * Checkpoint 3 · R3 — Abilita il multi-profilo a livello schema.
 *
 * `profiles.user_id` nasceva UNIQUE (`uq_profiles_user`): un utente = UN solo profilo. Il modello
 * page-admin richiede che un utente possieda il proprio profilo personale + N pagine org (tutte con
 * `user_id = suo`, come primary owner denormalizzato). Si sostituisce l'indice UNIQUE con uno normale.
 *
 * La FK `fk_profiles_user` richiede un indice su user_id: si AGGIUNGE prima l'indice normale, poi si
 * elimina l'UNIQUE (così la FK non resta mai senza indice di supporto). Non distruttivo per i dati.
 * L'univocità dell'ownership authz è ora garantita da `profile_members` (UNIQUE profile_id,user_id).
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        // Indice normale di supporto (idempotente: crea solo se manca).
        $has = $pdo->query("SHOW INDEX FROM profiles WHERE Key_name = 'idx_profiles_user'")->fetch();
        if ($has === false) {
            $pdo->exec('ALTER TABLE profiles ADD INDEX idx_profiles_user (user_id)');
        }
        // Elimina l'UNIQUE (se ancora presente): da qui un utente può possedere più profili.
        $uq = $pdo->query("SHOW INDEX FROM profiles WHERE Key_name = 'uq_profiles_user'")->fetch();
        if ($uq !== false) {
            $pdo->exec('ALTER TABLE profiles DROP INDEX uq_profiles_user');
        }
    }

    public function down(\PDO $pdo): void
    {
        // Ripristino best-effort: ricrea l'UNIQUE (fallisce se esistono già più profili per utente)
        // e rimuove l'indice normale. Reversibile solo finché il multi-profilo non è stato usato.
        $uq = $pdo->query("SHOW INDEX FROM profiles WHERE Key_name = 'uq_profiles_user'")->fetch();
        if ($uq === false) {
            $pdo->exec('ALTER TABLE profiles ADD UNIQUE KEY uq_profiles_user (user_id)');
        }
        $has = $pdo->query("SHOW INDEX FROM profiles WHERE Key_name = 'idx_profiles_user'")->fetch();
        if ($has !== false) {
            $pdo->exec('ALTER TABLE profiles DROP INDEX idx_profiles_user');
        }
    }
};
