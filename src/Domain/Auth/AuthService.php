<?php

namespace Spoome\Domain\Auth;

use PDO;
use Spoome\Core\Config;
use Spoome\Core\Db;
use Spoome\Core\I18n;
use Spoome\Core\Logger;
use Spoome\Core\Mailer;
use Spoome\Domain\Profiles\ProfileMemberRepository;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Users\User;
use Spoome\Domain\Users\UserRepository;
use Throwable;

/**
 * Orchestratore dell'autenticazione: registrazione (utente + profilo, atomica), login
 * (con throttling e anti-enumeration), verifica email, reset password.
 * Non tocca sessione/HTTP: ritorna risultati; sessione e risposta le gestiscono i controller.
 */
final class AuthService
{
    /** Hash bcrypt costante per equalizzare il tempo del login su email inesistenti (anti-enumeration timing). */
    private const DUMMY_HASH = '$2y$10$wt1OnnDItkpyS0zXAq1P4OHSupOPjNQaIQQjJQn.9fkDAVpy.eIka';

    private PDO $pdo;
    private UserRepository $users;
    private ProfileRepository $profiles;
    private ProfileMemberRepository $members;
    private EmailVerificationService $emailVerify;
    private PasswordResetService $passwordReset;
    private RateLimiter $rateLimiter;
    private TokenService $tokens;

    public function __construct(
        ?PDO $pdo = null,
        ?UserRepository $users = null,
        ?ProfileRepository $profiles = null,
        ?EmailVerificationService $emailVerify = null,
        ?PasswordResetService $passwordReset = null,
        ?RateLimiter $rateLimiter = null,
        ?TokenService $tokens = null,
        ?ProfileMemberRepository $members = null,
    ) {
        $this->pdo           = $pdo ?? Db::connection();
        $this->users         = $users ?? new UserRepository($this->pdo);
        $this->profiles      = $profiles ?? new ProfileRepository($this->pdo);
        $this->members       = $members ?? new ProfileMemberRepository($this->pdo);
        $this->emailVerify   = $emailVerify ?? new EmailVerificationService($this->pdo);
        $this->passwordReset = $passwordReset ?? new PasswordResetService($this->pdo);
        $this->rateLimiter   = $rateLimiter ?? new RateLimiter($this->pdo);
        $this->tokens        = $tokens ?? new TokenService($this->pdo);
    }

    /**
     * Policy password: 10–72 char (bcrypt tronca oltre 72 byte), almeno una lettera e un numero,
     * e NON deve essere una password banale (blocklist + pattern: parola-comune+cifre, un solo
     * carattere ripetuto). Blocca casi come "password12" che superavano la sola regola length+classi.
     */
    public static function isStrongPassword(string $pw): bool
    {
        if (mb_strlen($pw) < 10 || strlen($pw) > 72 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
            return false;
        }
        $low = mb_strtolower($pw);
        $blocklist = [
            'password12', 'password123', 'password1234', 'passw0rd12', 'welcome1234',
            '1234567890', '12345678', '123456789', 'qwerty1234', 'qwertyuiop1',
            'abcdefgh12', 'iloveyou12', 'letmein123', 'spoome1234', 'aaaaaaaa11',
        ];
        if (in_array($low, $blocklist, true)) {
            return false;
        }
        // Pattern banali: parola-comune (+ eventuali cifre), oppure un solo carattere ripetuto (+ cifre).
        if (preg_match('/^(password|passw0rd|spoome|qwerty|welcome|letmein|admin|iloveyou)\d*$/i', $pw)
            || preg_match('/^(.)\1{5,}\d*$/', $pw)) {
            return false;
        }
        return true;
    }

    public static function passwordPolicyMessage(): string
    {
        return I18n::t('auth.error.password_policy');
    }

