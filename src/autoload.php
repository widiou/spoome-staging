<?php
/**
 * Autoloader PSR-4 per il namespace "Spoome\" → cartella src/.
 * Niente Composer: si registra a mano (il server non ha Composer/SSH).
 * Idempotente: si registra una sola volta.
 */
(static function (): void {
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    spl_autoload_register(static function (string $class): void {
        $prefix  = 'Spoome\\';
        $baseDir = __DIR__ . '/';

        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;
        }
    });
})();
