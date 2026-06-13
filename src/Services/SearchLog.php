<?php

namespace Spoome\Services;

/**
 * Registrazione delle ricerche nella tabella search_log.
 * Estratto da Athlete::insertInLog (che ora delega qui). Ignora ricerche vuote,
 * bot e alcuni IP.
 */
final class SearchLog
{
    private const BLOCKED_IPS = ['188.8.28.17', '135.181.177.119'];

    public static function record(string $query): void
    {
        if ($query === '') {
            return;
        }

        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'UNKNOWN';
        if (\str_contains(\strtolower($userAgent), 'bot')) {
            return;
        }

        $userIp = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';
        if (\in_array($userIp, self::BLOCKED_IPS, true)) {
            return;
        }

        $referrer    = $_SERVER['HTTP_REFERER'] ?? null;
        $referrerUrl = $referrer ?: ($_SERVER['REQUEST_URI'] ?? 'UNKNOWN');

        $pdo  = \Database::getInstance()->getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO search_log (query, user_ip, user_agent, referrer, referrer_url, search_time, user_id)
             VALUES (:query, :user_ip, :user_agent, :referrer, :referrer_url, :search_time, :user_id)'
        );
        $stmt->execute([
            ':query'        => $query,
            ':user_ip'      => $userIp,
            ':user_agent'   => $userAgent,
            ':referrer'     => $referrer,
            ':referrer_url' => $referrerUrl,
            ':search_time'  => (new \DateTime())->format('Y-m-d H:i:s'),
            ':user_id'      => $_SESSION['user_id'] ?? null,
        ]);
    }
}
