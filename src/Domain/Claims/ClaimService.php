<?php

namespace Spoome\Domain\Claims;

use Spoome\Core\Config;
use Spoome\Core\Db;
use Spoome\Core\I18n;
use Spoome\Core\Mailer;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Admin\AuditRepository;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Notifications\NotificationRepository;
use Spoome\Domain\Profiles\ProfileMemberRepository;
use Spoome\Domain\Profiles\ProfileRepository;

/**
 * Flusso di rivendicazione profilo.
 * - `request`: un utente SENZA profilo chiede di rivendicare un profilo NON rivendicato.
 * - `approve`/`reject`: moderazione admin. L'approvazione assegna la proprietà e rifiuta le richieste
 *   concorrenti sullo stesso profilo. Ogni decisione è tracciata nell'audit.
 */
final class ClaimService
{
    private ClaimRepository $claims;
    private ProfileRepository $profiles;
    private ProfileMemberRepository $members;
    private AuditRepository $audit;
    private RateLimiter $rateLimiter;
    private NotificationRepository $notifications;

    public function __construct(
        ?ClaimRepository $claims = null,
        ?ProfileRepository $profiles = null,
        ?AuditRepository $audit = null,
        ?RateLimiter $rateLimiter = null,
        ?NotificationRepository $notifications = null,
        ?ProfileMemberRepository $members = null
    ) {
        $this->claims   = $claims ?? new ClaimRepository();
        $this->profiles = $profiles ?? new ProfileRepository();
        $this->members  = $members ?? new ProfileMemberRepository();
        $this->audit    = $audit ?? new AuditRepository();
        $this->rateLimiter = $rateLimiter ?? new RateLimiter();
        $this->notifications = $notifications ?? new NotificationRepository();
    }

    public function request(int $userId, int $profileId, ?string $message, string $ip): ServiceResult
    {
        $profile = $this->profiles->findRawById($profileId);
        if ($profile === null) {
            return ServiceResult::fail(I18n::t('claim.err_notfound'), 404);
        }
        if (($profile['claim_status'] ?? 'claimed') !== 'unclaimed' || $profile['user_id'] !== null) {
            return ServiceResult::fail(I18n::t('claim.err_already_claimed'), 422);
        }
        if ($this->profiles->userHasProfile($userId)) {
            return ServiceResult::fail(I18n::t('claim.err_has_profile'), 422);
        }
        if ($this->claims->pendingFor($profileId, $userId) !== null) {
            return ServiceResult::fail(I18n::t('claim.err_pending'), 422);
        }
        if ($this->rateLimiter->tooManyByKey('claim:' . $ip, 10, 60)) {
            return ServiceResult::fail(I18n::t('claim.err_throttled'), 429);
        }
        $this->rateLimiter->hit('claim:' . $ip, $ip);

        $msg = $message !== null ? mb_substr(trim($message), 0, 1000) : null;
        $id  = $this->claims->create($profileId, $userId, $msg);

        return ServiceResult::ok(['id' => $id], ['message' => I18n::t('claim.done_sent')]);
    }

