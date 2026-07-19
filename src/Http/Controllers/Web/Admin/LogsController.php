<?php

namespace Spoome\Http\Controllers\Web\Admin;

use Spoome\Core\Pagination;
use Spoome\Core\Request;
use Spoome\Core\Response;
use Spoome\Domain\Admin\AdminLogService;

/**
 * Viewer dei log applicativi: errori ricorrenti raggruppati + dettaglio occorrenze.
 */
final class LogsController extends AdminController
{
    private const PER_PAGE = 30;

    public function index(Request $request): void
    {
        $filters = [
            'level'   => (string) $request->input('level', ''),
            'channel' => (string) $request->input('channel', ''),
            'q'       => (string) $request->input('q', ''),
        ];
        $pg = Pagination::of((int) $request->input('page', '1'), self::PER_PAGE);

        $svc    = new AdminLogService();
        $result = $svc->grouped($filters, $pg->page, self::PER_PAGE);

        $this->renderAdmin('admin/logs/index', [
            'title'    => $this->title('admin.nav.logs'),
            'active'   => 'logs',
            'groups'   => $result['items'],
            'total'    => $result['total'],
            'filters'  => $filters,
            'levels'   => AdminLogService::LEVELS,
            'channels' => $svc->channels(),
            'counts'   => $svc->levelCounts24h(),
            'page'     => $pg->page,
            'pages'    => $pg->pages($result['total']),
        ]);
    }

    public function show(Request $request): void
    {
        $fp = (string) ($request->params['fp'] ?? '');
        if ($fp === '') {
            Response::redirect('admin/log');
            return;
        }
        $rows = (new AdminLogService())->occurrences($fp);

        $this->renderAdmin('admin/logs/show', [
            'title'       => $this->title('admin.nav.logs'),
            'active'      => 'logs',
            'fingerprint' => $fp,
            'rows'        => $rows,
        ]);
    }
}
