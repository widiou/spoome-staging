<?php

namespace Spoome\Http\Controllers\Web\Admin;

use Spoome\Core\I18n;
use Spoome\Core\Pagination;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Core\Session;
use Spoome\Domain\Auth\CurrentUser;
use Spoome\Domain\Claims\ClaimRepository;
use Spoome\Domain\Claims\ClaimService;
use Spoome\Domain\Profiles\ProfileRepository;
use Spoome\Domain\Sports\SportRepository;

/**
 * Moderazione delle rivendicazioni + creazione di profili "non rivendicati" (seed della piattaforma).
 */
final class ClaimsController extends AdminController
{
    public function index(Request $request): void
    {
        $status = (string) $request->input('status', 'pending');
        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $status = 'pending';
        }
        $pg      = Pagination::of((int) $request->input('page', '1'), 30);
        $repo    = new ClaimRepository();
        $result  = $repo->listByStatus($status, $pg->page, 30);
        $pending = $repo->countPending();

        $this->renderAdmin('admin/claims/index', [
            'title'        => $this->title('admin.nav.claims'),
            'active'       => 'claims',
            'requests'     => $result['items'],
            'total'        => $result['total'],
            'status'       => $status,
            'pendingCount' => $pending,
            'pendingClaims' => $pending,
            'page'         => $pg->page,
            'pages'        => $pg->pages($result['total']),
            'notice'       => Session::takeFlash(),
        ]);
    }

    public function approve(Request $request): void
    {
        $id  = (int) ($request->params['id'] ?? 0);
        $me  = CurrentUser::resolve($request)->id;
        $res = (new ClaimService())->approve($me, $id, $request->ip());
        Session::flash($res->ok ? ($res->meta['message'] ?? '') : ($res->error ?? ''), $res->ok ? 'success' : 'error');
        Response::redirect('admin/rivendicazioni');
    }

    public function reject(Request $request): void
    {
        $id  = (int) ($request->params['id'] ?? 0);
        $me  = CurrentUser::resolve($request)->id;
        $res = (new ClaimService())->reject($me, $id, (string) $request->input('note', ''), $request->ip());
        Session::flash($res->ok ? ($res->meta['message'] ?? '') : ($res->error ?? ''), $res->ok ? 'success' : 'error');
        Response::redirect('admin/rivendicazioni');
    }

    /* -------------------------------------------- Crea profilo non rivendicato ---- */

    public function newProfile(Request $request): void
    {
        $this->renderAdmin('admin/claims/new-profile', [
            'title'  => $this->title('admin.claims.create_title'),
            'active' => 'claims',
            'types'  => (new ProfileRepository())->activeTypes(),
            'sports' => (new SportRepository())->all(),
            'notice' => Session::takeFlash(),
        ]);
    }

    public function createProfile(Request $request): void
    {
        $repo    = new ProfileRepository();
        $name    = trim((string) $request->input('display_name', ''));
        $typeKey = (string) $request->input('profile_type', '');
        $sportId = (int) $request->input('sport_id', 0);
        $headline = trim((string) $request->input('headline', ''));

        $typeId = $repo->typeIdByKey($typeKey);
        if ($name === '' || mb_strlen($name) < 2 || $typeId === null) {
            Session::flash(I18n::t('admin.claims.create_invalid'), 'error');
            Response::redirect('admin/rivendicazioni/nuovo');
            return;
        }

        $handle = $repo->uniqueHandle($name);
        $id = $repo->createUnclaimed($typeId, $handle, $name, $headline !== '' ? $headline : null, $sportId > 0 ? $sportId : null);

        (new \Spoome\Domain\Admin\AuditRepository())->record(
            CurrentUser::resolve($request)->id,
            'profile.create_unclaimed',
            'profile',
            $id,
            ['handle' => $handle, 'name' => $name],
            $request->ip()
        );

        Session::flash(I18n::t('admin.claims.create_done', ['handle' => $handle]), 'success');
        Response::redirect('admin/rivendicazioni');
    }
}
