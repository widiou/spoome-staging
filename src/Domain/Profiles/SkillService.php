<?php

namespace Spoome\Domain\Profiles;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Support\Str;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Connections\ConnectionRepository;
use Spoome\Domain\Notifications\NotificationService;

/**
 * Logica di dominio delle competenze del profilo + endorsement.
 * - Gestione proprietaria (add/reorder/remove): ownership a livello SQL (WHERE profile_id) nel repository.
 * - Endorse: solo connessioni ACCEPTED, mai self-endorse, rate-limit, notifica al proprietario.
 * Riusabile identico da Web e (in futuro) API. Restituisce sempre ServiceResult.
 */
final class SkillService
{
    public const MAX_SKILLS = 20;
    private const LABEL_MAX = 60;

    private const ENDORSE_MAX = 60;   // colpi
    private const ENDORSE_WINDOW = 10; // minuti

    // Gestione proprietaria delle skill (add/remove/reorder): throttle per profilo, parità con endorse
    // e con il nuovo endpoint Bearer POST /me/skills. Chiave dedicata "skill:{pid}".
    private const MANAGE_MAX = 30;    // colpi
    private const MANAGE_WINDOW = 10; // minuti

    private SkillRepository $repo;
    private ConnectionRepository $connections;
    private RateLimiter $limiter;
    private NotificationService $notifications;

    public function __construct(
        ?SkillRepository $repo = null,
        ?ConnectionRepository $connections = null,
        ?RateLimiter $limiter = null,
        ?NotificationService $notifications = null
    ) {
        $this->repo = $repo ?? new SkillRepository();
        $this->connections = $connections ?? new ConnectionRepository();
        $this->limiter = $limiter ?? new RateLimiter();
        $this->notifications = $notifications ?? new NotificationService();
    }

    /* --------------------------------------------------- gestione proprietaria ---- */

    public function addSkill(int $ownerProfileId, string $label, string $ip = 'unknown'): ServiceResult
    {
        $label = $this->normalizeLabel($label);
        if ($label === '') {
            return ServiceResult::fail(I18n::t('skill.error.empty'), 422, ['label' => I18n::t('skill.error.empty')]);
        }
        $label = Str::clamp($label, self::LABEL_MAX);
        if ($this->limiter->tooManyByKey('skill:' . $ownerProfileId, self::MANAGE_MAX, self::MANAGE_WINDOW)) {
            return ServiceResult::fail(I18n::t('skill.error.throttled'), 429);
        }
        if ($this->repo->countForProfile($ownerProfileId) >= self::MAX_SKILLS) {
            return ServiceResult::fail(I18n::t('skill.error.max', ['max' => (string) self::MAX_SKILLS]), 422);
        }
        if ($this->repo->labelExists($ownerProfileId, $label)) {
            return ServiceResult::fail(I18n::t('skill.error.duplicate'), 422, ['label' => I18n::t('skill.error.duplicate')]);
        }
        // Posizione = in coda.
        $position = $this->repo->countForProfile($ownerProfileId);
        $id = $this->repo->add($ownerProfileId, $label, $position);
        $this->limiter->hit('skill:' . $ownerProfileId, $ip);
        // Restituisce anche la label normalizzata così il chiamante può renderizzare il frammento
        // (chip skill) senza rileggere il DB — aggiunta retro-compatibile all'envelope.
        return ServiceResult::ok(['id' => $id, 'label' => $label], [], 201);
    }

    public function removeSkill(int $ownerProfileId, int $skillId, string $ip = 'unknown'): ServiceResult
    {
        if ($this->limiter->tooManyByKey('skill:' . $ownerProfileId, self::MANAGE_MAX, self::MANAGE_WINDOW)) {
            return ServiceResult::fail(I18n::t('skill.error.throttled'), 429);
        }
        $this->repo->delete($skillId, $ownerProfileId);
        $this->limiter->hit('skill:' . $ownerProfileId, $ip);
        return ServiceResult::noContent();
    }

    /** @param int[] $orderedIds */
    public function reorder(int $ownerProfileId, array $orderedIds, string $ip = 'unknown'): ServiceResult
    {
        if ($this->limiter->tooManyByKey('skill:' . $ownerProfileId, self::MANAGE_MAX, self::MANAGE_WINDOW)) {
            return ServiceResult::fail(I18n::t('skill.error.throttled'), 429);
        }
        $ids = array_values(array_filter(array_map('intval', $orderedIds), static fn ($i) => $i > 0));
        if ($ids !== []) {
            $this->repo->reorder($ownerProfileId, $ids);
            $this->limiter->hit('skill:' . $ownerProfileId, $ip);
        }
        return ServiceResult::ok(['ok' => true]);
    }

    /* ---------------------------------------------------------------- endorse ---- */

    public function endorse(int $endorserProfileId, int $skillId, string $ip): ServiceResult
    {
        $ownerPid = $this->repo->findOwnerProfileId($skillId);
        if ($ownerPid === null) {
            return ServiceResult::fail(I18n::t('skill.error.not_found'), 404);
        }
        if ($ownerPid === $endorserProfileId) {
            return ServiceResult::fail(I18n::t('skill.error.self'), 422);
        }
        if (!$this->connections->areConnected($endorserProfileId, $ownerPid)) {
            return ServiceResult::fail(I18n::t('skill.error.not_connected'), 403);
        }
        if ($this->limiter->tooManyByKey('endorse:' . $endorserProfileId, self::ENDORSE_MAX, self::ENDORSE_WINDOW)) {
            return ServiceResult::fail(I18n::t('skill.error.throttled'), 429);
        }

        $created = $this->repo->endorse($skillId, $endorserProfileId);
        if ($created) {
            $this->limiter->hit('endorse:' . $endorserProfileId, $ip);
            $label = (string) ($this->repo->findLabel($skillId) ?? '');
            $this->notifications->skillEndorsed($endorserProfileId, $ownerPid, $label);
        }

        return ServiceResult::ok([
            'endorsed' => true,
            'count'    => $this->repo->endorsementCount($skillId),
        ]);
    }

    public function removeEndorsement(int $endorserProfileId, int $skillId, string $ip): ServiceResult
    {
        $ownerPid = $this->repo->findOwnerProfileId($skillId);
        if ($ownerPid === null) {
            return ServiceResult::fail(I18n::t('skill.error.not_found'), 404);
        }
        if ($this->limiter->tooManyByKey('endorse:' . $endorserProfileId, self::ENDORSE_MAX, self::ENDORSE_WINDOW)) {
            return ServiceResult::fail(I18n::t('skill.error.throttled'), 429);
        }
        $removed = $this->repo->removeEndorsement($skillId, $endorserProfileId);
        if ($removed) {
            $this->limiter->hit('endorse:' . $endorserProfileId, $ip);
        }
        return ServiceResult::ok([
            'endorsed' => false,
            'count'    => $this->repo->endorsementCount($skillId),
        ]);
    }

    /* ------------------------------------------------------------------ helper ---- */

    /** trim + collassa gli spazi multipli. */
    private function normalizeLabel(string $label): string
    {
        $label = trim($label);
        return (string) preg_replace('/\s+/u', ' ', $label);
    }
}
