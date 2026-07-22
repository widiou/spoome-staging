<?php

namespace Spoome\Domain\Profiles;

use Spoome\Core\Config;
use Spoome\Core\I18n;
use Spoome\Domain\Claims\ClaimRepository;
use Spoome\Domain\Connections\ConnectionRepository;
use Spoome\Domain\Connections\ConnectionService;
use Spoome\Domain\Feed\FeedService;
use Spoome\Domain\Follows\FollowRepository;

/**
 * Costruttore del read-model della pagina pubblica del profilo (`/atleti/{handle}` e canonici per tipo).
 *
 * Estratto da `Web\ProfileController::show`: raccoglie in un unico punto tutta la logica di dominio della
 * vista profilo — contesto community (follow/connessioni), competenze+endorsement, rivendicazione,
 * affiliazioni type-aware (roster società · militanza atleta), post del profilo, insight proprietario —
 * così che sia riusabile e testabile fuori dall'HTTP.
 *
 * NON tocca HTTP né sessione: riceve il profilo TARGET (array pubblico da ProfileRepository) e l'id utente
 * del visitatore (o null se anonimo) e ritorna un array puro di variabili per la view `atleti/show`.
 * Il controller resta sottile: risolve il target (handle → 404), gestisce il redirect canonico, poi passa
 * questo modello a `View::render` (aggiungendovi solo `notice`, che è flash di sessione).
 */
final class ProfilePageService
{
    private ProfileDetailsRepository $details;
    private FollowRepository $follows;
    private ConnectionRepository $connRepo;
    private ProfileRepository $profiles;
    private SkillRepository $skills;
    private AffiliationRepository $affiliations;
    private ProfileViewRepository $views;
    private ClaimRepository $claims;
    private ActingContext $acting;
    private ConnectionService $connections;
    private FeedService $feed;
    private RecommendationService $recos;

    public function __construct(
        ?ProfileDetailsRepository $details = null,
        ?FollowRepository $follows = null,
        ?ConnectionRepository $connRepo = null,
        ?ProfileRepository $profiles = null,
        ?SkillRepository $skills = null,
        ?AffiliationRepository $affiliations = null,
        ?ProfileViewRepository $views = null,
        ?ClaimRepository $claims = null,
        ?ActingContext $acting = null,
        ?ConnectionService $connections = null,
        ?FeedService $feed = null,
        ?RecommendationService $recos = null
    ) {
        $this->details      = $details ?? new ProfileDetailsRepository();
        $this->follows      = $follows ?? new FollowRepository();
        $this->connRepo     = $connRepo ?? new ConnectionRepository();
        $this->profiles     = $profiles ?? new ProfileRepository();
        $this->skills       = $skills ?? new SkillRepository();
        $this->affiliations = $affiliations ?? new AffiliationRepository();
        $this->views        = $views ?? new ProfileViewRepository();
        $this->claims       = $claims ?? new ClaimRepository();
        $this->acting       = $acting ?? new ActingContext();
        $this->connections  = $connections ?? new ConnectionService($this->connRepo);
        $this->feed         = $feed ?? new FeedService();
        $this->recos        = $recos ?? new RecommendationService();
    }

