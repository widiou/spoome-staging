<?php

namespace Spoome\Http\Controllers\Web\Admin;

use Spoome\Core\Pagination;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\Session;
use Spoome\Domain\Admin\AdminUserService;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Users\UserRepository;

/**
 * Gestione utenti (area admin): elenco filtrabile, scheda di dettaglio e azioni amministrative.
 * Le azioni delegano ad AdminUserService (che applica salvaguardie e traccia l'audit).
 */
final class UsersController extends AdminController
{
    private const PER_PAGE = 25;

    public function index(Request $request): void
    {
        $filters = [
            'q'      => (string) $request->input('q', ''),
            'status' => (string) $request->input('status', ''),
            'role'   => (string) $request->input('role', ''),
        ];
        $pg = Pagination::of((int) $request->input('page', '1'), self::PER_PAGE);

        $result = (new UserRepository())->paginate($filters, $pg->page, self::PER_PAGE);

        $this->renderAdmin('admin/users/index', [
            'title'   => $this->title('admin.nav.users'),
            'active'  => 'users',
            'users'   => $result['items'],
            'total'   => $result['total'],
            'filters' => $filters,
            'page'    => $pg->page,
            'pages'   => $pg->pages($result['total']),
            'notice'  => Session::takeFlash(),
        ]);
    }

    public function show(Request $request): void
    {
        $id  = (int) ($request->params['id'] ?? 0);
        $row = (new UserRepository())->findDetailRow($id);
        if ($row === null) {
            http_response_code(404);
            Response::redirect('admin/utenti');
            return;
        }

        $this->renderAdmin('admin/users/show', [
            'title'  => $this->title('admin.nav.users'),
            'active' => 'users',
            'u'      => $row,
            'roles'  => AdminUserService::ROLES,
            'me'     => CurrentUser::resolve($request)->id,
            'notice' => Session::takeFlash(),
        ]);
    }

    public function suspend(Request $request): void
    {
        $this->run($request, static fn (AdminUserService $s, int $me, int $id, string $ip) => $s->suspend($me, $id, $ip));
    }

    public function reactivate(Request $request): void
    {
        $this->run($request, static fn (AdminUserService $s, int $me, int $id, string $ip) => $s->reactivate($me, $id, $ip));
    }

    public function verifyEmail(Request $request): void
    {
        $this->run($request, static fn (AdminUserService $s, int $me, int $id, string $ip) => $s->verifyEmail($me, $id, $ip));
    }

    public function changeRole(Request $request): void
    {
        $role = (string) $request->input('role', '');
        $this->run($request, static fn (AdminUserService $s, int $me, int $id, string $ip) => $s->changeRole($me, $id, $role, $ip));
    }

    public function verifyProfile(Request $request): void
    {
        $this->run($request, static fn (AdminUserService $s, int $me, int $id, string $ip) => $s->toggleProfileVerified($me, $id, $ip));
    }

    /** Esegue un'azione utente e torna alla scheda con flash. */
    private function run(Request $request, callable $action): void
    {
        $id  = (int) ($request->params['id'] ?? 0);
        $me  = CurrentUser::resolve($request)->id;
        $ip  = $request->ip();
        $res = $action(new AdminUserService(), $me, $id, $ip);

        if ($res->ok) {
            Session::flash($res->meta['message'] ?? '', 'success');
        } else {
            Session::flash($res->error ?? '', 'error');
        }
        Response::redirect('admin/utenti/' . $id);
    }
}
