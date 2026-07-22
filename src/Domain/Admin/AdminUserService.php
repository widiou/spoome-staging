<?php

namespace Spoome\Domain\Admin;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Notifications\NotificationRepository;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Users\UserRepository;

/**
 * Azioni amministrative sugli utenti (sospensione, ruolo, verifica). Ogni mutazione è tracciata
 * nell'audit trail. Regole di salvaguardia: un admin non può auto-declassarsi/sospendersi, e non
 * si può rimuovere l'ULTIMO admin attivo (lock-out prevention).
 */
final class AdminUserService
{
    public const ROLES    = ['member', 'moderator', 'admin'];
    public const STATUSES = ['pending', 'active', 'suspended'];

    private UserRepository $users;
    private AuditRepository $audit;
    private ProfileRepository $profiles;
    private NotificationRepository $notifications;

    public function __construct(
        ?UserRepository $users = null,
        ?AuditRepository $audit = null,
        ?ProfileRepository $profiles = null,
        ?NotificationRepository $notifications = null
    ) {
        $this->users = $users ?? new UserRepository();
        $this->audit = $audit ?? new AuditRepository();
        $this->profiles = $profiles ?? new ProfileRepository();
        $this->notifications = $notifications ?? new NotificationRepository();
    }

    /**
     * Verifica/annulla la verifica del profilo PERSONALE dell'utente. Deterministico: usa
     * `findPersonalByUserId` (is_organization = 0, id ASC) — mai `findByUserId`, che per un utente
     * multi-profilo (personale + N pagine) colpirebbe una riga arbitraria. Le PAGINE-org si verificano
     * SOLO dal percorso dedicato by profile_id (`setOrgVerified`): nessuna verifica accidentale a catena.
     */
    public function toggleProfileVerified(int $adminId, int $targetUserId, string $ip): ServiceResult
    {
        $profile = $this->profiles->findPersonalByUserId($targetUserId);
        if ($profile === null) {
            return ServiceResult::fail(I18n::t('admin.users.err_no_profile'), 422);
        }
        $verify = $profile->verifiedAt === null; // se non verificato → verifica; altrimenti annulla
        $this->profiles->setVerified($profile->id, $verify);
        $this->audit->record($adminId, $verify ? 'profile.verify' : 'profile.unverify', 'profile', $profile->id, [
            'handle' => $profile->handle,
        ], $ip);
        if ($verify) {
            $this->notifications->create(
                $targetUserId,
                'profile_verified',
                I18n::t('notif.profile_verified.title'),
                I18n::t('notif.profile_verified.body'),
                'profilo'
            );
        }
        return ServiceResult::ok(null, ['message' => I18n::t($verify ? 'admin.users.done_verified_profile' : 'admin.users.done_unverified_profile')]);
    }

    /**
     * Verifica (o annulla la verifica di) una PAGINA-organizzazione DIRETTAMENTE per profile_id. È
     * l'ancora del badge derivato "verificato dalla società" (M3): `verifyingOrgsOf` accende il badge
     * dei membri solo se l'org-ancora ha `verified_at` non nullo. Disaccoppiato da user_id: il target è
     * risolto server-side per id (IDOR-safe) e DEVE essere `is_organization = 1` (guardia a livello dati,
     * defense-in-depth oltre allo stack auth→admin→step-up→CSRF della rotta). Idempotente: impostare lo
     * stato già presente non riscrive né ri-audita né ri-notifica. Notifica il proprietario solo se la
     * pagina è rivendicata (user_id non nullo); una pagina unclaimed non ha destinatario.
     */
    public function setOrgVerified(int $adminId, int $profileId, bool $verify, string $ip): ServiceResult
    {
        $row = $this->profiles->findAdminRowById($profileId);
        if ($row === null) {
            return ServiceResult::fail(I18n::t('admin.profiles.err_notfound'), 404);
        }
        if ((int) $row['is_organization'] !== 1) {
            // Il percorso by profile_id è SOLO per le pagine-org: un profilo persona va verificato
            // dalla scheda utente (findPersonalByUserId). Rifiuto esplicito, nessun effetto.
            return ServiceResult::fail(I18n::t('admin.profiles.err_not_org'), 422);
        }

        $alreadyVerified = $row['verified_at'] !== null;
        if ($alreadyVerified === $verify) {
            // Nessun cambiamento reale: evita audit/notifiche duplicate su doppio submit.
            return ServiceResult::ok(null, ['message' => I18n::t($verify ? 'admin.profiles.done_verified' : 'admin.profiles.done_unverified')]);
        }

        $this->profiles->setVerified((int) $row['id'], $verify);
        $this->audit->record($adminId, $verify ? 'profile.verify' : 'profile.unverify', 'profile', (int) $row['id'], [
            'handle' => $row['handle'],
            'kind'   => 'organization',
        ], $ip);

        $ownerId = $row['user_id'] !== null ? (int) $row['user_id'] : 0;
        if ($verify && $ownerId > 0) {
            $this->notifications->create(
                $ownerId,
                'profile_verified',
                I18n::t('notif.profile_verified.title'),
                I18n::t('notif.profile_verified.body'),
                'atleti/' . $row['handle']
            );
        }
        return ServiceResult::ok(null, ['message' => I18n::t($verify ? 'admin.profiles.done_verified' : 'admin.profiles.done_unverified')]);
    }

