<?php

/**
 * Front controller UNICO di Spoome v2 (web + API).
 * La docroot dovrebbe puntare qui (public/). In staging sotto /beta/ funziona anche con la
 * docroot alla root del progetto grazie al .htaccess che instrada tutto su questo file.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

require $root . '/src/autoload.php';
require $root . '/config/env.php';
require $root . '/src/Core/helpers.php';

use Spoome\Core\Config;
use Spoome\Core\ErrorHandler;
use Spoome\Core\I18n;
use Spoome\Core\Logger;
use Spoome\Core\Request;
use Spoome\Core\Router;
use Spoome\Core\SecurityHeaders;
use Spoome\Core\Session;

ErrorHandler::register();

// Header di sicurezza indipendenti dal transport (oltre a quelli del .htaccess).
SecurityHeaders::send();
date_default_timezone_set('Europe/Rome');
I18n::setLocale((string) Config::get('APP_LOCALE', 'it'));

$request = Request::capture();

// Contesto di richiesta per il logging centralizzato (correla tutti i log della stessa richiesta).
Logger::init([
    'request_id' => bin2hex(random_bytes(8)),
    'ip'         => $request->ip(),
    'method'     => $request->method,
    'path'       => $request->path,
]);

// Sessione solo per il web (l'API è stateless: usa il token Bearer, niente cookie di sessione).
if (!$request->isApi()) {
    Session::start();
}

$router = new Router();
require $root . '/config/routes.php'; // popola $router

$router->dispatch($request);
