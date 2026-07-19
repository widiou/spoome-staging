<?php

namespace Spoome\Domain\Connections;

use PDO;
use Spoome\Core\Db;

/**
 * Accesso alla tabella `connections` (grafo simmetrico con stato). Le query considerano
 * sempre entrambe le direzioni della coppia (requester/addressee).
 */
final class ConnectionRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Riga tra a e b in qualunque verso, o null. Campi: requester_id, addressee_id, status. */
    public function findBetween(int $a, int $b): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT requester_id, addressee_id, status FROM connections
             WHERE (requester_id = :a1 AND addressee_id = :b1) OR (requester_id = :b2 AND addressee_id = :a2)
             LIMIT 1'
        );
        $stmt->execute(['a1' => $a, 'b1' => $b, 'b2' => $b, 'a2' => $a]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** True se a e b sono connessi (accepted), in qualunque verso. */
    public function areConnected(int $a, int $b): bool
    {
        $row = $this->findBetween($a, $b);
        return $row !== null && $row['status'] === 'accepted';
    }

    public function insertPending(int $requesterId, int $addresseeId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO connections (requester_id, addressee_id, status) VALUES (:r, :a, \'pending\')'
        );
        $stmt->execute(['r' => $requesterId, 'a' => $addresseeId]);
    }

    /** Accetta la richiesta pending requester→addressee. True se qualcosa è stato accettato. */
    public function accept(int $requesterId, int $addresseeId): bool
    {
        // Atomico: transizione a 'accepted' + contatore nella STESSA transazione (no drift).
        $accepted = Db::transaction($this->pdo, function (PDO $pdo) use ($requesterId, $addresseeId): bool {
            $stmt = $pdo->prepare(
                "UPDATE connections SET status = 'accepted', responded_at = NOW()
                 WHERE requester_id = :r AND addressee_id = :a AND status = 'pending'"
            );
            $stmt->execute(['r' => $requesterId, 'a' => $addresseeId]);
            if ($stmt->rowCount() !== 1) {
                return false;
            }
            // Contatore denormalizzato: connessione accettata → +1 a entrambi.
            $pdo->prepare('UPDATE profiles SET connections_count = connections_count + 1 WHERE id IN (:r, :a)')
                ->execute(['r' => $requesterId, 'a' => $addresseeId]);
            return true;
        });
        // Connessione accettata: entrambi entrano nel set-sorgente del feed dell'altro → invalida entrambe le cache.
        if ($accepted) {
            \Spoome\Domain\Feed\FeedRepository::forgetSources($requesterId);
            \Spoome\Domain\Feed\FeedRepository::forgetSources($addresseeId);
        }
        return $accepted;
    }

    /** Elimina qualunque riga (pending o accepted) tra a e b, in entrambi i versi. */
    public function deleteBetween(int $a, int $b): void
    {
        // Atomico: lettura-stato + DELETE + eventuale decremento nella STESSA transazione, così lo
        // stato letto e la riga eliminata sono coerenti anche sotto concorrenza.
        $wasAccepted = Db::transaction($this->pdo, function (PDO $pdo) use ($a, $b): bool {
            // Rilevo lo stato PRIMA di eliminare: solo la rimozione di una connessione ACCETTATA
            // decrementa i contatori (annullare/rifiutare una pending non li tocca).
            $sel = $pdo->prepare(
                'SELECT status FROM connections
                 WHERE (requester_id = :a1 AND addressee_id = :b1) OR (requester_id = :b2 AND addressee_id = :a2)
                 LIMIT 1'
            );
            $sel->execute(['a1' => $a, 'b1' => $b, 'b2' => $b, 'a2' => $a]);
            $status = $sel->fetchColumn();

            $stmt = $pdo->prepare(
                'DELETE FROM connections
                 WHERE (requester_id = :a1 AND addressee_id = :b1) OR (requester_id = :b2 AND addressee_id = :a2)'
            );
            $stmt->execute(['a1' => $a, 'b1' => $b, 'b2' => $b, 'a2' => $a]);
            if ($status === 'accepted') {
                $pdo->prepare('UPDATE profiles SET connections_count = GREATEST(0, connections_count - 1) WHERE id IN (:a, :b)')
                    ->execute(['a' => $a, 'b' => $b]);
                return true;
            }
            return false;
        });
        // Rimossa una connessione accettata: entrambi escono dal set-sorgente del feed dell'altro → invalida entrambe le cache.
        if ($wasAccepted) {
            \Spoome\Domain\Feed\FeedRepository::forgetSources($a);
            \Spoome\Domain\Feed\FeedRepository::forgetSources($b);
        }
    }

    /** Numero di connessioni accettate di un profilo. */
    public function connectionCount(int $profileId): int
    {
        $stmt = $this->pdo->prepare('SELECT connections_count FROM profiles WHERE id = :p');
        $stmt->execute(['p' => $profileId]);
        return (int) $stmt->fetchColumn();
    }

    /** Numero di richieste in entrata (pending verso $profileId). */
    public function incomingCount(int $profileId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM connections WHERE addressee_id = :p AND status = 'pending'"
        );
        $stmt->execute(['p' => $profileId]);
        return (int) $stmt->fetchColumn();
    }
}
