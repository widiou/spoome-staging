<?php

namespace Spoome\Http\Controllers\Web\Admin;

use Spoome\Core\Request;
use Spoome\Domain\Admin\AdminStatsService;

/**
 * Modulo statistiche avanzato: serie temporali, KPI, funnel e classifiche.
 */
final class StatsController extends AdminController
{
    public function index(Request $request): void
    {
        $days = (int) $request->input('range', 30);
        $data = (new AdminStatsService())->overview($days);

        $this->renderAdmin('admin/stats', array_merge($data, [
            'title'  => $this->title('admin.nav.stats'),
            'active' => 'stats',
        ]));
    }
}
