<?php

namespace Spoome\Domain\Admin;

use PDO;
use Spoome\Core\Db;

/**
 * Audit trail dell'area admin (append-only). Registra ogni azione sensibile e la rilegge
 * per la dashboard/sicurezza. Nessun metodo di UPDATE/DELETE: è per policy immutabile.
 */
final class AuditRepository
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * @param array<string,mixed> $meta contesto denormalizzato (nome bersaglio, valori prima/dopo…)
     */
    public function record(
        int $adminUserId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $meta = [],
        string $ip = 'unknown'
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_audit_log (admin_user_id, action, target_type, target_id, meta, ip)
             VALUES (:a, :act, :tt, :ti, :m, :ip)'
        );
        $stmt->execute([
            'a'   => $adminUserId,
            'act' => mb_substr($action, 0, 60),
            'tt'  => $targetType,
            'ti'  => $targetId,
            'm'   => $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            'ip'  => mb_substr($ip, 0, 45),
        ]);
    }

    /** Ultime azioni, con l'email dell'admin che le ha compiute. */
    public function recent(int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.action, a.target_type, a.target_id, a.meta, a.ip, a.created_at,
                    u.email AS admin_email
             FROM admin_audit_log a
             JOIN users u ON u.id = a.admin_user_id
             ORDER BY a.id DESC LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function total(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM admin_audit_log')->fetchColumn();
    }
}
