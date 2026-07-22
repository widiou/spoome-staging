<?php

namespace Spoome\Domain\Opportunities;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Profiles\ProfileRepository;

/**
 * Candidature: un ATLETA si candida a un'opportunità aperta; l'ORG che l'ha pubblicata gestisce le
 * candidature ricevute (accetta / non-seleziona) — chiude il loop richiesto da Steve. Contratto
 * ServiceResult unico web/API.
 *
 * AUTHZ AL LIVELLO DATI (MASSIMO, niente IDOR):
 *  - candidarsi: SOLO un profilo NON-organizzazione (persona/atleta). L'org non si candida.
 *  - gestire/leggere le candidature di un'opportunità: SOLO l'org che l'ha pubblicata
 *    (opportunity.org_profile_id === actingPid), verificato via join ad ogni azione.
 *  - un atleta vede solo le PROPRIE candidature (scoping per applicant_profile_id, non qui).
 */
final class ApplicationService
{
    private const DECISIONS = ['accepted', 'rejected'];

    private const MAX_ACTIONS = 30;
    private const WINDOW_MIN   = 10;

    private ApplicationRepository $apps;
    private OpportunityRepository $opps;
    private ProfileRepository $profiles;
    private RateLimiter $limiter;

    public function __construct(
        ?ApplicationRepository $apps = null,
        ?OpportunityRepository $opps = null,
        ?ProfileRepository $profiles = null,
        ?RateLimiter $limiter = null
    ) {
        $this->apps     = $apps ?? new ApplicationRepository();
        $this->opps     = $opps ?? new OpportunityRepository();
        $this->profiles = $profiles ?? new ProfileRepository();
        $this->limiter  = $limiter ?? new RateLimiter();
    }

    /** Un atleta ($actingPid, profilo NON-org) si candida all'opportunità $oppId. */
    public function apply(int $actingPid, int $oppId, ?string $coverMessage, string $ip = 'unknown'): ServiceResult
    {
        $applicant = $this->profiles->findEnrichedById($actingPid);
        if ($applicant === null) {
            return ServiceResult::fail(I18n::t('opp.error.not_found'), 404);
        }
        // Solo persone (atleti) si candidano; le organizzazioni pubblicano, non applicano.
        if (!empty($applicant['is_organization'])) {
            return ServiceResult::fail(I18n::t('app.error.org_cannot_apply'), 403);
        }

        $opp = $this->opps->findById($oppId);
        if ($opp === null) {
            return ServiceResult::fail(I18n::t('opp.error.not_found'), 404);
        }
        if ((int) $opp['org_profile_id'] === $actingPid) {
            // Difensivo: un atleta non è mai il publisher org, ma non fidarsi mai dell'invariante.
            return ServiceResult::fail(I18n::t('app.error.self'), 422);
        }
        if ($opp['status'] !== 'open') {
            return ServiceResult::fail(I18n::t('app.error.closed'), 422);
        }
        // gmdate: allinea il confronto DATE al fuso di MySQL (sessione UTC → CURDATE() UTC, come listPublic).
        if ($opp['deadline'] !== null && $opp['deadline'] < gmdate('Y-m-d')) {
            return ServiceResult::fail(I18n::t('app.error.expired'), 422);
        }
        if ($this->apps->findByOpportunityAndApplicant($oppId, $actingPid) !== null) {
            return ServiceResult::fail(I18n::t('app.error.already'), 422);
        }
        if ($this->limiter->tooManyByKey('app:apply:' . $actingPid, self::MAX_ACTIONS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('opp.error.throttled'), 429);
        }

        $msg = $coverMessage !== null ? mb_substr(trim($coverMessage), 0, 1000) : null;
        $id  = $this->apps->create($oppId, $actingPid, $msg);
        $this->limiter->hit('app:apply:' . $actingPid, $ip);

        return ServiceResult::ok(['id' => $id, 'status' => 'submitted'], ['message' => I18n::t('app.done.applied')], 201);
    }

    /** L'org accetta una candidatura ricevuta. */
    public function accept(int $actingPid, int $appId, string $ip = 'unknown'): ServiceResult
    {
        return $this->decide($actingPid, $appId, 'accepted', 'app.done.accepted', $ip);
    }

    /** L'org non seleziona (rifiuta) una candidatura ricevuta. */
    public function reject(int $actingPid, int $appId, string $ip = 'unknown'): ServiceResult
    {
        return $this->decide($actingPid, $appId, 'rejected', 'app.done.rejected', $ip);
    }

    /**
     * Elenca le candidature di un'opportunità, SOLO per l'org che l'ha pubblicata (authz-bearing).
     * Ritorna ServiceResult(data=items, meta={total, opportunity}) o 403/404.
     */
    public function applicationsForOwner(int $actingPid, int $oppId, int $page = 1, int $perPage = 30): ServiceResult
    {
        $opp = $this->opps->findEnrichedById($oppId);
        if ($opp === null) {
            return ServiceResult::fail(I18n::t('opp.error.not_found'), 404);
        }
        if ((int) $opp['org_profile_id'] !== $actingPid) {
            return ServiceResult::fail(I18n::t('opp.error.forbidden'), 403);
        }
        $res = $this->apps->listForOpportunity($oppId, $page, $perPage);
        return ServiceResult::ok($res['items'], ['total' => $res['total'], 'opportunity' => $opp]);
    }

    /* ------------------------------------------------------------ helpers ---- */

    /**
     * Nucleo comune di accept/reject. Authz: l'acting deve essere l'org publisher dell'opportunità
     * cui la candidatura appartiene (no IDOR). Guard TOCTOU nel repo (respond aggiorna solo se submitted).
     */
    private function decide(int $actingPid, int $appId, string $status, string $doneKey, string $ip): ServiceResult
    {
        if (!in_array($status, self::DECISIONS, true)) {
            return ServiceResult::fail(I18n::t('app.error.bad_status'), 422); // difensivo: mai da input
        }
        $app = $this->apps->findById($appId);
        if ($app === null) {
            return ServiceResult::fail(I18n::t('app.error.not_found'), 404);
        }
        $opp = $this->opps->findById((int) $app['opportunity_id']);
        if ($opp === null) {
            return ServiceResult::fail(I18n::t('opp.error.not_found'), 404);
        }
        if ((int) $opp['org_profile_id'] !== $actingPid) {
            return ServiceResult::fail(I18n::t('opp.error.forbidden'), 403);
        }
        if ($this->limiter->tooManyByKey('opp:manage:' . $actingPid, self::MAX_ACTIONS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('opp.error.throttled'), 429);
        }
        if (!$this->apps->respond($appId, $status)) {
            return ServiceResult::fail(I18n::t('app.error.not_pending'), 422);
        }
        $this->limiter->hit('opp:manage:' . $actingPid, $ip);

        return ServiceResult::ok(['id' => $appId, 'status' => $status], ['message' => I18n::t($doneKey)]);
    }
}
