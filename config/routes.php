<?php

/**
 * Registro delle rotte. Riceve $router (Spoome\Core\Router) dal front controller.
 * Web = HTML (SEO), API = JSON sotto Config::apiPrefix() (default /api/v1).
 *
 * @var \Spoome\Core\Router $router
 */

use Spoome\Core\Config;
use Spoome\Core\Csrf;
use Spoome\Core\Response;
use Spoome\Core\View;
use Spoome\Http\Controllers\Web\AuthController as WebAuth;
use Spoome\Http\Controllers\Web\ProfileController as WebProfile;
use Spoome\Http\Controllers\Web\MyProfileController as WebMyProfile;
use Spoome\Http\Controllers\Web\AvatarController as WebAvatar;
use Spoome\Http\Controllers\Web\FollowController as WebFollow;
use Spoome\Http\Controllers\Web\ConnectionController as WebConnection;
use Spoome\Http\Controllers\Web\NetworkController as WebNetwork;
use Spoome\Http\Controllers\Web\FeedController as WebFeed;
use Spoome\Http\Controllers\Web\LinkController as WebLink;
use Spoome\Http\Controllers\Web\MessagesController as WebMessages;
use Spoome\Http\Controllers\Web\PageController as WebPage;
use Spoome\Http\Controllers\Web\PageMemberController as WebPageMember;
use Spoome\Http\Controllers\Web\ProfileDetailsController as WebDetails;
use Spoome\Http\Controllers\Web\SkillController as WebSkill;
use Spoome\Http\Controllers\Web\AffiliationController as WebAffiliation;
use Spoome\Http\Controllers\Web\RecommendationController as WebReco;
use Spoome\Http\Controllers\Web\ClaimController as WebClaim;
use Spoome\Http\Controllers\Web\SeoController as WebSeo;
use Spoome\Http\Controllers\Web\Admin\DashboardController as AdminDashboard;
use Spoome\Http\Controllers\Web\Admin\AuthController as AdminAuth;
use Spoome\Http\Controllers\Web\Admin\UsersController as AdminUsers;
use Spoome\Http\Controllers\Web\Admin\StatsController as AdminStats;
use Spoome\Http\Controllers\Web\Admin\LogsController as AdminLogs;
use Spoome\Http\Controllers\Web\Admin\ModerationController as AdminMod;
use Spoome\Http\Controllers\Web\Admin\ClaimsController as AdminClaims;
use Spoome\Http\Controllers\Web\Admin\NewsController as AdminNews;
use Spoome\Http\Controllers\Api\V1\AuthController as ApiAuth;
use Spoome\Http\Controllers\Api\V1\ProfileController as ApiProfile;
use Spoome\Http\Controllers\Api\V1\MeController as ApiMe;
use Spoome\Http\Controllers\Api\V1\FeedController as ApiFeed;
use Spoome\Http\Controllers\Api\V1\MessagesController as ApiMessages;
use Spoome\Http\Controllers\Api\V1\SkillController as ApiSkill;
use Spoome\Http\Controllers\Api\V1\AffiliationController as ApiAffiliation;
use Spoome\Http\Controllers\Api\V1\RecommendationController as ApiReco;
use Spoome\Http\Controllers\Api\V1\SuggestionController as ApiSuggestion;
use Spoome\Http\Controllers\Api\V1\ClaimController as ApiClaim;
use Spoome\Http\Controllers\Api\V1\MediaController as ApiMedia;
use Spoome\Http\Controllers\Api\V1\PagesController as ApiPages;
use Spoome\Http\Controllers\Api\V1\LinkController as ApiLink;
use Spoome\Http\Controllers\Api\V1\StreamController as ApiStream;
use Spoome\Http\Controllers\Api\V1\DevicesController as ApiDevices;
use Spoome\Http\Middleware\AuthMiddleware;
use Spoome\Http\Middleware\GuestMiddleware;
use Spoome\Http\Middleware\AdminMiddleware;
use Spoome\Http\Middleware\StepUpMiddleware;

