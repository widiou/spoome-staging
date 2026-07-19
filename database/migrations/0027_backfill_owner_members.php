<?php

/**
 * Horizon 0 · consolidamento authz — chiusura del "doppio-path" di ownership.
 *
 * Contesto: la migrazione 0023 aveva backfillato ogni profilo posseduto (user_id non-null) con la
 * sua owner-row in `profile_members`, MA la self-registration (AuthService::register) continuava a
 * scrivere solo `profiles.user_id` senza la membership → ogni iscritto DOPO la 0023 nasceva privo di
 * roster e reggeva l'authz solo sul dual-read fallback (profiles.user_id). Da ora AuthService scrive
 * la owner-row in transazione; questa migrazione ripesca gli orfani già esistenti.
 *
 * Idempotente (INSERT IGNORE su UNIQUE uq_member): rieseguibile senza effetti. I profili unclaimed
 * (user_id NULL, claim flow) restano correttamente senza membri — nessun controllore ancora.
 */
return new class () {
    public function up(\PDO $pdo): void
    {
        // Ogni profilo posseduto e privo di owner-row → membro 'owner'. Ricopre gli iscritti post-0023.
        $pdo->exec("INSERT IGNORE INTO profile_members (profile_id, user_id, role)
                    SELECT p.id, p.user_id, 'owner'
                    FROM profiles p
                    WHERE p.user_id IS NOT NULL
                      AND NOT EXISTS (
                          SELECT 1 FROM profile_members pm
                          WHERE pm.profile_id = p.id AND pm.role = 'owner'
                      )");
    }

    public function down(\PDO $pdo): void
    {
        // Non reversibile in modo mirato (non distinguerebbe le righe da questo backfill da quelle di
        // 0023 o create dagli utenti). No-op: il dato è coerente e ricostruibile.
    }
};
