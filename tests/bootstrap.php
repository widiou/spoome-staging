<?php
/**
 * Bootstrap dei test. Carica l'autoloader applicativo (nessuna dipendenza runtime da Composer)
 * più l'autoload PSR-4 di Composer per le classi di test, se presente.
 */
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/../src/Core/helpers.php';

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

/**
 * Sicurezza: alcuni Service/Repository (es. Logger::security, ClaimService::approve che apre
 * la transazione su Db::connection() invece che sul PDO iniettato, repository default-costruiti
 * senza PDO esplicito) chiamano internamente Spoome\Core\Db::connection() — il singleton che legge
 * le credenziali dal `.env` REALE del progetto (il DB di staging su SiteGround).
 *
 * Se un test d'integrazione è configurato (SPOOME_TEST_DSN presente), reindirizziamo QUEL
 * singleton verso lo stesso DB usa-e-getta MySQL usato dai test, così ogni chiamata interna a
 * Db::connection() — anche quella non esplicitamente iniettata da un test — non tocca MAI il DB
 * reale. Va fatto DOPO aver caricato config/env.php (che altrimenti sovrascriverebbe questi valori
 * con quelli del `.env` vero) e PRIMA che qualunque test possa invocare Db::connection().
 */
require_once dirname(__DIR__) . '/config/env.php';

$spoomeTestDsn = getenv('SPOOME_TEST_DSN');
if ($spoomeTestDsn !== false && $spoomeTestDsn !== '') {
    $paramsPos = strpos($spoomeTestDsn, ':');
    $paramsStr = $paramsPos !== false ? substr($spoomeTestDsn, $paramsPos + 1) : '';
    $dsnParts = [];
    foreach (explode(';', $paramsStr) as $pair) {
        if (!str_contains($pair, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $pair, 2);
        $dsnParts[trim($k)] = trim($v);
    }

    $overrides = [
        'DB_HOST'    => $dsnParts['host'] ?? '127.0.0.1',
        'DB_NAME'    => $dsnParts['dbname'] ?? '',
        'DB_CHARSET' => $dsnParts['charset'] ?? 'utf8mb4',
        'DB_USER'    => (string) getenv('SPOOME_TEST_USER'),
        'DB_PASS'    => (string) getenv('SPOOME_TEST_PASS'),
        // Non-produzione di default: i test possono comunque forzare APP_ENV=production
        // temporaneamente (in un singolo test) per verificare i gate specifici di quell'ambiente.
        'APP_ENV'    => 'testing',
    ];
    foreach ($overrides as $k => $v) {
        $_ENV[$k] = $v;
        putenv($k . '=' . $v);
    }
}
