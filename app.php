<?php
/**
 * Front controller MVC (strangler).
 * Accessibile come /app.php/<rotta> (PATH_INFO) oppure /app.php?route=<rotta>.
 * Convive col legacy: gestisce solo le rotte registrate qui, il resto resta
 * servito dai vecchi file. Da qui crescerà l'app MVC.
 */

chdir(__DIR__);

require_once __DIR__ . '/config/env.php';   // .env + autoloader PSR-4
require_once __DIR__ . '/db/Database.php';  // connessione (legacy, usata dai repository)
require_once __DIR__ . '/models/Athlete.php'; // entità legacy (idratazione)

use Spoome\Core\Config;
use Spoome\Core\Migrator;
use Spoome\Core\Router;
use Spoome\Http\Response;
use Spoome\Controllers\Api\AthleteController;

$router = new Router();

$router->get('/ping', static fn() => Response::json([
    'ok'  => true,
    'env' => Config::appEnv(),
]));

$router->get('/athletes/search', [AthleteController::class, 'search']);
$router->get('/athletes/{id}', [AthleteController::class, 'show']);

// Runner migrazioni — MAI in produzione, richiede MIGRATION_TOKEN.
$router->get('/migrate', static function () {
    $token = (string) Config::get('MIGRATION_TOKEN', '');
    if (Config::isProduction()) {
        Response::json(['error' => 'Disabilitato in produzione'], 403);
        return;
    }
    if ($token === '' || !\hash_equals($token, (string) ($_GET['token'] ?? ''))) {
        Response::json(['error' => 'Token non valido o assente'], 403);
        return;
    }
    $migrator = new Migrator(\Database::getInstance()->getConnection(), __DIR__ . '/database/migrations');
    Response::json(['result' => $migrator->migrate()]);
});

// Diagnostica DB (read-only) per decidere migrazioni sicure. Stessa protezione di /migrate.
$router->get('/dbcheck', static function () {
    $token = (string) Config::get('MIGRATION_TOKEN', '');
    if (Config::isProduction()) {
        Response::json(['error' => 'Disabilitato in produzione'], 403);
        return;
    }
    if ($token === '' || !\hash_equals($token, (string) ($_GET['token'] ?? ''))) {
        Response::json(['error' => 'Token non valido o assente'], 403);
        return;
    }
    $pdo = \Database::getInstance()->getConnection();
    $val = static fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();

    Response::json([
        'organizations' => [
            'total'              => $val('SELECT COUNT(*) FROM organizations'),
            'null_item_id'       => $val('SELECT COUNT(*) FROM organizations WHERE item_id IS NULL'),
            'distinct_item_id'   => $val('SELECT COUNT(DISTINCT item_id) FROM organizations'),
            'dup_item_id_groups' => $val('SELECT COUNT(*) FROM (SELECT item_id FROM organizations WHERE item_id IS NOT NULL GROUP BY item_id HAVING COUNT(*) > 1) t'),
        ],
        'bigevents' => [
            'total'              => $val('SELECT COUNT(*) FROM bigevents'),
            'null_item_id'       => $val('SELECT COUNT(*) FROM bigevents WHERE item_id IS NULL'),
            'distinct_item_id'   => $val('SELECT COUNT(DISTINCT item_id) FROM bigevents'),
            'dup_item_id_groups' => $val('SELECT COUNT(*) FROM (SELECT item_id FROM bigevents GROUP BY item_id HAVING COUNT(*) > 1) t'),
        ],
    ]);
});

$path = $_SERVER['PATH_INFO'] ?? ($_GET['route'] ?? '/');
$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
