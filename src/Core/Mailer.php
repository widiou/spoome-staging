<?php

namespace Spoome\Core;

/**
 * Invio email minimale. In staging/dev registra il messaggio (incluso il link) su
 * storage/logs/mail.log — così i flussi di verifica/reset sono testabili senza SMTP.
 * In produzione usa mail() (in futuro: SMTP dedicato).
 */
final class Mailer
{
    public static function send(string $to, string $subject, string $htmlBody): bool
    {
        $from = (string) Config::get('MAIL_FROM_ADDRESS', 'no-reply@spoome.it');
        $name = (string) Config::get('MAIL_FROM_NAME', 'Spoome');

        // Traccia sempre su log (senza dati sensibili oltre al necessario link/subject).
        self::logMail($to, $subject, $htmlBody);

        // In produzione tenta l'invio reale.
        if (Config::isProduction()) {
            $headers = implode("\r\n", [
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'From: ' . sprintf('%s <%s>', $name, $from),
            ]);
            return @mail($to, $subject, $htmlBody, $headers);
        }

        // In staging/dev consideriamo l'email "inviata" (link recuperabile dal log).
        return true;
    }

    private static function logMail(string $to, string $subject, string $body): void
    {
        $dir = \dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] TO=%s SUBJECT=%s\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            strip_tags($body),
            str_repeat('-', 60)
        );
        @file_put_contents($dir . '/mail.log', $line, FILE_APPEND | LOCK_EX);
    }
}
