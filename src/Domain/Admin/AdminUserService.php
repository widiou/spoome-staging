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

    /** Verifica/annulla la verifica del profilo dell'utente. Scrive verified_at + audit + notifica. */
    public function toggleProfileVerified(int $adminId, int $targetUserId, string $ip): ServiceResult
    {
        $profile = $this->profiles->findByUserId($targetUserId);
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
