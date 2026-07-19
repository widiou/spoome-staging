<?php

namespace Spoome\Domain\Admin;

use PDO;
use Spoome\Core\Db;
use Spoome\Core\Pagination;

/**
 * Lettura dei log applicativi (`app_logs`) per l'area admin. Due viste:
 * - "raggruppata" per fingerprint: gli errori ricorrenti in cima (il segnale che conta);
 * - "dettaglio" di un singolo fingerprint: le occorrenze nel tempo.
 * Filtri per livello/canale/testo, tutti parametrizzati; il nome tabella non è mai input utente.
 */
final class AdminLogService
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /**
     * @param array{level?:string,channel?:string,q?:string} $filters
     * @return array{items:array<int,array<string,mixed>>, total:int}
     */
    public function grouped(array $filters, int $page = 1, int $perPage = 30): array
    {
        [$where, $bind] = $this->buildFilters($filters);
        $offset = Pagination::of($page, $perPage)->offset();

        $countStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM (SELECT 1 FROM app_logs {$where} GROUP BY fingerprint) t"
        );
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        $sql = "SELECT fingerprint,
                       MAX(level) AS level,
                       MAX(channel) AS channel,
                       SUBSTRING_INDEX(GROUP_CONCAT(message ORDER BY id DESC SEPARATOR 0x1e), 0x1e, 1) AS message,
                       MAX(file) AS file, MAX(line) AS line,
                       COUNT(*) AS occurrences,
                       MAX(created_at) AS last_seen,
                       MIN(created_at) AS first_seen
                FROM app_logs {$where}
                GROUP BY fingerprint
                ORDER BY last_seen DESC
                LIMIT :lim OFFSET :off";
        $stmt = $this->pdo->prepare($sql);
        foreach ($bind as $k => $v) {
            $stmt->bindValue(':' . $k, $v);
        }
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /** Occorrenze di un fingerprint specifico, più recenti prima. */
    public function occurrences(string $fingerprint, int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, level, channel, message, context, file, line, request_id, user_id, ip, method, path, created_at
             FROM app_logs WHERE fingerprint = :fp ORDER BY id DESC LIMIT :lim'
        );
        $stmt->bindValue(':fp', $fingerprint);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Canali distinti presenti (per il filtro a tendina). */
    public function channels(): array
    {
        return array_map(
            static fn($r) => (string) $r['channel'],
            $this->pdo->query('SELECT DISTINCT channel FROM app_logs ORDER BY channel')->fetchAll()
        );
    }

    /** @return array<string,int> conteggio per livello nelle ultime 24h (badge sintetico). */
    public function levelCounts24h(): array
    {
        $rows = $this->pdo->query(
            "SELECT level, COUNT(*) AS c FROM app_logs
             WHERE created_at > (NOW() - INTERVAL 24 HOUR) GROUP BY level"
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['level']] = (int) $r['c'];
        }
        return $out;
    }

    /**
     * @param array{level?:string,channel?:string,q?:string} $filters
     * @return array{0:string,1:array<string,mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $clauses = [];
        $bind = [];
        if (in_array($filters['level'] ?? '', self::LEVELS, true)) {
            $clauses[] = 'level = :level';
            $bind['level'] = $filters['level'];
        }
        $channel = trim((string) ($filters['channel'] ?? ''));
        if ($channel !== '') {
            $clauses[] = 'channel = :channel';
            $bind['channel'] = $channel;
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $clauses[] = 'message LIKE :q';
            $bind['q'] = '%' . $q . '%';
        }
        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        return [$where, $bind];
    }
}
