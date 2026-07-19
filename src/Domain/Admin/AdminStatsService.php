<?php

namespace Spoome\Domain\Admin;

use PDO;
use Spoome\Core\Db;

/**
 * Modulo statistiche avanzato dell'area admin. Serie temporali giornaliere (con riempimento
 * dei giorni a zero), KPI con confronto periodo-su-periodo, breakdown, funnel di attivazione
 * e classifiche. Query statiche o parametrizzate per soli interi (range in giorni): nessun
 * input testuale raggiunge l'SQL.
 */
final class AdminStatsService
{
    /** Intervalli selezionabili (giorni). */
    public const RANGES = [7, 30, 90];

    /** Tabelle ammesse per le serie temporali (whitelist: il nome non è mai input utente). */
    private const SERIES_TABLES = ['users', 'posts', 'messages', 'activities', 'follows'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Db::connection();
    }

    /** @return array<string,mixed> struttura completa per la pagina statistiche */
    public function overview(int $days): array
    {
        $days = in_array($days, self::RANGES, true) ? $days : 30;
        return [
            'range'        => $days,
            'ranges'       => self::RANGES,
            'kpis'         => $this->kpis($days),
            'series'       => $this->series($days),
            'breakdowns'   => $this->breakdowns(),
            'funnel'       => $this->funnel(),
            'leaderboards' => $this->leaderboards(),
        ];
    }

    /* --------------------------------------------------------------- KPI ---- */

    /** @return array<string,array{current:int,previous:int,delta:?float}> */
    private function kpis(int $days): array
    {
        return [
            'users'       => $this->kpi('users', $days),
            'posts'       => $this->kpi('posts', $days),
            'messages'    => $this->kpi('messages', $days),
            'connections' => $this->kpi('connections', $days, "status = 'accepted'"),
        ];
    }

    /** Conteggio periodo corrente vs precedente + variazione %. */
    private function kpi(string $table, int $days, string $extra = ''): array
    {
        if (!in_array($table, ['users', 'posts', 'messages', 'connections'], true)) {
            return ['current' => 0, 'previous' => 0, 'delta' => null];
        }
        $and = $extra !== '' ? " AND {$extra}" : '';
        $cur = $this->count("SELECT COUNT(*) FROM {$table} WHERE created_at >= (NOW() - INTERVAL :d DAY){$and}", $days);
        $prev = $this->count(
            "SELECT COUNT(*) FROM {$table}
             WHERE created_at >= (NOW() - INTERVAL :d2 DAY) AND created_at < (NOW() - INTERVAL :d DAY){$and}",
            $days,
            $days * 2
        );
        $delta = $prev > 0 ? round(($cur - $prev) / $prev * 100, 1) : ($cur > 0 ? null : 0.0);
        return ['current' => $cur, 'previous' => $prev, 'delta' => $delta];
    }

    private function count(string $sql, int $d, ?int $d2 = null): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':d', $d, PDO::PARAM_INT);
        if ($d2 !== null) {
            $stmt->bindValue(':d2', $d2, PDO::PARAM_INT);
        }
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /* ------------------------------------------------------------ SERIE ---- */

    /**
     * Serie temporali giornaliere allineate sullo stesso asse date (giorni a zero inclusi).
     * @return array{labels:array<int,string>, series:array<string,array<int,int>>}
     */
    private function series(int $days): array
    {
        // Asse date: da (oggi - days + 1) a oggi.
        $labels = [];
        $index  = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} day"));
            $labels[] = $day;
            $index[$day] = count($labels) - 1;
        }

        $out = [];
        foreach (['users', 'posts', 'messages'] as $table) {
            $row = array_fill(0, $days, 0);
            foreach ($this->dailyCounts($table, $days) as $d => $c) {
                if (isset($index[$d])) {
                    $row[$index[$d]] = $c;
                }
            }
            $out[$table] = $row;
        }

        return ['labels' => $labels, 'series' => $out];
    }

    /** @return array<string,int> mappa 'Y-m-d' => conteggio */
    private function dailyCounts(string $table, int $days): array
    {
        if (!in_array($table, self::SERIES_TABLES, true)) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            "SELECT DATE(created_at) AS d, COUNT(*) AS c FROM {$table}
             WHERE created_at >= (CURDATE() - INTERVAL :d DAY) GROUP BY DATE(created_at)"
        );
        $stmt->bindValue(':d', $days, PDO::PARAM_INT);
        $stmt->execute();
        $map = [];
        foreach ($stmt->fetchAll() as $r) {
            $map[(string) $r['d']] = (int) $r['c'];
        }
        return $map;
    }

    /* -------------------------------------------------------- BREAKDOWN ---- */

    private function breakdowns(): array
    {
        return [
            'users_by_status' => $this->pairs(
                "SELECT status AS k, COUNT(*) AS c FROM users GROUP BY status ORDER BY c DESC"
            ),
            'profiles_by_type' => $this->pairs(
                "SELECT pt.label AS k, COUNT(p.id) AS c FROM profiles p
                 JOIN profile_types pt ON pt.id = p.profile_type_id GROUP BY pt.id ORDER BY c DESC"
            ),
            'profiles_by_sport' => $this->pairs(
                "SELECT s.name AS k, COUNT(p.id) AS c FROM profiles p
                 JOIN sports s ON s.id = p.sport_id GROUP BY s.id ORDER BY c DESC LIMIT 8"
            ),
        ];
    }

    /** @return array<int,array{label:string,count:int}> */
    private function pairs(string $sql): array
    {
        return array_map(
            static fn ($r) => ['label' => (string) $r['k'], 'count' => (int) $r['c']],
            $this->pdo->query($sql)->fetchAll()
        );
    }

    /* ----------------------------------------------------------- FUNNEL ---- */

    /**
     * Funnel di attivazione: iscritti → email verificata → profilo creato → primo post → connessi.
     * @return array<int,array{label:string,count:int}>
     */
    private function funnel(): array
    {
        $registered = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $verified   = (int) $this->pdo->query('SELECT COUNT(*) FROM users WHERE email_verified_at IS NOT NULL')->fetchColumn();
        $withProfile = (int) $this->pdo->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
        $posted = (int) $this->pdo->query('SELECT COUNT(DISTINCT profile_id) FROM posts')->fetchColumn();
        $connected = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT requester_id AS pid FROM connections WHERE status='accepted'
                UNION SELECT addressee_id FROM connections WHERE status='accepted'
             ) t"
        )->fetchColumn();

        return [
            ['label' => 'funnel.registered',   'count' => $registered],
            ['label' => 'funnel.verified',     'count' => $verified],
            ['label' => 'funnel.with_profile', 'count' => $withProfile],
            ['label' => 'funnel.posted',       'count' => $posted],
            ['label' => 'funnel.connected',    'count' => $connected],
        ];
    }

    /* ----------------------------------------------------- LEADERBOARDS ---- */

    private function leaderboards(): array
    {
        return [
            'top_followers' => $this->pdo->query(
                "SELECT p.display_name, p.handle, COUNT(f.id) AS c
                 FROM profiles p JOIN follows f ON f.followee_id = p.id
                 GROUP BY p.id ORDER BY c DESC LIMIT 6"
            )->fetchAll(),
            'top_posters' => $this->pdo->query(
                "SELECT p.display_name, p.handle, COUNT(po.id) AS c
                 FROM profiles p JOIN posts po ON po.profile_id = p.id
                 GROUP BY p.id ORDER BY c DESC LIMIT 6"
            )->fetchAll(),
        ];
    }
}
