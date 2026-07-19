<?php

namespace Spoome\Domain\Claims;

use PDO;
use Spoome\Core\Db;

/**
 * Richieste di rivendicazione profilo (`claim_requests`). Solo query parametrizzate.
 */
final class ClaimRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    public function create(int $profileId, int $userId, ?string $message): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO claim_requests (profile_id, user_id, message) VALUES (:p, :u, :m)'
        );
        $stmt->execute(['p' => $profileId, 'u' => $userId, 'm' => ($message ?? '') !== '' ? $message : null]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Richiesta pendente di questo utente per questo profilo (dedupe). */
    public function pendingFor(int $profileId, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM claim_requests WHERE profile_id = :p AND user_id = :u AND status = 'pending' LIMIT 1"
        );
        $stmt->execute(['p' => $profileId, 'u' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Dettaglio richiesta con dati profilo + richiedente (per l'admin). */
    public function findDetail(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.*, p.handle AS profile_handle, p.display_name AS profile_name, p.claim_status,
                    p.user_id AS profile_owner_id, u.email AS user_email
             FROM claim_requests cr
             JOIN profiles p ON p.id = cr.profile_id
             JOIN users u ON u.id = cr.user_id
             WHERE cr.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Coda per stato, con dati profilo + richiedente.
     * @return array{items:array<int,array<string,mixed>>, total:int}
     */
    public function listByStatus(string $status, int $page = 1, int $perPage = 30): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM claim_requests WHERE status = :s');
        $countStmt->execute(['s' => $status]);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'SELECT cr.id, cr.message, cr.status, cr.created_at, cr.reviewed_at,
                    p.handle AS profile_handle, p.display_name AS profile_name, p.claim_status,
                    u.email AS user_email, cr.user_id
             FROM claim_requests cr
             JOIN profiles p ON p.id = cr.profile_id
             JOIN users u ON u.id = cr.user_id
             WHERE cr.status = :s
             ORDER BY cr.id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':s', $status);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function countPending(): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM claim_requests WHERE status = 'pending'")->fetchColumn();
    }

    public function markReviewed(int $id, string $status, ?string $note, int $reviewerId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE claim_requests SET status = :s, review_note = :n, reviewed_by_user_id = :r, reviewed_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            's'  => $status,
            'n'  => ($note ?? '') !== '' ? $note : null,
            'r'  => $reviewerId,
            'id' => $id,
        ]);
    }

    /** Rifiuta automaticamente le altre richieste pendenti sullo stesso profilo (dopo un'approvazione). */
    public function rejectOtherPending(int $profileId, int $exceptId, int $reviewerId, string $note): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE claim_requests SET status = 'rejected', review_note = :n, reviewed_by_user_id = :r, reviewed_at = NOW()
             WHERE profile_id = :p AND id <> :ex AND status = 'pending'"
        );
        $stmt->execute(['n' => $note, 'r' => $reviewerId, 'p' => $profileId, 'ex' => $exceptId]);
    }

    /** Richieste dell'utente (la sua pagina "Le mie rivendicazioni"). */
    public function forUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cr.id, cr.status, cr.message, cr.review_note, cr.created_at, cr.reviewed_at,
                    p.handle AS profile_handle, p.display_name AS profile_name
             FROM claim_requests cr JOIN profiles p ON p.id = cr.profile_id
             WHERE cr.user_id = :u ORDER BY cr.id DESC'
        );
        $stmt->execute(['u' => $userId]);
        return $stmt->fetchAll();
    }
}
