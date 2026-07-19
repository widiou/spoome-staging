<?php

namespace Spoome\Domain\Follows;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Feed\ActivityRepository;
use Spoome\Domain\Notifications\NotificationService;

/**
 * Regole del follow (asimmetrico). Vincoli: non ci si segue da soli, non si seguono profili privati,
 * rate-limit anti-abuso. Riusato identico da Web (sessione) e API (Bearer).
 */
final class FollowService
{
    private const MAX_ACTIONS = 60;   // azioni follow/unfollow
    private const WINDOW_MIN  = 10;   // nella finestra (minuti)

    private FollowRepository $follows;
    private RateLimiter $limiter;
    private ActivityRepository $activities;
    private NotificationService $notifications;

    public function __construct(?FollowRepository $follows = null, ?RateLimiter $limiter = null, ?ActivityRepository $activities = null, ?NotificationService $notifications = null)
    {
        $this->follows = $follows ?? new FollowRepository();
        $this->limiter = $limiter ?? new RateLimiter();
        $this->activities = $activities ?? new ActivityRepository();
        $this->notifications = $notifications ?? new NotificationService();
    }

    /**
     * L'attore ($actorProfileId) inizia a seguire il target.
     * @return ServiceResult ok con lo stato {following, followers_count, following_count} del target.
     */
    public function follow(int $actorProfileId, int $targetId, string $targetVisibility, string $targetName = '', string $ip = 'unknown'): ServiceResult
    {
        if ($targetId === $actorProfileId) {
            return ServiceResult::fail(I18n::t('follow.error.self'), 422);
        }
        if ($targetVisibility === 'private') {
            return ServiceResult::fail(I18n::t('follow.error.private'), 403);
        }
        if ($this->limiter->tooManyByKey('follow:' . $actorProfileId, self::MAX_ACTIONS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('follow.error.throttled'), 429);
        }

        $created = $this->follows->follow($actorProfileId, $targetId);
        if ($created) {
            $this->limiter->hit('follow:' . $actorProfileId, $ip);
            $this->activities->record($actorProfileId, ActivityRepository::FOLLOWED, $targetId, $targetName);
            $this->notifications->follow($actorProfileId, $targetId);
        }
        return ServiceResult::ok($this->state($actorProfileId, $targetId));
    }

    /** L'attore smette di seguire il target (idempotente). */
    public function unfollow(int $actorProfileId, int $targetId, string $ip = 'unknown'): ServiceResult
    {
        if ($this->limiter->tooManyByKey('follow:' . $actorProfileId, self::MAX_ACTIONS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('follow.error.throttled'), 429);
        }
        $this->follows->unfollow($actorProfileId, $targetId);
        $this->limiter->hit('follow:' . $actorProfileId, $ip);
        return ServiceResult::ok($this->state($actorProfileId, $targetId));
    }

    /** Stato pubblico della relazione verso il target (per aggiornare UI/risposta API). */
    private function state(int $actorProfileId, int $targetId): array
    {
        return [
            'following'       => $this->follows->isFollowing($actorProfileId, $targetId),
            'followers_count' => $this->follows->followerCount($targetId),
            'following_count' => $this->follows->followingCount($targetId),
        ];
    }
}
