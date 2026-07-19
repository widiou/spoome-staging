<?php

namespace Spoome\Domain\Auth;

use PDO;
use Spoome\Core\Db;
use Spoome\Support\Str;

/**
 * Verifica email: token grezzo inviato via email, nel DB solo l'hash. Monouso, scadenza 24h.
 */
final class EmailVerificationService
{
    private const TTL_HOURS = 24;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Crea un token per l'utente (invalida i precedenti non usati) e ritorna il grezzo. */
    public function issue(int $userId): string
    {
        $this->pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')
            ->execute(['uid' => $userId]);

        $raw = Str::token(32);
        $stmt = $this->pdo->prepare(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at)
             VALUES (:uid, :hash, (NOW() + INTERVAL :ttl HOUR))'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':hash', Str::hashToken($raw));
        $stmt->bindValue(':ttl', self::TTL_HOURS, PDO::PARAM_INT);
        $stmt->execute();
        return $raw;
    }

    /** Valida e consuma il token; ritorna lo user_id, oppure null se invalido/scaduto/usato. */
    public function consume(string $rawToken): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id FROM email_verifications
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['hash' => Str::hashToken($rawToken)]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $this->pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = :id')
            ->execute(['id' => $row['id']]);
        return (int) $row['user_id'];
    }

    /**
     * Pulizia (job di manutenzione): elimina i token già consumati o scaduti (inservibili per consume()).
     * Batch per non tenere lock lunghi.
     * @return int righe eliminate
     */
    public function purgeStale(int $batch = 5000): int
    {
        $batch = max(1, min($batch, 50000));
        $total = 0;
        do {
            $stmt = $this->pdo->prepare(
                'DELETE FROM email_verifications WHERE used_at IS NOT NULL OR expires_at < NOW() LIMIT :lim'
            );
            $stmt->bindValue(':lim', $batch, PDO::PARAM_INT);
            $stmt->execute();
            $n = $stmt->rowCount();
            $total += $n;
        } while ($n === $batch);
        return $total;
    }
}