$api    = Config::apiPrefix();
$csrf   = [Csrf::class, 'verify'];
$guest  = [GuestMiddleware::class, 'handle'];
$auth   = [AuthMiddleware::class, 'handle'];
$admin  = [AdminMiddleware::class, 'handle'];
$stepup = [StepUpMiddleware::class, 'handle'];

/* ================================================================= WEB ==== */

$router->get('/', static function (): void {
    // L'utente autenticato entra nell'app (feed); il "claimant" senza profilo va alla rivendicazione
    // (evita il loop /→/feed→/ per chi non ha un profilo). Session::has() distingue "assente" (sessioni
    // pre-deploy → fallback query) da null (claimant senza profilo).
    if (auth_id() !== null) {
        $pid = \Spoome\Core\Session::has('profile_id')
            ? \Spoome\Core\Session::get('profile_id')
            : (new \Spoome\Domain\Profiles\ProfileRepository())->findByUserId((int) auth_id())?->id;
        Response::redirect($pid !== null ? 'feed' : 'rivendicazioni');
        return;
    }
    $recent = (new \Spoome\Domain\Profiles\ProfileRepository())->listPublic(1, 6);
    View::render('home', [
        'title'   => Config::appName() . ' — ' . \Spoome\Core\I18n::t('app.tagline'),
        'appName' => Config::appName(),
        'recent'  => $recent['items'],
    ]);
});

$router->get('/health', static function (): void {
    Response::html('OK ' . Config::appName() . ' [' . Config::appEnv() . ']');
});

// SEO
$router->get('/robots.txt', [WebSeo::class, 'robots']);
$router->get('/sitemap.xml', [WebSeo::class, 'sitemap']);

// Registrazione
$router->get('/registrati', [WebAuth::class, 'showRegister'], [$guest]);
$router->post('/registrati', [WebAuth::class, 'register'], [$guest, $csrf]);
// Registrazione "per rivendicare" (crea l'account senza profilo)
$router->get('/registrati/rivendica', [WebAuth::class, 'showRegisterClaim'], [$guest]);
$router->post('/registrati/rivendica', [WebAuth::class, 'registerClaim'], [$guest, $csrf]);

// Login / logout
$router->get('/accedi', [WebAuth::class, 'showLogin'], [$guest]);
$router->post('/accedi', [WebAuth::class, 'login'], [$guest, $csrf]);
$router->post('/esci', [WebAuth::class, 'logout'], [$csrf]);

// Verifica email
$router->get('/verifica', [WebAuth::class, 'verifyEmail']);

// Proprio profilo (area autenticata): `/profilo` = VISTA (redirect al path pubblico, stile Instagram),
// `/profilo/modifica` = editor. La scrittura resta su POST /profilo.
$router->get('/profilo', [WebMyProfile::class, 'show'], [$auth]);
$router->get('/profilo/modifica', [WebMyProfile::class, 'edit'], [$auth]);
$router->post('/profilo', [WebMyProfile::class, 'update'], [$auth, $csrf]);

// Pagine organizzazione + acting context ("agisci come")
$router->get('/pagine/nuova', [WebPage::class, 'newForm'], [$auth]);
$router->post('/pagine', [WebPage::class, 'create'], [$auth, $csrf]);
$router->post('/agisci-come', [WebPage::class, 'switchActing'], [$auth, $csrf]);

// Inviti a gestire una pagina — lato INVITATO (inbox + accetta/rifiuta). Rotte con segmento letterale
// `inviti`: registrate PRIMA di quelle scopate su {handle} per disambiguare senza ambiguità.
$router->get('/pagine/inviti', [WebPageMember::class, 'inbox'], [$auth]);
$router->post('/pagine/inviti/{inviteId}/accetta', [WebPageMember::class, 'accept'], [$auth, $csrf]);
$router->post('/pagine/inviti/{inviteId}/rifiuta', [WebPageMember::class, 'decline'], [$auth, $csrf]);
$router->post('/pagine/inviti/{inviteId}/revoca', [WebPageMember::class, 'revokeInvite'], [$auth, $csrf]);

