<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\AffiliationRepository;
use Spoome\Domain\Profiles\AffiliationService;
use Spoome\Domain\Profiles\ProfilePresenter;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\ApiController;

/**
 * API affiliazioni (envelope {data,meta}/{errors}). Letture pubbliche (roster/militanza di un profilo
 * pubblico); scritture solo-Bearer con acting-context via header X-Acting-Profile (il lato org agisce
 * come la propria pagina). La logica/authz di dominio vive in AffiliationService (single source col web).
 */
final class AffiliationController extends ApiController
{
    /** GET /profiles/{handle}/roster — Roster/Membri confermati di un'organizzazione (pubblico). */
    public function roster(Request $request): void
    {
        $org = (new ProfileRepository())->findPublicByHandle((string) $request->param('handle', ''));
        if ($org === null) {
            Response::error(I18n::t('atleti.show.not_found_title'), 404);
            return;
        }
        $rows = (new AffiliationRepository())->rosterOf((int) $org['id']);
        Response::json(array_map([self::class, 'present'], $rows), 200, ['total' => count($rows)]);
    }

    /** GET /profiles/{handle}/affiliations — "Militanza / Carriera" confermata di un profilo (pubblico). */
    public function affiliations(Request $request): void
    {
        $member = (new ProfileRepository())->findPublicByHandle((string) $request->param('handle', ''));
        if ($member === null) {
            Response::error(I18n::t('atleti.show.not_found_title'), 404);
            return;
        }
        $rows = (new AffiliationRepository())->affiliationsOf((int) $member['id']);
        Response::json(array_map([self::class, 'present'], $rows), 200, ['total' => count($rows)]);
    }

    /** POST /profiles/{handle}/affiliation — propone un'affiliazione con {handle} (direzione da is_organization). */
    public function request(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $ctx = new ActingContext();
        $actingPid = $ctx->resolveForWrite($request, $user, 'admin');
        if ($actingPid === null) {
            Response::error(I18n::t('act.error.forbidden'), 403);
            return;
        }
        $repo   = new ProfileRepository();
        $acting = $repo->findEnrichedById($actingPid);
        $target = $repo->findPublicByHandle((string) $request->param('handle', ''));
        if ($acting === null || $target === null) {
            Response::error(I18n::t('affil.error.not_found'), 404);
            return;
        }
        $actingOrg = !empty($acting['is_organization']);
        $targetOrg = !empty($target['is_organization']);
        if ($targetOrg) {
            [$memberPid, $orgPid] = [(int) $acting['id'], (int) $target['id']];
        } elseif ($actingOrg) {
            [$memberPid, $orgPid] = [(int) $target['id'], (int) $acting['id']];
        } else {
            Response::error(I18n::t('affil.error.not_org'), 422);
            return;
        }
        $this->emitJson((new AffiliationService())->request($actingPid, $memberPid, $orgPid, $request->body(), $request->ip()));
    }

    /** POST /affiliations/{id}/confirm */
    public function confirm(Request $request): void
    {
        $actingPid = $this->actingWrite($request);
        if ($actingPid === null) {
            return;
        }
        $this->emitJson((new AffiliationService())->confirm($actingPid, (int) $request->param('id'), $request->ip()));
    }

    /** POST /affiliations/{id}/reject */
    public function reject(Request $request): void
    {
        $actingPid = $this->actingWrite($request);
        if ($actingPid === null) {
            return;
        }
        $this->emitJson((new AffiliationService())->remove($actingPid, (int) $request->param('id'), $request->ip()));
    }

    /** DELETE /affiliations/{id} */
    public function remove(Request $request): void
    {
        $actingPid = $this->actingWrite($request);
        if ($actingPid === null) {
            return;
        }
        $this->emitJson((new AffiliationService())->remove($actingPid, (int) $request->param('id'), $request->ip()));
    }

    /** GET /me/affiliations/pending — richieste in ingresso da confermare per l'acting profile. */
    public function pending(Request $request): void
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
        $rows = (new AffiliationRepository())->pendingFor($actingPid);
        Response::json(array_map([self::class, 'present'], $rows), 200, ['total' => count($rows)]);
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

    /**
     * Mappa una riga affiliazione arricchita (controparte = cp_*) in forma API pulita.
     * Shape unica in {@see ProfilePresenter::affiliation()} (riusata anche dalla pagina profilo API).
     */
    private static function present(array $r): array
    {
        return ProfilePresenter::affiliation($r);
    }
}
