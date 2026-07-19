<?php
/**
 * Job CLI di ingestione news — da eseguire via CRON su SiteGround.
 *
 * Cron consigliato (ogni 15 min): l'intervallo reale per-fonte (refresh_minutes) è rispettato dal service,
 * quindi eseguirlo spesso è innocuo: aggiorna solo le fonti effettivamente "dovute".
 *
 *   *\/15 * * * *  php /home/USER/public_html/beta/jobs/news_fetch.php >> /home/USER/logs/news.log 2>&1
 *
 * Fetch sicuro via SafeHttpFetcher (SSRF-guard). Fire-and-forget per fonte: un feed rotto non blocca gli altri.
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

use Spoome\Domain\News\NewsIngestionService;

$r = (new NewsIngestionService())->run();

fwrite(STDOUT, sprintf(
    "[%s] news ingest: %d fonti, %d nuovi articoli, %d errori\n",
    date('c'), $r['sources'], $r['added'], count($r['errors'])
));
foreach ($r['errors'] as $sid => $err) {
    fwrite(STDERR, "  source #$sid: $err\n");
}
exit(0);
