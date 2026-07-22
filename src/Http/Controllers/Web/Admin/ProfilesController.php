<?php

namespace Spoome\Http\Controllers\Web\Admin;

use Spoome\Core\Pagination;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\Session;
use Spoome\Domain\Admin\AdminUserService;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Profiles\ProfileRepository;

/**
 * Verifica delle PAGINE-organizzazione (società/associazioni/federazioni) — l'ancora del badge
 * derivato "verificato dalla società" (M3). Elenco filtrabile + azioni verifica/rimuovi-verifica
 * per profile_id, disaccoppiate da user_id (una pagina può essere unclaimed). Ogni azione delega ad
 * AdminUserService::setOrgVerified (guardia is_organization + audit). Rotte dietro auth→admin→step-up→CSRF.
 */
final class ProfilesController extends AdminController
{
    private const PER_PAGE = 25;

    public function index(Request $request): void
    {
        $verified = (string) $request->input('verified', '');
        if (!in_array($verified, ['verified', 'unverified'], true)) {
            $verified = '';
        }
        $filters = [
            'q'        => trim((string) $request->input('q', '')),
            'verified' => $verified,
        ];
        $pg = Pagination::of((int) $request->input('page', '1'), self::PER_PAGE);

        $result = (new ProfileRepository())->listOrganizations($filters['q'], $filters['verified'], $pg->page, self::PER_PAGE);

        $this->renderAdmin('admin/profiles/index', [
            'title'    => $this->title('admin.nav.profiles'),
            'active'   => 'profiles',
            'orgs'     => $result['items'],
            'total'    => $result['total'],
            'filters'  => $filters,
            'page'     => $pg->page,
            'pages'    => $pg->pages($result['total']),
            'notice'   => Session::takeFlash(),
        ]);
    }

    public function verify(Request $request): void
    {
        $this->run($request, true);
    }

    public function unverify(Request $request): void
    {
        $this->run($request, false);
    }

    /** Esegue l'azione di (de)verifica by profile_id e torna all'elenco con flash. */
    private function run(Request $request, bool $verify): void
    {
        $id  = (int) ($request->params['id'] ?? 0);
        $me  = CurrentUser::resolve($request)->id;
        $res = (new AdminUserService())->setOrgVerified($me, $id, $verify, $request->ip());

        Session::flash(
            $res->ok ? ($res->meta['message'] ?? '') : ($res->error ?? ''),
            $res->ok ? 'success' : 'error'
        );
        Response::redirect('admin/profili');
    }
}
