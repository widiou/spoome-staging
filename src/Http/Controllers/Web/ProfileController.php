<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Pagination;
use Spoome\Core\Request;
use Spoome\Core\Session;
use Spoome\Core\View;
use Spoome\Support\Str;
use Spoome\Domain\Profiles\ProfilePageService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Sports\SportRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Directory pubblica dei profili (`/atleti`) e pagina pubblica del singolo profilo
 * (`/atleti/{handle}`). Server-rendered per la SEO; gli stessi dati sono esposti via API JSON.
 */
final class ProfileController extends Controller
{
    private const PER_PAGE = 24;

    /* ---------------------------------------------------------- DIRECTORY ---- */

    public function index(Request $request): void
    {
        $profiles = new ProfileRepository();
        $sportsRepo = new SportRepository();

        // Filtri dall'URL (whitelist: solo tipi/sport esistenti hanno effetto).
        $typeKeys  = $profiles->activeTypeKeys();
        $typeKey   = (string) $request->input('tipo', '');
        $typeKey   = \in_array($typeKey, $typeKeys, true) ? $typeKey : '';

        $sportSlug = (string) $request->input('sport', '');
        $sportId   = $sportSlug !== '' ? $sportsRepo->idBySlug($sportSlug) : null;
        if ($sportId === null) {
            $sportSlug = '';
        }

        $search = Str::clamp(trim((string) $request->input('q', '')), 80);

        $pg = Pagination::of((int) $request->input('pagina', 1), self::PER_PAGE);
        $page = $pg->page;

        // Discovery per tipo (stile "Società da seguire"): nella landing senza filtri, sezioni curate per ogni
        // tipo attivo così le organizzazioni — non solo gli atleti — vengono scoperte. È identica per tutti e
        // cambia lentamente → CACHATA (300s), con withCount=false (niente COUNT). Quando si filtra/cerca → griglia piatta.
        $isLanding = $typeKey === '' && $sportSlug === '' && $search === '' && $page === 1;
        $discovery = [];
        if ($isLanding) {
            $discovery = \Spoome\Core\Cache::remember('discovery_landing_v1', 300, static function () use ($profiles) {
                $out = [];
                foreach ($profiles->activeTypes() as $tp) {
                    $sec = $profiles->listPublic(1, 6, (string) $tp['key'], null, null, false);
                    if ($sec['items'] !== []) {
                        $out[] = [
                            'key'    => (string) $tp['key'],
                            'label'  => (string) $tp['label'],
                            'is_org' => !empty($tp['is_organization']),
                            'items'  => $sec['items'],
                            'total'  => 0,
                        ];
                    }
                }
                return $out;
            });
        }

        // Query flat SOLO se non-landing o landing senza discovery (fallback): evita il COUNT non filtrato
        // sull'intera tabella profili sulla pagina SEO più visitata.
        if (!$isLanding || $discovery === []) {
            $result = $profiles->listPublic($page, self::PER_PAGE, $typeKey ?: null, $sportId, $search ?: null);
        } else {
            $result = ['items' => [], 'total' => 0];
        }
        $total = $result['total'];
        $pages = $pg->pages($total);

        // M4 · analytics d'uso "chi cerca": solo ricerche reali (query non vuota) e SOLO alla prima
        // pagina → la paginazione della stessa ricerca non gonfia il conteggio. Fail-safe (non lancia
        // mai). Il typeahead `suggest()` NON è instrumentato di proposito (rumoroso).
        if ($search !== '' && $page === 1) {
            \Spoome\Domain\Analytics\AnalyticsService::search(auth_id(), (int) $total);
        }

        View::render('atleti/index', [
            'title'       => $this->title('atleti.index.title'),
            'description' => I18n::t('atleti.index.meta'),
            'items'       => $result['items'],
            'total'       => $total,
            'types'       => $profiles->activeTypes(),
            'sports'      => $sportsRepo->all(),
            'filterType'  => $typeKey,
            'filterSport' => $sportSlug,
            'filterQuery' => $search,
            'page'        => $page,
            'pages'       => $pages,
            'discovery'   => $discovery,
        ], 'base');
    }

