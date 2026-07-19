<?php

namespace Spoome\Http\Controllers\Web\Admin;

use Spoome\Core\Request;
use Spoome\Core\Session;
use Spoome\Domain\Admin\AdminMetricsService;
use Spoome\Domain\Admin\AuditRepository;

/**
 * Dashboard amministrativa: metriche a colpo d'occhio + ultime azioni di audit.
 */
final class DashboardController extends AdminController
{
    public function index(Request $request): void
    {
        $metrics = (new AdminMetricsService())->dashboard();
        $audit   = (new AuditRepository())->recent(12);

        $this->renderAdmin('admin/dashboard', [
            'title'   => $this->title('admin.nav.dashboard'),
            'active'  => 'dashboard',
            'metrics' => $metrics,
            'audit'   => $audit,
            'notice'  => Session::takeFlash(),
        ]);
    }
}
