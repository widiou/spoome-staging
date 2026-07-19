<?php

namespace Spoome\Domain\Feed;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Support\Str;
use Spoome\Domain\Auth\RateLimiter;
use Spoome\Domain\Notifications\NotificationService;

/**
 * Interazioni sui post (like, commenti). Mantiene i contatori denormalizzati (via PostRepository)
 * e notifica il proprietario del post (riusando NotificationService). Contratto: ServiceResult.
 * Rate-limit anti-spam per profilo (come i post) su like e commenti.
 */
final class PostEngagementService
{
    private const MAX_LIKES = 60;      // toggle like per finestra
    private const MAX_COMMENTS = 20;   // commenti per finestra
    private const WINDOW_MIN = 10;     // minuti

    private PostRepository $posts;
    private NotificationService $notifs;
    private RateLimiter $limiter;

    public function __construct(?PostRepository $posts = null, ?NotificationService $notifs = null, ?RateLimiter $limiter = null)
    {
        $this->posts  = $posts ?? new PostRepository();
        $this->notifs = $notifs ?? new NotificationService();
        $this->limiter = $limiter ?? new RateLimiter();
    }

    /** @return ServiceResult data: {liked:bool, count:int} */
    public function toggleLike(int $actorProfileId, int $postId, string $ip = 'unknown'): ServiceResult
    {
        $post = $this->posts->find($postId);
        if ($post === null) {
            return ServiceResult::fail(I18n::t('feed.err_post_notfound'), 404);
        }
        if ($this->limiter->tooManyByKey('like:' . $actorProfileId, self::MAX_LIKES, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('feed.err_throttled_like'), 429);
        }
        $this->limiter->hit('like:' . $actorProfileId, $ip);
        $res = $this->posts->toggleLike($postId, $actorProfileId);

        // Notifica solo al primo like e mai a sé stessi.
        if ($res['liked'] && (int) $post['profile_id'] !== $actorProfileId) {
            $this->notifs->postLike($actorProfileId, (int) $post['profile_id']);
        }
        return ServiceResult::ok($res);
    }

    /** @return ServiceResult data: {id:int, count:int} */
    public function comment(int $actorProfileId, int $postId, string $body, string $ip = 'unknown'): ServiceResult
    {
        $post = $this->posts->find($postId);
        if ($post === null) {
            return ServiceResult::fail(I18n::t('feed.err_post_notfound'), 404);
        }
        $body = trim($body);
        if ($body === '') {
            return ServiceResult::fail(I18n::t('feed.err_comment_empty'), 422);
        }
        if ($this->limiter->tooManyByKey('cmt:' . $actorProfileId, self::MAX_COMMENTS, self::WINDOW_MIN)) {
            return ServiceResult::fail(I18n::t('feed.err_throttled_comment'), 429);
        }
        $this->limiter->hit('cmt:' . $actorProfileId, $ip);
        $body = Str::clamp($body, 1000);
        $id   = $this->posts->addComment($postId, $actorProfileId, $body);

        if ((int) $post['profile_id'] !== $actorProfileId) {
            $this->notifs->postComment($actorProfileId, (int) $post['profile_id']);
        }
        $fresh = $this->posts->find($postId);
        return ServiceResult::ok(['id' => $id, 'count' => (int) ($fresh['comments_count'] ?? 0)]);
    }

    public function deleteComment(int $actorProfileId, int $commentId, bool $isAdmin): ServiceResult
    {
        $comment = $this->posts->findComment($commentId);
        if ($comment === null) {
            return ServiceResult::fail(I18n::t('feed.err_comment_notfound'), 404);
        }
        $post = $this->posts->find((int) $comment['post_id']);
        // Autorizzato: autore del commento, proprietario del post, o admin.
        $ownsComment = (int) $comment['profile_id'] === $actorProfileId;
        $ownsPost    = $post !== null && (int) $post['profile_id'] === $actorProfileId;
        if (!$ownsComment && !$ownsPost && !$isAdmin) {
            return ServiceResult::fail(I18n::t('feed.err_forbidden'), 403);
        }
        $this->posts->deleteComment($commentId, (int) $comment['post_id']);
        return ServiceResult::ok(['post_id' => (int) $comment['post_id']]);
    }
}