    /**
     * Typeahead ricerca: JSON dei migliori match (nome/handle/sport). Pubblico, read-only.
     */
    public function suggest(Request $request): void
    {
        $q = trim((string) $request->input('q', ''));
        if (mb_strlen($q) < 2) {
            \Spoome\Core\Response::json([]);
            return;
        }
        $q = Str::clamp($q, 80);
        $res = (new ProfileRepository())->listPublic(1, 7, null, null, $q, false);
        $out = [];
        foreach ($res['items'] as $p) {
            $out[] = [
                'name'     => (string) $p['display_name'],
                'handle'   => (string) $p['handle'],
                'url'      => url(profile_path($p)),
                'type'     => (string) ($p['type_label'] ?? ''),
                'sport'    => $p['sport_name'] ?? null,
                'avatar'   => !empty($p['avatar_path']) ? url((string) $p['avatar_path']) : null,
                'initials' => initials((string) $p['display_name']),
                'verified' => !empty($p['verified_at']),
            ];
        }
        \Spoome\Core\Response::json($out);
    }

    /* ------------------------------------------------------ PAGINA PROFILO ---- */

    public function show(Request $request): void
    {
        $handle  = (string) $request->param('handle', '');
        $profile = $handle !== '' ? (new ProfileRepository())->findPublicByHandle($handle) : null;

        if ($profile === null) {
            \http_response_code(404);
            View::render('message', [
                'title'       => $this->title('atleti.show.not_found_title'),
                'heading'     => I18n::t('atleti.show.not_found_title'),
                'message'     => I18n::t('atleti.show.not_found_msg'),
                'type'        => 'error',
                'actionUrl'   => url('atleti'),
                'actionLabel' => I18n::t('atleti.show.back_to_directory'),
            ], 'base');
            return;
        }

        // URL canonico tipizzato (unica sede: profile_path). Se il percorso richiesto non è quello
        // canonico per il tipo del profilo (es. /atleti/{org} o /societa/{persona}), 301 al canonico.
        $canonicalPath = profile_path($profile);
        if (\ltrim($request->path, '/') !== $canonicalPath) {
            \Spoome\Core\Response::redirect($canonicalPath, 301);
            return;
        }

        // M4 · analytics d'uso "apre profilo": registrato DOPO il redirect canonico (niente doppio
        // conteggio) e su profilo confermato. Fail-safe (non lancia mai).
        \Spoome\Domain\Analytics\AnalyticsService::profileOpen(auth_id(), (int) $profile['id']);

        // Tutta la costruzione del read-model (community/claim/skills/roster/insights/posts, type-aware) vive
        // nel Service: il controller resta sottile e passa il visitatore (id utente di sessione) come dato.
        $view = (new ProfilePageService())->buildFor($profile, auth_id());
        $view['notice'] = Session::takeFlash(); // flash: unica dipendenza di sessione, resta al controller

        View::render('atleti/show', $view, 'base');
    }

    /* ------------------------------------------------ LISTE FOLLOW ---- */

    public function followers(Request $request): void
    {
        $this->followList($request, true);
    }

    public function following(Request $request): void
    {
        $this->followList($request, false);
    }

    private function followList(Request $request, bool $followers): void
    {
        $repo    = new ProfileRepository();
        $handle  = (string) $request->param('handle', '');
        $profile = $handle !== '' ? $repo->findPublicByHandle($handle) : null;

        if ($profile === null) {
            \http_response_code(404);
            View::render('message', [
                'title'       => $this->title('atleti.show.not_found_title'),
                'heading'     => I18n::t('atleti.show.not_found_title'),
                'message'     => I18n::t('atleti.show.not_found_msg'),
                'type'        => 'error',
                'actionUrl'   => url('atleti'),
                'actionLabel' => I18n::t('atleti.show.back_to_directory'),
            ], 'base');
            return;
        }

        $pid    = (int) $profile['id'];
        $pg     = Pagination::of((int) $request->input('pagina', 1), self::PER_PAGE);
        $result = $followers ? $repo->followersOf($pid, $pg->page, self::PER_PAGE) : $repo->followingOf($pid, $pg->page, self::PER_PAGE);
        $total  = $result['total'];
        $pages  = $pg->pages($total);
        $mode   = $followers ? 'followers' : 'following';

        View::render('atleti/follow-list', [
            'title'   => $this->title('follow.' . $mode) . ' · ' . (string) $profile['display_name'],
            'profile' => $profile,
            'mode'    => $mode,
            'items'   => $result['items'],
            'total'   => $total,
            'page'    => $pg->page,
            'pages'   => $pages,
        ], 'base');
    }
}
