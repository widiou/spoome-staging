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

    /**
     * Blocca la riga del profilo target e ne restituisce lo stato di rivendicazione.
     * Va chiamato DENTRO una transazione: serializza gli `approve` concorrenti sullo STESSO
     * profilo (due admin, richieste diverse) e chiude la finestra TOCTOU sull'ownership.
     * Nota: query di sola lettura sulla connessione condivisa (`Db::connection()`), il lock è
     * sulla transazione — la riga vive in `profiles`, ma il lock è legato alla connessione, non al repo.
     * @return array{id:int,user_id:?int,claim_status:?string}|null
     */
    public function lockProfileForClaim(int $profileId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, claim_status FROM profiles WHERE id = :pid FOR UPDATE'
        );
        $stmt->execute(['pid' => $profileId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Blocca la riga della richiesta e ne restituisce lo stato corrente (rivalidazione sotto lock).
     * Va chiamato DENTRO una transazione: serializza due `approve`/`reject` sulla STESSA richiesta.
     */
    public function lockRequestStatus(int $requestId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT status FROM claim_requests WHERE id = :rid FOR UPDATE'
        );
        $stmt->execute(['rid' => $requestId]);
        $status = $stmt->fetchColumn();
        return $status === false ? null : (string) $status;
    }

    /**
     * Blocca la riga del richiedente (guard "hai già un profilo"). Va chiamato DENTRO una
     * transazione: serializza due `approve` sullo STESSO utente (richieste su profili diversi),
     * così solo il primo può diventare owner e il secondo rivalida `userHasProfile` sotto lock.
     * @return bool false se l'utente non esiste più (annulla l'approvazione).
     */
    public function lockUserExists(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE id = :uid FOR UPDATE');
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * True se l'utente possiede già un profilo — lettura BLOCCANTE (`FOR SHARE`), da chiamare
     * DENTRO la transazione di approve(). A differenza di ProfileRepository::userHasProfile()
     * (SELECT plain, per i percorsi di sola lettura non transazionali), la lettura bloccante NON
     * dipende dallo snapshot REPEATABLE READ: legge sempre l'ultima versione committata, così il
     * secondo approve dello stesso richiedente (su un altro profilo) vede l'ownership appena
     * assegnata dal primo e aborta — eliminando la dipendenza dal livello di isolamento.
     * `FOR SHARE` e non `FOR UPDATE`: qui serve solo OSSERVARE lo stato committato, non mutare
     * quelle righe; il lock esclusivo che serializza i due approve dello stesso utente è già preso
     * su `users` (lockUserExists) — questo è il minimo sufficiente a chiudere lo scenario C.
     */
    public function userHasProfileLockAware(int $userId): bool
    {
        // FOR SHARE richiede MySQL >= 8.0.1 (produzione confermata 8.4.6): è il PRIMO FOR SHARE
        // della codebase — il resto usa FOR UPDATE — quindi il requisito di versione è annotato qui
        // per non ri-sollevare il dubbio di portabilità in futuro (la vecchia sintassi LOCK IN SHARE
        // MODE resta valida ma è deprecata: non usarla).
        $stmt = $this->pdo->prepare('SELECT 1 FROM profiles WHERE user_id = :uid LIMIT 1 FOR SHARE');
        $stmt->execute(['uid' => $userId]);
        return (bool) $stmt->fetchColumn();
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

    /**
     * Marca la richiesta come decisa (approved/rejected) SOLO se ancora `pending`.
     * Il guard `AND status = 'pending'` chiude la finestra TOCTOU: un `reject` concorrente non può
     * sovrascrivere (né rinotificare) una richiesta appena approvata/rifiutata da un'altra
     * transazione. Da usare sotto lock (la riga è già bloccata da lockRequestStatus in approve/reject).
     * @return bool true se una riga è stata aggiornata (era pending); false se 0 righe toccate (conflitto).
     */
    public function markReviewed(int $id, string $status, ?string $note, int $reviewerId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE claim_requests SET status = :s, review_note = :n, reviewed_by_user_id = :r, reviewed_at = NOW()
             WHERE id = :id AND status = 'pending'"
        );
        $stmt->execute([
            's'  => $status,
            'n'  => ($note ?? '') !== '' ? $note : null,
            'r'  => $reviewerId,
            'id' => $id,
        ]);
        return $stmt->rowCount() > 0;
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