    public function approve(int $adminId, int $requestId, string $ip): ServiceResult
    {
        $req = $this->claims->findDetail($requestId);
        if ($req === null) {
            return ServiceResult::fail(I18n::t('admin.claims.err_notfound'), 404);
        }
        if ($req['status'] !== 'pending') {
            return ServiceResult::fail(I18n::t('admin.claims.err_not_pending'), 422);
        }
        // Pre-check "best effort" (senza lock): scarta i casi palesemente invalidi SENZA aprire una
        // transazione. NON è autoritativo: i ricontrolli anti-corsa veri girano sotto lock qui sotto.
        if (($req['claim_status'] ?? 'claimed') !== 'unclaimed' || $req['profile_owner_id'] !== null) {
            return ServiceResult::fail(I18n::t('admin.claims.err_taken'), 422);
        }
        if ($this->profiles->userHasProfile((int) $req['user_id'])) {
            return ServiceResult::fail(I18n::t('admin.claims.err_user_has_profile'), 422);
        }

        // Atomico: rivalidazione sotto lock + trasferimento proprietà + owner-row autoritativa in
        // profile_members (come registrazione/PageService) + moderazione, tutto-o-niente. La owner-row
        // allinea il claim al modello multi-profilo: l'authz non resta appesa al solo user_id denormalizzato.
        $profileId  = (int) $req['profile_id'];
        $newOwnerId = (int) $req['user_id'];

        // I ricontrolli critici stanno DENTRO la transazione con SELECT ... FOR UPDATE (anti-TOCTOU).
        // Ordine di lock deterministico per evitare deadlock: profilo → richiesta → utente.
        //  - il lock sul PROFILO serializza due approve sullo stesso profilo (chi arriva secondo lo
        //    trova già `claimed` e aborta) ed è preso PER PRIMO così nessuno tiene lock sulle richieste
        //    mentre attende il profilo (evita il ciclo profilo↔richiesta con rejectOtherPending);
        //  - il lock sulla RICHIESTA serializza due decisioni sulla stessa richiesta;
        //  - il lock sull'UTENTE serializza due approve dello stesso richiedente su profili diversi
        //    (chi arriva secondo rivalida userHasProfile sotto lock e aborta).
        // In conflitto: nessuna scrittura, si torna 409 e la tx si chiude committando solo letture.
        $conflict = $this->runGuardedTransaction(function () use ($profileId, $newOwnerId, $requestId, $adminId): ?ServiceResult {
            $profile = $this->claims->lockProfileForClaim($profileId);
            if ($profile === null) {
                return ServiceResult::fail(I18n::t('admin.claims.err_notfound'), 404);
            }
            if (($profile['claim_status'] ?? 'claimed') !== 'unclaimed' || $profile['user_id'] !== null) {
                return ServiceResult::fail(I18n::t('admin.claims.err_taken'), 409);
            }
            if ($this->claims->lockRequestStatus($requestId) !== 'pending') {
                return ServiceResult::fail(I18n::t('admin.claims.err_not_pending'), 409);
            }
            if (!$this->claims->lockUserExists($newOwnerId)) {
                return ServiceResult::fail(I18n::t('admin.claims.err_notfound'), 404);
            }
            // Recheck lock-aware (FOR SHARE): non poggia sullo snapshot REPEATABLE READ, così lo
            // scenario C (stesso utente, due profili) vede l'ownership committata dal primo approve.
            if ($this->claims->userHasProfileLockAware($newOwnerId)) {
                return ServiceResult::fail(I18n::t('admin.claims.err_user_has_profile'), 409);
            }

            $this->profiles->assignOwner($profileId, $newOwnerId);
            $this->members->addMember($profileId, $newOwnerId, 'owner', null);
            $this->claims->markReviewed($requestId, 'approved', null, $adminId);
            $this->claims->rejectOtherPending($profileId, $requestId, $adminId, I18n::t('admin.claims.auto_reject'));
            return null; // successo: nessun conflitto
        });

        if ($conflict instanceof ServiceResult) {
            return $conflict; // corsa persa sotto lock: stato cambiato nel frattempo, nessuna scrittura
        }

        $this->audit->record($adminId, 'claim.approve', 'profile', (int) $req['profile_id'], [
            'request_id' => $requestId,
            'user_id'    => (int) $req['user_id'],
            'handle'     => $req['profile_handle'],
        ], $ip);

        $this->notifyDecision($req, true, null);

        return ServiceResult::ok(null, ['message' => I18n::t('admin.claims.done_approved')]);
    }

