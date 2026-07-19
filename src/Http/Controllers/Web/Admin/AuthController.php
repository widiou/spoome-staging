<?php

namespace Spoome\Http\Controllers\Web\Admin;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\Session;
use Spoome\Core\View;
use Spoome\Domain\Admin\AuditRepository;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Http\Controllers\Controller;

/**
 * Re-autenticazione step-up dell'area admin: l'utente è già admin loggato, ma per entrare
 * (o rientrare dopo la scadenza) deve reinserire la password. Rotte montate con [$auth, $admin]
 * ma SENZA StepUpMiddleware (altrimenti si auto-bloccherebbero).
 */
final class AuthController extends Controller
{
    /** Tentativi massimi di verifica step-up per IP nella finestra. */
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MIN   = 15;

    public function show(Request $request): void
    {
        View::render('admin/verifica', [
            'title'  => $this->title('admin.stepup.title'),
            'notice' => Session::takeFlash(),
        ], 'admin-plain');
    }

    public function verify(Request $request): void
    {
        $user = CurrentUser::resolve($request);
        $ip   = $request->ip();
        $key  = 'adminstepup:ip:' . $ip;
        $limiter = new RateLimiter();

        if ($limiter->tooManyByKey($key, self::MAX_ATTEMPTS, self::WINDOW_MIN)) {
            Session::flash(I18n::t('admin.stepup.throttled'), 'error');
            Response::redirect('admin/verifica');
            return;
        }

        $password = (string) $request->input('password', '');
        if ($password === '' || !password_verify($password, $user->passwordHash)) {
            $limiter->hit($key, $ip);
            (new AuditRepository())->record($user->id, 'admin.stepup_failed', 'user', $user->id, [], $ip);
            Session::flash(I18n::t('admin.stepup.wrong'), 'error');
            Response::redirect('admin/verifica');
            return;
        }

        // Verifica superata: rigenera l'id di sessione (l'elevazione di privilegio non deve
        // riusare l'id pre-step-up), apre la finestra step-up e traccia l'accesso.
        Session::regenerate();
        Session::set('admin_stepup_at', time());
        (new AuditRepository())->record($user->id, 'admin.stepup_ok', 'user', $user->id, [], $ip);

        $intended = (string) Session::get('admin_stepup_intended', '/admin');
        Session::forget('admin_stepup_intended');
        // Sicurezza: consenti solo destinazioni interne all'area admin.
        if (!preg_match('#^/admin(/|$)#', $intended)) {
            $intended = '/admin';
        }
        Response::redirect($intended);
    }
}
