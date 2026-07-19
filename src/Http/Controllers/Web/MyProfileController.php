<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Core\Session;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\ProfileDetailsRepository;
use Spoome\Domain\Profiles\ProfileDetailsService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Profiles\ProfileService;
use Spoome\Domain\Profiles\ProfileViewRepository;
use Spoome\Domain\Profiles\RecommendationService;
use Spoome\Domain\Profiles\SkillRepository;
use Spoome\Domain\Sports\SportRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Area autenticata: editor del proprio profilo (`/profilo`).
 * Rotte protette da AuthMiddleware + CSRF. La logica (validazione, handle, sport, salvataggio)
 * vive in ProfileService: qui si adatta solo l'input HTML e si rende la vista.
 */
final class MyProfileController extends Controller
{
    /**
     * `/profilo` = VISTA del proprio profilo. Stile Instagram: il tuo profilo È la tua pagina pubblica,
     * quindi si redirige al path canonico tipizzato dell'acting profile. Lì la stessa vista pubblica
     * mostra le affordance da proprietario (Modifica, insight visite, i tuoi post) a chi può gestirla.
     */
    public function show(Request $request): void
    {
        $user     = CurrentUser::resolve($request);
        $actingId = (new ActingContext())->resolve($request, $user);
        $profile  = $actingId !== null ? (new ProfileRepository())->findEnrichedById($actingId) : null;

        if ($profile === null) {
            Response::redirect('rivendicazioni');
            return;
        }

        Response::redirect(profile_path($profile));
    }

    public function edit(Request $request): void
    {
        $user     = CurrentUser::resolve($request);
        // Multi-profilo: si modifica l'ACTING profile (personale o la pagina su cui si sta agendo).
        $actingId = (new ActingContext())->resolve($request, $user);
        $profile  = $actingId !== null ? (new ProfileRepository())->findEnrichedById($actingId) : null;

        // Utente "claimant" senza profilo: indirizzalo alla rivendicazione.
        if ($profile === null) {
            Response::redirect('rivendicazioni');
            return;
        }

        $this->renderForm($profile, $profile, null, Session::takeFlash());
    }

    public function update(Request $request): void
    {
        $user = CurrentUser::resolve($request);
        $repo = new ProfileRepository();
        $ctx  = new ActingContext();

        // Authz multi-profilo: si modifica l'acting profile, con almeno ruolo 'editor'. Un profilo
        // dichiarato ma non gestibile → 403 (mai fallback silenzioso sul personale).
        $actingId = $ctx->resolveForWrite($request, $user, 'editor');
        if ($actingId === null) {
            if ($ctx->personalProfileId($user) !== null) {
                $this->respond($request, ServiceResult::fail(I18n::t('act.error.forbidden'), 403), 'profilo');
            } else {
                Response::redirect('rivendicazioni');
            }
            return;
        }
        $profile = $repo->findEnrichedById($actingId);
        if ($profile === null) { // difensivo
            Response::redirect('');
            return;
        }

        $data   = $request->body();
        $result = (new ProfileService($repo))->update((int) $profile['id'], $data);

        // Ramo async: envelope JSON (successo {saved:true} · errore con fields). Il ramo no-JS resta
        // sotto e mantiene il re-render del form coi valori inviati (eccezione consentita, vedi spec §2.3).
        if ($request->wantsJson()) {
            $this->respond(
                $request,
                $result->ok ? ServiceResult::ok(['saved' => true]) : $result,
                'profilo',
                I18n::t('profile.flash.saved')
            );
            return;
        }

        if (!$result->ok) {
            // Ripopola il form con i valori inviati (merge sui dati correnti).
            $merged = array_merge($profile, [
                'display_name'     => $data['display_name'] ?? $profile['display_name'],
                'handle'           => $data['handle'] ?? $profile['handle'],
                'headline'         => $data['headline'] ?? $profile['headline'],
                'bio'              => $data['bio'] ?? $profile['bio'],
                'sport_slug'       => trim((string) ($data['sport'] ?? '')),
                'location_city'    => $data['location_city'] ?? $profile['location_city'],
                'location_region'  => $data['location_region'] ?? $profile['location_region'],
                'location_country' => $data['location_country'] ?? $profile['location_country'],
                'visibility'       => $data['visibility'] ?? $profile['visibility'],
            ]);
            $this->renderForm($profile, $merged, $result->error, null);
            return;
        }

        Session::flash(I18n::t('profile.flash.saved'), 'success');
        Response::redirect('profilo');
    }

    /* ------------------------------------------------------------ helpers ---- */

