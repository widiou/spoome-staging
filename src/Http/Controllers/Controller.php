<?php

namespace Spoome\Http\Controllers;

use Spoome\Core\Config;
use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Core\Session;
use Spoome\Domain\Profiles\Profile;
use Spoome\Domain\Profiles\ProfileRepository;

/**
 * Base comune ai controller. Centralizza le utility ripetute (titolo pagina) e — soprattutto —
 * l'UNICA traduzione ServiceResult → HTTP condivisa da web e API (backend API-first / multi-client).
 */
abstract class Controller
{
    /** Titolo pagina: "<testo> · <AppName>". */
    protected function title(string $key, array $replace = []): string
    {
        return I18n::t($key, $replace) . ' · ' . Config::appName();
    }

    /**
     * MAPPATURA UNICA ServiceResult → envelope JSON. Sorgente sola-verità del contratto:
     *  - ok      → 2xx con { data, meta }  (204 senza corpo)
     *  - fail    → { errors:[{status,title,detail,fields}] } con lo status del risultato
     * Usata sia dai controller API (scritture/letture Bearer) sia dal ramo async del responder web.
     */
    protected function emitJson(ServiceResult $result): void
    {
        if ($result->ok) {
            if ($result->code === 204) {
                Response::noContent();
                return;
            }
            // Clampa lo status di successo nel 2xx (come fa il ramo fail per il 4xx): se un Service
            // restituisse per errore ok() con un code non-2xx, non emettere un http_response_code invalido.
            $status = ($result->code >= 200 && $result->code < 300) ? $result->code : 200;
            Response::json($result->data, $status, $result->meta);
            return;
        }
        Response::error(
            $result->error ?? I18n::t('api.error.invalid_data'),
            $result->code >= 400 ? $result->code : 422,
            null,
            $result->errors !== [] ? ['fields' => $result->errors] : []
        );
    }

    /**
     * Responder web (progressive enhancement). Traduce un ServiceResult in risposta HTTP:
     *  - richiesta async (Accept: application/json) → stesso envelope JSON dell'API (emitJson);
     *  - richiesta classica → flash + redirect (successo `$flashOk` se dato, altrimenti errore dal risultato).
     * Non solleva mai eccezioni: ogni ramo termina in Response::* (gli helper nav girano su ogni pagina).
     *
     * @param string      $redirect path relativo per il fallback no-JS (es. 'feed', 'profilo#link')
     * @param string|null $flashOk  messaggio di successo per il flash (solo ramo no-JS); null = nessun flash su ok
     */
    protected function respond(Request $request, ServiceResult $result, string $redirect, ?string $flashOk = null): void
    {
        if ($request->wantsJson()) {
            $this->emitJson($result);
            return;
        }
        if (!$result->ok) {
            Session::flash($result->error ?? I18n::t('api.error.invalid_data'), 'error');
        } elseif ($flashOk !== null) {
            Session::flash($flashOk, 'success');
        }
        Response::redirect($redirect);
    }

    /**
     * Micro-helper per "entità non trovata" (actor/target null): 404 JSON in async, redirect di ripiego no-JS.
     */
    protected function notFound(Request $request, string $redirect, string $key = 'atleti.show.not_found_title'): void
    {
        $this->respond($request, ServiceResult::fail(I18n::t($key), 404), $redirect);
    }

    /**
     * Risolve il profilo TARGET dal parametro di rotta `handle` (via findByHandle → entità Profile) o,
     * se assente/inesistente, emette un 404 (JSON in async/API, redirect+flash no-JS via notFound()) e
     * ritorna null. Incapsula il pattern ripetuto `findByHandle + null → 404`. Uso:
     *   `$target = $this->resolveTargetOr404($request, $repo); if ($target === null) return;`
     */
    protected function resolveTargetOr404(Request $request, ProfileRepository $repo, string $redirect = 'atleti'): ?Profile
    {
        $target = $repo->findByHandle((string) $request->param('handle', ''));
        if ($target === null) {
            $this->notFound($request, $redirect);
            return null;
        }
        return $target;
    }
}
