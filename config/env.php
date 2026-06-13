<?php
/**
 * Loader minimale di variabili d'ambiente da file .env (nessuna dipendenza esterna).
 * Idempotente. Popola $_ENV e getenv(). Espone l'helper env().
 *
 * All'inclusione carica automaticamente il .env nella root del progetto
 * (la cartella che contiene questo config/, ossia dirname(__DIR__)).
 */

if (!function_exists('spoome_env_load')) {

    function spoome_env_load(string $file): void
    {
        static $loaded = [];
        if (isset($loaded[$file])) {
            return;
        }
        $loaded[$file] = true;

        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);
            if ($key === '') {
                continue;
            }
            // Rimuove eventuali virgolette di wrapping
            $len = strlen($value);
            if ($len >= 2
                && ($value[0] === '"' || $value[0] === "'")
                && $value[$len - 1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return $default;
        }
        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

// Carica automaticamente il .env della root del progetto.
spoome_env_load(dirname(__DIR__) . '/.env');

// Registra l'autoloader PSR-4 per il namespace Spoome\ (src/).
require_once dirname(__DIR__) . '/src/autoload.php';
