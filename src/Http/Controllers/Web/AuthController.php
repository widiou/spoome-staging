<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\Session;
use Spoome\Core\Validator;
use Spoome\Core\View;
use Spoome\Domain\Auth\AuthService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Sports\SportRepository;
use Spoome\Domain\Users\UserRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Controller web dell'autenticazione (HTML, sessione). CSRF applicato come middleware sulle rotte POST.
 * Anti-enumeration: risposte generiche su registrazione/recupero. Testi via i18n (t()).
 */
final class AuthController extends Controller
{
    /* ----------------------------------------------------------- REGISTER ---- */

    /** Registrazione "per rivendicare": crea l'account senza profilo, per poi rivendicarne uno esistente. */
    public function showRegisterClaim(Request $request): void
    {
        View::render('auth/register-claim', [
            'title' => $this->title('claim.register.title'),
            'old'   => [],
        ], 'base');
    }

    public function registerClaim(Request $request): void
    {
        $data = $request->body();
        $v = Validator::make($data, [
            'email'    => 'required|email|max:190',
            'password' => 'required|min:10|confirmed',
        ]);

        $error = null;
        if ($v->fails()) {
            $error = $v->firstError();
        } elseif (!AuthService::isStrongPassword((string) $data['password'])) {
            $error = I18n::t('auth.error.password_policy');
        }
        if ($error !== null) {
            View::render('auth/register-claim', ['title' => $this->title('claim.register.title'), 'error' => $error, 'old' => $data], 'base');
            return;
        }

        $result = (new AuthService())->registerClaimant((string) $data['email'], (string) $data['password'], $request->ip());
        if (!$result['ok'] && ($result['error'] ?? '') !== 'email_taken') {
            View::render('auth/register-claim', ['title' => $this->title('claim.register.title'), 'error' => I18n::t('auth.error.register_failed'), 'old' => $data], 'base');
            return;
        }

        Session::flash(I18n::t('auth.flash.registered'), 'success');
        Response::redirect('accedi');
    }

    public function showRegister(Request $request): void
    {
        View::render('auth/register', [
            'title'  => $this->title('auth.register.title'),
            'types'  => (new ProfileRepository())->activePersonalTypes(),
            'sports' => (new SportRepository())->all(),
            'old'    => [],
        ], 'base');
    }

    public function register(Request $request): void
    {
        $data  = $request->body();
        $repo  = new ProfileRepository();

        $v = Validator::make($data, [
            'display_name' => 'required|min:2|max:160',
            'email'        => 'required|email|max:190',
            'password'     => 'required|min:10|confirmed',
            'profile_type' => 'required|in:' . implode(',', $repo->activePersonalTypeKeys()),
        ]);

        $error = null;
        if ($v->fails()) {
            $error = $v->firstError();
        } elseif (!AuthService::isStrongPassword((string) $data['password'])) {
            $error = I18n::t('auth.error.password_policy');
        }
        if ($error !== null) {
            $this->renderRegister($repo, $error, $data);
            return;
        }

        // Sport facoltativo all'iscrizione: risolvi lo slug in id (null se assente/ignoto).
        $sportSlug = trim((string) ($data['sport'] ?? ''));
        $sportId   = $sportSlug !== '' ? (new SportRepository())->idBySlug($sportSlug) : null;

        $result = (new AuthService())->register(
            (string) $data['email'],
            (string) $data['password'],
            (string) $data['display_name'],
            (string) $data['profile_type'],
            $request->ip(),
            $sportId
        );

        if (!$result['ok'] && ($result['error'] ?? '') !== 'email_taken') {
            $this->renderRegister($repo, I18n::t('auth.error.register_failed'), $data);
            return;
        }

        // Anti-enumeration: successo ed "email già usata" danno la stessa risposta.
        Session::flash(I18n::t('auth.flash.registered'), 'success');
        Response::redirect('accedi');
    }

    /* -------------------------------------------------------------- LOGIN ---- */

    public function showLogin(Request $request): void
    {
        View::render('auth/login', [
            'title'  => $this->title('auth.login.title'),
            'notice' => Session::takeFlash(),
            'old'    => [],
        ], 'base');
    }

    public function login(Request $request): void
    {
        $data = $request->body();
        $v = Validator::make($data, ['email' => 'required|email', 'password' => 'required']);
        if ($v->fails()) {
            $this->renderLogin(I18n::t('auth.error.email_password'), $data);
            return;
        }

        $result = (new AuthService())->login((string) $data['email'], (string) $data['password'], $request->ip());
        if (!$result['ok']) {
            $this->renderLogin($result['error'], $data);
            return;
        }

        $this->startUserSession((int) $result['user']->id, $result['user']->role, $result['user']->sessionEpoch);
        Response::redirect('');
    }

    public function logout(Request $request): void
    {
        Session::destroy();
        Response::redirect('');
    }

    /* ------------------------------------------------------ VERIFY EMAIL ---- */