// Gestione MEMBRI di una pagina org — lato PAGINA (owner/admin; authz nel PageMemberService).
$router->get('/pagine/{handle}/membri', [WebPageMember::class, 'manage'], [$auth]);
$router->post('/pagine/{handle}/membri/invita', [WebPageMember::class, 'invite'], [$auth, $csrf]);
$router->post('/pagine/{handle}/membri/{userId}/ruolo', [WebPageMember::class, 'changeRole'], [$auth, $csrf]);
$router->post('/pagine/{handle}/membri/{userId}/rimuovi', [WebPageMember::class, 'removeMember'], [$auth, $csrf]);

// Avatar + copertina (AJAX JSON)
$router->post('/profilo/avatar', [WebAvatar::class, 'upload'], [$auth, $csrf]);
$router->post('/profilo/avatar/elimina', [WebAvatar::class, 'delete'], [$auth, $csrf]);
$router->post('/profilo/cover', [WebAvatar::class, 'uploadCover'], [$auth, $csrf]);
$router->post('/profilo/cover/elimina', [WebAvatar::class, 'deleteCover'], [$auth, $csrf]);

// Sotto-entità del profilo (esperienze, palmarès, link)
$router->post('/profilo/esperienze', [WebDetails::class, 'addExperience'], [$auth, $csrf]);
$router->post('/profilo/esperienze/{id}', [WebDetails::class, 'updateExperience'], [$auth, $csrf]);
$router->post('/profilo/esperienze/{id}/elimina', [WebDetails::class, 'deleteExperience'], [$auth, $csrf]);
$router->post('/profilo/palmares', [WebDetails::class, 'addAchievement'], [$auth, $csrf]);
$router->post('/profilo/palmares/{id}', [WebDetails::class, 'updateAchievement'], [$auth, $csrf]);
$router->post('/profilo/palmares/{id}/elimina', [WebDetails::class, 'deleteAchievement'], [$auth, $csrf]);
$router->post('/profilo/link', [WebDetails::class, 'addLink'], [$auth, $csrf]);
$router->post('/profilo/link/{id}', [WebDetails::class, 'updateLink'], [$auth, $csrf]);
$router->post('/profilo/link/{id}/elimina', [WebDetails::class, 'deleteLink'], [$auth, $csrf]);

// Competenze proprie (editor)
$router->post('/profilo/competenze', [WebSkill::class, 'add'], [$auth, $csrf]);
$router->post('/profilo/competenze/{id}/elimina', [WebSkill::class, 'delete'], [$auth, $csrf]);
$router->post('/profilo/competenze/riordina', [WebSkill::class, 'reorder'], [$auth, $csrf]);

// Affiliazioni atleta↔organizzazione (roster/militanza): richiesta + conferma bilaterale (autenticate, CSRF)
$router->post('/profilo/affiliazioni', [WebAffiliation::class, 'request'], [$auth, $csrf]);
$router->post('/profilo/affiliazioni/{id}/conferma', [WebAffiliation::class, 'confirm'], [$auth, $csrf]);
$router->post('/profilo/affiliazioni/{id}/rifiuta', [WebAffiliation::class, 'reject'], [$auth, $csrf]);
$router->post('/profilo/affiliazioni/{id}/elimina', [WebAffiliation::class, 'remove'], [$auth, $csrf]);

// Raccomandazioni ricevute (gestione nell'editor): accetta (pubblica) / nascondi (rifiuta o oscura)
$router->post('/profilo/raccomandazioni/{id}/accetta', [WebReco::class, 'accept'], [$auth, $csrf]);
$router->post('/profilo/raccomandazioni/{id}/nascondi', [WebReco::class, 'hide'], [$auth, $csrf]);

