<?php

namespace Spoome\Services;

/**
 * Cache su file (JSON) con scrittura atomica e TTL.
 * Estratta da helpers/gFunctions.php (loadCache/saveCache), che ora delegano qui.
 */
final class Cache
{
    /** Ritorna i dati cache se presenti e non scaduti, altrimenti null. */
    public static function get(string $file, int $ttlHours): ?array
    {
        if (!\is_file($file)) {
            return null;
        }

        $content = \json_decode((string) \file_get_contents($file), true);
        if (!\is_array($content) || !isset($content['updated'], $content['data'])) {
            return null; // cache corrotta ⇒ ignora
        }

        $updated   = new \DateTime($content['updated']);
        $now       = new \DateTime();
        $diffHours = ($now->getTimestamp() - $updated->getTimestamp()) / 3600;

        return ($diffHours < $ttlHours) ? $content['data'] : null;
    }

    /** Scrive i dati in cache in modo atomico (tmp + rename). */
    public static function put(string $file, array $data): void
    {
        $dir = \dirname($file);
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0775, true);
        }

        $tmp = $file . '.tmp';
        \file_put_contents($tmp, \json_encode([
            'updated' => \date('Y-m-d H:i:s'),
            'data'    => $data,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        \rename($tmp, $file);
    }
}