    /**
     * Costruisce il read-model della view web `atleti/show` (server-rendered, SEO).
     *
     * Decora il read-model "core" (dominio puro, condiviso con l'API — vedi {@see collect()}) con i soli
     * campi HTML/SEO della pagina: title, description, canonical, og:*. Nessuna logica di dominio qui:
     * tutto ciò che è dato vive in `collect()`, così web e API restano allineati senza duplicazioni.
     *
     * @param array    $profile      profilo TARGET pubblico (già risolto dal controller via findPublicByHandle)
     * @param int|null $viewerUserId id utente del visitatore (auth_id()), o null se anonimo
     * @return array   variabili per la view `atleti/show` (senza `notice`, che resta al controller)
     */
    public function buildFor(array $profile, ?int $viewerUserId): array
    {
        $c    = $this->collect($profile, $viewerUserId);
        $name = $c['name'];

        $descParts = \array_filter([
            $profile['headline'] ?? null,
            $profile['sport_name'] ?? null,
            $c['location'],
        ]);
        $ogImageRel = $profile['cover_path'] ?? ($profile['avatar_path'] ?? null);

        return [
            'title'        => $name . ' · ' . Config::appName(),
            'description'  => $descParts ? \implode(' · ', $descParts) : I18n::t('atleti.show.meta_fallback', ['name' => $name]),
            'p'            => $profile,
            'attributes'   => $c['attributes'],
            'sections'     => $c['sections'],
            'location'     => $c['location'],
            'canonical'    => Config::absoluteUrl($c['canonicalPath']),
            'ogType'       => 'profile',
            'ogImage'      => $ogImageRel ? Config::absoluteUrl((string) $ogImageRel) : null,
            'experiences'  => $c['experiences'],
            'achievements' => $c['achievements'],
            'links'        => $c['links'],
            'follow'       => $c['follow'],
            'connection'   => $c['connection'],
            'claim'        => $c['claim'],
            'skills'       => $c['skills'],
            'canEndorse'   => $c['canEndorse'],
            'endorsedIds'  => $c['endorsedIds'],
            'endorsers'    => $c['endorsers'],
            'recommendations' => $c['recommendations'],
            'canRecommend'    => $c['canRecommend'],
            'roster'       => $c['roster'],
            'militanza'    => $c['militanza'],
            'affPending'   => $c['affPending'],
            'canManageAff' => $c['canManageAff'],
            'clubVerified'  => $c['clubVerification']['club'],
            'clubVerifiers' => $c['clubVerification']['orgs'],
            'canManage'    => $c['canManage'],
            'profilePosts' => $c['profilePosts'],
            'myHandle'     => $c['myHandle'],
            'insights'     => $c['insights'],
        ];
    }

    /**
     * Read-model in forma API (JSON puro, envelope `{data}`): STESSA logica di dominio della pagina web
     * ({@see collect()}), serializzata da {@see ProfilePresenter::page()}. Nessun campo HTML/SEO, nessuna PII:
     * gli insight proprietari sono inclusi SOLO se il visitatore può gestire la pagina (stessa authz del web).
     *
     * @param array    $profile      profilo TARGET pubblico (già risolto dal controller via findPublicByHandle)
     * @param int|null $viewerUserId id utente del visitatore (CurrentUser::fromBearer), o null se anonimo
     * @return array   payload per `Response::json()` (data)
     */
    public function apiModel(array $profile, ?int $viewerUserId): array
    {
        return ProfilePresenter::page($this->collect($profile, $viewerUserId));
    }

