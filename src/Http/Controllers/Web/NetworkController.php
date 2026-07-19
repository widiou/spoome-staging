<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Core\Session;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Connections\ConnectionSuggestionService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Pagina "Rete" (area autenticata): suggerimenti di 2° grado + richieste in entrata + connessioni.
 */
final class NetworkController extends Controller
{
    public function index(Request $request): void
    {
        $user = CurrentUser::resolve($request);
        $repo = new ProfileRepository();
        $me   = $repo->findByUserId($user->id);
        if ($me === null) {
            Response::redirect('rivendicazioni');
            return;
        }

        $connections = $repo->connectionsOf($me->id, 1, 100);
        $requests    = $repo->incomingRequestsOf($me->id, 1, 100);

        // Suggerimenti "Persone che potresti conoscere" (2° grado + fallback). Soft-fail:
        // un problema qui non deve rompere la pagina Rete.
        $suggestions = [];
        $city = null;
        try {
            $raw  = $repo->findRawById($me->id);
            $city = ($raw['location_city'] ?? '') !== '' ? $raw['location_city'] : null;
            $suggestions = (new ConnectionSuggestionService())
                ->suggestionsFor($me->id, $me->sportId, $city, 12);
        } catch (\Throwable $e) {
            $suggestions = [];
        }

        View::render('rete/index', [
            'title'            => $this->title('nav.network'),
            'suggestions'      => $suggestions,
            'meSportId'        => $me->sportId,
            'meCity'           => $city,
            'connections'      => $connections['items'],
            'connectionsTotal' => $connections['total'],
            'requests'         => $requests['items'],
            'requestsTotal'    => $requests['total'],
            'notice'           => Session::takeFlash(),
        ], 'base');
    }

    /** Ignora un suggerimento (POST, CSRF). Flash + redirect alla pagina Rete. */
    public function dismissSuggestion(Request $request): void
    {
        $user   = CurrentUser::resolve($request);
        $repo   = new ProfileRepository();
        $me     = $repo->findByUserId($user->id);
        if ($me === null) {
            $this->notFound($request, 'rivendicazioni');
            return;
        }
        $target = $this->resolveTargetOr404($request, $repo, 'rete');
        if ($target === null) {
            return;
        }

        $result = (new ConnectionSuggestionService())->dismiss($me->id, $target->id, $request->ip());
        // Payload esplicito {dismissed,handle} in caso di successo (contratto async: removeCard).
        if ($result->ok) {
            $result = ServiceResult::ok(['dismissed' => true, 'handle' => $target->handle]);
        }

        $this->respond($request, $result, 'rete', I18n::t('suggest.flash.dismissed'));
    }
}
