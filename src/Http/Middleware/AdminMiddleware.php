<?php

namespace Spoome\Http\Middleware;

use Spoome\Core\Config;
use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;

/**
 * Cancello dell'area amministrativa. Richiede un utente con role=admin.
 * Un non-admin (o non autenticato) riceve un 404 identico a una rotta inesistente:
 * l'area /admin NON deve rivelare la propria esistenza (no 403, che confermerebbe la risorsa).
 * Va montato DOPO AuthMiddleware nella catena.
 */
final class AdminMiddleware
{
    public function handle(Request $request): bool
    {
        $user = CurrentUser::resolve($request);
        if ($user !== null && $user->isAdmin()) {
            return true;
        }

        // Camuffato da 404: nessun indizio che esista un'area riservata.
        if ($request->wantsJson()) {
            Response::error(I18n::t('error.not_found'), 404);
            return false;
        }
        http_response_code(404);
        View::render('message', [
            'title'       => I18n::t('error.not_found_title') . ' · ' . Config::appName(),
            'heading'     => I18n::t('error.not_found_title'),
            'message'     => I18n::t('error.not_found'),
            'type'        => 'error',
            'actionUrl'   => url(''),
            'actionLabel' => I18n::t('error.back_home'),
        ], 'base');
        return false;
    }
}
