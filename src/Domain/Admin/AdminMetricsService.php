<?php

namespace Spoome\Domain\Admin;

use PDO;
use Spoome\Core\Db;

/**
 * Aggrega le metriche della dashboard admin. Sole query statiche (nessun input utente):
 * conteggi utenti/profili/contenuti/grafo e stato di salute (errori recenti nei log).
 */
final class AdminMetricsService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** @return array<string,mixed> struttura consumata da views/pages/admin/dashboard.php */
    public function dashboard(): array
    {
        return [
            'users'    => $this->users(),
            'profiles' => $this->profiles(),
            'content'  => $this->content(),
            'graph'    => $this->graph(),
            'health'   => $this->health(),
            'recent_signups' => $this->recentSignups(),
            'recent_errors'  => $this->recentErrors(),
        ];
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }

    /** @return array<string,int> */
    private function users(): array
    {
        return [
            'total'     => $this->scalar('SELECT COUNT(*) FROM users'),
            'active'    => $this->scalar("SELECT COUNT(*) FROM users WHERE status = 'active'"),
            'pending'   => $this->scalar("SELECT COUNT(*) FROM users WHERE status = 'pending'"),
            'suspended' => $this->scalar("SELECT COUNT(*) FROM users WHERE status = 'suspended'"),
            'new_7d'    => $this->scalar('SELECT COUNT(*) FROM users WHERE created_at > (NOW() - INTERVAL 7 DAY)'),
            'staff'     => $this->scalar("SELECT COUNT(*) FROM users WHERE role IN ('admin','moderator')"),
        ];
    }

    /** @return array{total:int,by_type:array<int,array<string,mixed>>} */
    private function profiles(): array
    {
        $rows = $this->pdo->query(
            'SELECT pt.label AS label, COUNT(p.id) AS cnt
             FROM profiles p JOIN profile_types pt ON pt.id = p.profile_type_id
             GROUP BY pt.id, pt.label ORDER BY cnt DESC'
        )->fetchAll();

        return [
            'total'   => $this->scalar('SELECT COUNT(*) FROM profiles'),
            'by_type' => array_map(static fn($r) => [
                'label' => (string) $r['label'],
                'count' => (int) $r['cnt'],
            ], $rows),
        ];
    }

    /** @return array<string,int> */
    private function content(): array
    {
        return [
            'posts_total'      => $this->scalar('SELECT COUNT(*) FROM posts'),
            'posts_7d'         => $this->scalar('SELECT COUNT(*) FROM posts WHERE created_at > (NOW() - INTERVAL 7 DAY)'),
            'messages_total'   => $this->scalar('SELECT COUNT(*) FROM messages'),
            'messages_7d'      => $this->scalar('SELECT COUNT(*) FROM messages WHERE created_at > (NOW() - INTERVAL 7 DAY)'),
            'activities_total' => $this->scalar('SELECT COUNT(*) FROM activities'),
        ];
    }

    /** @return array<string,int> */
    private function graph(): array
    {
        return [
            'follows'             => $this->scalar('SELECT COUNT(*) FROM follows'),
            'connections'         => $this->scalar("SELECT COUNT(*) FROM connections WHERE status = 'accepted'"),
            'connections_pending' => $this->scalar("SELECT COUNT(*) FROM connections WHERE status = 'pending'"),
        ];
    }

    /** @return array<string,int> */
    private function health(): array
    {
        return [
            'errors_24h'   => $this->scalar("SELECT COUNT(*) FROM app_logs WHERE level IN ('error','critical','alert','emergency') AND created_at > (NOW() - INTERVAL 24 HOUR)"),
            'warnings_24h' => $this->scalar("SELECT COUNT(*) FROM app_logs WHERE level = 'warning' AND created_at > (NOW() - INTERVAL 24 HOUR)"),
        ];
    }

    private function recentSignups(): array
    {
        return $this->pdo->query(
            'SELECT id, email, role, status, created_at FROM users ORDER BY id DESC LIMIT 6'
        )->fetchAll();
    }

    private function recentErrors(): array
    {
        return $this->pdo->query(
            "SELECT message, level, path, created_at, fingerprint
             FROM app_logs WHERE level IN ('error','critical','alert','emergency')
             ORDER BY id DESC LIMIT 6"
        )->fetchAll();
    }
}
