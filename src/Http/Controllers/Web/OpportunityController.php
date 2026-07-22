<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Core\Session;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Opportunities\ApplicationRepository;
use Spoome\Domain\Opportunities\ApplicationService;
use Spoome\Domain\Opportunities\OpportunityRepository;
use Spoome\Domain\Opportunities\OpportunityService;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Sports\SportRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Web (HTML, CSRF) di Opportunities + Applications. Guscio sottile sopra i Service (stessa logica/authz
 * dell'API). Browse/dettaglio pubblici (SEO); pubblicazione/candidatura/gestione autenticate.
 * L'acting-context risolve il profilo che agisce (org per pubblicare/gestire, persona per candidarsi).
 */
final class OpportunityController extends Controller
{
    /* ---------------------------------------------------------------- browse / read ---- */

    /** GET /opportunita — bacheca pubblica (aperte, non scadute), filtri disciplina + zona. */
    public function index(Request $request): void
    {
        $sports  = (new SportRepository())->all();
        $slug    = trim((string) $request->input('sport', ''));
        $sportId = $slug !== '' ? (new SportRepository())->idBySlug($slug) : null;
        $region  = trim((string) $request->input('region', '')) ?: null;
        $page    = max(1, (int) $request->input('page', 1));

        $res = (new OpportunityRepository())->listPublic($sportId, $region, $page, 20);

        View::render('opportunita/index', [
            'title'         => $this->title('opp.index.title'),
            'items'         => $res['items'],
            'total'         => $res['total'],
            'page'          => $page,
            'sports'        => $sports,
            'selectedSport' => $slug,
            'selectedRegion' => $region ?? '',
            'canPublish'    => $this->actingIsOrg($request),
            'notice'        => Session::takeFlash(),
        ], 'base');
    }

    /** GET /opportunita/{id} — dettaglio pubblico. */
    public function show(Request $request): void
    {
        $opp = (new OpportunityRepository())->findEnrichedById((int) $request->param('id'));
        if ($opp === null) {
            $this->notFound($request, 'opportunita', 'opp.error.not_found');
            return;
        }

        $actingPid = $this->readActingPid($request);
        $isOwner   = $actingPid !== null && (int) $opp['org_profile_id'] === $actingPid;
        $hasApplied = $actingPid !== null && !$isOwner
            && (new ApplicationRepository())->findByOpportunityAndApplicant((int) $opp['id'], $actingPid) !== null;

        View::render('opportunita/show', [
            'title'      => $this->title('opp.show.title', ['title' => (string) $opp['title']]),
            'opp'        => $opp,
            'isOwner'    => $isOwner,
            'hasApplied' => $hasApplied,
            'canApply'   => $actingPid !== null && !$isOwner && !$hasApplied,
            'notice'     => Session::takeFlash(),
        ], 'base');
    }

    /** GET /opportunita/pubblica — form di pubblicazione (solo org). */
    public function publishForm(Request $request): void
    {
        if (!$this->actingIsOrg($request)) {
            Session::flash(I18n::t('opp.error.not_org'), 'error');
            Response::redirect('opportunita');
            return;
        }
        View::render('opportunita/publish', [
            'title'  => $this->title('opp.publish.title'),
            'sports' => (new SportRepository())->all(),
            'kinds'  => OpportunityService::KINDS,
            'notice' => Session::takeFlash(),
            // Prefill (R-Moat M5, #45): lo step 3 dell'onboarding Società rimanda QUI con la disciplina/
            // zona già impostate sulla pagina — "nessuna nuova view, solo prefill" (spec di Bianca). Solo
            // valori di visualizzazione (selected/value): la validazione reale resta in OpportunityService,
            // un query-param manomesso al più precompila male un campo, non aggira alcun controllo.
            'prefillSportId' => (int) $request->input('sport_id', 0) ?: null,
            'prefillRegion'  => trim((string) $request->input('region', '')),
            'prefillCity'    => trim((string) $request->input('city', '')),
        ], 'base');
    }

    /** GET /opportunita/mie — opportunità pubblicate dall'acting (org). */
    public function mine(Request $request): void
    {
        $actingPid = $this->readActingPid($request);
        $res = $actingPid !== null
            ? (new OpportunityRepository())->listForOrg($actingPid, max(1, (int) $request->input('page', 1)), 20)
            : ['items' => [], 'total' => 0];

        View::render('opportunita/mine', [
            'title'  => $this->title('opp.mine.title'),
            'items'  => $res['items'],
            'total'  => $res['total'],
            'notice' => Session::takeFlash(),
        ], 'base');
    }

    /** GET /opportunita/candidature — candidature inviate dall'acting (atleta). */
    public function myApplications(Request $request): void
    {
        $actingPid = $this->readActingPid($request);
        $res = $actingPid !== null
            ? (new ApplicationRepository())->listForApplicant($actingPid, max(1, (int) $request->input('page', 1)), 20)
            : ['items' => [], 'total' => 0];

        View::render('opportunita/mine-applications', [
            'title'  => $this->title('opp.my_apps.title'),
            'items'  => $res['items'],
            'total'  => $res['total'],
            'notice' => Session::takeFlash(),
        ], 'base');
    }

    /** GET /opportunita/{id}/candidature — candidature ricevute (solo org publisher). */
    public function applications(Request $request): void
    {
        $actingPid = $this->writeActingPid($request);
        if ($actingPid === null) {
            return;
        }
        $oppId = (int) $request->param('id');
        $res   = (new ApplicationService())->applicationsForOwner($actingPid, $oppId, max(1, (int) $request->input('page', 1)), 30);
        if (!$res->ok) {
            Session::flash($res->error ?? I18n::t('opp.error.not_found'), 'error');
            Response::redirect('opportunita/mie');
            return;
        }
        View::render('opportunita/applications', [
            'title'  => $this->title('opp.apps.title'),
            'opp'    => $res->meta['opportunity'],
            'items'  => is_array($res->data) ? $res->data : [],
            'total'  => (int) ($res->meta['total'] ?? 0),
            'notice' => Session::takeFlash(),
        ], 'base');
    }

    /* ---------------------------------------------------------------- writes (CSRF) ---- */

    /** POST /opportunita — pubblica (org). */
    public function create(Request $request): void
    {
        $actingPid = $this->writeActingPid($request);
        if ($actingPid === null) {
            return;
        }
        $user = CurrentUser::resolve($request);
        $res  = (new OpportunityService())->publish($actingPid, $user?->id, $request->body(), $request->ip());

        // TODO (UX, non-bloccante): al fallimento di validazione il form no-JS riparte vuoto (flash +
        // redirect). Ripopolare i campi inviati (via flash dei vecchi input o rendering inline del form
        // con gli errori) quando si rifinisce la UX di pubblicazione.
        $newId    = $res->ok && is_array($res->data) ? (int) ($res->data['id'] ?? 0) : 0;
        $redirect = $res->ok && $newId > 0 ? 'opportunita/' . $newId : 'opportunita/pubblica';
        $this->respond($request, $res, $redirect, $res->ok ? (string) ($res->meta['message'] ?? '') : null);
    }

    /** POST /opportunita/{id}/chiudi — chiudi (org publisher). */
    public function close(Request $request): void
    {
        $actingPid = $this->writeActingPid($request);
        if ($actingPid === null) {
            return;
        }
        $oppId = (int) $request->param('id');
        $res   = (new OpportunityService())->close($actingPid, $oppId, $request->ip());
        $this->respond($request, $res, 'opportunita/' . $oppId, $res->ok ? (string) ($res->meta['message'] ?? '') : null);
    }

    /** POST /opportunita/{id}/candidati — candidati (atleta). */
    public function apply(Request $request): void
    {
        $actingPid = $this->writeActingPid($request);
        if ($actingPid === null) {
            return;
        }
        $oppId = (int) $request->param('id');
        $msg   = ($m = $request->input('cover_message')) !== null ? (string) $m : null;
        $res   = (new ApplicationService())->apply($actingPid, $oppId, $msg, $request->ip());
        $this->respond($request, $res, 'opportunita/' . $oppId, $res->ok ? (string) ($res->meta['message'] ?? '') : null);
    }

    /** POST /candidature/{id}/accetta — l'org accetta una candidatura. */
    public function acceptApplication(Request $request): void
    {
        $actingPid = $this->writeActingPid($request);
        if ($actingPid === null) {
            return;
        }
        $res = (new ApplicationService())->accept($actingPid, (int) $request->param('id'), $request->ip());
        $this->respond($request, $res, $this->back($request), $res->ok ? (string) ($res->meta['message'] ?? '') : null);
    }

    /** POST /candidature/{id}/rifiuta — l'org non seleziona una candidatura. */
    public function rejectApplication(Request $request): void
    {
        $actingPid = $this->writeActingPid($request);
        if ($actingPid === null) {
            return;
        }
        $res = (new ApplicationService())->reject($actingPid, (int) $request->param('id'), $request->ip());
        $this->respond($request, $res, $this->back($request), $res->ok ? (string) ($res->meta['message'] ?? '') : null);
    }

    /* ------------------------------------------------------------ helpers ---- */

    /** Acting profile per la SCRITTURA (≥admin) o null dopo aver emesso 403. */
    private function writeActingPid(Request $request): ?int
    {
        $user = CurrentUser::resolve($request);
        $pid  = $user !== null ? (new ActingContext())->resolveForWrite($request, $user, 'admin') : null;
        if ($pid === null) {
            $this->respond($request, ServiceResult::fail(I18n::t('act.error.forbidden'), 403), 'opportunita');
            return null;
        }
        return $pid;
    }

    /** Acting profile per la LETTURA (fallback silenzioso al personale), o null se anonimo. */
    private function readActingPid(Request $request): ?int
    {
        $user = CurrentUser::resolve($request);
        return $user !== null ? (new ActingContext())->resolve($request, $user) : null;
    }

    /** True se l'acting corrente è un profilo-organizzazione (per mostrare la CTA "Pubblica"). */
    private function actingIsOrg(Request $request): bool
    {
        $pid = $this->readActingPid($request);
        if ($pid === null) {
            return false;
        }
        $p = (new ProfileRepository())->findEnrichedById($pid);
        return $p !== null && !empty($p['is_organization']);
    }

    /** Redirect di ripiego no-JS: 'return' whitelistato a path interni, altrimenti /opportunita/mie. */
    private function back(Request $request): string
    {
        $ret = trim((string) $request->input('return', ''));
        if ($ret !== '' && $ret[0] !== '/' && !str_contains($ret, '://')) {
            return $ret;
        }
        return 'opportunita/mie';
    }
}