// Directory profili + pagina pubblica del singolo profilo (SEO server-rendered)
$router->get('/cerca/suggerimenti', [WebProfile::class, 'suggest']); // typeahead ricerca (JSON)
$router->get('/atleti', [WebProfile::class, 'index']);
$router->get('/atleti/{handle}', [WebProfile::class, 'show']);
// URL canonici TIPIZZATI per le organizzazioni (analogo /company vs /in di LinkedIn). Stesso
// controller: `show` calcola il path canonico dal type e fa 301 se il percorso non è quello.
// Gli handle sono globalmente unici → nessuna ambiguità. Le persone restano su /atleti.
$router->get('/societa/{handle}', [WebProfile::class, 'show']);
$router->get('/associazione/{handle}', [WebProfile::class, 'show']);
$router->get('/federazione/{handle}', [WebProfile::class, 'show']);

// Follow: liste (pubbliche) + azioni segui/non-segui (autenticate, CSRF)
$router->get('/atleti/{handle}/follower', [WebProfile::class, 'followers']);
$router->get('/atleti/{handle}/seguiti', [WebProfile::class, 'following']);
$router->post('/atleti/{handle}/segui', [WebFollow::class, 'follow'], [$auth, $csrf]);
$router->post('/atleti/{handle}/nonseguire', [WebFollow::class, 'unfollow'], [$auth, $csrf]);

// Connessioni: azioni (autenticate, CSRF) + pagina Rete
$router->post('/atleti/{handle}/connetti', [WebConnection::class, 'connect'], [$auth, $csrf]);
$router->post('/atleti/{handle}/disconnetti', [WebConnection::class, 'disconnect'], [$auth, $csrf]);
$router->get('/rete', [WebNetwork::class, 'index'], [$auth]);
$router->post('/rete/suggerimenti/{handle}/ignora', [WebNetwork::class, 'dismissSuggestion'], [$auth, $csrf]);

// Endorsement competenze (dal profilo pubblico altrui)
$router->post('/atleti/{handle}/competenze/{id}/endorsa', [WebSkill::class, 'endorse'], [$auth, $csrf]);
$router->post('/atleti/{handle}/competenze/{id}/rimuovi', [WebSkill::class, 'removeEndorse'], [$auth, $csrf]);

// Raccomandazione: scrittura dal profilo pubblico di un connesso (autenticata, CSRF)
$router->post('/atleti/{handle}/raccomanda', [WebReco::class, 'write'], [$auth, $csrf]);

// Rivendicazione profilo (lato utente)
$router->post('/atleti/{handle}/rivendica', [WebClaim::class, 'request'], [$auth, $csrf]);
$router->get('/rivendicazioni', [WebClaim::class, 'mine'], [$auth]);

// Notifiche in-app
$router->get('/notifiche', [\Spoome\Http\Controllers\Web\NotificationController::class, 'index'], [$auth]);

// Feed (area autenticata)
$router->get('/feed', [WebFeed::class, 'index'], [$auth]);
$router->post('/feed/post', [WebFeed::class, 'createPost'], [$auth, $csrf]);
$router->post('/feed/unfurl', [WebLink::class, 'unfurl'], [$auth, $csrf]);
// Image-proxy anteprime link (same-origin, gate = firma HMAC nel token `u`; nessun auth per la cache).
$router->get('/link-image', [WebLink::class, 'image']);
$router->post('/feed/post/{id}/elimina', [WebFeed::class, 'deletePost'], [$auth, $csrf]);
$router->post('/feed/post/{id}/like', [WebFeed::class, 'like'], [$auth, $csrf]);
$router->post('/feed/post/{id}/commenta', [WebFeed::class, 'comment'], [$auth, $csrf]);
$router->post('/feed/commento/{id}/elimina', [WebFeed::class, 'deleteComment'], [$auth, $csrf]);

// Messaggi diretti (area autenticata; solo tra profili connessi — imposto nei service)
$router->get('/messaggi', [WebMessages::class, 'inbox'], [$auth]);
$router->get('/messaggi/{handle}/nuovi', [WebMessages::class, 'poll'], [$auth]);
$router->get('/messaggi/{handle}', [WebMessages::class, 'thread'], [$auth]);
$router->post('/messaggi/{handle}', [WebMessages::class, 'send'], [$auth, $csrf]);

