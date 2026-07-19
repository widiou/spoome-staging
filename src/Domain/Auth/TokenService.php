<?php

namespace Spoome\Domain\Auth;

use PDO;
use Spoome\Core\Config;
use Spoome\Core\Db;
use Spoome\Support\Str;

/**
 * Token Bearer per l'API (app native). Il token grezzo è restituito UNA sola volta;
 * nel DB si salva solo l'hash SHA-256. Scadenza + revoca + rotation del refresh.
 */
final class TokenService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * Emette una coppia access+refresh per l'utente.
     * @return array{access:string, refresh:string, expires_in:int}
     */
    public function issue(int $userId, ?string $device = null): array
    {
        $accessTtl  = (int) Config::get('ACCESS_TOKEN_TTL', 3600);
        $refreshTtl = (int) Config::get('REFRESH_TOKEN_TTL', 2592000);

        $access  = $this->store($userId, 'access', $accessTtl, $device);
        $refresh = $this->store($userId, 'refresh', $refreshTtl, $device);

        return ['access' => $access, 'refresh' => $refresh, 'expires_in' => $accessTtl];
    }

    private function store(int $userId, string $kind, int $ttl, ?string $device): string
    {
        $raw  = Str::token(32);
        $hash = Str::hashToken($raw);
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, token_hash, kind, device_label, expires_at)
             VALUES (:uid, :hash, :kind, :dev, (NOW() + INTERVAL :ttl SECOND))'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':hash', $hash);
        $stmt->bindValue(':kind', $kind);
        $stmt->bindValue(':dev', $device !== null ? mb_substr($device, 0, 190) : null);
        $stmt->bindValue(':ttl', $ttl, PDO::PARAM_INT);
        $stmt->execute();
        return $raw;
    }

    /** Valida un token grezzo del tipo indicato e ritorna lo user_id, oppure null. */
    public function resolve(string $rawToken, string $kind = 'access'): ?int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id FROM auth_tokens
             WHERE token_hash = :hash AND kind = :kind AND revoked_at IS NULL AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute(['hash' => Str::hashToken($rawToken), 'kind' => $kind]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $this->pdo->prepare('UPDATE auth_tokens SET last_used_at = NOW() WHERE id = :id')
            ->execute(['id' => $row['id']]);
        return (int) $row['user_id'];
    }

    /** Ruota il refresh token: valida quello vecchio, lo revoca ed emette una nuova coppia. */
    public function refresh(string $rawRefresh, ?string $device = null): ?array
    {
        $userId = $this->resolve($rawRefresh, 'refresh');
        if ($userId === null) {
            return null;
        }
        $this->revoke($rawRefresh);
        return $this->issue($userId, $device);
    }

    public function revoke(string $rawToken): void
    {
        $this->pdo->prepare('UPDATE auth_tokens SET revoked_at = NOW() WHERE token_hash = :hash AND revoked_at IS NULL')
            ->execute(['hash' => Str::hashToken($rawToken)]);
    }

    /** Revoca tutti i token di un utente (es. dopo reset password). */
    public function revokeAllForUser(int $userId): void
    {
        $this->pdo->prepare('UPDATE auth_tokens SET revoked_at = NOW() WHERE user_id = :uid AND revoked_at IS NULL')
            ->execute(['uid' => $userId]);
    }

    /**
     * Pulizia (job di manutenzione): elimina i token non più utilizzabili — scaduti o revocati.
     * Sono già inservibili per resolve() (che filtra revoked_at/expires_at), quindi la cancellazione
     * è puramente di igiene/GDPR. Cancella a batch per non tenere lock lunghi.
     * @return int righe eliminate
     */
    public function purgeExpired(int $batch = 5000): int
    {
        $batch = max(1, min($batch, 50000));
        $total = 0;
        do {
            $stmt = $this->pdo->prepare(
                'DELETE FROM auth_tokens WHERE expires_at < NOW() OR revoked_at IS NOT NULL LIMIT :lim'
            );
            $stmt->bindValue(':lim', $batch, PDO::PARAM_INT);
            $stmt->execute();
            $n = $stmt->rowCount();
            $total += $n;
        } while ($n === $batch);
        return $total;
    }
}
