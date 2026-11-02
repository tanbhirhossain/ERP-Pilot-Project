<?php
namespace Modules\HRM\Providers;
use Illuminate\Support\ServiceProvider;
use Modules\HRM\Interfaces\OrganizationRepositoryInterface;
use Modules\HRM\Repositories\OrganizationRepository;
use Modules\HRM\Interfaces\DepartmentRepositoryInterface;
use Modules\HRM\Repositories\DepartmentRepository;
use Modules\HRM\Interfaces\DesignationRepositoryInterface;
use Modules\HRM\Repositories\DesignationRepository;
use Modules\HRM\Interfaces\BiometricDeviceRepositoryInterface;
use Modules\HRM\Repositories\BiometricDeviceRepository;
use Modules\HRM\Interfaces\Employee\EmployeeRepositoryInterface;
use Modules\HRM\Repositories\Employee\EmployeeRepository;
use Modules\HRM\Interfaces\Roster\RosterShiftRepositoryInterface;
use Modules\HRM\Repositories\Roster\RosterShiftRepository;
use Modules\HRM\Interfaces\Roster\RosterDutyRepositoryInterface;
use Modules\HRM\Repositories\Roster\RosterDutyRepository;
use Modules\HRM\Interfaces\Attendance\AttendanceLogRepositoryInterface;
use Modules\HRM\Repositories\Attendance\AttendanceLogRepository;
use Modules\HRM\Interfaces\Attendance\AttendancePairRepositoryInterface;
use Modules\HRM\Repositories\Attendance\AttendancePairRepository;
use Modules\HRM\Interfaces\Attendance\AttendanceDailyRepositoryInterface;
use Modules\HRM\Repositories\Attendance\AttendanceDailyRepository;
use Modules\HRM\Interfaces\Attendance\AttendanceSyncRepositoryInterface;
use Modules\HRM\Repositories\Attendance\AttendanceSyncRepository;
use Modules\HRM\Interfaces\Roster\RosterShiftSwapRepositoryInterface;
use Modules\HRM\Repositories\Roster\RosterShiftSwapRepository;
use Modules\HRM\Interfaces\Roster\RosterOvertimeRepositoryInterface;
use Modules\HRM\Repositories\Roster\RosterOvertimeRepository;
use Modules\HRM\Interfaces\Leave\LeaveTypeRepositoryInterface;
use Modules\HRM\Repositories\Leave\LeaveTypeRepository;
use Modules\HRM\Interfaces\Leave\LeaveRequestRepositoryInterface;
use Modules\HRM\Repositories\Leave\LeaveRequestRepository;
use Modules\HRM\Interfaces\Informed\InformedRequestRepositoryInterface;
use Modules\HRM\Repositories\Informed\InformedRequestRepository;
use Modules\HRM\Interfaces\EmployeeSupervisorRepositoryInterface;
use Modules\HRM\Repositories\EmployeeSupervisorRepository;

class HRMServiceProvider extends ServiceProvider {
    public function register(): void {
        $this->app->bind(OrganizationRepositoryInterface::class, OrganizationRepository::class);
        $this->app->bind(DepartmentRepositoryInterface::class, DepartmentRepository::class);
        $this->app->bind(DesignationRepositoryInterface::class, DesignationRepository::class);
        $this->app->bind(BiometricDeviceRepositoryInterface::class, BiometricDeviceRepository::class);
        $this->app->bind(EmployeeRepositoryInterface::class, EmployeeRepository::class);
        $this->app->bind(RosterShiftRepositoryInterface::class, RosterShiftRepository::class);
        $this->app->bind(RosterDutyRepositoryInterface::class, RosterDutyRepository::class);
        $this->app->bind(AttendanceLogRepositoryInterface::class, AttendanceLogRepository::class);
        $this->app->bind(AttendancePairRepositoryInterface::class, AttendancePairRepository::class);
        $this->app->bind(AttendanceDailyRepositoryInterface::class, AttendanceDailyRepository::class);
        $this->app->bind(AttendanceSyncRepositoryInterface::class, AttendanceSyncRepository::class);
        $this->app->bind(RosterShiftSwapRepositoryInterface::class, RosterShiftSwapRepository::class);
        $this->app->bind(RosterOvertimeRepositoryInterface::class, RosterOvertimeRepository::class);
        $this->app->bind(LeaveTypeRepositoryInterface::class, LeaveTypeRepository::class);
        $this->app->bind(LeaveRequestRepositoryInterface::class, LeaveRequestRepository::class);
        $this->app->bind(InformedRequestRepositoryInterface::class, InformedRequestRepository::class);
        $this->app->bind(EmployeeSupervisorRepositoryInterface::class, EmployeeSupervisorRepository::class);
    }
    public function boot(): void {
        $this->loadViewsFrom(__DIR__.'/../Resources/views','hrm');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        if(file_exists(__DIR__.'/../Routes/api.php')) $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\HRM\Console\Commands\ReprocessOrphansCommand::class,
                \Modules\HRM\Console\Commands\RunAttendancePipelineCommand::class,
                \Modules\HRM\Console\Commands\SyncAttendanceCommand::class,
                \Modules\HRM\Console\Commands\MapDeviceUsersCommand::class,
                \Modules\HRM\Console\Commands\FixBiometricMappingCommand::class,
                \Modules\HRM\Console\Commands\FixAttendancePairingCommand::class,
            ]);
        }
    }
}