    public function reject(int $adminId, int $requestId, ?string $note, string $ip): ServiceResult
    {
        $req = $this->claims->findDetail($requestId);
        if ($req === null) {
            return ServiceResult::fail(I18n::t('admin.claims.err_notfound'), 404);
        }
        if ($req['status'] !== 'pending') {
            return ServiceResult::fail(I18n::t('admin.claims.err_not_pending'), 422);
        }

        $note = $note !== null ? mb_substr(trim($note), 0, 500) : null;

        // Atomico e sotto lock come approve(): il pre-check statico qui sopra NON è autoritativo.
        // Senza lock, un reject concorrente potrebbe sovrascrivere (e rinotificare) una richiesta
        // appena APPROVATA da un altro admin. Blocchiamo la riga (lockRequestStatus, come approve),
        // rivalidiamo lo stato, poi ci affidiamo anche al guard `AND status='pending'` di
        // markReviewed: se tocca 0 righe la corsa è persa → 409, nessuna scrittura, nessuna notifica.
        $conflict = $this->runGuardedTransaction(function () use ($requestId, $note, $adminId): ?ServiceResult {
            if ($this->claims->lockRequestStatus($requestId) !== 'pending') {
                return ServiceResult::fail(I18n::t('admin.claims.err_not_pending'), 409);
            }
            if (!$this->claims->markReviewed($requestId, 'rejected', $note, $adminId)) {
                return ServiceResult::fail(I18n::t('admin.claims.err_not_pending'), 409);
            }
            return null; // successo: nessun conflitto
        });

        if ($conflict instanceof ServiceResult) {
            return $conflict; // corsa persa sotto lock: stato già deciso altrove, nessuna scrittura/notifica
        }

        $this->audit->record($adminId, 'claim.reject', 'profile', (int) $req['profile_id'], [
            'request_id' => $requestId,
            'user_id'    => (int) $req['user_id'],
        ], $ip);

        $this->notifyDecision($req, false, $note);

        return ServiceResult::ok(null, ['message' => I18n::t('admin.claims.done_rejected')]);
    }

    /**
     * Esegue $fn dentro una transazione (via Db::transaction) traducendo un deadlock InnoDB
     * (SQLSTATE 40001 / errno 1213) in un ServiceResult di conflitto 409, invece di lasciar
     * propagare la PDOException fino a un 500. Il deadlock è un esito di corsa legittimo: InnoDB
     * ha già fatto il rollback (nessuna scrittura parziale), quindi al chiamante basta un 409 come
     * per gli altri ricontrolli persi sotto lock. Ogni altra PDOException viene rilanciata.
     * @param callable():(?ServiceResult) $fn
     */
    private function runGuardedTransaction(callable $fn): ?ServiceResult
    {
        try {
            return Db::transaction(Db::connection(), $fn);
        } catch (\PDOException $e) {
            if ($this->isDeadlock($e)) {
                return ServiceResult::fail(I18n::t('admin.claims.err_conflict'), 409);
            }
            throw $e;
        }
    }

    /** Riconosce un deadlock InnoDB (SQLSTATE 40001 / errno 1213). */
    private function isDeadlock(\PDOException $e): bool
    {
        $errno = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : 0;
        return (string) $e->getCode() === '40001' || $errno === 1213;
    }

    /**
     * Notifica al richiedente l'esito della rivendicazione: notifica in-app + email di cortesia.
     * @param array<string,mixed> $req riga di ClaimRepository::findDetail
     */
    private function notifyDecision(array $req, bool $approved, ?string $note): void
    {
        $userId = (int) $req['user_id'];
        $name   = (string) $req['profile_name'];

        if ($approved) {
            $title = I18n::t('notif.claim_approved.title');
            $body  = I18n::t('notif.claim_approved.body', ['name' => $name]);
            $path  = 'profilo';
        } else {
            $title = I18n::t('notif.claim_rejected.title');
            $body  = I18n::t('notif.claim_rejected.body', ['name' => $name]);
            if (($note ?? '') !== '') {
                $body .= ' — ' . $note;
            }
            $path  = 'rivendicazioni';
        }

        $this->notifications->create($userId, $approved ? 'claim_approved' : 'claim_rejected', $title, $body, $path);

        // Email di cortesia (best-effort: un fallimento non blocca l'operazione).
        $email = (string) ($req['user_email'] ?? '');
        if ($email !== '') {
            try {
                Mailer::send($email, $title, $body . '<br><br><a href="' . Config::absoluteUrl($path) . '">' . Config::absoluteUrl($path) . '</a>');
            } catch (\Throwable $e) {
                // Ignorato: la notifica in-app è già stata creata.
            }
        }
    }
}
