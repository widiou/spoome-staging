<?php

namespace Spoome\Core;

use RuntimeException;

/**
 * Render di template PHP server-side con layout. L'escaping è responsabilità dei template
 * tramite l'helper globale e() — mai echo grezzo di dati utente.
 */
final class View
{
    private static ?string $base = null;

    private static function base(): string
    {
        return self::$base ??= \dirname(__DIR__, 2) . '/views';
    }

    /**
     * Rende la pagina views/pages/{$page}.php dentro il layout views/layouts/{$layout}.php.
     * @param array<string,mixed> $data
     */
    public static function render(string $page, array $data = [], string $layout = 'base'): void
    {
        $content = self::capture(self::base() . '/pages/' . $page . '.php', $data);
        $data['content'] = $content;
        echo self::capture(self::base() . '/layouts/' . $layout . '.php', $data);
    }

    /** Rende un partial e ne ritorna l'HTML (per composizione). */
    public static function partial(string $name, array $data = []): string
    {
        return self::capture(self::base() . '/partials/' . $name . '.php', $data);
    }

    private static function capture(string $file, array $data): string
    {
        if (!\is_file($file)) {
            throw new RuntimeException("Template non trovato: {$file}");
        }
        \extract($data, EXTR_SKIP);
        \ob_start();
        require $file;
        return (string) \ob_get_clean();
    }
}
