<?php

namespace Spoome\Domain\Auth;

use Spoome\Core\Config;
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
                // Un solo punto decide "sessione web valida": epoch aggiornato (#4) E dentro i
                // timeout idle/assoluto (#5). Se una qualsiasi condizione cade la sessione è morta.
                // AND corto-circuitato: con epoch stale non tocchiamo nemmeno gli ancoraggi timeout.
                if (self::sessionEpochIsCurrent($u) && self::sessionIsWithinTimeouts()) {
                    return $u;
                }
                // Sessione non più legittima (cambio password → stale, o scaduta per timeout):
                // la distruggiamo, così anche gli helper di nav (auth_id/is_admin, che leggono
                // $_SESSION direttamente) tornano coerentemente anonimi e la rotta protetta redirige al login.
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
     * Enforcement lato server dei timeout di sessione (#5). Il cookie SESSION_LIFETIME è scadenza
     * client-side, non attendibile: qui la sessione muore davvero sul server.
     *  - ASSOLUTO: now - login_at > SESSION_ABSOLUTE_HOURS (default 12h) → morta, a prescindere
     *    dall'attività (ancora fissa a login_at, non scorre mai).
     *  - IDLE: now - last_seen > SESSION_IDLE_MINUTES (default 30m) → morta per inattività.
     * L'assoluto è valutato PRIMA dell'idle: una sessione oltre il tetto assoluto è scaduta anche se
     * l'utente è attivissimo. Se è valida, l'idle è una finestra SCORREVOLE: aggiorniamo last_seen a
     * now (sliding), mentre login_at resta ancorato.
     *
     * Fail-safe / no-regressione: le sessioni aperte PRIMA di questo rilascio non hanno login_at/
     * last_seen. Le "vediamo per la prima volta" ora e seediamo entrambe le ancore a now, trattandole
     * come appena viste. È un grace one-time all'atto del deploy: dà una finestra fresca invece di
     * sfrattare in massa ogni utente live. L'alternativa "chiave mancante => scaduta" sloggherebbe
     * tutte le sessioni attive al primo hit = regressione visibile inaccettabile. Nessun 500 possibile:
     * i cast a int e i default rendono la funzione totale.
     */
    private static function sessionIsWithinTimeouts(): bool
    {
        $now = time();

        // Seed on first-see (sessioni legacy senza ancoraggi): finestra fresca, niente logout di massa.
        if (!Session::has('login_at') || !Session::has('last_seen')) {
            Session::set('login_at', $now);
            Session::set('last_seen', $now);
            return true;
        }

        $loginAt  = (int) Session::get('login_at', $now);
        $lastSeen = (int) Session::get('last_seen', $now);

        // Clamp difensivo: una config presente ma vuota/non numerica/<=0 (es. `SESSION_ABSOLUTE_HOURS=`)
        // farebbe `(int)` -> 0 -> soglia 0 -> sfratto di OGNI sessione a ogni richiesta (logout di massa).
        // Un valore <= 0 non ha senso per un timeout: ricadiamo sul default sicuro invece che sul footgun.
        $absHours = (int) Config::get('SESSION_ABSOLUTE_HOURS', 12);
        $idleMin  = (int) Config::get('SESSION_IDLE_MINUTES', 30);
        $absoluteSeconds = ($absHours > 0 ? $absHours : 12) * 3600;
        $idleSeconds     = ($idleMin > 0 ? $idleMin : 30) * 60;

        if ($now - $loginAt > $absoluteSeconds) {
            return false;
        }
        if ($now - $lastSeen > $idleSeconds) {
            return false;
        }

        // Entro le soglie: finestra idle scorrevole. login_at NON si tocca (ancora assoluta).
        Session::set('last_seen', $now);
        return true;
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
