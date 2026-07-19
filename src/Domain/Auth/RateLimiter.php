<?php

namespace Spoome\Domain\Auth;

use PDO;
use Spoome\Core\Db;

/**
 * Throttling anti-abuso basato sulla tabella `login_attempts`.
 * - Login: il blocco è deciso per IP (l'IP dell'attaccante), NON per email: così un attaccante
 *   non può lockare l'account di una vittima fallendo login sulla sua email (DoS mirato).
 * - Azioni sensibili (register/forgot/reset): throttle generico per chiave (IP o email).
 */
final class RateLimiter
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** Registra un tentativo (identifier = email o chiave logica). */
    public function record(string $identifier, string $ip, bool $successful): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (identifier, ip, successful) VALUES (:id, :ip, :ok)'
        );
        $stmt->execute(['id' => mb_substr($identifier, 0, 190), 'ip' => $ip, 'ok' => $successful ? 1 : 0]);
    }

    /**
     * Tentativi di LOGIN falliti recenti DA QUESTO IP (base della decisione di blocco login).
     * Esclude i "colpi" del throttle generico (identifier con prefisso "xxx:") che condividono la tabella:
     * gli identifier di login sono email (mai contengono ':'), quindi il filtro li isola in modo affidabile.
     */
    public function tooManyByIp(string $ip, int $maxAttempts = 5, int $withinMinutes = 15): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE successful = 0 AND ip = :ip AND identifier NOT LIKE '%:%'
               AND attempted_at > (NOW() - INTERVAL :mins MINUTE)"
        );
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':mins', $withinMinutes, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn() >= $maxAttempts;
    }

    /**
     * Registra un "colpo" su una chiave logica (es. "pwf:ip:1.2.3.4"). L'IP reale NON viene salvato
     * (sentinella '-') per non inquinare il contatore dei login falliti per IP.
     */
    public function hit(string $key, string $ip): void
    {
        $this->record($key, '-', false);
    }

    /** True se la chiave ha superato la soglia nella finestra (throttle generico). */
    public function tooManyByKey(string $key, int $maxAttempts, int $withinMinutes): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE identifier = :key AND attempted_at > (NOW() - INTERVAL :mins MINUTE)'
        );
        $stmt->bindValue(':key', mb_substr($key, 0, 190));
        $stmt->bindValue(':mins', $withinMinutes, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn() >= $maxAttempts;
    }

    /**
     * Pulizia (da chiamare da un job): rimuove i tentativi più vecchi di N ore.
     * Cancella a batch (LIMIT) per non tenere lock lunghi su una tabella che cresce illimitata.
     * @return int righe eliminate
     */
    public function purgeOlderThan(int $hours = 24, int $batch = 5000): int
    {
        $hours = max(1, $hours);
        $batch = max(1, min($batch, 50000));
        $total = 0;
        do {
            $stmt = $this->pdo->prepare(
                'DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL :h HOUR) LIMIT :lim'
            );
            $stmt->bindValue(':h', $hours, PDO::PARAM_INT);
            $stmt->bindValue(':lim', $batch, PDO::PARAM_INT);
            $stmt->execute();
            $n = $stmt->rowCount();
            $total += $n;
        } while ($n === $batch);
        return $total;
    }
}
