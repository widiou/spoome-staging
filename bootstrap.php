<?php
$__root = __DIR__; while ($__root !== '/' && !is_file($__root . '/bootstrap.php')) { $__root = dirname($__root); } chdir($__root);
date_default_timezone_set('Europe/Rome');

// ENV (deve precedere la definizione delle costanti dipendenti dall'ambiente)
require_once __DIR__ . '/config/env.php';

// Path / URL base — derivati dall'ambiente (prod: /network/ ; staging: /beta/ ; ...)
define('PODIO_PATH', __DIR__);
define('BASE_PATH', '/' . trim((string) env('BASE_PATH', '/network/'), '/') . '/');
define('BASE_URL', rtrim((string) env('APP_URL', 'https://spoome.it'), '/') . BASE_PATH);
define('PODIO_URL', BASE_PATH . 'podio/');



// FUNCTION
require_once 'helpers/function.php';

// SETTINGS
require_once 'settings/default.php';

// DATABASE
require_once 'db/Database.php';
$pdo = Database::getInstance()->getConnection(); // <<< Connessione PDO globale

// UAC
require_once 'uac/auth.php';

// MODELS
require_once 'models/Athlete.php';
require_once 'models/Event.php';
require_once 'models/User.php';
require_once 'models/Organizations.php';

// TEXT
require_once 'locales/it/default.php';
require_once 'locales/it/messages.php';
require_once 'locales/it/placeholders.php';
require_once 'locales/it/menu.php';
require_once 'locales/it/widgets.php';

// COMPONENTS
require_once 'vendor/autoload.php';

// API
require_once 'services/app/function.php';
