<?php

namespace Spoome\Http\Controllers\Web\Admin;

use Spoome\Core\Pagination;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\Session;
use Spoome\Domain\Admin\AdminContentService;
use Spoome\Domain\Auth\CurrentUser;

/**
 * Moderazione contenuti: elenco degli ultimi post con rimozione (tracciata nell'audit).
 */
final class ModerationController extends AdminController
{
    private const PER_PAGE = 30;

    public function index(Request $request): void
    {
        $pg     = Pagination::of((int) $request->input('page', '1'), self::PER_PAGE);
        $result = (new AdminContentService())->recentPosts($pg->page, self::PER_PAGE);

        $this->renderAdmin('admin/moderation/index', [
            'title'  => $this->title('admin.nav.moderation'),
            'active' => 'moderation',
            'posts'  => $result['items'],
            'total'  => $result['total'],
            'page'   => $pg->page,
            'pages'  => $pg->pages($result['total']),
            'notice' => Session::takeFlash(),
        ]);
    }

    public function deletePost(Request $request): void
    {
        $id  = (int) ($request->params['id'] ?? 0);
        $me  = CurrentUser::resolve($request)->id;
        $res = (new AdminContentService())->deletePost($me, $id, $request->ip());

        Session::flash($res->ok ? ($res->meta['message'] ?? '') : ($res->error ?? ''), $res->ok ? 'success' : 'error');
        Response::redirect('admin/contenuti');
    }
}
