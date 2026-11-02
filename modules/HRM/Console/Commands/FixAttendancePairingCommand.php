<?php

namespace Modules\HRM\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Modules\HRM\Models\Attendance\AttendancePair;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;

class FixAttendancePairingCommand extends Command
{
    protected $signature = 'hrm:fix-pairing
        {--employee= : Employee ID}
        {--device_user= : Device user ID (will be mapped to employee_id)}
        {--from= : Start duty date (Y-m-d), required}
        {--to= : End duty date (Y-m-d), required}
        {--dry-run : Preview only (default)}
        {--apply : Rebuild the selected employee/date range}';

    protected $description = 'Safely preview or rebuild attendance pairs for one employee and a required date range';

    public function handle(): int
    {
        $employeeId = $this->option('employee');
        $deviceUserId = $this->option('device_user');
        $from = $this->option('from');
        $to = $this->option('to');

        if (!$employeeId && !$deviceUserId) {
            $this->error('Specify --employee=... or --device_user=.... No default employee is assumed.');
            return self::INVALID;
        }
        if (!$from || !$to || !Carbon::hasFormat($from, 'Y-m-d') || !Carbon::hasFormat($to, 'Y-m-d') || $to < $from) {
            $this->error('Specify a valid inclusive --from=YYYY-MM-DD and --to=YYYY-MM-DD date range.');
            return self::INVALID;
        }
        if (Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 31) {
            $this->error('Repairs are limited to 32 calendar days. Split larger repairs into smaller ranges.');
            return self::INVALID;
        }
        if ($deviceUserId) {
            $mappedEmployeeId = DeviceUser::query()->where('device_user_id', $deviceUserId)->value('employee_id');
            if (!$mappedEmployeeId) {
                $this->error("Device user {$deviceUserId} is not mapped to an employee.");
                return self::FAILURE;
            }
            if ($employeeId && $employeeId !== $mappedEmployeeId) {
                $this->error('The supplied employee and device user map to different employees.');
                return self::INVALID;
            }
            $employeeId = $mappedEmployeeId;
        }

        $pairQuery = AttendancePair::query()->where('employee_id', $employeeId)
            ->whereDate('duty_date', '>=', $from)->whereDate('duty_date', '<=', $to);
        $wrong = (clone $pairQuery)->where(function ($query): void {
            $query->whereRaw('worked_hours > 12')
                ->orWhereRaw('DATE(punch_in) != duty_date')
                ->orWhereRaw('DATE(punch_out) != duty_date');
        })->get();
        $dailyQuery = AttendanceDaily::query()->where('employee_id', $employeeId)
            ->whereDate('duty_date', '>=', $from)->whereDate('duty_date', '<=', $to);
        $deviceUserIds = DeviceUser::query()->where('employee_id', $employeeId)->pluck('device_user_id')->map(fn ($id) => (string) $id)->all();
        $biometricId = Employee::query()->where('employee_id', $employeeId)->value('biometric_id');
        if ($biometricId) $deviceUserIds[] = (string) $biometricId;
        if ($deviceUserId) $deviceUserIds[] = (string) $deviceUserId;
        $deviceUserIds = array_values(array_unique($deviceUserIds));
        $logQuery = AttendanceLog::query()->where(function ($query) use ($employeeId, $deviceUserIds): void {
            $query->where('employee_id', $employeeId);
            if ($deviceUserIds) $query->orWhereIn('device_user_id', $deviceUserIds);
        })->where('punch_time', '>=', $from.' 00:00:00')->where('punch_time', '<=', $to.' 23:59:59');

        $this->info("Scope: employee {$employeeId}, {$from} through {$to}");
        $this->info('Suspect pairs: '.$wrong->count().' | daily rows: '.(clone $dailyQuery)->count().' | logs: '.(clone $logQuery)->count().' (processed: '.(clone $logQuery)->where('is_processed', true)->count().')');
        if ($wrong->isNotEmpty()) {
            $this->table(['ID', 'Employee', 'Duty date', 'Punch in → out', 'Hours', 'Shift type'], $wrong->map(fn ($pair) => [
                $pair->id, $pair->employee_id, $pair->duty_date,
                $pair->punch_in.' → '.($pair->punch_out ?? 'OPEN'), $pair->worked_hours, $pair->shift_type_applied,
            ])->all());
        }

        if (!$this->option('apply') || $this->option('dry-run')) {
            $this->warn('DRY RUN — no data changed. Re-run with --apply (without --dry-run) to rebuild this exact scope.');
            return self::SUCCESS;
        }
        if (!$this->confirm("This will delete all pairs and daily rows and reset logs for {$employeeId} from {$from} through {$to}. Continue?", false)) {
            $this->warn('Cancelled.');
            return self::SUCCESS;
        }

        $logCount = (clone $logQuery)->count();
        $logProcessed = (clone $logQuery)->where('is_processed', true)->count();
        $globalPending = AttendanceLog::query()->where('is_processed', false)->where('punch_time', '>=', $from.' 00:00:00')->where('punch_time', '<=', $to.' 23:59:59')->count();
        if ($logCount > 50000 || $globalPending + $logProcessed > 50000) {
            $this->error('The repair would process more than 50,000 raw logs (including other pending logs in the selected date range). Split into smaller ranges.');
            return self::FAILURE;
        }
        DB::transaction(function () use ($pairQuery, $dailyQuery, $logQuery): void {
            (clone $pairQuery)->delete();
            (clone $dailyQuery)->delete();
            (clone $logQuery)->update(['is_processed' => false]);
        });

        $result = app(AttendanceProcessingService::class)->processPending(50000, $from.' 00:00:00', $to.' 23:59:59');
        $this->info("Reset {$logCount} logs. Reprocess result:");
        $this->table(['Metric', 'Value'], collect($result)->map(fn ($value, $key) => [$key, is_array($value) ? json_encode($value) : $value])->values()->all());
        $this->info('Rebuild complete. Verify attendance dailies for the selected employee and dates.');
        return self::SUCCESS;
    }
}