// Recupero / reset password
$router->get('/recupera-password', [WebAuth::class, 'showForgot'], [$guest]);
$router->post('/recupera-password', [WebAuth::class, 'forgot'], [$guest, $csrf]);
$router->get('/reimposta', [WebAuth::class, 'showReset'], [$guest]);
$router->post('/reimposta', [WebAuth::class, 'reset'], [$guest, $csrf]);

/* =============================================================== ADMIN ==== */
// Area riservata. Catena: autenticato → admin (404 se non lo sei) → step-up (re-auth password).
// La verifica step-up gira SENZA $stepup (altrimenti si auto-bloccherebbe).
$router->get('/admin/verifica', [AdminAuth::class, 'show'], [$auth, $admin]);
$router->post('/admin/verifica', [AdminAuth::class, 'verify'], [$auth, $admin, $csrf]);

$router->get('/admin', [AdminDashboard::class, 'index'], [$auth, $admin, $stepup]);

// Statistiche
$router->get('/admin/statistiche', [AdminStats::class, 'index'], [$auth, $admin, $stepup]);

// Utenti
$router->get('/admin/utenti', [AdminUsers::class, 'index'], [$auth, $admin, $stepup]);
$router->get('/admin/utenti/{id}', [AdminUsers::class, 'show'], [$auth, $admin, $stepup]);
$router->post('/admin/utenti/{id}/sospendi', [AdminUsers::class, 'suspend'], [$auth, $admin, $stepup, $csrf]);
$router->post('/admin/utenti/{id}/riattiva', [AdminUsers::class, 'reactivate'], [$auth, $admin, $stepup, $csrf]);
$router->post('/admin/utenti/{id}/verifica', [AdminUsers::class, 'verifyEmail'], [$auth, $admin, $stepup, $csrf]);
$router->post('/admin/utenti/{id}/ruolo', [AdminUsers::class, 'changeRole'], [$auth, $admin, $stepup, $csrf]);
$router->post('/admin/utenti/{id}/verifica-profilo', [AdminUsers::class, 'verifyProfile'], [$auth, $admin, $stepup, $csrf]);

// Rivendicazioni (moderazione claim + creazione profili non rivendicati)
$router->get('/admin/rivendicazioni', [AdminClaims::class, 'index'], [$auth, $admin, $stepup]);
$router->get('/admin/rivendicazioni/nuovo', [AdminClaims::class, 'newProfile'], [$auth, $admin, $stepup]);
$router->post('/admin/rivendicazioni/nuovo', [AdminClaims::class, 'createProfile'], [$auth, $admin, $stepup, $csrf]);
$router->post('/admin/rivendicazioni/{id}/approva', [AdminClaims::class, 'approve'], [$auth, $admin, $stepup, $csrf]);
$router->post('/admin/rivendicazioni/{id}/rifiuta', [AdminClaims::class, 'reject'], [$auth, $admin, $stepup, $csrf]);

// Moderazione contenuti
$router->get('/admin/contenuti', [AdminMod::class, 'index'], [$auth, $admin, $stepup]);
$router->post('/admin/contenuti/{id}/elimina', [AdminMod::class, 'deletePost'], [$auth, $admin, $stepup, $csrf]);

// News di settore: fonti RSS (elenco, CRUD, attiva/disattiva, intervallo, ingestione manuale)
$router->get('/admin/news', [AdminNews::class, 'index'], [$auth, $admin, $stepup]);
$router->post('/admin/news', [AdminNews::class, 'create'], [$auth, $admin, $stepup, $csrf]);
$router->post('/admin/news/aggiorna', [AdminNews::class, 'fetch'], [$auth, $admin, $stepup, $csrf]);
$router->post('/admin/news/{id}', [AdminNews::class, 'update'], [$auth, $admin, $stepup, $csrf]);
$router->post('/admin/news/{id}/attiva', [AdminNews::class, 'toggle'], [$auth, $admin, $stepup, $csrf]);
$router->post('/admin/news/{id}/elimina', [AdminNews::class, 'delete'], [$auth, $admin, $stepup, $csrf]);

