<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\ProfilePresenter;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Profiles\RecommendationService;
use Spoome\Http\Controllers\ApiController;

/**
 * API raccomandazioni (envelope {data,meta}/{errors}, solo-Bearer per le scritture). Guscio sottile su
 * RecommendationService (stessa logica/authz del web). Le raccomandazioni VISIBILI di un profilo sono già
 * nel read-model GET /profiles/{handle}; qui: scrittura, accetta/nascondi le proprie, elenco pending.
 */
final class RecommendationController extends ApiController
{
    /** POST /profiles/{handle}/recommendation — scrive una raccomandazione per {handle} ({body,relationship}). */
    public function write(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        // Autore = profilo PERSONALE (azione solo-personale, come l'endorse).
        $actor = (new ActingContext())->personalProfile($user);
        if ($actor === null) {
            Response::error(I18n::t('atleti.show.not_found_title'), 404);
            return;
        }
        $target = (new ProfileRepository())->findByHandle((string) $request->param('handle', ''));
        if ($target === null) {
            Response::error(I18n::t('reco.error.not_found'), 404);
            return;
        }
        $body = (string) ($request->body()['body'] ?? '');
        $rel  = trim((string) ($request->body()['relationship'] ?? ''));
        $this->emitJson((new RecommendationService())->write($actor->id, $target->id, $body, $rel !== '' ? $rel : null, $request->ip()));
    }

    /** POST /me/recommendations/{id}/accept — il destinatario pubblica una raccomandazione ricevuta. */
    public function accept(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $this->emitJson((new RecommendationService())->accept((int) $user->id, (int) $request->param('id'), $request->ip()));
    }

    /** POST /me/recommendations/{id}/hide — il destinatario rifiuta/nasconde una raccomandazione ricevuta. */
    public function hide(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $this->emitJson((new RecommendationService())->hide((int) $user->id, (int) $request->param('id'), $request->ip()));
    }

    /** GET /me/recommendations/pending — raccomandazioni in attesa di approvazione ricevute dal proprio profilo. */
    public function pending(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $pid = (new ActingContext())->personalProfileId($user);
        if ($pid === null) {
            Response::json([], 200, ['total' => 0]);
            return;
        }
        $rows = (new RecommendationService())->pendingFor($pid);
        Response::json(array_map([ProfilePresenter::class, 'recommendation'], $rows), 200, ['total' => count($rows)]);
    }
}
