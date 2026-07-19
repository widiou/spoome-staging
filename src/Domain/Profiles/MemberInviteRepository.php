<?php

namespace Spoome\Domain\Profiles;

use PDO;
use Spoome\Core\Db;

/**
 * Accesso ai dati di `profile_member_invites`: gli inviti a diventare membro di una pagina.
 *
 * Tabella separata da `profile_members` di proposito (un invito pendente NON è una membership →
 * non deve mai comparire nell'authz `roleOf`). Solo `PageMemberService::accept` materializza la
 * membership. Difesa a livello dati: ogni lettura/mutazione dell'invitato filtra per
 * `invited_user_id`; ogni azione di pagina filtra per `profile_id`. Query tutte parametrizzate.
 *
 * Un solo invito ATTIVO per (pagina, invitato): UNIQUE(profile_id, invited_user_id). Il re-invito
 * dopo declined/revoked riusa la stessa riga (upsert → torna 'pending').
 */
final class MemberInviteRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * Crea (o RIUSA) l'invito per (pagina, invitato). Se esiste già una riga per questa coppia
     * — tipicamente un vecchio invito 'declined'/'revoked' — viene riportata a 'pending' con il
     * nuovo ruolo/mittente/token. Ritorna l'id dell'invito (nuovo o riusato).
     */
    public function createOrReset(int $profileId, int $invitedUserId, int $invitedByUserId, string $role, ?string $token): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO profile_member_invites
                 (profile_id, invited_user_id, invited_by_user_id, role, status, token, created_at, responded_at)
             VALUES (:pid, :invitee, :inviter, :role, 'pending', :token, NOW(), NULL)
             ON DUPLICATE KEY UPDATE
                 invited_by_user_id = VALUES(invited_by_user_id),
                 role         = VALUES(role),
                 status       = 'pending',
                 token        = VALUES(token),
                 created_at   = NOW(),
                 responded_at = NULL,
                 id           = LAST_INSERT_ID(id)"
        );
        $stmt->bindValue(':pid', $profileId, PDO::PARAM_INT);
        $stmt->bindValue(':invitee', $invitedUserId, PDO::PARAM_INT);
        $stmt->bindValue(':inviter', $invitedByUserId, PDO::PARAM_INT);
        $stmt->bindValue(':role', $role, PDO::PARAM_STR);
        $stmt->bindValue(':token', $token, $token === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();
        return (int) $this->pdo->lastInsertId();
    }

    /** Invito pendente per la coppia (pagina, invitato), o null. Per bloccare il doppio-invito. */
    public function findPendingFor(int $profileId, int $invitedUserId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM profile_member_invites
             WHERE profile_id = :pid AND invited_user_id = :uid AND status = 'pending' LIMIT 1"
        );
        $stmt->execute(['pid' => $profileId, 'uid' => $invitedUserId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Invito per id, MA solo se appartiene all'utente indicato (l'invitato). Authz a livello dati:
     * accept/decline possono agire solo sui PROPRI inviti.
     */
    public function findByIdForInvitee(int $inviteId, int $invitedUserId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM profile_member_invites WHERE id = :id AND invited_user_id = :uid LIMIT 1'
        );
        $stmt->execute(['id' => $inviteId, 'uid' => $invitedUserId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Invito per id, NON scopato. Usato dalla revoca via route `/pagine/inviti/{id}/revoca` per
     * ricavare (profile_id, invited_user_id): l'authz vera (acting è admin della pagina) è poi
     * ri-verificata dentro PageMemberService::revokeInvite. La riga non è sensibile di per sé.
     */
    public function findById(int $inviteId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM profile_member_invites WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $inviteId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Invito per id nel contesto di una pagina (per revoca da parte di owner/admin). */
    public function findByIdForPage(int $inviteId, int $profileId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM profile_member_invites WHERE id = :id AND profile_id = :pid LIMIT 1'
        );
        $stmt->execute(['id' => $inviteId, 'pid' => $profileId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Inviti PENDENTI ricevuti da un utente (la sua "inbox inviti"), con nome/handle della pagina
     * e di chi ha invitato. Hot path della UI destinatario (F4).
     * @return array<int,array<string,mixed>>
     */
    public function pendingForUser(int $userId, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.id, i.profile_id, i.role, i.created_at,
                    p.handle AS page_handle, p.display_name AS page_name,
                    i.invited_by_user_id
             FROM profile_member_invites i
             JOIN profiles p ON p.id = i.profile_id
             WHERE i.invited_user_id = :uid AND i.status = 'pending'
             ORDER BY i.id DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Inviti PENDENTI di una pagina (per la UI di gestione membri, F4). */
    public function pendingForPage(int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT i.id, i.invited_user_id, i.role, i.created_at, i.invited_by_user_id,
                    pp.handle, pp.display_name
             FROM profile_member_invites i
             LEFT JOIN profiles pp ON pp.id = (
                 SELECT p2.id FROM profiles p2
                 JOIN profile_types pt ON pt.id = p2.profile_type_id
                 WHERE p2.user_id = i.invited_user_id AND pt.is_organization = 0
                 ORDER BY p2.id ASC LIMIT 1
             )
             WHERE i.profile_id = :pid AND i.status = 'pending'
             ORDER BY i.id DESC"
        );
        $stmt->execute(['pid' => $profileId]);
        return $stmt->fetchAll();
    }

    /**
     * Segna l'esito di un invito, SOLO se è ancora 'pending' (transizione idempotente: una seconda
     * chiamata non fa nulla). @return int righe modificate (0 = non era più pending).
     */
    public function markResponded(int $inviteId, string $status): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE profile_member_invites
             SET status = :st, responded_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute(['st' => $status, 'id' => $inviteId]);
        return $stmt->rowCount();
    }

    /** Revoca l'invito pendente per (pagina, invitato). @return int righe modificate. */
    public function revoke(int $profileId, int $invitedUserId): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE profile_member_invites
             SET status = 'revoked', responded_at = NOW()
             WHERE profile_id = :pid AND invited_user_id = :uid AND status = 'pending'"
        );
        $stmt->execute(['pid' => $profileId, 'uid' => $invitedUserId]);
        return $stmt->rowCount();
    }
}