    public function suspend(int $adminId, int $targetId, string $ip): ServiceResult
    {
        if ($adminId === $targetId) {
            return ServiceResult::fail(I18n::t('admin.users.err_self'), 422);
        }
        $target = $this->users->findById($targetId);
        if ($target === null) {
            return ServiceResult::fail(I18n::t('admin.users.err_notfound'), 404);
        }
        // Non sospendere l'ultimo admin attivo.
        if ($target->isAdmin() && $this->users->activeAdminCount() <= 1) {
            return ServiceResult::fail(I18n::t('admin.users.err_last_admin'), 422);
        }
        $this->users->updateStatus($targetId, 'suspended');
        $this->audit->record($adminId, 'user.suspend', 'user', $targetId, ['email' => $target->email], $ip);
        return ServiceResult::ok(null, ['message' => I18n::t('admin.users.done_suspended')]);
    }

    public function reactivate(int $adminId, int $targetId, string $ip): ServiceResult
    {
        $target = $this->users->findById($targetId);
        if ($target === null) {
            return ServiceResult::fail(I18n::t('admin.users.err_notfound'), 404);
        }
        $this->users->updateStatus($targetId, 'active');
        $this->audit->record($adminId, 'user.reactivate', 'user', $targetId, ['email' => $target->email], $ip);
        return ServiceResult::ok(null, ['message' => I18n::t('admin.users.done_reactivated')]);
    }

    public function verifyEmail(int $adminId, int $targetId, string $ip): ServiceResult
    {
        $target = $this->users->findById($targetId);
        if ($target === null) {
            return ServiceResult::fail(I18n::t('admin.users.err_notfound'), 404);
        }
        $this->users->markVerifiedAndActive($targetId);
        $this->audit->record($adminId, 'user.verify_email', 'user', $targetId, ['email' => $target->email], $ip);
        return ServiceResult::ok(null, ['message' => I18n::t('admin.users.done_verified')]);
    }

    public function changeRole(int $adminId, int $targetId, string $role, string $ip): ServiceResult
    {
        if (!in_array($role, self::ROLES, true)) {
            return ServiceResult::fail(I18n::t('admin.users.err_role'), 422);
        }
        if ($adminId === $targetId) {
            // Evita che un admin si tolga da solo i privilegi (potenziale lock-out accidentale).
            return ServiceResult::fail(I18n::t('admin.users.err_self_role'), 422);
        }
        $target = $this->users->findById($targetId);
        if ($target === null) {
            return ServiceResult::fail(I18n::t('admin.users.err_notfound'), 404);
        }
        // Declassare un admin: solo se non è l'ultimo attivo.
        if ($target->isAdmin() && $role !== 'admin' && $this->users->activeAdminCount() <= 1) {
            return ServiceResult::fail(I18n::t('admin.users.err_last_admin'), 422);
        }
        if ($target->role === $role) {
            return ServiceResult::ok(null, ['message' => I18n::t('admin.users.done_role')]);
        }
        $this->users->updateRole($targetId, $role);
        $this->audit->record($adminId, 'user.change_role', 'user', $targetId, [
            'email' => $target->email, 'from' => $target->role, 'to' => $role,
        ], $ip);
        return ServiceResult::ok(null, ['message' => I18n::t('admin.users.done_role')]);
    }
}
