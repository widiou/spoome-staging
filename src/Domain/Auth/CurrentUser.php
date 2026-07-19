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
                return $u;
            }
        }

        // 2) token Bearer (app native / API)
        return self::fromBearer($request);
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