    /**
     * Registrazione: crea utente (pending) + profilo, invia email di verifica. Atomica.
     * @return array{ok:bool, userId?:int, error?:string}
     */
    public function register(string $email, string $password, string $displayName, string $profileTypeKey, string $ip, ?int $sportId = null): array
    {
        $email = mb_strtolower(trim($email));

        // Throttle anti-spam registrazioni per IP.
        if ($this->rateLimiter->tooManyByKey('reg:' . $ip, 10, 60)) {
            return ['ok' => false, 'error' => 'throttled'];
        }
        $this->rateLimiter->hit('reg:' . $ip, $ip);

        // Costo bcrypt calcolato SEMPRE (anche se l'email esiste) per non rivelare l'esistenza via timing.
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if ($this->users->emailExists($email)) {
            // Anti-enumeration: risposta generica lato controller; qui distinguiamo per log interni.
            return ['ok' => false, 'error' => 'email_taken'];
        }
        $typeId = $this->profiles->typeIdByKey($profileTypeKey);
        if ($typeId === null) {
            return ['ok' => false, 'error' => 'invalid_type'];
        }
        // Guardia anti doppio-path (difesa in profondità: i controller già filtrano la whitelist):
        // le ORGANIZZAZIONI non nascono dalla self-registration ma SOLO come pagine via PageService,
        // che stabilisce la owner-row autoritativa in `profile_members`. Iscriversi come org lascerebbe
        // il profilo con owner solo denormalizzato (profiles.user_id) e nessun roster → rischio.
        if ($this->profiles->isOrganizationKey($profileTypeKey)) {
            return ['ok' => false, 'error' => 'org_not_allowed'];
        }

        $handle = $this->profiles->uniqueHandle($displayName);

        try {
            $this->pdo->beginTransaction();
            $userId = $this->users->create($email, $hash);
            $profileId = $this->profiles->create($userId, $typeId, $handle, $displayName, $sportId);
            // Owner-row autoritativa da subito: `profile_members` è la sorgente di verità dell'authz
            // (ActingContext). Scriverla qui elimina la divergenza col fallback su profiles.user_id
            // per ogni nuovo iscritto → consolidamento, zero regressione (il ruolo resta 'owner').
            $this->members->addMember($profileId, $userId, 'owner', null);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            return ['ok' => false, 'error' => 'db_error'];
        }

        $this->sendVerificationEmail($userId, $email);
        return ['ok' => true, 'userId' => $userId];
    }

