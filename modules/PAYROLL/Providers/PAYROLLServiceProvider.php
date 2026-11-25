<?php

namespace Modules\PAYROLL\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\PAYROLL\Console\Commands\PayrollAttendanceBenchmarkCommand;
use Modules\PAYROLL\Console\Commands\PayrollPreflightCommand;
use Modules\PAYROLL\Console\Commands\RecalculateTax;
use Modules\PAYROLL\Interfaces\HRM\AttendancePayrollDataProviderInterface;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Interfaces\Repositories\PayrollLoanRepositoryInterface;
use Modules\PAYROLL\Interfaces\Repositories\PayrollSlipRepositoryInterface;
use Modules\PAYROLL\Interfaces\Repositories\PfAccountRepositoryInterface;
use Modules\PAYROLL\Interfaces\Repositories\PayrollSalarySheetRepositoryInterface;
use Modules\PAYROLL\Repositories\PayrollLoanRepository;
use Modules\PAYROLL\Repositories\PayrollSlipRepository;
use Modules\PAYROLL\Repositories\PfAccountRepository;
use Modules\PAYROLL\Repositories\PayrollSalarySheetRepository;
use Modules\PAYROLL\Services\HRM\HrmAttendancePayrollDataProvider;
use Modules\PAYROLL\Services\HRM\HrmEmployeePayrollDataProvider;

final class PAYROLLServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmployeePayrollDataProviderInterface::class, HrmEmployeePayrollDataProvider::class);
        $this->app->bind(AttendancePayrollDataProviderInterface::class, HrmAttendancePayrollDataProvider::class);
        $this->app->bind(PayrollSalarySheetRepositoryInterface::class, PayrollSalarySheetRepository::class);
        $this->app->bind(PayrollLoanRepositoryInterface::class, PayrollLoanRepository::class);
        $this->app->bind(PayrollSlipRepositoryInterface::class, PayrollSlipRepository::class);
        $this->app->bind(PfAccountRepositoryInterface::class, PfAccountRepository::class);
    }

    public function boot(): void
    {
        $this->registerRateLimiters();

        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([PayrollPreflightCommand::class, PayrollAttendanceBenchmarkCommand::class, RecalculateTax::class]);
        }
    }

    /**
     * Named limiter for the module's bulk writes.
     *
     * Allocating provident-fund accounts, accruing a month and backfilling history each expand into
     * one or two ledger rows per employee — 875 accounts is 1,750 statements — so a loop of them
     * starves every other request. Keyed on the person, not the address: an office behind one NAT
     * would otherwise share a bucket and the twelfth clerk would be told to wait for the first.
     */
    private function registerRateLimiters(): void
    {
        RateLimiter::for('payroll-bulk-write', function (Request $request): Limit {
            return Limit::perMinute(6)->by('payroll-bulk|'.($request->user()?->id ?? $request->ip()));
        });
    }
}
