<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\PAYROLL\Services\PayrollDashboardService;

final class PayrollDashboardController extends Controller
{
    public function __invoke(PayrollDashboardService $dashboard): Response
    {
        return Inertia::render('PAYROLL::Dashboard/Index', ['summary' => $dashboard->summary()]);
    }
}