    /**
     * Cuore condiviso web↔API: calcola una volta sola TUTTO lo stato di dominio della pagina profilo
     * (contesto community, competenze+endorsement, rivendicazione, affiliazioni type-aware, post, insight
     * proprietari). Ritorna dati puri; la decorazione view (SEO) e la serializzazione JSON sono a valle.
     *
     * @return array<string,mixed>
     */
    private function collect(array $profile, ?int $viewerUserId): array
    {
        $pid  = (int) $profile['id'];
        $name = (string) $profile['display_name'];
        $location = $this->locationLine($profile);

        // Contesto community: follow + connessioni, con lo stato del visitatore.
        // Ruolo del visitatore su QUESTO profilo, calcolato UNA SOLA volta (evita due canActAs = fino a 6 query
        // ridondanti per ogni vista). canManage = editor+ (barra Modifica/insight/no-follow-su-sé); admin+ per le affiliazioni.
        $myRole    = $viewerUserId !== null ? $this->acting->roleFor($viewerUserId, $pid) : null;
        $myRank    = $myRole !== null ? (['editor' => 1, 'admin' => 2, 'owner' => 3][$myRole] ?? 0) : 0;
        $canManage = $myRank >= 1;
        $isOwn = false;
        $isFollowing = false;
        $connStatus = ConnectionService::NONE;
        $viewerPid = null;
        $myHandle = '';
        if ($viewerUserId !== null) {
            // Identità del visitatore = profilo PERSONALE (endorse/connessione/visita sono azioni personali).
            // findByUserId è non-deterministico per chi possiede personale + pagine → prima il personale.
            $viewer = $this->profiles->personalOrAny($viewerUserId);
            if ($viewer !== null) {
                $viewerPid = $viewer->id;
                $myHandle  = $viewer->handle;
                $isOwn = $viewer->id === $pid;
                // Chi gestisce la pagina non segue/si connette a sé stesso né genera auto-visite.
                if (!$isOwn && !$canManage) {
                    $isFollowing = $this->follows->isFollowing($viewer->id, $pid);
                    $connStatus  = $this->connections->statusFrom($viewer->id, $pid);
                    // F3 "Chi ha visto il tuo profilo": registra la visita (viewer→viewed), passiva,
                    // solo per visitatori autenticati con profilo diversi dal proprietario.
                    // Soft-fail: la pagina profilo è un percorso SEO caldo — un errore qui NON deve dare 500.
                    try {
                        $this->views->record($viewer->id, $pid);
                    } catch (\Throwable $e) {
                        // silenzioso di proposito: la registrazione della visita è best-effort.
                    }
                }
            }
        }
        $follow = [
            'count_followers' => $this->follows->followerCount($pid),
            'count_following' => $this->follows->followingCount($pid),
            'authenticated'   => $viewerUserId !== null,
            'is_own'          => $isOwn,
            'is_following'    => $isFollowing,
            'can_follow'      => $viewerUserId !== null && !$isOwn && !$canManage,
        ];
        $connection = [
            'count'       => $this->connRepo->connectionCount($pid),
            'status'      => $connStatus,
            'can_connect' => $viewerUserId !== null && !$isOwn && !$canManage,
        ];

        // Competenze + endorsement. Il visitatore può endorsare solo se è una connessione ACCEPTED
        // (non è il proprietario). Gli id già endorsati alimentano lo stato dei bottoni.
        $skills      = $this->skills->forProfile($pid);
        $canEndorse  = $viewerPid !== null && !$isOwn && $connStatus === ConnectionService::CONNECTED;
        $endorsedIds = $canEndorse ? $this->skills->endorsedSkillIdsBy($viewerPid, $pid) : [];
        $endorsers   = $skills !== [] ? $this->skills->recentEndorsers($pid) : [];

        // Raccomandazioni (testimonial LinkedIn-style): SOLO le VISIBILI (approvate) sono pubbliche.
        // Il destinatario è sempre una persona (v1) → mai per le organizzazioni. Lettura non critica del
        // percorso SEO caldo: soft-fail a lista vuota (come views->record) per non trasformare un errore
        // di questa feature in un 500 dell'intera pagina profilo. Le PENDING NON stanno qui: si gestiscono
        // nell'editor (pendingFor). `canRecommend` = stessa condizione logica di canEndorse + destinatario persona.
        $recommendations = [];
        if (empty($profile['is_organization'])) {
            try {
                $recommendations = $this->recos->visibleFor($pid);
            } catch (\Throwable $e) {
                $recommendations = [];
            }
        }
        $canRecommend = $viewerPid !== null && !$isOwn
            && $connStatus === ConnectionService::CONNECTED
            && empty($profile['is_organization']);

        // Contesto rivendicazione: solo per i profili NON rivendicati (senza proprietario).
        $isUnclaimed = ($profile['claim_status'] ?? 'claimed') === 'unclaimed';
        $viewerHasProfile = $viewerUserId !== null && $this->profiles->userHasProfile($viewerUserId);
        $claimPending = false;
        if ($isUnclaimed && $viewerUserId !== null && !$viewerHasProfile) {
            $claimPending = $this->claims->pendingFor($pid, $viewerUserId) !== null;
        }
        $claim = [
            'is_unclaimed'  => $isUnclaimed,
            'authenticated' => $viewerUserId !== null,
            'has_profile'   => $viewerHasProfile,
            'can_request'   => $isUnclaimed && $viewerUserId !== null && !$viewerHasProfile && !$claimPending,
            'pending'       => $claimPending,
        ];

        // Campi type-specific + descrittore di sezioni per tipo (single source con l'editor).
        $schemaFields = ProfileAttributes::fields($profile['attributes_schema'] ?? null);
        $attributes   = ProfileAttributes::present($schemaFields, $profile['attributes'] ?? null);
        $sections     = ProfileAttributes::sections(
            (string) ($profile['type_key'] ?? 'atleta'),
            !empty($profile['is_organization'])
        );

        // P2 affiliazioni: roster (org) · militanza (atleta), sempre confermate. Le richieste in ingresso
        // e la gestione (conferma/rimozione) solo per chi può agire come questa pagina (ruolo ≥ admin).
        $roster       = !empty($sections['roster']) ? $this->affiliations->rosterOf($pid) : [];
        // `career` (militanza atleta) e `org_career` (affiliazioni di una società verso una federazione)
        // usano entrambe affiliationsOf($pid) = le affiliazioni di cui $pid è il membro.
        $militanza    = (!empty($sections['career']) || !empty($sections['org_career'])) ? $this->affiliations->affiliationsOf($pid) : [];
        $canManageAff = $myRank >= 2; // admin+ (riusa il ruolo già risolto, niente seconda canActAs)
        $affPending   = $canManageAff ? $this->affiliations->pendingFor($pid) : [];

        // M3 Verification-da-club: badge "verificato dalla società" DERIVATO — la sorgente di verità è
        // l'affiliazione confermata verso un'org essa stessa verificata (verifyingOrgsOf). Nessun flag
        // denormalizzato: la revoca è automatica e atomica al ritiro dell'affiliazione o alla de-verifica
        // dell'org (nessuna race di revoca). Precedenza allo staff badge (`verified_at`): se già verificato
        // dallo staff non duplichiamo il badge; le org-ancora restano comunque esposte in API (provenance).
        // Query unica indicizzata (idx_aff_member) e ristretta a QUESTO profilo — mai in un nav-helper.
        $staffVerified = !empty($profile['verified_at']);
        $verifyingOrgs = $this->affiliations->verifyingOrgsOf($pid);
        $clubVerification = [
            'staff' => $staffVerified,
            'club'  => !$staffVerified && $verifyingOrgs !== [],
            'orgs'  => $verifyingOrgs,
        ];

        // Sezione "Post" (stile Instagram): i contenuti del profilo, idratati come nel feed. Solo per
        // visitatori autenticati — i form like/commento richiedono sessione+CSRF (variante read-only anonima = follow-up).
        $profilePosts = ($viewerUserId !== null && $viewerPid !== null)
            ? $this->feed->postsOf($pid, $viewerPid, 12)
            : [];

        // Insight proprietario "Chi ha visto il profilo": in chiaro, ma solo per chi gestisce la pagina.
        $insights = null;
        if ($canManage) {
            $insights = [
                'views7d'       => $this->views->distinctViewers7d($pid),
                'recentViewers' => $this->views->recentViewers($pid, 8),
            ];
        }

        return [
            // Identità/target + campi condivisi con la decorazione SEO
            'profile'      => $profile,
            'name'         => $name,
            'location'     => $location,
            'canonicalPath' => profile_path($profile),
            'attributes'   => $attributes,
            'sections'     => $sections,
            'experiences'  => $this->details->experiences($pid),
            'achievements' => $this->details->achievements($pid),
            'links'        => $this->details->links($pid),
            // Stato visitatore su QUESTO profilo
            'isOwn'        => $isOwn,
            'canManage'    => $canManage,
            'myHandle'     => $myHandle,
            // Community
            'follow'       => $follow,
            'connection'   => $connection,
            'claim'        => $claim,
            // Competenze + endorsement
            'skills'       => $skills,
            'canEndorse'   => $canEndorse,
            'endorsedIds'  => $endorsedIds,
            'endorsers'    => $endorsers,
            // Raccomandazioni visibili (approvate) + se il visitatore può raccomandare
            'recommendations' => $recommendations,
            'canRecommend'    => $canRecommend,
            // Affiliazioni type-aware
            'roster'       => $roster,
            'militanza'    => $militanza,
            'affPending'   => $affPending,
            'canManageAff' => $canManageAff,
            'clubVerification' => $clubVerification,
            // Post + insight proprietari
            'profilePosts' => $profilePosts,
            'insights'     => $insights,
        ];
    }

    /** Riga località leggibile: "Città, Regione, Paese" senza segmenti vuoti. */
    private function locationLine(array $p): string
    {
        return \implode(', ', \array_filter([
            $p['location_city'] ?? null,
            $p['location_region'] ?? null,
            $p['location_country'] ?? null,
        ]));
    }
}
