<?php
namespace Modules\HRM\Console\Commands;

use Illuminate\Console\Command;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Attendance\AttendanceLog;

class FixBiometricMappingCommand extends Command
{
    protected $signature = 'hrm:fix-biometric {--apply : Write changes} {--reset-expired : Also reset 7d+ expired orphans}';
    protected $description = 'Professional fix for biometric mapping: sync employees.biometric_id <-> device_users <-> attendance_logs orphans';

    public function handle(): int
    {
        $apply = (bool)$this->option('apply');
        $this->info('=== Biometric Mapping Diagnostic (Professional: employees.biometric_id as source of truth) ===');

        $employees = Employee::count();
        $deviceUsers = DeviceUser::count();
        $logs = AttendanceLog::count();
        $orphans = AttendanceLog::whereNull('employee_id')->where('is_processed', false)->count();
        $expired = AttendanceLog::whereNull('employee_id')->where('is_processed', true)->count();
        $mappedLogs = AttendanceLog::whereNotNull('employee_id')->count();
        $employeesWithBio = Employee::whereNotNull('biometric_id')->count();
        $employeesWithoutBio = Employee::whereNull('biometric_id')->count();

        $this->table(['Metric','Value'], [
            ['employees', $employees],
            ['employees with biometric_id', $employeesWithBio],
            ['employees WITHOUT biometric_id', $employeesWithoutBio],
            ['device_users', $deviceUsers],
            ['device_users mapped', DeviceUser::whereNotNull('employee_id')->count()],
            ['device_users unmapped', DeviceUser::whereNull('employee_id')->count()],
            ['attendance_logs total', $logs],
            ['attendance_logs mapped', $mappedLogs],
            ['orphan unprocessed', $orphans],
            ['orphan expired (is_processed)', $expired],
            ['attendance_pairs', \Modules\HRM\Models\Attendance\AttendancePair::count()],
            ['attendance_dailies', \Modules\HRM\Models\Attendance\AttendanceDaily::count()],
        ]);

        // Sample orphans vs mapped
        $sampleOrphan = AttendanceLog::whereNull('employee_id')->limit(5)->pluck('device_user_id')->unique()->implode(', ');
        $sampleMapped = AttendanceLog::whereNotNull('employee_id')->limit(5)->pluck('device_user_id')->unique()->implode(', ');
        $this->info("Sample orphan device_user_ids: [$sampleOrphan]");
        $this->info("Sample mapped device_user_ids: [$sampleMapped]");

        // Check mismatch: employees.biometric_id vs device_users
        $mismatch = 0;
        $wouldFix = 0;
        $rows = [];
        foreach (DeviceUser::whereNotNull('employee_id')->get() as $du) {
            $emp = Employee::where('employee_id', $du->employee_id)->first();
            if (!$emp) continue;
            if ($emp->biometric_id !== $du->device_user_id) {
                $mismatch++;
                $rows[] = [$du->device_user_id, $du->name ?? '-', $emp->employee_id, $emp->full_name, $emp->biometric_id ?? 'NULL', 'MISMATCH'];
            }
        }

        if ($mismatch > 0) {
            $this->warn("Found $mismatch device_users where employees.biometric_id != device_users.device_user_id (Excel stale IDs)");
            $this->table(['DeviceUser ID','Device Name','Employee ID','Employee Name','Employee biometric_id','Status'], array_slice($rows,0,10));
            if (count($rows) > 10) $this->info('... showing 10 of '.$mismatch);
        }

        // Check employees without biometric_id but have device_user mapping
        $empWithoutBioButHaveDeviceUser = Employee::whereNull('biometric_id')->whereHas('deviceUsers')->count();
        if ($empWithoutBioButHaveDeviceUser > 0) {
            $this->info("Employees without biometric_id but have device_users link: $empWithoutBioButHaveDeviceUser — will backfill from device_users");
            if ($apply) {
                $fixed = 0;
                foreach (Employee::whereNull('biometric_id')->get() as $emp) {
                    $du = DeviceUser::where('employee_id', $emp->employee_id)->first();
                    if ($du) {
                        $emp->update(['biometric_id' => $du->device_user_id]);
                        $fixed++;
                    }
                }
                $this->info("Backfilled $fixed employees.biometric_id from device_users");
            }
        }

        // Check orphan device_user_ids that exist on device but not in device_users
        $orphanIds = AttendanceLog::whereNull('employee_id')->where('is_processed', false)->distinct()->limit(100)->pluck('device_user_id')->unique();
        $missingInDeviceUsers = [];
        foreach ($orphanIds as $oid) {
            if (!DeviceUser::where('device_user_id', $oid)->exists() && !Employee::where('biometric_id', $oid)->exists()) {
                $missingInDeviceUsers[] = $oid;
            }
        }
        if (!empty($missingInDeviceUsers)) {
            $this->warn('Orphan device_user_ids not in device_users nor employees.biometric_id (need sync from device): '.implode(', ', array_slice($missingInDeviceUsers,0,10)));
            $this->info('Fix: HRM -> Device Users -> Sync from Device for each F18, then re-run this with --apply, then hrm:map-device-users --replace');
        }

        // Suggest resetting expired if requested
        if ($this->option('reset-expired') && $apply) {
            $reset = AttendanceLog::whereNull('employee_id')->where('is_processed', true)->update(['is_processed' => false]);
            $this->info("Reset $reset expired orphans to unprocessed (will retry after mapping fix)");
        } elseif ($expired > 0) {
            $this->info("Tip: $expired expired orphans (7d+ old) are marked processed and won't be retried. After fixing mapping, run: php artisan hrm:reprocess-orphans --reset-expired --limit=5000");
            $this->info("Or: php artisan hrm:fix-biometric --apply --reset-expired");
        }

        if (!$apply) {
            $this->info('Dry-run only. Add --apply to write fixes (backfill biometric_id, reset expired).');
            $this->info('Next: php artisan hrm:map-device-users --dry-run, then --replace, then hrm:fix-biometric --apply');
        } else {
            $this->info('Done. Now run: php artisan hrm:reprocess-orphans --limit=5000 && php artisan hrm:pipeline --stage=pairs');
        }

        return 0;
    }
}
