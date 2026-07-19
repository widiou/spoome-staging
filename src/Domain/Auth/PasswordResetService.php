<?php

namespace Spoome\Domain\Auth;

use PDO;
use Spoome\Core\Db;
use Spoome\Support\Str;

/**
 * Reset password: token grezzo via email, nel DB solo l'hash. Monouso, scadenza 1h.
 */
final class PasswordResetService
{
    private const TTL_HOURS = 1;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Crea un token per l'utente (invalida i precedenti) e ritorna il grezzo. */
    public function issue(int $userId): string
    {
        $this->pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :uid AND used_at IS NULL')
            ->execute(['uid' => $userId]);

        $raw = Str::token(32);
        $stmt = $this->pdo->prepare(
            'INSERT INTO password_resets (user_id, token_hash, expires_at)
             VALUES (:uid, :hash, (NOW() + INTERVAL :ttl HOUR))'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':hash', Str::hashToken($raw));
        $stmt->bindValue(':ttl', self::TTL_HOURS, PDO::PARAM_INT);
        $stmt->execute();
        return $raw;
    }

    /** Ritorna lo user_id se il token è valido (non consuma). */
    public function resolve(string $rawToken): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT user_id FROM password_resets
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['hash' => Str::hashToken($rawToken)]);
        $id = $stmt->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /**
     * Consuma ATOMICAMENTE il token e ritorna lo user_id, oppure null se non valido/già usato.
     * La UPDATE con WHERE used_at IS NULL è la "claim": solo una richiesta concorrente ottiene rowCount()===1.
     */
    public function resolveAndConsume(string $rawToken): ?int
    {
        $hash = Str::hashToken($rawToken);
        $claim = $this->pdo->prepare(
            'UPDATE password_resets SET used_at = NOW()
             WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()'
        );
        $claim->execute(['hash' => $hash]);
        if ($claim->rowCount() !== 1) {
            return null;
        }
        $sel = $this->pdo->prepare('SELECT user_id FROM password_resets WHERE token_hash = :hash LIMIT 1');
        $sel->execute(['hash' => $hash]);
        $id = $sel->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    /** Marca il token come usato (dopo l'effettivo cambio password). */
    public function consume(string $rawToken): void
    {
        $this->pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE token_hash = :hash AND used_at IS NULL')
            ->execute(['hash' => Str::hashToken($rawToken)]);
    }

    /**
     * Pulizia (job di manutenzione): elimina i token già consumati o scaduti (inservibili per resolve()).
     * Batch per non tenere lock lunghi.
     * @return int righe eliminate
     */
    public function purgeStale(int $batch = 5000): int
    {
        $batch = max(1, min($batch, 50000));
        $total = 0;
        do {
            $stmt = $this->pdo->prepare(
                'DELETE FROM password_resets WHERE used_at IS NOT NULL OR expires_at < NOW() LIMIT :lim'
            );
            $stmt->bindValue(':lim', $batch, PDO::PARAM_INT);
            $stmt->execute();
            $n = $stmt->rowCount();
            $total += $n;
        } while ($n === $batch);
        return $total;
    }
}
