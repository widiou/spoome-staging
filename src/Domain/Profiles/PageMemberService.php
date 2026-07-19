<?php

namespace Spoome\Domain\Profiles;

use PDO;
use Spoome\Core\Db;
use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Notifications\NotificationRepository;
use Spoome\Support\Str;
use Throwable;

/**
 * Gestione dei MEMBRI di una pagina (org): inviti, accettazione/rifiuto, cambio ruolo, rimozione.
 *
 * Modello (LinkedIn-like): una pagina ha un roster in `profile_members` con ruoli
 * owner > admin > editor (l'authz vive in ActingContext). Questo Service è la logica di dominio
 * PURA — nessun HTTP/sessione: prende id già risolti e ritorna sempre un `ServiceResult`.
 *
 * Invarianti di sicurezza:
 *  - Solo owner/admin invitano/gestiscono (authz via ActingContext::canActAs, ri-valida ad ogni call).
 *  - Un invito conferisce SOLO 'admin' o 'editor' (mai owner: quello passa da un transfer esplicito).
 *  - accept/decline agiscono SOLO sul proprio invito (filtro a livello dati su invited_user_id).
 *  - Safeguard ULTIMO-OWNER: una pagina resta SEMPRE con >= 1 owner. Le operazioni che possono
 *    ridurre gli owner (remove, changeRole demozione) girano in transazione con SELECT ... FOR UPDATE
 *    sulle righe owner → il conteggio è serializzato e il safeguard non è aggirabile in concorrenza.
 *  - `admin` non può toccare un `owner`: solo un owner gestisce gli owner.
 */
final class PageMemberService
{
    /** Ruoli conferibili/assegnabili via questo service (mai 'owner'). */
    public const ASSIGNABLE_ROLES = ['admin', 'editor'];

    private PDO $pdo;
    private ProfileRepository $profiles;
    private ProfileMemberRepository $members;
    private MemberInviteRepository $invites;
    private ActingContext $acting;
    private NotificationRepository $notifications;

    public function __construct(
        ?PDO $pdo = null,
        ?ProfileRepository $profiles = null,
        ?ProfileMemberRepository $members = null,
        ?MemberInviteRepository $invites = null,
        ?ActingContext $acting = null,
        ?NotificationRepository $notifications = null
    ) {
        $this->pdo           = $pdo ?? Db::connection();
        $this->profiles      = $profiles ?? new ProfileRepository($this->pdo);
        $this->members       = $members ?? new ProfileMemberRepository($this->pdo);
        $this->invites       = $invites ?? new MemberInviteRepository($this->pdo);
        $this->acting        = $acting ?? new ActingContext($this->members, $this->profiles);
        $this->notifications = $notifications ?? new NotificationRepository($this->pdo);
    }

