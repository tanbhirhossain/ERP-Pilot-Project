<?php

namespace Modules\HRM\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\HRM\Console\Commands\EmployeeLoginsCommand;
use Modules\HRM\Console\Commands\FixAttendancePairingCommand;
use Modules\HRM\Console\Commands\FixBiometricMappingCommand;
use Modules\HRM\Console\Commands\MapDeviceUsersCommand;
use Modules\HRM\Console\Commands\ReprocessOrphansCommand;
use Modules\HRM\Console\Commands\RunAttendancePipelineCommand;
use Modules\HRM\Console\Commands\SyncAttendanceCommand;
use Modules\HRM\Interfaces\Approval\ApprovalChainRepositoryInterface;
use Modules\HRM\Interfaces\Approval\LeaveRequestWorkflowRepositoryInterface;
use Modules\HRM\Interfaces\Approval\OvertimeRequestWorkflowRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendanceRegularizationRepositoryInterface;
use Modules\HRM\Interfaces\Certificate\CertificateRequestRepositoryInterface;
use Modules\HRM\Interfaces\Device\UserDeviceTokenRepositoryInterface;
use Modules\HRM\Interfaces\Employee\EmployeeResignationRepositoryInterface;
use Modules\HRM\Interfaces\Notice\NoticeRepositoryInterface;
use Modules\HRM\Interfaces\NotificationRepositoryInterface;
use Modules\HRM\Interfaces\Task\WorkTaskRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendanceDailyRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendanceLogRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendancePairRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendanceRepairRepositoryInterface;
use Modules\HRM\Interfaces\Attendance\AttendanceSyncRepositoryInterface;
use Modules\HRM\Interfaces\BiometricDeviceRepositoryInterface;
use Modules\HRM\Interfaces\DepartmentRepositoryInterface;
use Modules\HRM\Interfaces\DesignationRepositoryInterface;
use Modules\HRM\Interfaces\Employee\EmployeeRepositoryInterface;
use Modules\HRM\Interfaces\EmployeeSupervisorRepositoryInterface;
use Modules\HRM\Interfaces\Informed\InformedRequestRepositoryInterface;
use Modules\HRM\Interfaces\Leave\LeaveRequestRepositoryInterface;
use Modules\HRM\Interfaces\Leave\LeaveTypeRepositoryInterface;
use Modules\HRM\Interfaces\OrganizationRepositoryInterface;
use Modules\HRM\Interfaces\Roster\RosterDutyRepositoryInterface;
use Modules\HRM\Interfaces\Roster\RosterOvertimeRepositoryInterface;
use Modules\HRM\Interfaces\Roster\RosterShiftRepositoryInterface;
use Modules\HRM\Interfaces\Roster\RosterShiftSwapRepositoryInterface;
use Modules\HRM\Repositories\Approval\ApprovalChainRepository;
use Modules\HRM\Repositories\Approval\LeaveRequestWorkflowRepository;
use Modules\HRM\Repositories\Approval\OvertimeRequestWorkflowRepository;
use Modules\HRM\Repositories\Attendance\AttendanceRegularizationRepository;
use Modules\HRM\Repositories\Certificate\CertificateRequestRepository;
use Modules\HRM\Repositories\Device\UserDeviceTokenRepository;
use Modules\HRM\Repositories\Employee\EmployeeResignationRepository;
use Modules\HRM\Repositories\Notice\NoticeRepository;
use Modules\HRM\Repositories\NotificationRepository;
use Modules\HRM\Repositories\Task\WorkTaskRepository;
use Modules\HRM\Repositories\Attendance\AttendanceDailyRepository;
use Modules\HRM\Repositories\Attendance\AttendanceLogRepository;
use Modules\HRM\Repositories\Attendance\AttendancePairRepository;
use Modules\HRM\Repositories\Attendance\AttendanceRepairRepository;
use Modules\HRM\Repositories\Attendance\AttendanceSyncRepository;
use Modules\HRM\Repositories\BiometricDeviceRepository;
use Modules\HRM\Repositories\DepartmentRepository;
use Modules\HRM\Repositories\DesignationRepository;
use Modules\HRM\Repositories\Employee\EmployeeRepository;
use Modules\HRM\Repositories\EmployeeSupervisorRepository;
use Modules\HRM\Repositories\Informed\InformedRequestRepository;
use Modules\HRM\Repositories\Leave\LeaveRequestRepository;
use Modules\HRM\Repositories\Leave\LeaveTypeRepository;
use Modules\HRM\Repositories\OrganizationRepository;
use Modules\HRM\Repositories\Roster\RosterDutyRepository;
use Modules\HRM\Repositories\Roster\RosterOvertimeRepository;
use Modules\HRM\Repositories\Roster\RosterShiftRepository;
use Modules\HRM\Repositories\Roster\RosterShiftSwapRepository;
use Modules\HRM\Services\Attendance\OffDayResolver;

class HRMServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/hrm.php', 'hrm');

        // Stateless and dependency-free; a singleton avoids re-resolving it
        // once per employee per date inside the bulk reconciliation loop.
        $this->app->singleton(OffDayResolver::class);

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
        $this->app->bind(AttendanceRepairRepositoryInterface::class, AttendanceRepairRepository::class);
        $this->app->bind(RosterShiftSwapRepositoryInterface::class, RosterShiftSwapRepository::class);
        $this->app->bind(RosterOvertimeRepositoryInterface::class, RosterOvertimeRepository::class);
        $this->app->bind(LeaveTypeRepositoryInterface::class, LeaveTypeRepository::class);
        $this->app->bind(LeaveRequestRepositoryInterface::class, LeaveRequestRepository::class);
        $this->app->bind(InformedRequestRepositoryInterface::class, InformedRequestRepository::class);
        $this->app->bind(EmployeeSupervisorRepositoryInterface::class, EmployeeSupervisorRepository::class);
        $this->app->bind(ApprovalChainRepositoryInterface::class, ApprovalChainRepository::class);
        $this->app->bind(LeaveRequestWorkflowRepositoryInterface::class, LeaveRequestWorkflowRepository::class);
        $this->app->bind(OvertimeRequestWorkflowRepositoryInterface::class, OvertimeRequestWorkflowRepository::class);
        $this->app->bind(AttendanceRegularizationRepositoryInterface::class, AttendanceRegularizationRepository::class);
        $this->app->bind(CertificateRequestRepositoryInterface::class, CertificateRequestRepository::class);
        $this->app->bind(UserDeviceTokenRepositoryInterface::class, UserDeviceTokenRepository::class);
        $this->app->bind(EmployeeResignationRepositoryInterface::class, EmployeeResignationRepository::class);
        $this->app->bind(NoticeRepositoryInterface::class, NoticeRepository::class);
        $this->app->bind(NotificationRepositoryInterface::class, NotificationRepository::class);
        $this->app->bind(WorkTaskRepositoryInterface::class, WorkTaskRepository::class);
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'hrm');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        if (file_exists(__DIR__.'/../Routes/api.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/api.php');
        }
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([
                EmployeeLoginsCommand::class,
                ReprocessOrphansCommand::class,
                RunAttendancePipelineCommand::class,
                SyncAttendanceCommand::class,
                MapDeviceUsersCommand::class,
                FixBiometricMappingCommand::class,
                FixAttendancePairingCommand::class,
            ]);
        }
    }
}
