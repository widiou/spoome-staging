<?php

namespace Spoome\Http\Controllers\Web\Admin;

use Spoome\Core\Request;
use Spoome\Domain\Analytics\AnalyticsReportService;

/**
 * M4 — Analytics d'uso: lettura ON-DEMAND (sync/pull, NO cron) degli eventi instrumentati
 * (search, profile_open; opportunity_publish/apply arriveranno con M2). Dietro la catena
 * autenticato → admin (404-cloak) → step-up, come il resto dell'area riservata.
 */
final class AnalyticsController extends AdminController
{
    public function index(Request $request): void
    {
        $days = (int) $request->input('range', 30);
        $data = (new AnalyticsReportService())->overview($days);

        $this->renderAdmin('admin/analytics', array_merge($data, [
            'title'  => $this->title('admin.nav.analytics'),
            'active' => 'analytics',
        ]));
    }
}
