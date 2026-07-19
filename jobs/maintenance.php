<?php
/**
 * Job CLI di manutenzione — da eseguire via CRON su SiteGround (hosting condiviso, no daemon).
 *
 * Cosa fa (idempotente, sicuro a ri-esecuzione):
 *  1) PURGE delle tabelle a crescita illimitata (GDPR + storage): login_attempts (>30g),
 *     auth_tokens (scaduti/revocati), email_verifications + password_resets (usati/scaduti),
 *     app_logs (>90g), user_events (>30g), link_previews (cache scaduta). DELETE a batch.
 *  2) RECONCILE dei contatori denormalizzati dalla source-of-truth (difesa in profondità, non distruttiva).
 *
 * Cron consigliato (una volta al giorno, di notte — poco traffico):
 *
 *   17 3 * * *  php /home/USER/public_html/beta/jobs/maintenance.php >> /home/USER/logs/maintenance.log 2>&1
 *
 * Logga su stdout un riepilogo (righe eliminate per tabella + contatori riallineati) ed exit 0.
 */

declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
require $root . '/src/autoload.php';
require $root . '/config/env.php';
require $root . '/src/Core/helpers.php';

date_default_timezone_set('Europe/Rome');

use Spoome\Domain\Maintenance\MaintenanceService;

$r = (new MaintenanceService())->run();

$purged = $r['purged'];
fwrite(STDOUT, sprintf(
    "[%s] maintenance purge: %d righe totali eliminate\n",
    date('c'),
    array_sum($purged)
));
foreach ($purged as $table => $n) {
    fwrite(STDOUT, sprintf("  purge %-20s %d\n", $table, $n));
}

$reconciled = $r['reconciled'];
fwrite(STDOUT, sprintf(
    "[%s] maintenance reconcile: %d contatori riallineati (drift corretto)\n",
    date('c'),
    array_sum($reconciled)
));
foreach ($reconciled as $counter => $n) {
    if ($n > 0) {
        fwrite(STDOUT, sprintf("  reconcile %-28s %d\n", $counter, $n));
    }
}

exit(0);
