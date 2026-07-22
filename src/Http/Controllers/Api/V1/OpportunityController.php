<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Opportunities\ApplicationRepository;
use Spoome\Domain\Opportunities\ApplicationService;
use Spoome\Domain\Opportunities\OpportunityPresenter;
use Spoome\Domain\Opportunities\OpportunityRepository;
use Spoome\Domain\Opportunities\OpportunityService;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Sports\SportRepository;
use Spoome\Http\Controllers\ApiController;

/**
 * API Opportunities + Applications (envelope {data,meta}/{errors}). Letture pubbliche (browse/dettaglio
 * = annunci fatti per essere scoperti); SCRITTURE solo-Bearer con acting-context (X-Acting-Profile).
 * Tutta la logica/authz vive nei Service (single source col web). Guscio sottile: adatta input → Service → emitJson.
 */
final class OpportunityController extends ApiController
{
    /** GET /opportunities — browse pubblico (aperte, non scadute), filtri ?sport=slug&region=. */
    public function index(Request $request): void
    {
        $sportId = $this->sportFilter($request);
        $region  = trim((string) $request->input('region', '')) ?: null;
        $page    = max(1, (int) $request->input('page', 1));

        $res = (new OpportunityRepository())->listPublic($sportId, $region, $page, 20);
        Response::json(
            array_map([OpportunityPresenter::class, 'card'], $res['items']),
            200,
            ['total' => $res['total'], 'page' => $page]
        );
    }

    /** GET /opportunities/{id} — dettaglio pubblico. */
    public function show(Request $request): void
    {
        $opp = (new OpportunityRepository())->findEnrichedById((int) $request->param('id'));
        if ($opp === null) {
            Response::error(I18n::t('opp.error.not_found'), 404);
            return;
        }
        Response::json(OpportunityPresenter::full($opp));
    }

    /** POST /opportunities — pubblica (Bearer, acting = org). */
    public function create(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $actingPid = (new ActingContext())->resolveForWrite($request, $user, 'admin');
        if ($actingPid === null) {
            Response::error(I18n::t('act.error.forbidden'), 403);
            return;
        }
        $this->emitJson((new OpportunityService())->publish($actingPid, $user->id, $request->body(), $request->ip()));
    }

    /** POST /opportunities/{id}/close — chiudi (Bearer, org publisher). */
    public function close(Request $request): void
    {
        $actingPid = $this->actingWrite($request);
        if ($actingPid === null) {
            return;
        }
        $this->emitJson((new OpportunityService())->close($actingPid, (int) $request->param('id'), $request->ip()));
    }

    /** POST /opportunities/{id}/applications — candidati (Bearer, acting = atleta non-org). */
    public function apply(Request $request): void
    {
        $actingPid = $this->actingWrite($request);
        if ($actingPid === null) {
            return;
        }
        $msg = ($m = $request->input('cover_message')) !== null ? (string) $m : null;
        $this->emitJson((new ApplicationService())->apply($actingPid, (int) $request->param('id'), $msg, $request->ip()));
    }

    /** GET /opportunities/{id}/applications — candidature ricevute (Bearer, SOLO org publisher). */
    public function applications(Request $request): void
    {
        $actingPid = $this->actingWrite($request);
        if ($actingPid === null) {
            return;
        }
        $page = max(1, (int) $request->input('page', 1));
        $res  = (new ApplicationService())->applicationsForOwner($actingPid, (int) $request->param('id'), $page, 30);
        if (!$res->ok) {
            $this->emitJson($res);
            return;
        }
        Response::json(
            array_map([OpportunityPresenter::class, 'application'], is_array($res->data) ? $res->data : []),
            200,
            ['total' => (int) ($res->meta['total'] ?? 0), 'page' => $page]
        );
    }

    /** POST /applications/{id}/accept — l'org accetta una candidatura ricevuta. */
    public function accept(Request $request): void
    {
        $actingPid = $this->actingWrite($request);
        if ($actingPid === null) {
            return;
        }
        $this->emitJson((new ApplicationService())->accept($actingPid, (int) $request->param('id'), $request->ip()));
    }

    /** POST /applications/{id}/reject — l'org non seleziona una candidatura ricevuta. */
    public function reject(Request $request): void
    {
        $actingPid = $this->actingWrite($request);
        if ($actingPid === null) {
            return;
        }
        $this->emitJson((new ApplicationService())->reject($actingPid, (int) $request->param('id'), $request->ip()));
    }

    /** GET /me/opportunities — le opportunità pubblicate dall'acting (org). */
    public function mine(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $actingPid = (new ActingContext())->resolve($request, $user);
        if ($actingPid === null) {
            Response::json([], 200, ['total' => 0]);
            return;
        }
        $page = max(1, (int) $request->input('page', 1));
        $res  = (new OpportunityRepository())->listForOrg($actingPid, $page, 20);
        Response::json(
            array_map([OpportunityPresenter::class, 'card'], $res['items']),
            200,
            ['total' => $res['total'], 'page' => $page]
        );
    }

    /** GET /me/applications — le candidature inviate dall'acting (atleta). */
    public function myApplications(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $actingPid = (new ActingContext())->resolve($request, $user);
        if ($actingPid === null) {
            Response::json([], 200, ['total' => 0]);
            return;
        }
        $page = max(1, (int) $request->input('page', 1));
        $res  = (new ApplicationRepository())->listForApplicant($actingPid, $page, 20);
        Response::json(
            array_map([OpportunityPresenter::class, 'myApplication'], $res['items']),
            200,
            ['total' => $res['total'], 'page' => $page]
        );
    }

    /* ------------------------------------------------------------ helpers ---- */

    /** Bearer + acting profile (≥admin) o null (dopo aver emesso 401/403). */
    private function actingWrite(Request $request): ?int
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return null;
        }
        $actingPid = (new ActingContext())->resolveForWrite($request, $user, 'admin');
        if ($actingPid === null) {
            Response::error(I18n::t('act.error.forbidden'), 403);
            return null;
        }
        return $actingPid;
    }

    /** Risolve il filtro disciplina da ?sport=slug (o ?sport_id=). Null se assente/sconosciuto. */
    private function sportFilter(Request $request): ?int
    {
        $slug = trim((string) $request->input('sport', ''));
        if ($slug !== '') {
            return (new SportRepository())->idBySlug($slug);
        }
        $id = (int) $request->input('sport_id', 0);
        return $id > 0 ? $id : null;
    }
}
