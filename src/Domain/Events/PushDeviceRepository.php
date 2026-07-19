<?php

namespace Spoome\Domain\Events;

use PDO;
use Spoome\Core\Db;

/**
 * Registro dei device-token per il push nativo/web (scaffolding Phase 1: nessun invio ancora).
 * Upsert idempotente su UNIQUE(platform, token); ogni operazione è scopata all'utente corrente.
 */
final class PushDeviceRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * Registra (o rinfresca) un token per l'utente. Se lo stesso (platform, token) esisteva già
     * per un altro utente, la proprietà passa a $userId (device riassegnato dopo logout/login).
     *
     * Cap anti-bloat: se stiamo per aggiungere un NUOVO device per l'utente (token non ancora suo)
     * e questo supererebbe $maxPerUser, i device meno recenti (last_seen_at ASC) vengono potati prima
     * dell'insert, così la tabella non cresce illimitatamente per-utente. Il refresh di un token già
     * dell'utente non incrementa il conteggio e non innesca potatura.
     * @return array<string,mixed> riga del device
     */
    public function upsert(int $userId, string $platform, string $token, int $maxPerUser = 20): array
    {
        if ($maxPerUser > 0 && !$this->ownsToken($userId, $platform, $token)) {
            // Lascia spazio per l'inserimento in arrivo: pota fino a (max - 1).
            $this->pruneToCapacity($userId, $maxPerUser - 1);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO push_devices (user_id, platform, token) VALUES (:u, :p, :t)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), last_seen_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute(['u' => $userId, 'p' => $platform, 't' => $token]);

        $sel = $this->pdo->prepare(
            'SELECT id, user_id, platform, token, created_at, last_seen_at
             FROM push_devices WHERE platform = :p AND token = :t LIMIT 1'
        );
        $sel->execute(['p' => $platform, 't' => $token]);
        return $sel->fetch() ?: [];
    }

    /** Rimuove un token dell'utente corrente (idempotente). True se una riga è stata rimossa. */
    public function delete(int $userId, string $token): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM push_devices WHERE user_id = :u AND token = :t');
        $stmt->execute(['u' => $userId, 't' => $token]);
        return $stmt->rowCount() > 0;
    }

    /** Vero se (platform, token) è già registrato PER QUESTO utente (refresh, non un nuovo device). */
    private function ownsToken(int $userId, string $platform, string $token): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM push_devices WHERE user_id = :u AND platform = :p AND token = :t LIMIT 1'
        );
        $stmt->execute(['u' => $userId, 'p' => $platform, 't' => $token]);
        return (bool) $stmt->fetchColumn();
    }

    /** Pota i device dell'utente finché non ne restano al più $keep (elimina i meno recenti). */
    private function pruneToCapacity(int $userId, int $keep): void
    {
        $keep = max(0, $keep);
        $cnt  = $this->pdo->prepare('SELECT COUNT(*) FROM push_devices WHERE user_id = :u');
        $cnt->execute(['u' => $userId]);
        $count = (int) $cnt->fetchColumn();
        if ($count <= $keep) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'DELETE FROM push_devices WHERE user_id = :u ORDER BY last_seen_at ASC, id ASC LIMIT :lim'
        );
        $stmt->bindValue(':u', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $count - $keep, PDO::PARAM_INT);
        $stmt->execute();
    }
}
