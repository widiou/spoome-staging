<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Pagination;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Connections\ConnectionService;
use Spoome\Domain\Follows\FollowService;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\ProfilePageService;
use Spoome\Domain\Profiles\ProfilePresenter;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Sports\SportRepository;
use Spoome\Http\Controllers\ApiController;
use Spoome\Support\Str;

/**
 * API pubblica dei profili (JSON): directory con filtri/paginazione e dettaglio per handle.
 * Riusa gli stessi repository della vista web (SEO) → nessuna duplicazione di query/logica.
 */
final class ProfileController extends ApiController
{
    private const PER_PAGE     = 24;
    private const PER_PAGE_MAX = 50;
    private const SEARCH_MAX   = 80;

    /** GET /profiles — directory: ?tipo= &sport= &q= &pagina= &per_page= */
    public function index(Request $request): void
    {
        $profiles   = new ProfileRepository();
        $sportsRepo = new SportRepository();

        // Filtri whitelisted: solo tipi/sport esistenti hanno effetto.
        $typeKeys = $profiles->activeTypeKeys();
        $typeKey  = (string) $request->input('tipo', '');
        $typeKey  = in_array($typeKey, $typeKeys, true) ? $typeKey : '';

        $sportSlug = (string) $request->input('sport', '');
        $sportId   = $sportSlug !== '' ? $sportsRepo->idBySlug($sportSlug) : null;
        if ($sportId === null) {
            $sportSlug = '';
        }

        $search = Str::clamp(trim((string) $request->input('q', '')), self::SEARCH_MAX);

        $pg     = Pagination::fromRequest($request, self::PER_PAGE, self::PER_PAGE_MAX);
        $result = $profiles->listPublic($pg->page, $pg->perPage, $typeKey ?: null, $sportId, $search ?: null);

        Response::json(
            array_map([ProfilePresenter::class, 'card'], $result['items']),
            200,
            $pg->meta($result['total'], [
                'filters' => ['tipo' => $typeKey, 'sport' => $sportSlug, 'q' => $search],
            ])
        );
    }

    /**
     * GET /profiles/{handle} — dettaglio pubblico completo (parità di contenuto con la pagina web).
     * Stesso read-model della vista `atleti/show` via ProfilePageService: community, competenze+endorsement,
     * affiliazioni type-aware, post, e — solo per chi gestisce la pagina — insight proprietari.
     * Il visitatore si risolve dal token Bearer (o anonimo): mai dalla sessione cookie → nessuna CSRF.
     */
    public function show(Request $request): void
    {
        $handle  = (string) $request->param('handle', '');
        // findPublicByHandle filtra visibility='public' → profili privati/riservati = 404, come il web.
        $profile = $handle !== '' ? (new ProfileRepository())->findPublicByHandle($handle) : null;

        if ($profile === null) {
            Response::error(I18n::t('atleti.show.not_found_title'), 404);
            return;
        }

        $viewer = CurrentUser::fromBearer($request);

        // M4 · analytics d'uso "apre profilo" (parità col web). Attore dal Bearer già risolto; API
        // stateless → nessun anon_id. Fail-safe (non lancia mai). La ricerca via API (/profiles?q=)
        // NON è instrumentata: endpoint pubblico ad alto traffico, attore non risolto → basso segnale.
        \Spoome\Domain\Analytics\AnalyticsService::profileOpen($viewer?->id, (int) $profile['id']);

        Response::json((new ProfilePageService())->apiModel($profile, $viewer?->id));
    }

    /* ---------------------------------------------------------- FOLLOW ---- */

    /** POST /profiles/{handle}/follow — l'utente Bearer segue il profilo. */
    public function follow(Request $request): void
    {
        $this->followAction($request, true);
    }

    /** DELETE /profiles/{handle}/follow — l'utente Bearer smette di seguire. */
    public function unfollow(Request $request): void
    {
        $this->followAction($request, false);
    }

    private function followAction(Request $request, bool $follow): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $repo   = new ProfileRepository();
        // Split: il follow parte sempre dal profilo PERSONALE (le pagine non seguono).
        $actor  = (new ActingContext())->personalProfile($user);
        $target = $this->resolveTargetOr404($request, $repo);
        if ($target === null) {
            return;
        }
        if ($actor === null) {
            Response::error(I18n::t('atleti.show.not_found_title'), 404);
            return;
        }
        $svc = new FollowService();
        $this->emitJson($follow
            ? $svc->follow($actor->id, $target->id, $target->visibility, $target->displayName, $request->ip())
            : $svc->unfollow($actor->id, $target->id, $request->ip()));
    }

    /** GET /profiles/{handle}/followers — profili che seguono. */
    public function followers(Request $request): void
    {
        $this->followList($request, true);
    }

    /** GET /profiles/{handle}/following — profili seguiti. */
    public function following(Request $request): void
    {
        $this->followList($request, false);
    }

    private function followList(Request $request, bool $followers): void
    {
        $repo   = new ProfileRepository();
        $target = $this->resolveTargetOr404($request, $repo);
        if ($target === null) {
            return;
        }
        $pg     = Pagination::fromRequest($request, self::PER_PAGE, self::PER_PAGE_MAX);
        $result = $followers ? $repo->followersOf($target->id, $pg->page, $pg->perPage) : $repo->followingOf($target->id, $pg->page, $pg->perPage);

        Response::json(
            array_map([ProfilePresenter::class, 'card'], $result['items']),
            200,
            $pg->meta($result['total'])
        );
    }

    /* ------------------------------------------------------ CONNESSIONI ---- */

    /** POST /profiles/{handle}/connection — richiedi-o-accetta connessione (Bearer). */
    public function connect(Request $request): void
    {
        $this->connectionAction($request, true);
    }

    /** DELETE /profiles/{handle}/connection — annulla/rifiuta/rimuovi (Bearer). */
    public function disconnect(Request $request): void
    {
        $this->connectionAction($request, false);
    }

    private function connectionAction(Request $request, bool $connect): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $repo   = new ProfileRepository();
        // Split: le connessioni sono sempre del profilo PERSONALE (le pagine non connettono).
        $actor  = (new ActingContext())->personalProfile($user);
        $target = $this->resolveTargetOr404($request, $repo);
        if ($target === null) {
            return;
        }
        if ($actor === null) {
            Response::error(I18n::t('atleti.show.not_found_title'), 404);
            return;
        }
        $svc = new ConnectionService();
        $this->emitJson($connect
            ? $svc->connect($actor->id, $target->id, $target->visibility, $target->displayName, $request->ip())
            : $svc->disconnect($actor->id, $target->id, $request->ip()));
    }
}
