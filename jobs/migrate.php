<?php
/**
 * Runner CLI delle migrazioni — sostituisce l'endpoint HTTP /__migrate (rimosso da config/routes.php:
 * eseguire DDL via una POST, anche protetta, era superficie di sicurezza inutile su un hosting con
 * accesso SSH). Da lanciare manualmente ad ogni release che introduce migrazioni nuove:
 *
 *   php jobs/migrate.php status   Elenca applicate/pendenti in database/migrations/. Sola lettura.
 *   php jobs/migrate.php up       Applica in ordine le migrazioni pendenti e le registra. Idempotente.
 *
 * Wrapper sottile attorno a Spoome\Core\Migrator (src/Core/Migrator.php): la logica di scansione
 * (glob su database/migrations/) e di applicazione/registrazione (tabella `migrations`) resta lì,
 * non è duplicata qui — questo file usa solo l'API pubblica del Migrator.
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

use Spoome\Core\Db;
use Spoome\Core\Migrator;

/** Usage su STDERR, per gli invocatori (shell/cron) che vogliono distinguere errori d'uso dall'output. */
function migrate_usage(): void
{
    fwrite(STDERR, "Uso: php jobs/migrate.php <status|up>\n");
}

$args = array_slice($argv, 1);
if (count($args) !== 1 || !in_array($args[0], ['status', 'up'], true)) {
    migrate_usage();
    exit(1);
}
$command = $args[0];

$migrationsDir = $root . '/database/migrations';
$pdo           = Db::connection();
$migrator      = new Migrator($pdo, $migrationsDir);

if ($command === 'status') {
    // pending(): API pubblica del Migrator — [path => nome] delle migrazioni non ancora applicate.
    $pending      = $migrator->pending();
    $pendingNames = array_values($pending);

    // Il Migrator non espone un metodo pubblico per l'elenco applicate (solo un applied() privato):
    // per mostrare lo stato completo leggiamo direttamente la tabella `migrations` (la stessa che il
    // Migrator crea/aggiorna), senza duplicare la logica di scansione/registrazione.
    $stmt         = $pdo->query('SELECT migration FROM migrations ORDER BY migration');
    $appliedNames = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];

    $all = array_unique(array_merge($appliedNames, $pendingNames));
    sort($all);

    if ($all === []) {
        fwrite(STDOUT, "Nessuna migrazione trovata in database/migrations/\n");
        exit(0);
    }

    foreach ($all as $name) {
        $status = in_array($name, $pendingNames, true) ? 'PENDING  ' : 'APPLICATA';
        fwrite(STDOUT, sprintf("%s  %s\n", $status, $name));
    }
    fwrite(STDOUT, sprintf("\n%d applicate, %d pendenti\n", count($appliedNames), count($pendingNames)));
    exit(0);
}

// $command === 'up'. Controlliamo le pendenti PRIMA di applicare: evita l'accoppiamento alla stringa
// esatta del sentinel di Migrator::migrate() e rende esplicito il "niente da fare".
if ($migrator->pending() === []) {
    fwrite(STDOUT, "nothing to do\n");
    exit(0);
}

// migrate() applica in ordine le pendenti e le registra, fermandosi al primo errore (log con prefisso FAIL:).
$log = $migrator->migrate();

$failed = false;
foreach ($log as $line) {
    fwrite(STDOUT, $line . "\n");
    if (str_starts_with($line, 'FAIL:')) {
        $failed = true;
    }
}

exit($failed ? 1 : 0);