    public function verifyEmail(Request $request): void
    {
        $token  = (string) ($request->query['token'] ?? '');
        $userId = $token !== '' ? (new AuthService())->verifyEmail($token) : null;

        if ($userId === null) {
            View::render('message', [
                'title'       => $this->title('auth.verify.invalid_title'),
                'heading'     => I18n::t('auth.verify.invalid_title'),
                'message'     => I18n::t('auth.verify.invalid_msg'),
                'type'        => 'error',
                'actionUrl'   => url('accedi'),
                'actionLabel' => I18n::t('auth.verify.go_login'),
            ], 'base');
            return;
        }

        // Auto-login dopo la verifica: rileggo l'utente per fissare in sessione l'epoch CORRENTE.
        // Un utente pending può aver richiesto un reset password prima di verificare (l'epoch è già
        // stato incrementato): partire da 0 auto-sfratterebbe subito la sessione appena creata.
        $user = (new UserRepository())->findById($userId);
        if ($user === null) {
            // Incoerenza: il token ha appena risolto questo id ma l'utente non è leggibile.
            // Non apriamo una sessione con ruolo/epoch di ripiego — falliamo in modo sicuro.
            View::render('message', [
                'title'       => $this->title('auth.verify.invalid_title'),
                'heading'     => I18n::t('auth.verify.invalid_title'),
                'message'     => I18n::t('auth.verify.invalid_msg'),
                'type'        => 'error',
                'actionUrl'   => url('accedi'),
                'actionLabel' => I18n::t('auth.verify.go_login'),
            ], 'base');
            return;
        }

        $this->startUserSession($userId, $user->role, $user->sessionEpoch);
        Session::flash(I18n::t('auth.flash.verified'), 'success');
        Response::redirect('');
    }

    /* --------------------------------------------------- PASSWORD RESET ---- */

    public function showForgot(Request $request): void
    {
        View::render('auth/forgot-password', [
            'title'  => $this->title('auth.forgot.title'),
            'notice' => Session::takeFlash(),
        ], 'base');
    }

    public function forgot(Request $request): void
    {
        $email = (string) $request->input('email', '');
        if ($email !== '') {
            (new AuthService())->requestPasswordReset($email, $request->ip());
        }
        View::render('auth/forgot-password', [
            'title'  => $this->title('auth.forgot.title'),
            'notice' => ['message' => I18n::t('auth.flash.forgot_generic'), 'type' => 'success'],
        ], 'base');
    }

    public function showReset(Request $request): void
    {
        $token = (string) ($request->query['token'] ?? '');
        if ($token === '') {
            View::render('message', [
                'title'       => $this->title('auth.reset.title'),
                'heading'     => I18n::t('auth.reset.missing_title'),
                'message'     => I18n::t('auth.reset.missing_msg'),
                'type'        => 'error',
                'actionUrl'   => url('recupera-password'),
                'actionLabel' => I18n::t('auth.reset.request_new'),
            ], 'base');
            return;
        }
        View::render('auth/reset-password', ['title' => $this->title('auth.reset.title'), 'token' => $token], 'base');
    }

    public function reset(Request $request): void
    {
        $token = (string) $request->input('token', '');
        $pw    = (string) $request->input('password', '');
        $pwc   = (string) $request->input('password_confirmation', '');

        $error = null;
        if ($pw !== $pwc) {
            $error = I18n::t('auth.error.password_mismatch');
        } elseif (!AuthService::isStrongPassword($pw)) {
            $error = I18n::t('auth.error.password_policy');
        }
        if ($error === null) {
            $result = (new AuthService())->resetPassword($token, $pw, $request->ip());
            if ($result['ok']) {
                Session::flash(I18n::t('auth.flash.reset_done'), 'success');
                Response::redirect('accedi');
                return;
            }
            $error = $result['error'] ?? I18n::t('auth.error.reset_failed');
        }

        View::render('auth/reset-password', [
            'title' => $this->title('auth.reset.title'),
            'token' => $token,
            'error' => $error,
        ], 'base');
    }

    /* ------------------------------------------------------------ helpers ---- */

    private function renderRegister(ProfileRepository $repo, ?string $error, array $old): void
    {
        View::render('auth/register', [
            'title'  => $this->title('auth.register.title'),
            'types'  => $repo->activePersonalTypes(),
            'sports' => (new SportRepository())->all(),
            'error'  => $error,
            'old'    => $old,
        ], 'base');
    }

    private function renderLogin(?string $error, array $old): void
    {
        View::render('auth/login', ['title' => $this->title('auth.login.title'), 'error' => $error, 'old' => $old], 'base');
    }

    private function startUserSession(int $userId, string $role, int $sessionEpoch = 0): void
    {
        Session::regenerate(); // anti session-fixation
        Session::set('user_id', $userId);
        Session::set('role', $role);
        // Epoch di sessione: fissa la generazione con cui questa sessione nasce. Un successivo cambio
        // password incrementerà users.session_epoch → CurrentUser sfratterà questa sessione (stale),
        // ma NON quelle aperte dopo (che ripartono dall'epoch aggiornato). Vedi CurrentUser.
        Session::set('session_epoch', $sessionEpoch);
        // Denormalizza il profilo PERSONALE in sessione: elimina una query per pagina negli helper di
        // nav. null è legittimo (utente "claimant" senza profilo) — Session::has() distingue assente da
        // null. Multi-profilo: `profile_id` = personale (default dell'acting context) e l'acting parte
        // sul personale; lo switcher potrà spostarlo su una pagina gestita.
        $repo    = new ProfileRepository();
        $profile = $repo->personalOrAny($userId);
        Session::set('profile_id', $profile?->id);
        Session::set('acting_profile_id', $profile?->id);
    }
}
