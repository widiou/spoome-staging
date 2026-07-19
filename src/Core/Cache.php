<?php

namespace Spoome\Core;

/**
 * Cache minimale a due livelli, senza dipendenze:
 * - se APCu è disponibile → cache condivisa tra richieste con TTL (dati quasi-statici);
 * - altrimenti → memoizzazione per-richiesta (evita comunque query ripetute nella stessa richiesta).
 *
 * Pensata per dati che cambiano di rado (profile_types, sports). NON usare per dati per-utente.
 */
final class Cache
{
    /** @var array<string,mixed> memo per-richiesta (fallback quando APCu non c'è) */
    private static array $memo = [];

    private static ?bool $apcu = null;

    private static function apcuOn(): bool
    {
        if (self::$apcu === null) {
            self::$apcu = function_exists('apcu_enabled') && apcu_enabled();
        }
        return self::$apcu;
    }

    /**
     * Ritorna il valore in cache o lo calcola con $producer e lo memorizza.
     * @template T
     * @param callable():T $producer
     * @return T
     */
    public static function remember(string $key, int $ttl, callable $producer): mixed
    {
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        if (self::apcuOn()) {
            $ok = false;
            $val = apcu_fetch($key, $ok);
            if ($ok) {
                return self::$memo[$key] = $val;
            }
            $val = $producer();
            apcu_store($key, $val, $ttl);
            return self::$memo[$key] = $val;
        }

        return self::$memo[$key] = $producer();
    }

    /** Invalida una chiave (es. dopo un cambio ai dati di riferimento). */
    public static function forget(string $key): void
    {
        unset(self::$memo[$key]);
        if (self::apcuOn()) {
            apcu_delete($key);
        }
    }
}