    /**
     * Registrazione "per rivendicare": crea SOLO l'utente, senza profilo. L'utente rivendicherà
     * poi un profilo non rivendicato (con approvazione admin) che diventerà la sua identità.
     * @return array{ok:bool,error?:string,userId?:int}
     */
    public function registerClaimant(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));

        if ($this->rateLimiter->tooManyByKey('reg:' . $ip, 10, 60)) {
            return ['ok' => false, 'error' => 'throttled'];
        }
        $this->rateLimiter->hit('reg:' . $ip, $ip);

        $hash = password_hash($password, PASSWORD_DEFAULT);

        if ($this->users->emailExists($email)) {
            return ['ok' => false, 'error' => 'email_taken'];
        }

        try {
            $userId = $this->users->create($email, $hash);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'db_error'];
        }

        $this->sendVerificationEmail($userId, $email);
        return ['ok' => true, 'userId' => $userId];
    }

    public function sendVerificationEmail(int $userId, string $email): void
    {
        $raw = $this->emailVerify->issue($userId);
        $link = Config::absoluteUrl('verifica') . '?token=' . $raw;
        Mailer::send($email, I18n::t('email.verify.subject'), I18n::t('email.verify.body', ['link' => $link]));
    }

    /**
     * Login: throttling + verifica credenziali senza user-enumeration.
     * @return array{ok:bool, user?:User, error?:string, code?:int}
     */
    public function login(string $email, string $password, string $ip): array
    {
        $email = mb_strtolower(trim($email));

        // Blocco per IP (non per email): impedisce il DoS di lockout mirato di una vittima.
        if ($this->rateLimiter->tooManyByIp($ip)) {
            Logger::security('Login bloccato per throttling', ['email' => $email, 'ip' => $ip]);
            return ['ok' => false, 'error' => I18n::t('auth.error.throttled'), 'code' => 429];
        }

        $user = $this->users->findByEmail($email);
        // Tempo costante: se l'utente non esiste, si verifica comunque contro un hash fittizio.
        if ($user === null) {
            password_verify($password, self::DUMMY_HASH);
            $valid = false;
        } else {
            $valid = password_verify($password, $user->passwordHash);
        }

        if (!$valid) {
            $this->rateLimiter->record($email, $ip, false);
            Logger::security('Login fallito', ['email' => $email, 'ip' => $ip]);
            return ['ok' => false, 'error' => I18n::t('auth.error.credentials'), 'code' => 401];
        }

        // Credenziali corrette: possiamo rivelare lo stato (l'utente ha provato di essere lui).
        // In PRODUZIONE il gate "conferma email prima di accedere" resta. Fuori produzione (beta/staging,
        // dove non c'è consegna email reale → indirizzi @spoome.local) un utente pending non potrebbe MAI
        // accedere: consentiamo il login così la beta resta testabile/usabile. Produzione invariata.
        if ($user->isPending() && Config::isProduction()) {
            return ['ok' => false, 'error' => I18n::t('auth.error.pending'), 'code' => 403];
        }
        // Blocca SEMPRE i sospesi (moderazione), in ogni ambiente. NB: non usare !isActive() qui — fuori
        // produzione i pending sono ammessi dal gate sopra, e !isActive() li rifiuterebbe rendendo morto
        // l'allowance beta (nuovi iscritti mai in grado di loggare senza email di verifica reale).
        if ($user->isSuspended()) {
            return ['ok' => false, 'error' => I18n::t('auth.error.inactive'), 'code' => 403];
        }

        $this->rateLimiter->record($email, $ip, true);
        $this->users->recordLogin($user->id);
        return ['ok' => true, 'user' => $user];
    }

    /** Verifica email da token: attiva l'utente. @return int|null userId */
    public function verifyEmail(string $rawToken): ?int
    {
        $userId = $this->emailVerify->consume($rawToken);
        if ($userId === null) {
            return null;
        }
        $this->users->markVerifiedAndActive($userId);
        return $userId;
    }

    /** Richiesta reset: sempre "successo" lato controller (anti-enumeration). Throttle per IP e per email. */
    public function requestPasswordReset(string $email, string $ip = 'unknown'): void
    {
        $email = mb_strtolower(trim($email));

        // Throttle: max 5 richieste/15min per IP, max 3/60min verso la stessa email (anti email-bombing).
        if ($this->rateLimiter->tooManyByKey('pwf:ip:' . $ip, 5, 15)
            || $this->rateLimiter->tooManyByKey('pwf:em:' . $email, 3, 60)) {
            return;
        }
        $this->rateLimiter->hit('pwf:ip:' . $ip, $ip);
        $this->rateLimiter->hit('pwf:em:' . $email, $ip);

        $user = $this->users->findByEmail($email);
        if ($user === null || $user->status === 'suspended') {
            return;
        }
        $raw = $this->passwordReset->issue($user->id);
        $link = Config::absoluteUrl('reimposta') . '?token=' . $raw;
        Mailer::send($email, I18n::t('email.reset.subject'), I18n::t('email.reset.body', ['link' => $link]));
    }

    /**
     * Reset password: valida il token (consumo ATOMICO), aggiorna la password, revoca i token API.
     * @return array{ok:bool, error?:string, userId?:int}
     */
    public function resetPassword(string $rawToken, string $newPassword, string $ip = 'unknown'): array
    {
        // Throttle per IP contro brute-force del token (già improbabile: token a 256 bit).
        if ($this->rateLimiter->tooManyByKey('pwr:' . $ip, 10, 15)) {
            return ['ok' => false, 'error' => I18n::t('auth.error.throttled')];
        }
        $this->rateLimiter->hit('pwr:' . $ip, $ip);

        if (!self::isStrongPassword($newPassword)) {
            return ['ok' => false, 'error' => self::passwordPolicyMessage()];
        }
        // Consumo atomico: valida e marca usato in un colpo solo (no race sul monouso).
        $userId = $this->passwordReset->resolveAndConsume($rawToken);
        if ($userId === null) {
            return ['ok' => false, 'error' => I18n::t('auth.error.reset_invalid')];
        }
        $this->users->updatePassword($userId, password_hash($newPassword, PASSWORD_DEFAULT));
        $this->tokens->revokeAllForUser($userId); // invalida sessioni token dopo cambio password
        return ['ok' => true, 'userId' => $userId];
    }
}
