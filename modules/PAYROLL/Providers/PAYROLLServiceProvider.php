<?php

namespace Modules\PAYROLL\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\PAYROLL\Interfaces\HRM\AttendancePayrollDataProviderInterface;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Interfaces\Repositories\PayrollLoanRepositoryInterface;
use Modules\PAYROLL\Interfaces\Repositories\PayrollSalarySheetRepositoryInterface;
use Modules\PAYROLL\Repositories\PayrollLoanRepository;
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
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([\Modules\PAYROLL\Console\Commands\PayrollPreflightCommand::class, \Modules\PAYROLL\Console\Commands\PayrollAttendanceBenchmarkCommand::class]);
        }
    }
}
