<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Pagination;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\ProfileDetailsRepository;
use Spoome\Domain\Profiles\ProfileDetailsService;
use Spoome\Domain\Profiles\ProfilePresenter;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Profiles\ProfileService;
use Spoome\Http\Controllers\ApiController;

/**
 * API di gestione del PROPRIO profilo e sotto-entità (JSON). Solo-Bearer (mai sessione cookie):
 * le stesse operazioni del web `/profilo`, ma per le app native. La logica è nei Service condivisi —
 * qui si adatta solo l'input e si traduce il ServiceResult con respond().
 */
final class MeController extends ApiController
{
    /** PATCH /me — aggiorna i campi core del proprio profilo; ritorna il profilo aggiornato. */
    public function update(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $repo = new ProfileRepository();
        $ctx  = new ActingContext();
        // Multi-profilo: PATCH /me aggiorna l'ACTING profile (header X-Acting-Profile, ri-validato
        // via canActAs('editor')). Un profilo dichiarato ma non gestibile → 403.
        $actingId = $ctx->resolveForWrite($request, $user, 'editor');
        if ($actingId === null) {
            Response::error(I18n::t('act.error.forbidden'), $ctx->personalProfileId($user) !== null ? 403 : 404);
            return;
        }

        $result = (new ProfileService($repo))->update($actingId, $request->body());
        if (!$result->ok) {
            $this->emitJson($result);
            return;
        }

        $fresh   = $repo->findEnrichedById($actingId);
        $details = new ProfileDetailsRepository();
        Response::json(ProfilePresenter::full(
            $fresh,
            $details->experiences($actingId),
            $details->achievements($actingId),
            $details->links($actingId)
        ));
    }

    /* ------------------------------------------------------- ESPERIENZE ---- */

    public function addExperience(Request $request): void
    {
        [$pid, $svc] = $this->context($request) ?: [null, null];
        if ($pid === null) {
            return;
        }
        $this->emitJson($svc->addExperience($pid, $request->body()));
    }

    public function updateExperience(Request $request): void
    {
        [$pid, $svc] = $this->context($request) ?: [null, null];
        if ($pid === null) {
            return;
        }
        $this->emitJson($svc->updateExperience((int) $request->param('id'), $pid, $request->body()));
    }

    public function deleteExperience(Request $request): void
    {
        [$pid, $svc] = $this->context($request) ?: [null, null];
        if ($pid === null) {
            return;
        }
        $this->emitJson($svc->deleteExperience((int) $request->param('id'), $pid));
    }

    /* --------------------------------------------------------- PALMARÈS ---- */

    public function addAchievement(Request $request): void
    {
        [$pid, $svc] = $this->context($request) ?: [null, null];
        if ($pid === null) {
            return;
        }
        $this->emitJson($svc->addAchievement($pid, $request->body()));
    }

    public function updateAchievement(Request $request): void
    {
        [$pid, $svc] = $this->context($request) ?: [null, null];
        if ($pid === null) {
            return;
        }
        $this->emitJson($svc->updateAchievement((int) $request->param('id'), $pid, $request->body()));
    }

    public function deleteAchievement(Request $request): void
    {
        [$pid, $svc] = $this->context($request) ?: [null, null];
        if ($pid === null) {
            return;
        }
        $this->emitJson($svc->deleteAchievement((int) $request->param('id'), $pid));
    }

    /* ------------------------------------------------------------- LINK ---- */

    public function addLink(Request $request): void
    {
        [$pid, $svc] = $this->context($request) ?: [null, null];
        if ($pid === null) {
            return;
        }
        $this->emitJson($svc->addLink($pid, $request->body()));
    }

    public function updateLink(Request $request): void
    {
        [$pid, $svc] = $this->context($request) ?: [null, null];
        if ($pid === null) {
            return;
        }
        $this->emitJson($svc->updateLink((int) $request->param('id'), $pid, $request->body()));
    }

    public function deleteLink(Request $request): void
    {
        [$pid, $svc] = $this->context($request) ?: [null, null];
        if ($pid === null) {
            return;
        }
        $this->emitJson($svc->deleteLink((int) $request->param('id'), $pid));
    }

    /* ------------------------------------------------------ CONNESSIONI ---- */

    /** GET /me/connections — connessioni accettate del proprio profilo. */
    public function connections(Request $request): void
    {
        $this->connectionList($request, false);
    }

    /** GET /me/connections/requests — richieste di connessione in entrata. */
    public function connectionRequests(Request $request): void
    {
        $this->connectionList($request, true);
    }

    private function connectionList(Request $request, bool $incoming): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $repo = new ProfileRepository();
        // Split: le connessioni sono SEMPRE del profilo PERSONALE (le pagine non connettono).
        $me   = (new ActingContext())->personalProfile($user);
        if ($me === null) {
            Response::error(I18n::t('api.error.unauthorized'), 404);
            return;
        }
        $pg     = Pagination::fromRequest($request, 24, 50);
        $result = $incoming ? $repo->incomingRequestsOf($me->id, $pg->page, $pg->perPage) : $repo->connectionsOf($me->id, $pg->page, $pg->perPage);

        Response::json(
            array_map([ProfilePresenter::class, 'card'], $result['items']),
            200,
            $pg->meta($result['total'])
        );
    }

    /* ------------------------------------------------------------ helpers ---- */

    /**
     * Risolve [profileId, ProfileDetailsService] per l'utente Bearer; emette 401/404 e ritorna null se impossibile.
     * @return array{0:int,1:ProfileDetailsService}|null
     */
    private function context(Request $request): ?array
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return null;
        }
        // Multi-profilo: editing dei details sull'ACTING profile (header X-Acting-Profile, ri-validato
        // via canActAs('editor')). Un profilo dichiarato ma non gestibile → 403 (mai fallback sul personale).
        $ctx = new ActingContext();
        $pid = $ctx->resolveForWrite($request, $user, 'editor');
        if ($pid === null) {
            Response::error(I18n::t('act.error.forbidden'), $ctx->personalProfileId($user) !== null ? 403 : 404);
            return null;
        }
        return [$pid, new ProfileDetailsService()];
    }
}
