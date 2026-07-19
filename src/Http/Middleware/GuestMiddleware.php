<?php

namespace Spoome\Http\Middleware;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Auth\CurrentUser;

/**
 * Consente l'accesso solo agli utenti NON autenticati (pagine login/registrazione).
 * Se già loggato: web → redirect alla home; API → 400.
 */
final class GuestMiddleware
{
    public function handle(Request $request): bool
    {
        if (CurrentUser::resolve($request) === null) {
            return true;
        }
        if ($request->wantsJson()) {
            Response::error(I18n::t('api.error.already_auth'), 400);
        } else {
            Response::redirect('');
        }
        return false;
    }
}
