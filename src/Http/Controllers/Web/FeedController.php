<?php

namespace Spoome\Http\Controllers\Web;

use Spoome\Core\I18n;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\ServiceResult;
use Spoome\Core\Session;
use Spoome\Core\View;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Feed\FeedPresenter;
use Spoome\Domain\Feed\FeedService;
use Spoome\Domain\Feed\PostRepository;
use Spoome\Domain\Feed\PostService;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\Profile;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\Controller;

/**
 * Feed ibrido (area autenticata): timeline (post + attività) dei profili seguiti/connessi + compositore post.
 */
final class FeedController extends Controller
{
    public function index(Request $request): void
    {
        $me = $this->me($request);
        if ($me === null) {
            Response::redirect('rivendicazioni');
            return;
        }
        $page = max(1, (int) $request->input('pagina', 1));
        $feed = (new FeedService())->timeline($me->id, $page);

        // Cold-start: feed vuoto alla prima pagina → suggerisci chi seguire.
        $suggested = [];
        if (!$feed['items'] && $page === 1) {
            $suggested = (new ProfileRepository())->suggestedFor($me->id, $me->sportId, 6);
        }

        View::render('feed/index', [
            'title'       => $this->title('nav.feed'),
            'feed'        => $feed['items'],
            'hasMore'     => $feed['has_more'],
            'page'        => $page,
            'myHandle'    => $me->handle,
            'suggested'   => $suggested,
            'notice'      => Session::takeFlash(),
        ], 'base');
    }

    public function createPost(Request $request): void
    {
        $me = $this->writeActor($request);
        if ($me === null) {
            return; // writeActor ha già risposto (403 / redirect)
        }
        $result = (new PostService())->create($me->id, $request->body(), $request->ip());
        // Ramo async: allega il frammento del nuovo post (STESSO partial della timeline, e()-scaped)
        // così il composer lo prepende in-place senza reload; mai markup costruito da input client.
        if ($result->ok && $request->wantsJson()) {
            $result = $this->withPostHtml($result, $me);
        }
        $this->respond($request, $result, 'feed');
    }

    /** Rende il post appena creato con lo stesso partial usato dalla timeline e lo allega come data.html. */
    private function withPostHtml(ServiceResult $result, Profile $me): ServiceResult
    {
        $postId = (int) ($result->data['id'] ?? 0);
        $post   = $postId > 0 ? (new PostRepository())->find($postId) : null;
        $author = (new ProfileRepository())->cardsByIds([$me->id])[$me->id] ?? null;
        if ($post === null || $author === null) {
            return $result; // fallback: il client farà reload
        }
        $preview = null;
        $hash = (string) ($post['link_preview_url_hash'] ?? '');
        if ($hash !== '') {
            $row = (new \Spoome\Domain\Links\LinkPreviewRepository())->find($hash);
            if ($row !== null) {
                $preview = \Spoome\Domain\Links\LinkPreviewPresenter::card($row);
            }
        }
        $item = FeedPresenter::item(
            ['kind' => 'post', 'id' => $postId, 'text' => (string) $post['body'], 'created_at' => $post['created_at']],
            $author,
            ['likes_count' => 0, 'comments_count' => 0, 'liked' => false, 'comments' => [], 'link_preview' => $preview]
        );
        $html = View::partial('feed-item', ['it' => $item, 'myHandle' => $me->handle]);
        return ServiceResult::ok($result->data + ['html' => $html], $result->meta, $result->code);
    }

    public function deletePost(Request $request): void
    {
        $me = $this->writeActor($request);
        if ($me === null) {
            return;
        }
        // Scoping a livello dati: PostService::delete filtra WHERE profile_id = acting → un membro
        // può cancellare solo i post del profilo per cui agisce.
        $result = (new PostService())->delete((int) $request->param('id'), $me->id);
        $this->respond($request, $result, 'feed');
    }

    public function like(Request $request): void
    {
        $me = $this->writeActor($request);
        if ($me === null) {
            return;
        }
        $result = (new \Spoome\Domain\Feed\PostEngagementService())
            ->toggleLike($me->id, (int) $request->param('id'), $request->ip());
        $this->respond($request, $result, 'feed');
    }

    public function comment(Request $request): void
    {
        $me = $this->writeActor($request);
        if ($me === null) {
            return;
        }
        $result = (new \Spoome\Domain\Feed\PostEngagementService())
            ->comment($me->id, (int) $request->param('id'), (string) $request->input('body', ''), $request->ip());
        $this->respond($request, $result, 'feed');
    }

    public function deleteComment(Request $request): void
    {
        $me = $this->writeActor($request);
        if ($me === null) {
            return;
        }
        $isAdmin = CurrentUser::resolve($request)->isAdmin();
        $result = (new \Spoome\Domain\Feed\PostEngagementService())
            ->deleteComment($me->id, (int) $request->param('id'), $isAdmin);
        $this->respond($request, $result, 'feed');
    }

    /** Profilo per le LETTURE (feed/timeline): l'acting profile (personale o pagina). */
    private function me(Request $request): ?Profile
    {
        $user     = CurrentUser::resolve($request);
        $actingId = (new ActingContext())->resolve($request, $user);
        return $actingId !== null ? (new ProfileRepository())->findById($actingId) : null;
    }

    /**
     * Attore per le SCRITTURE (post/comment/like AS page): l'acting profile, autorizzato via
     * canActAs('editor'). Se il profilo dichiarato non è gestibile → 403; se claimant senza profilo
     * → redirect. Ritorna null DOPO aver già risposto (il chiamante deve solo `return`).
     */
    private function writeActor(Request $request): ?Profile
    {
        $user = CurrentUser::resolve($request);
        $ctx  = new ActingContext();
        $actingId = $ctx->resolveForWrite($request, $user, 'editor');
        if ($actingId === null) {
            if ($ctx->personalProfileId($user) !== null) {
                $this->respond($request, ServiceResult::fail(I18n::t('act.error.forbidden'), 403), 'feed');
            } else {
                Response::redirect('rivendicazioni');
            }
            return null;
        }
        $profile = (new ProfileRepository())->findById($actingId);
        if ($profile === null) {
            Response::redirect('rivendicazioni');
            return null;
        }
        return $profile;
    }
}
