<?php

namespace Spoome\Http\Controllers\Api\V1;

use Spoome\Core\I18n;
use Spoome\Core\Pagination;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Feed\FeedService;
use Spoome\Domain\Feed\PostService;
use Spoome\Domain\Profiles\ActingContext;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Http\Controllers\ApiController;

/**
 * API del feed (JSON, solo-Bearer): timeline personale + creazione/eliminazione post.
 */
final class FeedController extends ApiController
{
    /** GET /feed — timeline del proprio profilo. */
    public function index(Request $request): void
    {
        $me = $this->meId($request);
        if ($me === null) {
            return;
        }
        $pg   = Pagination::fromRequest($request, FeedService::PER_PAGE, 50);
        $feed = (new FeedService())->timeline($me, $pg->page, $pg->perPage);

        Response::json($feed['items'], 200, [
            'page'     => $feed['page'],
            'per_page' => $feed['per_page'],
            'has_more' => $feed['has_more'],
        ]);
    }

    /** POST /posts — crea un post AS l'acting profile (header X-Acting-Profile, ri-validato). */
    public function createPost(Request $request): void
    {
        $me = $this->writeActorId($request);
        if ($me === null) {
            return;
        }
        $this->emitJson((new PostService())->create($me, $request->body(), $request->ip()));
    }

    /** DELETE /posts/{id} — elimina un post dell'acting profile (scoping WHERE profile_id). */
    public function deletePost(Request $request): void
    {
        $me = $this->writeActorId($request);
        if ($me === null) {
            return;
        }
        $this->emitJson((new PostService())->delete((int) $request->param('id'), $me));
    }

    /** POST /posts/{id}/like — toggla il like come acting profile. */
    public function like(Request $request): void
    {
        $me = $this->writeActorId($request);
        if ($me === null) {
            return;
        }
        $this->emitJson((new \Spoome\Domain\Feed\PostEngagementService())->toggleLike($me, (int) $request->param('id'), $request->ip()));
    }

    /** POST /posts/{id}/comments — aggiunge un commento come acting profile. */
    public function comment(Request $request): void
    {
        $me = $this->writeActorId($request);
        if ($me === null) {
            return;
        }
        $this->emitJson((new \Spoome\Domain\Feed\PostEngagementService())->comment($me, (int) $request->param('id'), (string) $request->input('body', ''), $request->ip()));
    }

    /** DELETE /comments/{id} — elimina un commento (autore/proprietario post/admin). */
    public function deleteComment(Request $request): void
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return;
        }
        $ctx = new ActingContext();
        $me  = $ctx->resolveForWrite($request, $user, 'editor');
        if ($me === null) {
            Response::error(I18n::t('act.error.forbidden'), $ctx->personalProfileId($user) !== null ? 403 : 404);
            return;
        }
        $this->emitJson((new \Spoome\Domain\Feed\PostEngagementService())->deleteComment($me, (int) $request->param('id'), $user->isAdmin()));
    }

    /** Id dell'ACTING profile dell'utente Bearer per le LETTURE, o null (dopo 401/404). */
    private function meId(Request $request): ?int
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return null;
        }
        $actingId = (new ActingContext())->resolve($request, $user);
        if ($actingId === null) {
            Response::error(I18n::t('api.error.unauthorized'), 404);
            return null;
        }
        return $actingId;
    }

    /**
     * Id dell'ACTING profile per le SCRITTURE (post/comment AS page): ri-validato via canActAs('editor').
     * Header X-Acting-Profile non fidato; un profilo non gestibile → 403. Null dopo aver risposto.
     */
    private function writeActorId(Request $request): ?int
    {
        $user = $this->requireBearerUser($request);
        if ($user === null) {
            return null;
        }
        $ctx = new ActingContext();
        $actingId = $ctx->resolveForWrite($request, $user, 'editor');
        if ($actingId === null) {
            Response::error(I18n::t('act.error.forbidden'), $ctx->personalProfileId($user) !== null ? 403 : 404);
            return null;
        }
        return $actingId;
    }
}
