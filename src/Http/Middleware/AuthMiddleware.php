<?php

namespace Spoome\Http\Middleware;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Auth\CurrentUser;

/**
 * Richiede un utente autenticato. Web → redirect al login; API → 401 JSON.
 * Ritorna false per interrompere la catena (risposta già inviata).
 */
final class AuthMiddleware
{
    public function handle(Request $request): bool
    {
        $user = CurrentUser::resolve($request);
        if ($user !== null) {
            return true;
        }
        if ($request->wantsJson()) {
            Response::error(I18n::t('api.error.unauthorized'), 401);
        } else {
            Response::redirect('accedi');
        }
        return false;
    }
}
