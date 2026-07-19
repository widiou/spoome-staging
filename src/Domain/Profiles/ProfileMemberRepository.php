<?php

namespace Spoome\Domain\Profiles;

use PDO;
use Spoome\Core\Db;

/**
 * Accesso ai dati di `profile_members`: la sorgente di verità dell'AUTHZ multi-profilo
 * ("quali utenti possono agire come quale profilo, con quale ruolo").
 *
 * Difesa a livello dati: ogni mutazione filtra per (profile_id, user_id). Tutte le query sono
 * parametrizzate. Gerarchia ruoli: owner > admin > editor (vedi ActingContext).
 *
 * R1: costruito ma NON ancora cablato in alcun controller. `profiles.user_id` resta autoritativo
 * (l'owner primario ne è il mirror). R5 sposterà i call-site delle write su ActingContext::canActAs.
 */
final class ProfileMemberRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Ruolo dell'utente su quel profilo (owner|admin|editor) o null se non è membro. */
    public function roleOf(int $userId, int $profileId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT role FROM profile_members WHERE user_id = :uid AND profile_id = :pid LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'pid' => $profileId]);
        $role = $stmt->fetchColumn();
        return $role === false ? null : (string) $role;
    }

    /**
     * True se il profilo ha ALMENO una riga membro (di qualunque utente/ruolo). Serve al dual-read
     * fallback: il ripiego su `profiles.user_id` è lecito SOLO per un profilo ancora privo di roster
     * (pre-backfill); se il roster esiste è autoritativo e nega l'escalation di un ex-membro rimosso.
     */
    public function hasAnyMember(int $profileId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM profile_members WHERE profile_id = :pid LIMIT 1'
        );
        $stmt->execute(['pid' => $profileId]);
        return (bool) $stmt->fetchColumn();
    }

    /** True se l'utente è membro (con qualunque ruolo) del profilo. */
    public function isMember(int $userId, int $profileId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM profile_members WHERE user_id = :uid AND profile_id = :pid LIMIT 1'
        );
        $stmt->execute(['uid' => $userId, 'pid' => $profileId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Membri di un profilo (roster), owner prima. @return array<int,array{user_id:int,role:string,invited_by:?int,created_at:string}>
     */
    public function membersOf(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT user_id, role, invited_by, created_at
             FROM profile_members WHERE profile_id = :pid
             ORDER BY FIELD(role, 'owner', 'admin', 'editor'), created_at ASC, id ASC"
        );
        $stmt->execute(['pid' => $profileId]);
        return $stmt->fetchAll();
    }

    /**
     * I profili (pagine) di cui l'utente è membro — hot path dello switcher "agisci come".
     * @return array<int,array{profile_id:int,role:string}>
     */
    public function pagesFor(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT profile_id, role FROM profile_members WHERE user_id = :uid ORDER BY profile_id ASC'
        );
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    /** Aggiunge (o ignora se già presente) un membro. UNIQUE(profile_id, user_id) → idempotente. */
    public function addMember(int $profileId, int $userId, string $role, ?int $invitedBy): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO profile_members (profile_id, user_id, role, invited_by)
             VALUES (:pid, :uid, :role, :inv)'
        );
        $stmt->bindValue(':pid', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':role', $role, PDO::PARAM_STR);
        $stmt->bindValue(':inv', $invitedBy, $invitedBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
    }

    /** Rimuove un membro. Il safeguard "ultimo owner" vive a livello applicativo (R6), non qui. */
    public function removeMember(int $profileId, int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM profile_members WHERE profile_id = :pid AND user_id = :uid'
        );
        $stmt->execute(['pid' => $profileId, 'uid' => $userId]);
    }

    /** Cambia il ruolo di un membro esistente. */
    public function setRole(int $profileId, int $userId, string $role): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE profile_members SET role = :role WHERE profile_id = :pid AND user_id = :uid'
        );
        $stmt->execute(['role' => $role, 'pid' => $profileId, 'uid' => $userId]);
    }

    /** Numero di owner attivi di un profilo (per il last-owner safeguard, R6). */
    public function ownerCount(int $profileId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM profile_members WHERE profile_id = :pid AND role = 'owner'"
        );
        $stmt->execute(['pid' => $profileId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Id degli owner di un profilo, con lock di riga (`FOR UPDATE`). Va chiamato DENTRO una
     * transazione: serializza le operazioni concorrenti che possono ridurre il numero di owner
     * (remove/changeRole) così il conteggio "quanti owner restano" è coerente e il safeguard
     * ultimo-owner non è aggirabile da due richieste in parallelo.
     * @return array<int,int>
     */
    public function ownerUserIdsForUpdate(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT user_id FROM profile_members WHERE profile_id = :pid AND role = 'owner' FOR UPDATE"
        );
        $stmt->execute(['pid' => $profileId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Roster arricchito per la UI (F4): unisce il profilo PERSONALE di ogni membro per mostrare
     * nome/handle/avatar. owner prima, poi per anzianità di membership.
     * @return array<int,array<string,mixed>>
     */
    public function membersWithProfile(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT pm.user_id, pm.role, pm.created_at,
                    pp.id AS profile_id, pp.handle, pp.display_name, pp.avatar_media_id
             FROM profile_members pm
             JOIN users u ON u.id = pm.user_id
             LEFT JOIN profiles pp ON pp.id = (
                 SELECT p2.id FROM profiles p2
                 JOIN profile_types pt ON pt.id = p2.profile_type_id
                 WHERE p2.user_id = pm.user_id AND pt.is_organization = 0
                 ORDER BY p2.id ASC LIMIT 1
             )
             WHERE pm.profile_id = :pid
             ORDER BY FIELD(pm.role, 'owner', 'admin', 'editor'), pm.created_at ASC, pm.id ASC"
        );
        $stmt->execute(['pid' => $profileId]);
        return $stmt->fetchAll();
    }
}
