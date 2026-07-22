<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Opportunities\OpportunityRepository;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Sports\SportRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Onboarding beachhead (R-Moat M5, issue #45): 3 step non generici per Atleta e per Società/Federazione,
 * pensati per portare l'utente dentro Opportunities (M2, live) il prima possibile con fiducia (M3, live).
 *
 * Design di Bianca (UX). Decisioni di scope dell'orchestratore per QUESTA prima iterazione:
 *  - Step 1 atleta ("sei già nel network?") NON fa una vera ricerca per similarità/dedup (non esiste,
 *    è un follow-up): rimanda alla ricerca generale già esistente (/atleti) con la query precompilata.
 *  - Step 2 società (gate di verifica) NON offre richiesta di verifica self-serve (non esiste, resta
 *    admin-manuale da /admin/profili): mostra solo il gate + la guida a cosa aspettarsi.
 *  - Nessuna nuova view per la pubblicazione: lo step 3 società rimanda al form ESISTENTE
 *    /opportunita/pubblica con prefill via query string (sport/regione/città della pagina).
 *
 * Solo GET: ogni step scrive attraverso rotte web ESISTENTI (POST /profilo, POST /opportunita), mai
 * endpoint nuovi — così l'authz/CSRF/validazione restano quelle già in produzione, senza duplicazioni.
 */
final class OnboardingController extends Controller
{
    /* ------------------------------------------------------------- ATLETA ---- */

    /**
     * GET /onboarding/atleta — step 1/3: promemoria dedup contro profili NON rivendicati.
     *
     * TODO (fuori scope M5, decisione dell'orchestratore): questa NON è una vera ricerca per similarità
     * (fuzzy/typo-tolerant, con merge dei due profili) — è un follow-up dichiarato. Qui si riusa TALE E
     * QUALE la ricerca generale già esistente (ProfileRepository::listPublic, la stessa di /atleti),
     * interrogata con il nome appena scelto in registrazione e filtrata ai soli `claim_status=unclaimed`.
     * Zero query/logica nuova. Il CTA "È il mio" porta al profilo pubblico del candidato, dove il bottone
     * di rivendicazione ESISTENTE (claim.panel.button → POST /atleti/{handle}/rivendica) fa il resto.
     */
    public function athleteStep1(Request $request): void
    {
        $profile = $this->actingProfile($request);
        if ($profile === null) {
            Response::redirect('rivendicazioni');
            return;
        }

        $candidates = [];
        $searchFailed = false;
        $name = trim((string) $profile['display_name']);
        if ($name !== '') {
            try {
                $res = (new ProfileRepository())->listPublic(1, 5, null, null, $name, false);
                foreach ($res['items'] as $row) {
                    if (($row['claim_status'] ?? 'claimed') === 'unclaimed' && (int) $row['id'] !== (int) $profile['id']) {
                        $candidates[] = $row;
                    }
                }
                $candidates = array_slice($candidates, 0, 2);
            } catch (\Throwable $e) {
                // Fail-open (spec Bianca): un errore di ricerca non deve bloccare l'onboarding.
                $searchFailed = true;
            }
        }

        View::render('onboarding/athlete-1', [
            'title'        => $this->title('onboard.athlete.dedup.title'),
            'profile'      => $profile,
            'candidates'   => $candidates,
            'searchFailed' => $searchFailed,
        ], 'base');
    }

    /** GET /onboarding/atleta/profilo — step 2/3: completa città (+foto facoltativa) o stato rivendicazione. */
    public function athleteStep2(Request $request): void
    {
        $profile = $this->actingProfile($request);
        if ($profile === null) {
            Response::redirect('rivendicazioni');
            return;
        }
        $user = CurrentUser::resolve($request);

        // Rivendicazione in corso su QUESTO profilo? (claim_status resta 'unclaimed' finché l'admin non
        // approva). In tal caso non forziamo il campo città (non è ancora "suo"): solo lo stato + il
        // proseguimento facoltativo, come da spec.
        $claimPending = ($profile['claim_status'] ?? 'claimed') === 'unclaimed'
            && $user !== null
            && (new \Spoome\Domain\Claims\ClaimRepository())->pendingFor((int) $profile['id'], $user->id) !== null;

        View::render('onboarding/athlete-2', [
            'title'        => $this->title('onboard.athlete.complete.title'),
            'profile'      => $profile,
            'claimPending' => $claimPending,
        ], 'base');
    }

    /** GET /onboarding/atleta/opportunita — step 3/3: opportunità pre-filtrate dal profilo (embedded, no link). */
    public function athleteStep3(Request $request): void
    {
        $profile = $this->actingProfile($request);
        if ($profile === null) {
            Response::redirect('rivendicazioni');
            return;
        }

        $sportId = $profile['sport_id'] !== null ? (int) $profile['sport_id'] : null;
        $region  = trim((string) ($profile['location_region'] ?? '')) ?: null;

        $items = [];
        if ($sportId !== null) {
            $res   = (new OpportunityRepository())->listPublic($sportId, $region, 1, 5);
            $items = $res['items'];
        }

        View::render('onboarding/athlete-3', [
            'title'   => $this->title('onboard.athlete.opps.title'),
            'profile' => $profile,
            'items'   => $items,
        ], 'base');
    }

    /* ---------------------------------------------------------- SOCIETÀ ---- */

    /** GET /onboarding/societa — step 1/3: disciplina presidiata + città/sede + logo. */
    public function orgStep1(Request $request): void
    {
        $profile = $this->actingProfile($request);
        if ($profile === null || empty($profile['is_organization'])) {
            Response::redirect('profilo');
            return;
        }

        View::render('onboarding/org-1', [
            'title'   => $this->title('onboard.org.setup.title'),
            'profile' => $profile,
            'sports'  => (new SportRepository())->all(),
        ], 'base');
    }

    /**
     * GET /onboarding/societa/verifica — step 2/3: gate di pubblicazione.
     * Verificata → conferma breve + CTA verso lo step 3 (form pubblicazione, prefillato).
     * Non verificata → guida chiara: la verifica resta admin-manuale (TODO self-serve, vedi §04 di Bianca).
     */
    public function orgStep2(Request $request): void
    {
        $profile = $this->actingProfile($request);
        if ($profile === null || empty($profile['is_organization'])) {
            Response::redirect('profilo');
            return;
        }

        $publishUrl = url('opportunita/pubblica') . '?' . http_build_query(array_filter([
            'sport_id' => $profile['sport_id'] ?? null,
            'region'   => $profile['location_region'] ?? null,
            'city'     => $profile['location_city'] ?? null,
        ]));

        View::render('onboarding/org-2', [
            'title'      => $this->title('onboard.org.verify.title'),
            'profile'    => $profile,
            'verified'   => !empty($profile['verified_at']),
            'publishUrl' => $publishUrl,
        ], 'base');
    }

    /* ------------------------------------------------------------ helpers ---- */

    /** Profilo (arricchito) su cui l'utente sta agendo adesso, o null se claimant senza profilo. */
    private function actingProfile(Request $request): ?array
    {
        $user = CurrentUser::resolve($request);
        if ($user === null) {
            return null;
        }
        $pid = (new ActingContext())->resolve($request, $user);
        return $pid !== null ? (new ProfileRepository())->findEnrichedById($pid) : null;
    }
}
