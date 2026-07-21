<?php

namespace Spoome\Domain\Auth;

use Spoome\Core\Logger;
use Spoome\Core\Request;
use Spoome\Core\Session;
use Spoome\Domain\Users\User;
use Spoome\Domain\Users\UserRepository;

/**
 * Risolve l'utente autenticato dalla richiesta: prima la sessione web, poi il token Bearer (API/native).
 * Il risultato viene messo in cache negli attributi della Request.
 */
final class CurrentUser
{
    public static function resolve(Request $request): ?User
    {
        if (array_key_exists('__user', $request->attributes)) {
            return $request->attributes['__user'];
        }

        $user = self::lookup($request);
        if ($user !== null) {
            Logger::setUser($user->id); // arricchisce i log con l'utente
        }
        $request->attributes['__user'] = $user;
        return $user;
    }

    private static function lookup(Request $request): ?User
    {
        $users = new UserRepository();

        // 1) sessione web
        $uid = Session::userId();
        if ($uid !== null) {
            $u = $users->findById($uid);
            if ($u && $u->isActive()) {
                if (self::sessionEpochIsCurrent($u)) {
                    return $u;
                }
                // Sessione emessa PRIMA dell'ultimo cambio password → stale: la distruggiamo, così
                // anche gli helper di nav (auth_id/is_admin, che leggono $_SESSION direttamente)
                // tornano coerentemente anonimi e la rotta protetta redirige al login.
                Session::destroy();
            }
        }

        // 2) token Bearer (app native / API) — canale indipendente dalla sessione web.
        return self::fromBearer($request);
    }

    /**
     * True se l'epoch salvato in sessione al login è aggiornato rispetto a users.session_epoch.
     * Fail-safe by design:
     *  - sessione priva di 'session_epoch' (creata prima di questa feature) → trattata come 0;
     *  - colonna session_epoch assente (prima della migrazione 0032) → User::sessionEpoch è 0.
     * In entrambi i casi 0 >= 0 è true → nessuna sessione legittima viene sloggata per errore.
     * Diventa false solo quando un cambio password ha incrementato l'epoch DB oltre quello di sessione.
     */
    private static function sessionEpochIsCurrent(User $u): bool
    {
        return (int) Session::get('session_epoch', 0) >= $u->sessionEpoch;
    }

    /**
     * Risolve l'utente SOLO dal token Bearer, ignorando la sessione web.
     * Usato dalle API di scrittura: garantisce che una richiesta autenticata via cookie di sessione
     * (potenziale CSRF cross-site) NON possa modificare dati tramite l'API — solo un token esplicito.
     */
    public static function fromBearer(Request $request): ?User
    {
        $raw = $request->bearerToken();
        if ($raw === null) {
            return null;
        }
        $uid = (new TokenService())->resolve($raw, 'access');
        if ($uid === null) {
            return null;
        }
        $u = (new UserRepository())->findById($uid);
        return ($u && $u->isActive()) ? $u : null;
    }
}