// Log & salute
$router->get('/admin/log', [AdminLogs::class, 'index'], [$auth, $admin, $stepup]);
$router->get('/admin/log/{fp}', [AdminLogs::class, 'show'], [$auth, $admin, $stepup]);

/* ================================================================= API ==== */

$router->get($api . '/ping', static fn () => Response::json(['pong' => true]));
$router->get($api . '/health', static fn () => Response::json([
    'ok' => true, 'app' => Config::appName(), 'env' => Config::appEnv(), 'version' => 'v1',
]));

$router->post($api . '/auth/register', [ApiAuth::class, 'register']);
$router->post($api . '/auth/login', [ApiAuth::class, 'login']);
$router->post($api . '/auth/refresh', [ApiAuth::class, 'refresh']);
$router->post($api . '/auth/logout', [ApiAuth::class, 'logout']);
$router->get($api  . '/auth/verify', [ApiAuth::class, 'verify']);
$router->post($api . '/auth/password/forgot', [ApiAuth::class, 'forgotPassword']);
$router->post($api . '/auth/password/reset', [ApiAuth::class, 'resetPassword']);
$router->get($api  . '/me', [ApiAuth::class, 'me'], [$auth]);

// Profili (lettura pubblica JSON) — riusano i repository della vista web.
$router->get($api . '/profiles', [ApiProfile::class, 'index']);
$router->get($api . '/profiles/{handle}', [ApiProfile::class, 'show']);
$router->get($api . '/profiles/{handle}/followers', [ApiProfile::class, 'followers']);
$router->get($api . '/profiles/{handle}/following', [ApiProfile::class, 'following']);
// Follow (scrittura, solo-Bearer).
$router->post($api   . '/profiles/{handle}/follow', [ApiProfile::class, 'follow']);
$router->delete($api . '/profiles/{handle}/follow', [ApiProfile::class, 'unfollow']);
// Connessioni (scrittura, solo-Bearer) + liste personali.
$router->post($api   . '/profiles/{handle}/connection', [ApiProfile::class, 'connect']);
$router->delete($api . '/profiles/{handle}/connection', [ApiProfile::class, 'disconnect']);
$router->get($api    . '/me/connections', [ApiMe::class, 'connections']);
$router->get($api    . '/me/connections/requests', [ApiMe::class, 'connectionRequests']);

// Affiliazioni: letture pubbliche (roster/militanza) + scritture solo-Bearer (acting via X-Acting-Profile)
$router->get($api    . '/profiles/{handle}/roster', [ApiAffiliation::class, 'roster']);
$router->get($api    . '/profiles/{handle}/affiliations', [ApiAffiliation::class, 'affiliations']);
$router->post($api   . '/profiles/{handle}/affiliation', [ApiAffiliation::class, 'request']);
$router->post($api   . '/affiliations/{id}/confirm', [ApiAffiliation::class, 'confirm']);
$router->post($api   . '/affiliations/{id}/reject', [ApiAffiliation::class, 'reject']);
$router->delete($api . '/affiliations/{id}', [ApiAffiliation::class, 'remove']);
$router->get($api    . '/me/affiliations/pending', [ApiAffiliation::class, 'pending']);

// Pagine organizzazione (JSON, solo-Bearer).
$router->post($api   . '/pages', [ApiPages::class, 'create']);

// Feed + post (JSON, solo-Bearer).
$router->get($api    . '/feed', [ApiFeed::class, 'index']);
$router->post($api   . '/posts', [ApiFeed::class, 'createPost']);
$router->delete($api . '/posts/{id}', [ApiFeed::class, 'deletePost']);
$router->post($api   . '/posts/{id}/like', [ApiFeed::class, 'like']);
$router->post($api   . '/posts/{id}/comments', [ApiFeed::class, 'comment']);
$router->delete($api . '/comments/{id}', [ApiFeed::class, 'deleteComment']);

