<?php

namespace Spoome\Core;

use Throwable;

/**
 * Logging centralizzato e strutturato.
 * - Tutti i livelli → file JSONL storage/logs/app.log (un evento JSON per riga, con contesto di richiesta).
 * - error/warning → anche tabella `app_logs` (storico interrogabile + fingerprint per consolidare i ricorrenti).
 * Il contesto di richiesta (request_id, ip, method, path, user_id) è impostato una volta al boot.
 */
final class Logger
{
    private const MAX_BYTES = 5_242_880; // 5 MB
    private const DB_LEVELS = ['error', 'warning'];

    private static ?string $dir = null;
    /** @var array<string,mixed> contesto valido per l'intera richiesta */
    private static array $requestContext = [];
    private static bool $inDbWrite = false;

    /** Imposta il contesto di richiesta (chiamato dal front controller). */
    public static function init(array $context): void
    {
        self::$requestContext = $context;
    }

    public static function setUser(?int $userId): void
    {
        self::$requestContext['user_id'] = $userId;
    }

    public static function log(string $level, string $message, array $context = [], string $channel = 'app'): void
    {
        $file = isset($context['file']) ? (string) $context['file'] : null;
        $line = isset($context['line']) ? (int) $context['line'] : null;
        $exc  = isset($context['exception']) ? (string) $context['exception'] : null;

        $record = array_merge([
            'ts'      => date('c'),
            'level'   => $level,
            'channel' => $channel,
            'message' => $message,
        ], self::$requestContext, ['context' => $context]);

        self::writeFile($record);

        if (in_array($level, self::DB_LEVELS, true)) {
            self::writeDb($level, $channel, $message, $context, $file, $line, $exc);
        }
    }

    public static function error(string $m, array $c = [], string $ch = 'app'): void   { self::log('error', $m, $c, $ch); }
    public static function warning(string $m, array $c = [], string $ch = 'app'): void { self::log('warning', $m, $c, $ch); }
    public static function info(string $m, array $c = [], string $ch = 'app'): void    { self::log('info', $m, $c, $ch); }
    public static function debug(string $m, array $c = [], string $ch = 'app'): void   { self::log('debug', $m, $c, $ch); }

    /** Canale dedicato agli eventi di sicurezza (login falliti, accessi negati, ...). */
    public static function security(string $m, array $c = []): void { self::log('warning', $m, $c, 'security'); }

    /**
     * Pulizia (job di manutenzione): elimina dalla tabella `app_logs` gli eventi più vecchi di N giorni.
     * Lo storico completo resta comunque sui file JSONL. Batch (LIMIT) per non tenere lock lunghi.
     * @return int righe eliminate
     */
    public static function purge(int $days = 90, int $batch = 5000): int
    {
        $days  = max(1, $days);
        $batch = max(1, min($batch, 50000));
        $pdo   = Db::connection();
        $total = 0;
        do {
            $stmt = $pdo->prepare('DELETE FROM app_logs WHERE created_at < (NOW() - INTERVAL :d DAY) LIMIT :lim');
            $stmt->bindValue(':d', $days, \PDO::PARAM_INT);
            $stmt->bindValue(':lim', $batch, \PDO::PARAM_INT);
            $stmt->execute();
            $n = $stmt->rowCount();
            $total += $n;
        } while ($n === $batch);
        return $total;
    }

    private static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = \dirname(__DIR__, 2) . '/storage/logs';
            if (!is_dir(self::$dir)) {
                @mkdir(self::$dir, 0775, true);
            }
        }
        return self::$dir;
    }

    private static function writeFile(array $record): void
    {
        $file = self::dir() . '/app.log';
        if (is_file($file) && filesize($file) > self::MAX_BYTES) {
            @rename($file, $file . '.' . date('Ymd_His'));
        }
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function writeDb(string $level, string $channel, string $message, array $context, ?string $file, ?int $line, ?string $exc): void
    {
        if (self::$inDbWrite) {
            return; // evita ricorsione se il logging su DB fallisce
        }
        self::$inDbWrite = true;
        try {
            $ctx = self::$requestContext;
            $fingerprint = sha1($level . '|' . $channel . '|' . ($file !== null ? $file . ':' . $line : $message));

            $stmt = Db::connection()->prepare(
                'INSERT INTO app_logs (level, channel, message, context, fingerprint, exception_class, file, line, request_id, user_id, ip, method, path)
                 VALUES (:level, :channel, :message, :context, :fp, :exc, :file, :line, :rid, :uid, :ip, :method, :path)'
            );
            $stmt->execute([
                'level'   => $level,
                'channel' => $channel,
                'message' => mb_substr($message, 0, 1000),
                'context' => $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'fp'      => $fingerprint,
                'exc'     => $exc,
                'file'    => $file !== null ? mb_substr($file, 0, 255) : null,
                'line'    => $line,
                'rid'     => $ctx['request_id'] ?? null,
                'uid'     => $ctx['user_id'] ?? null,
                'ip'      => $ctx['ip'] ?? null,
                'method'  => $ctx['method'] ?? null,
                'path'    => isset($ctx['path']) ? mb_substr((string) $ctx['path'], 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            // Il logging non deve mai rompere la richiesta: fallback silenzioso (resta su file).
        } finally {
            self::$inDbWrite = false;
        }
    }
}
