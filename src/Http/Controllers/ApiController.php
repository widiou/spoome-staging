<?php

namespace Spoome\Http\Controllers;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Users\User;

/**
 * Base dei controller API (JSON, solo-Bearer per le scritture). La traduzione ServiceResult → envelope
 * vive UNA volta sola in Controller::emitJson() (condivisa col responder web): qui si aggiunge solo la
 * risoluzione utente. Così ogni endpoint resta di 2-3 righe: adatta l'input, chiama il Service, `emitJson()`.
 */
abstract class ApiController extends Controller
{
    /**
     * Utente autenticato o null (sessione web O token Bearer). Se assente, emette 401.
     * Adatto ai READ: leggere i propri dati cross-site è comunque bloccato dalla same-origin policy.
     */
    protected function requireUser(Request $request): ?User
    {
        $user = CurrentUser::resolve($request);
        if ($user === null) {
            Response::error(I18n::t('api.error.unauthorized'), 401);
            return null;
        }
        return $user;
    }

    /**
     * Utente autenticato SOLO via token Bearer (mai sessione cookie). Se assente, emette 401.
     * Da usare per le API di SCRITTURA: elimina alla radice la CSRF via cookie di sessione.
     */
    protected function requireBearerUser(Request $request): ?User
    {
        $user = CurrentUser::fromBearer($request);
        if ($user === null) {
            Response::error(I18n::t('api.error.unauthorized'), 401);
            return null;
        }
        return $user;
    }
}
