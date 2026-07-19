<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\Request;
use Spoome\Domain\Profiles\PageService;
use Spoome\Http\Controllers\ApiController;

/**
 * API creazione pagine organizzazione (JSON, solo-Bearer — anti-CSRF). Parità col web /pagine.
 * L'acting context per le app native è stateless via header `X-Acting-Profile`: la creazione NON
 * lo persiste lato server (il client deciderà quale acting usare nelle richieste successive).
 */
final class PagesController extends ApiController
{
    /** POST /pages — crea una pagina org di cui l'utente Bearer diventa owner. */
    public function create(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $this->emitJson((new PageService())->create($user, $request->body(), $request->ip()));
    }
}