    /**
     * Invita l'utente il cui profilo PERSONALE ha handle $inviteeHandle a diventare membro della
     * pagina $pageProfileId con ruolo $role ('admin'|'editor').
     *
     * @return ServiceResult ok(['invite_id'=>int]) | fail(errore, code)
     */
    public function invite(int $actingUserId, int $pageProfileId, string $inviteeHandle, string $role): ServiceResult
    {
        if (!in_array($role, self::ASSIGNABLE_ROLES, true)) {
            return ServiceResult::fail(I18n::t('member.error.role_invalid'), 422, ['role' => I18n::t('member.error.role_invalid')]);
        }
        // Authz: solo owner/admin della pagina.
        if (!$this->acting->canActAs($actingUserId, $pageProfileId, 'admin')) {
            return ServiceResult::fail(I18n::t('member.error.forbidden'), 403);
        }

        $page = $this->profiles->findById($pageProfileId);
        if ($page === null) {
            return ServiceResult::fail(I18n::t('member.error.page_not_found'), 404);
        }

        $handle = Str::handle(trim($inviteeHandle));
        if ($handle === '') {
            return ServiceResult::fail(I18n::t('member.error.invitee_not_found'), 404, ['handle' => I18n::t('member.error.invitee_not_found')]);
        }
        $invitee = $this->profiles->findByHandle($handle);
        if ($invitee === null || $invitee->userId <= 0) {
            return ServiceResult::fail(I18n::t('member.error.invitee_not_found'), 404, ['handle' => I18n::t('member.error.invitee_not_found')]);
        }
        $inviteeUserId = $invitee->userId;

        // L'invito è "per handle del profilo PERSONALE": rifiuta gli handle di pagine org.
        $personal = $this->profiles->findPersonalByUserId($inviteeUserId);
        if ($personal === null || $personal->id !== $invitee->id) {
            return ServiceResult::fail(I18n::t('member.error.invitee_not_personal'), 422, ['handle' => I18n::t('member.error.invitee_not_personal')]);
        }

        if ($inviteeUserId === $actingUserId) {
            return ServiceResult::fail(I18n::t('member.error.self_invite'), 422);
        }
        if ($this->members->isMember($inviteeUserId, $pageProfileId)) {
            return ServiceResult::fail(I18n::t('member.error.already_member'), 422);
        }
        if ($this->invites->findPendingFor($pageProfileId, $inviteeUserId) !== null) {
            return ServiceResult::fail(I18n::t('member.error.already_invited'), 422);
        }

        $inviteId = $this->invites->createOrReset($pageProfileId, $inviteeUserId, $actingUserId, $role, Str::token(16));

        // Notifica all'invitato (riuso NotificationRepository). Best-effort: un fallimento non annulla
        // l'invito, già persistito.
        try {
            $this->notifications->create(
                $inviteeUserId,
                'page_invite',
                I18n::t('notif.page_invite.title'),
                I18n::t('notif.page_invite.body', ['page' => $page->displayName]),
                '/pagine/inviti'
            );
        } catch (Throwable $e) {
            \Spoome\Core\Logger::error('page_invite notification failed', ['exception' => $e->getMessage()]);
        }

        return ServiceResult::ok(['invite_id' => $inviteId], [], 201);
    }

    /**
     * L'invitato ACCETTA l'invito: materializza la membership + segna l'invito 'accepted' + notifica
     * chi ha invitato. Idempotente: una seconda accept non duplica nulla né rinotifica.
     */
    public function accept(int $userId, int $inviteId): ServiceResult
    {
        $invite = $this->invites->findByIdForInvitee($inviteId, $userId);
        if ($invite === null) {
            return ServiceResult::fail(I18n::t('member.error.invite_not_found'), 404);
        }
        if ($invite['status'] === 'accepted') {
            return ServiceResult::ok(['profile_id' => (int) $invite['profile_id']]); // idempotente
        }
        if ($invite['status'] !== 'pending') {
            return ServiceResult::fail(I18n::t('member.error.invite_not_pending'), 422);
        }

        $pageProfileId = (int) $invite['profile_id'];
        $role          = (string) $invite['role'];
        $inviterId     = (int) $invite['invited_by_user_id'];

        try {
            $changed = Db::transaction($this->pdo, function () use ($inviteId, $pageProfileId, $userId, $role, $inviterId): int {
                // markResponded riesce SOLO se ancora 'pending' → serializza due accept concorrenti:
                // il perdente vede rowCount 0 e non materializza/notifica due volte.
                $ok = $this->invites->markResponded($inviteId, 'accepted');
                if ($ok === 0) {
                    return 0;
                }
                $this->members->addMember($pageProfileId, $userId, $role, $inviterId); // INSERT IGNORE
                return 1;
            });
        } catch (Throwable $e) {
            \Spoome\Core\Logger::error('page_invite accept failed', ['exception' => $e->getMessage()]);
            return ServiceResult::fail(I18n::t('member.error.accept_failed'), 500);
        }

        if ($changed === 0) {
            // Un'altra richiesta ha già chiuso l'invito nel frattempo → esito idempotente.
            return ServiceResult::ok(['profile_id' => $pageProfileId]);
        }

        $page = $this->profiles->findById($pageProfileId);
        $who  = $this->profiles->findPersonalByUserId($userId);
        try {
            $this->notifications->create(
                $inviterId,
                'page_invite_accepted',
                I18n::t('notif.page_invite_accepted.title'),
                I18n::t('notif.page_invite_accepted.body', [
                    'name' => $who?->displayName ?? I18n::t('member.someone'),
                    'page' => $page?->displayName ?? '',
                ]),
                '/pagine/inviti'
            );
        } catch (Throwable $e) {
            \Spoome\Core\Logger::error('page_invite_accepted notification failed', ['exception' => $e->getMessage()]);
        }

        return ServiceResult::ok(['profile_id' => $pageProfileId]);
    }

