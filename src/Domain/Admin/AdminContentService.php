<?php

namespace Spoome\Domain\Admin;

use Spoome\Core\I18n;
use Spoome\Core\ServiceResult;
use Spoome\Domain\Feed\PostRepository;

/**
 * Moderazione dei contenuti pubblici (post del feed). Per privacy, i messaggi diretti — che sono
 * privati fra profili connessi — NON sono esposti qui. Ogni rimozione è tracciata nell'audit.
 */
final class AdminContentService
{
    private PostRepository $posts;
    private AuditRepository $audit;

    public function __construct(?PostRepository $posts = null, ?AuditRepository $audit = null)
    {
        $this->posts = $posts ?? new PostRepository();
        $this->audit = $audit ?? new AuditRepository();
    }

    /** @return array{items:array<int,array<string,mixed>>, total:int} */
    public function recentPosts(int $page = 1, int $perPage = 30): array
    {
        return $this->posts->recentWithAuthor($page, $perPage);
    }

    public function deletePost(int $adminId, int $postId, string $ip): ServiceResult
    {
        $post = $this->posts->find($postId);
        if ($post === null) {
            return ServiceResult::fail(I18n::t('admin.mod.err_notfound'), 404);
        }
        $this->posts->deleteById($postId);
        $this->audit->record($adminId, 'post.delete', 'post', $postId, [
            'author_profile' => (int) $post['profile_id'],
            'excerpt'        => mb_substr((string) $post['body'], 0, 80),
        ], $ip);
        return ServiceResult::ok(null, ['message' => I18n::t('admin.mod.done_deleted')]);
    }
}