// Link unfurl (JSON, solo-Bearer per i nativi; il web usa /feed/unfurl con sessione+CSRF).
$router->post($api . '/links/unfurl', [ApiLink::class, 'unfurl']);

// Messaggi diretti (JSON, solo-Bearer).
$router->get($api  . '/me/conversations', [ApiMessages::class, 'inbox']);
$router->get($api  . '/me/conversations/{handle}', [ApiMessages::class, 'thread']);
$router->post($api . '/me/conversations/{handle}', [ApiMessages::class, 'send']);

// Proprio profilo + sotto-entità (SCRITTURA). Auth solo-Bearer imposta nel controller (anti-CSRF).
$router->patch($api  . '/me', [ApiMe::class, 'update']);
$router->post($api   . '/me/experiences', [ApiMe::class, 'addExperience']);
$router->patch($api  . '/me/experiences/{id}', [ApiMe::class, 'updateExperience']);
$router->delete($api . '/me/experiences/{id}', [ApiMe::class, 'deleteExperience']);
$router->post($api   . '/me/achievements', [ApiMe::class, 'addAchievement']);
$router->patch($api  . '/me/achievements/{id}', [ApiMe::class, 'updateAchievement']);
$router->delete($api . '/me/achievements/{id}', [ApiMe::class, 'deleteAchievement']);
$router->post($api   . '/me/links', [ApiMe::class, 'addLink']);
$router->patch($api  . '/me/links/{id}', [ApiMe::class, 'updateLink']);
$router->delete($api . '/me/links/{id}', [ApiMe::class, 'deleteLink']);

// Competenze proprie (SCRITTURA, solo-Bearer) + endorsement su competenze altrui.
$router->post($api   . '/me/skills', [ApiSkill::class, 'add']);
$router->delete($api . '/me/skills/{id}', [ApiSkill::class, 'remove']);
$router->patch($api  . '/me/skills/order', [ApiSkill::class, 'reorder']);
$router->post($api   . '/profiles/{handle}/skills/{id}/endorsement', [ApiSkill::class, 'endorse']);
$router->delete($api . '/profiles/{handle}/skills/{id}/endorsement', [ApiSkill::class, 'removeEndorse']);

// Raccomandazioni: scrittura per {handle} + gestione delle proprie ricevute (accetta/nascondi/pending).
// Le VISIBILI sono già nel read-model GET /profiles/{handle}. Tutto solo-Bearer.
$router->post($api . '/profiles/{handle}/recommendation', [ApiReco::class, 'write']);
$router->post($api . '/me/recommendations/{id}/accept', [ApiReco::class, 'accept']);
$router->post($api . '/me/recommendations/{id}/hide', [ApiReco::class, 'hide']);
$router->get($api  . '/me/recommendations/pending', [ApiReco::class, 'pending']);

// Suggerimenti di connessione: ignora (solo-Bearer).
$router->delete($api . '/me/suggestions/{handle}', [ApiSuggestion::class, 'dismiss']);

// Rivendicazione profilo (solo-Bearer).
$router->post($api . '/profiles/{handle}/claim', [ApiClaim::class, 'request']);

// Realtime Phase 1 — stream consolidato (lettura; sessione web O Bearer native) + device-token (Bearer).
$router->get($api    . '/stream/since', [ApiStream::class, 'since']);
$router->post($api   . '/devices', [ApiDevices::class, 'register']);
$router->delete($api . '/devices/{token}', [ApiDevices::class, 'unregister']);

// Immagini del profilo (multipart, solo-Bearer).
$router->post($api   . '/me/avatar', [ApiMedia::class, 'uploadAvatar']);
$router->delete($api . '/me/avatar', [ApiMedia::class, 'deleteAvatar']);
$router->post($api   . '/me/cover', [ApiMedia::class, 'uploadCover']);
$router->delete($api . '/me/cover', [ApiMedia::class, 'deleteCover']);

// Migrazioni: niente più endpoint HTTP (superficie inutile su hosting con SSH). Runner CLI in
// jobs/migrate.php (status|up), invoca Spoome\Core\Migrator direttamente da riga di comando.
