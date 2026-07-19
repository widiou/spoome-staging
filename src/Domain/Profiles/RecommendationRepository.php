<?php

namespace Spoome\Domain\Profiles;

use PDO;
use Spoome\Core\Db;

/**
 * Raccomandazioni (testimonial LinkedIn-style) tra profili connessi. Modello ad approvazione:
 * `pending` → `visible` (accettata dal destinatario) / `hidden` (rifiutata o nascosta).
 *
 * Ownership a livello SQL (difesa in profondità): le mutazioni del destinatario filtrano per
 * `recipient_profile_id`; una riga per coppia (UNIQUE) → la scrittura è un upsert.
 * EMULATE_PREPARES=false: ogni named placeholder è usato una sola volta per statement (:r1/:r2…).
 */
final class RecommendationRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * Crea o riscrive la raccomandazione della coppia (author→recipient). Riscrivere azzera lo stato a
     * `pending` (il destinatario deve riapprovare il nuovo testo) e resetta responded_at. Ritorna l'id.
     */
    public function upsert(int $authorProfileId, int $recipientProfileId, string $body, ?string $relationship): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO profile_recommendations (author_profile_id, recipient_profile_id, body, relationship)
             VALUES (:author, :recipient, :body, :rel)
             ON DUPLICATE KEY UPDATE
                body = VALUES(body),
                relationship = VALUES(relationship),
                status = \'pending\',
                responded_at = NULL,
                created_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'author'    => $authorProfileId,
            'recipient' => $recipientProfileId,
            'body'      => $body,
            'rel'       => $relationship,
        ]);
        // ON DUPLICATE KEY UPDATE: lastInsertId non è affidabile sull'update → rileggo l'id della coppia.
        $id = (int) $this->pdo->lastInsertId();
        if ($id > 0 && $stmt->rowCount() === 1) {
            return $id; // INSERT nuovo (rowCount=1)
        }
        return (int) ($this->findByPair($authorProfileId, $recipientProfileId)['id'] ?? 0);
    }

    /**
     * Transizione di stato voluta dal DESTINATARIO. Ownership al livello dati: il WHERE vincola sia
     * l'id sia il recipient_profile_id, quindi un non-destinatario non può mai mutare la riga.
     * @return bool true se la riga è stata effettivamente cambiata (1 riga).
     */
    public function setStatus(int $id, int $recipientProfileId, string $status): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE profile_recommendations
                SET status = :status, responded_at = NOW()
              WHERE id = :id AND recipient_profile_id = :recipient'
        );
        $stmt->execute([
            'status'    => $status,
            'id'        => $id,
            'recipient' => $recipientProfileId,
        ]);
        return $stmt->rowCount() === 1;
    }

    /** Riga grezza per id, o null. */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM profile_recommendations WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** Riga grezza per coppia (author, recipient), o null. */
    public function findByPair(int $authorProfileId, int $recipientProfileId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM profile_recommendations
              WHERE author_profile_id = :author AND recipient_profile_id = :recipient LIMIT 1'
        );
        $stmt->execute(['author' => $authorProfileId, 'recipient' => $recipientProfileId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Raccomandazioni VISIBILI ricevute da un profilo, con i dati dell'autore joinati (per la vista
     * pubblica del profilo). Più recenti (per data di approvazione) prima.
     * @return array<int,array<string,mixed>>
     */
    public function visibleFor(int $recipientProfileId): array
    {
        return $this->listWithAuthor($recipientProfileId, 'visible');
    }

    /**
     * Raccomandazioni in attesa ricevute da un profilo (per la gestione: accetta/nascondi).
     * @return array<int,array<string,mixed>>
     */
    public function pendingFor(int $recipientProfileId): array
    {
        return $this->listWithAuthor($recipientProfileId, 'pending');
    }

    /**
     * Elenco per stato, con l'autore joinato (display_name, handle, avatar). Un solo placeholder per
     * il recipient e uno per lo stato (nessun riuso).
     * @return array<int,array<string,mixed>>
     */
    private function listWithAuthor(int $recipientProfileId, string $status): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.author_profile_id, r.recipient_profile_id, r.body, r.relationship,
                    r.status, r.created_at, r.responded_at,
                    ap.display_name AS author_display_name,
                    ap.handle       AS author_handle,
                    am.disk_path    AS author_avatar_path
               FROM profile_recommendations r
               JOIN profiles ap      ON ap.id = r.author_profile_id
               LEFT JOIN media am     ON am.id = ap.avatar_media_id
              WHERE r.recipient_profile_id = :recipient AND r.status = :status
              ORDER BY r.responded_at DESC, r.created_at DESC, r.id DESC'
        );
        $stmt->execute(['recipient' => $recipientProfileId, 'status' => $status]);
        return $stmt->fetchAll();
    }

    /**
     * Raccomandazioni SCRITTE da un profilo (visibili o in attesa), con il nome del destinatario —
     * per "le raccomandazioni che ho scritto".
     * @return array<int,array<string,mixed>>
     */
    public function writtenBy(int $authorProfileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.author_profile_id, r.recipient_profile_id, r.body, r.relationship,
                    r.status, r.created_at, r.responded_at,
                    rp.display_name AS recipient_display_name,
                    rp.handle       AS recipient_handle,
                    rm.disk_path    AS recipient_avatar_path
               FROM profile_recommendations r
               JOIN profiles rp      ON rp.id = r.recipient_profile_id
               LEFT JOIN media rm     ON rm.id = rp.avatar_media_id
              WHERE r.author_profile_id = :author AND r.status IN (\'visible\', \'pending\')
              ORDER BY r.created_at DESC, r.id DESC'
        );
        $stmt->execute(['author' => $authorProfileId]);
        return $stmt->fetchAll();
    }
}