    /** L'invitato RIFIUTA l'invito. Idempotente: se già chiuso, esito coerente senza rinotifica. */
    public function decline(int $userId, int $inviteId): ServiceResult
    {
        $invite = $this->invites->findByIdForInvitee($inviteId, $userId);
        if ($invite === null) {
            return ServiceResult::fail(I18n::t('member.error.invite_not_found'), 404);
        }
        if ($invite['status'] === 'accepted') {
            return ServiceResult::fail(I18n::t('member.error.invite_already_accepted'), 422);
        }
        if ($invite['status'] !== 'pending') {
            return ServiceResult::noContent(); // già declined/revoked → idempotente
        }

        $changed = $this->invites->markResponded($inviteId, 'declined');
        if ($changed > 0) {
            $inviterId = (int) $invite['invited_by_user_id'];
            $page      = $this->profiles->findById((int) $invite['profile_id']);
            $who       = $this->profiles->findPersonalByUserId($userId);
            try {
                $this->notifications->create(
                    $inviterId,
                    'page_invite_declined',
                    I18n::t('notif.page_invite_declined.title'),
                    I18n::t('notif.page_invite_declined.body', [
                        'name' => $who?->displayName ?? I18n::t('member.someone'),
                        'page' => $page?->displayName ?? '',
                    ]),
                    null
                );
            } catch (Throwable $e) {
                \Spoome\Core\Logger::error('page_invite_declined notification failed', ['exception' => $e->getMessage()]);
            }
        }

        return ServiceResult::noContent();
    }

    /**
     * Cambia il ruolo di un membro della pagina. Regole:
     *  - authz: acting owner/admin;
     *  - $newRole in {'admin','editor'} (promozione a owner solo via transfer, non qui);
     *  - `admin` non può toccare un `owner`;
     *  - non declassare l'ULTIMO owner (safeguard, transazione + FOR UPDATE).
     */
    public function changeRole(int $actingUserId, int $pageProfileId, int $targetUserId, string $newRole): ServiceResult
    {
        if (!in_array($newRole, self::ASSIGNABLE_ROLES, true)) {
            return ServiceResult::fail(I18n::t('member.error.role_invalid'), 422, ['role' => I18n::t('member.error.role_invalid')]);
        }
        if (!$this->acting->canActAs($actingUserId, $pageProfileId, 'admin')) {
            return ServiceResult::fail(I18n::t('member.error.forbidden'), 403);
        }

        $actingRole = $this->acting->roleFor($actingUserId, $pageProfileId);
        $targetRole = $this->members->roleOf($targetUserId, $pageProfileId);
        if ($targetRole === null) {
            return ServiceResult::fail(I18n::t('member.error.not_member'), 404);
        }
        if ($targetRole === $newRole) {
            return ServiceResult::ok(['role' => $newRole]); // no-op idempotente
        }
        // Solo un owner gestisce gli owner.
        if ($targetRole === 'owner' && $actingRole !== 'owner') {
            return ServiceResult::fail(I18n::t('member.error.owner_only'), 403);
        }

        try {
            $result = Db::transaction($this->pdo, function () use ($pageProfileId, $targetUserId, $targetRole, $newRole): ?string {
                // Blocca+conta gli owner PRIMA di agire: se stiamo declassando un owner e ne resta 0 → stop.
                $owners = $this->members->ownerUserIdsForUpdate($pageProfileId);
                if ($targetRole === 'owner' && count($owners) <= 1) {
                    return 'last_owner';
                }
                $this->members->setRole($pageProfileId, $targetUserId, $newRole);
                // Se declassiamo l'owner primario denormalizzato, sposta profiles.user_id su un altro owner.
                if ($targetRole === 'owner') {
                    $this->reassignPrimaryOwnerIfNeeded($pageProfileId, $targetUserId, $owners);
                }
                return null;
            });
        } catch (Throwable $e) {
            \Spoome\Core\Logger::error('page member changeRole failed', ['exception' => $e->getMessage()]);
            return ServiceResult::fail(I18n::t('member.error.update_failed'), 500);
        }

        if ($result === 'last_owner') {
            return ServiceResult::fail(I18n::t('member.error.last_owner'), 422);
        }

        return ServiceResult::ok(['role' => $newRole]);
    }