    private function renderForm(?array $profile, ?array $values, ?string $error, ?array $notice): void
    {
        $details = new ProfileDetailsRepository();
        $pid = $profile !== null ? (int) $profile['id'] : 0;

        $experiences = $pid ? $details->experiences($pid) : [];
        $achievements = $pid ? $details->achievements($pid) : [];
        $links = $pid ? $details->links($pid) : [];
        $skills = $pid ? (new SkillRepository())->forProfile($pid) : [];

        // P2 affiliazioni: roster (org) o militanza (atleta) confermate + richieste in ingresso da confermare.
        $affRepo      = $pid ? new \Spoome\Domain\Profiles\AffiliationRepository() : null;
        $affRoster    = $affRepo ? $affRepo->rosterOf($pid) : [];
        $affMilitanza = $affRepo ? $affRepo->affiliationsOf($pid) : [];
        $affPending   = $affRepo ? $affRepo->pendingFor($pid) : [];
        $affOutgoing  = $affRepo ? $affRepo->pendingOutgoingFor($pid) : [];

        // Raccomandazioni ricevute (testimonial): pending da approvare + visibili (nascondibili). Solo per
        // le persone (v1 persona→persona): un'organizzazione non ne riceve, quindi liste vuote → sezione assente.
        $isOrgProfile = !empty($profile['is_organization']);
        $recoSvc      = new RecommendationService();
        $recoPending  = ($pid && !$isOrgProfile) ? $recoSvc->pendingFor($pid) : [];
        $recoVisible  = ($pid && !$isOrgProfile) ? $recoSvc->visibleFor($pid) : [];

        // F3 "Chi ha visto il tuo profilo": widget passivo, in chiaro. Trend normalizzato a 7 bucket
        // (0 sui giorni mancanti) qui nel controller, mai nella vista.
        $viewsRepo = new ProfileViewRepository();
        $recentViewers = $pid ? $viewsRepo->recentViewers($pid, 12) : [];
        $viewsCount7d  = $pid ? $viewsRepo->distinctViewers7d($pid) : 0;
        $viewsTrend    = $this->normalizeTrend7($pid ? $viewsRepo->dailyTrend7d($pid) : []);

        // Campi type-specific: definizioni dallo schema del tipo + valori correnti + sezioni abilitate.
        $schemaFields = \Spoome\Domain\Profiles\ProfileAttributes::fields($profile['attributes_schema'] ?? null);
        $attrValues   = \Spoome\Domain\Profiles\ProfileAttributes::values($profile['attributes'] ?? null);
        $sections     = \Spoome\Domain\Profiles\ProfileAttributes::sections(
            (string) ($profile['type_key'] ?? 'atleta'),
            !empty($profile['is_organization'])
        );

        View::render('profilo/edit', [
            'title'        => $this->title('profile.edit.title'),
            'profile'      => $profile,
            'v'            => $values ?? [],
            'sports'       => (new SportRepository())->all(),
            'error'        => $error,
            'notice'       => $notice,
            'visibilities' => ProfileService::VISIBILITIES,
            'experiences'  => $experiences,
            'achievements' => $achievements,
            'links'        => $links,
            'skills'       => $skills,
            'affRoster'    => $affRoster,
            'affMilitanza' => $affMilitanza,
            'affPending'   => $affPending,
            'affOutgoing'  => $affOutgoing,
            'recoPending'  => $recoPending,
            'recoVisible'  => $recoVisible,
            'linkKinds'    => ProfileDetailsService::LINK_KINDS,
            'schemaFields' => $schemaFields,
            'attrValues'   => $attrValues,
            'sections'     => $sections,
            'completeness' => $this->completeness($profile ?? [], $experiences, $links, $schemaFields, $attrValues),
            'recentViewers'   => $recentViewers,
            'viewsCount7d'    => $viewsCount7d,
            'viewsTrend'      => $viewsTrend,
        ], 'base');
    }

    /**
     * Normalizza il trend visite a 7 bucket ordinati (dal più vecchio a oggi), riempiendo di 0
     * i giorni senza dati. Alimenta la sparkline SVG. @param array<string,int> $rawMap 'Y-m-d' => count
     * @return int[] esattamente 7 valori
     */
    private function normalizeTrend7(array $rawMap): array
    {
        $out = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} day"));
            $out[] = (int) ($rawMap[$day] ?? 0);
        }
        return $out;
    }

    /**
     * Percentuale di completezza del profilo + campi mancanti (per suggerire cosa aggiungere).
     * Type-aware: gli atleti hanno il set CV; gli org i campi descrittivi del loro schema + logo/bio/sito;
     * i fan un set minimo. `missing` contiene già le etichette leggibili (i18n o label di schema).
     * @param array<int,array> $schemaFields definizioni type-specific
     * @param array<string,mixed> $attrValues valori correnti degli attributi
     * @return array{pct:int, missing:array<int,string>}
     */
    private function completeness(array $p, array $experiences, array $links, array $schemaFields = [], array $attrValues = []): array
    {
        $typeKey = (string) ($p['type_key'] ?? 'atleta');
        $isOrg   = !empty($p['is_organization']);

        $hasAvatar = !empty($p['avatar_path']);
        $hasBio    = trim((string) ($p['bio'] ?? '')) !== '';
        $hasLoc    = trim((string) ($p['location_city'] ?? '')) !== '';

        if ($typeKey === 'fan') {
            $checks = [
                I18n::t('profile.complete.f_avatar') => $hasAvatar,
                I18n::t('profile.complete.f_bio')    => $hasBio,
            ];
        } elseif ($isOrg) {
            $checks = [
                I18n::t('profile.complete.f_logo')     => $hasAvatar,
                I18n::t('profile.complete.f_bio')      => $hasBio,
                I18n::t('profile.complete.f_location') => $hasLoc,
            ];
            foreach ($schemaFields as $f) {
                $checks[$f['label']] = trim((string) ($attrValues[$f['key']] ?? '')) !== '';
            }
            $checks[I18n::t('profile.complete.f_link')] = $links !== [];
        } else { // atleta
            $checks = [
                I18n::t('profile.complete.f_avatar')     => $hasAvatar,
                I18n::t('profile.complete.f_headline')   => trim((string) ($p['headline'] ?? '')) !== '',
                I18n::t('profile.complete.f_bio')        => $hasBio,
                I18n::t('profile.complete.f_sport')      => !empty($p['sport_id']) || !empty($p['sport_name']),
                I18n::t('profile.complete.f_location')   => $hasLoc,
                I18n::t('profile.complete.f_experience') => $experiences !== [],
                I18n::t('profile.complete.f_link')       => $links !== [],
            ];
        }

        $done  = count(array_filter($checks));
        $total = count($checks);
        $missing = array_keys(array_filter($checks, static fn($v) => !$v));

        return [
            'pct'     => $total > 0 ? (int) round($done / $total * 100) : 100,
            'missing' => $missing,
        ];
    }
}
