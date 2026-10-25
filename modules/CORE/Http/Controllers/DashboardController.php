<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Services\DashboardService;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
    ) {}

    public function __invoke(): Response
    {
        return Inertia::render('CORE::Dashboard', [
            'stats' => $this->dashboard->stats(),
            'recentActivities' => $this->dashboard->recentActivities(),
            'roleDistribution' => $this->dashboard->roleDistribution(),
        ]);
    }
}