    /**
     * Rimuove un membro dalla pagina. Regole: authz owner/admin; `admin` non rimuove un `owner`;
     * safeguard ultimo-owner; se si rimuove l'owner primario denormalizzato, riassegna
     * `profiles.user_id` a un altro owner (coerenza back-compat/claim).
     */
    public function removeMember(int $actingUserId, int $pageProfileId, int $targetUserId): ServiceResult
    {
        if (!$this->acting->canActAs($actingUserId, $pageProfileId, 'admin')) {
            return ServiceResult::fail(I18n::t('member.error.forbidden'), 403);
        }

        $actingRole = $this->acting->roleFor($actingUserId, $pageProfileId);
        $targetRole = $this->members->roleOf($targetUserId, $pageProfileId);
        if ($targetRole === null) {
            return ServiceResult::fail(I18n::t('member.error.not_member'), 404);
        }
        if ($targetRole === 'owner' && $actingRole !== 'owner') {
            return ServiceResult::fail(I18n::t('member.error.owner_only'), 403);
        }

        try {
            $result = Db::transaction($this->pdo, function () use ($pageProfileId, $targetUserId, $targetRole): ?string {
                $owners = $this->members->ownerUserIdsForUpdate($pageProfileId);
                if ($targetRole === 'owner' && count($owners) <= 1) {
                    return 'last_owner';
                }
                $this->members->removeMember($pageProfileId, $targetUserId);
                // Owner primario denormalizzato rimosso → spostalo su un altro owner rimasto.
                $this->reassignPrimaryOwnerIfNeeded($pageProfileId, $targetUserId, $owners);
                return null;
            });
        } catch (Throwable $e) {
            \Spoome\Core\Logger::error('page member remove failed', ['exception' => $e->getMessage()]);
            return ServiceResult::fail(I18n::t('member.error.remove_failed'), 500);
        }

        if ($result === 'last_owner') {
            return ServiceResult::fail(I18n::t('member.error.last_owner'), 422);
        }

        return ServiceResult::noContent();
    }

    /**
     * Revoca un invito PENDENTE (owner/admin). Idempotente: se non c'è nulla di pendente, no-content.
     */
    public function revokeInvite(int $actingUserId, int $pageProfileId, int $invitedUserId): ServiceResult
    {
        if (!$this->acting->canActAs($actingUserId, $pageProfileId, 'admin')) {
            return ServiceResult::fail(I18n::t('member.error.forbidden'), 403);
        }
        $this->invites->revoke($pageProfileId, $invitedUserId);
        return ServiceResult::noContent();
    }

    /**
     * Roster della pagina per la UI (F4): membri con ruolo + inviti pendenti.
     * @return ServiceResult ok(['members'=>array, 'pending_invites'=>array])
     */
    public function members(int $pageProfileId): ServiceResult
    {
        return ServiceResult::ok([
            'members'         => $this->members->membersWithProfile($pageProfileId),
            'pending_invites' => $this->invites->pendingForPage($pageProfileId),
        ]);
    }

    /**
     * Se $removedOrDemotedUserId è l'owner primario denormalizzato (profiles.user_id), sposta la
     * proprietà primaria su un altro owner rimasto. $ownersBefore è la lista bloccata FOR UPDATE nella
     * stessa transazione (contiene ancora il target). Chiamare SOLO dentro la transazione del safeguard.
     * @param array<int,int> $ownersBefore
     */
    private function reassignPrimaryOwnerIfNeeded(int $pageProfileId, int $removedOrDemotedUserId, array $ownersBefore): void
    {
        $raw = $this->profiles->findRawById($pageProfileId);
        if ($raw === null || $raw['user_id'] === null || (int) $raw['user_id'] !== $removedOrDemotedUserId) {
            return; // non era l'owner primario → niente da fare
        }
        foreach ($ownersBefore as $ownerId) {
            if ($ownerId !== $removedOrDemotedUserId) {
                $this->profiles->assignOwner($pageProfileId, $ownerId);
                return;
            }
        }
        // Nessun altro owner: non dovrebbe accadere (safeguard blocca prima), ma per sicurezza non
        // azzeriamo l'owner primario qui — resta invariato.
    }
}
