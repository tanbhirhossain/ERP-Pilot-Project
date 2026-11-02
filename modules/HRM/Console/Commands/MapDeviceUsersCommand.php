<?php
namespace Modules\HRM\Console\Commands;

use Illuminate\Console\Command;
use Modules\HRM\Models\DeviceUser;
use Modules\HRM\Models\Employee\Employee;

class MapDeviceUsersCommand extends Command
{
    protected $signature = 'hrm:map-device-users {--dry-run : Show what would be mapped without applying} {--replace : If employee already mapped to wrong Excel ID, replace with device ID} {--force : Apply even if orphan count large}';
    protected $description = 'Auto-map DeviceUser (from ZK) to Employee by name when IDs mismatch (Excel IDs vs device IDs)';

    public function handle(): int
    {
        $dry = (bool)$this->option('dry-run');
        // Unmapped device users that have a name from device sync
        $unmapped = DeviceUser::whereNull('employee_id')->whereNotNull('name')->where('name','!=','')->get();
        if ($unmapped->isEmpty()) {
            $this->info('No unmapped DeviceUsers with names found. First run: sync users from device.');
            $this->info('  POST HRM -> Device Users -> Sync from Device (for each F18), or: php artisan hrm:sync-users --device=1');
            $all = DeviceUser::count();
            $mapped = DeviceUser::whereNotNull('employee_id')->count();
            $this->line("DeviceUsers total: $all | mapped: $mapped | unmapped-no-name: ".DeviceUser::whereNull('employee_id')->whereNull('name')->count());
            return 0;
        }

        $this->info("Found {$unmapped->count()} unmapped DeviceUsers with device names — attempting name match to employees...");
        // Build employee name index (lower trimmed)
        $employees = Employee::select('employee_id','full_name','first_name','last_name')->get();
        $byFull = [];
        $byNormalized = [];
        foreach ($employees as $e) {
            $full = strtolower(trim($e->full_name ?? ($e->first_name.' '.$e->last_name)));
            $byFull[$full][] = $e;
            // normalized: remove extra spaces, dots
            $norm = preg_replace('/\s+/', ' ', $full);
            $byNormalized[$norm][] = $e;
        }

        $wouldMap = 0;
        $ambiguous = 0;
        $notFound = 0;
        $rows = [];

        foreach ($unmapped as $du) {
            $name = strtolower(trim($du->name));
            $norm = preg_replace('/\s+/', ' ', $name);
            $candidates = $byFull[$name] ?? $byNormalized[$norm] ?? null;

            // Try exact first, then loose: contains
            if (!$candidates) {
                // Loose: employee full_name contains device name or vice versa (for "TANBHIR HOSSAIN" vs "Tanbhir Hossain ")
                $loose = $employees->filter(fn($e)=> str_contains(strtolower($e->full_name ?? ''), $name) || str_contains($name, strtolower($e->full_name ?? '')))->values();
                if ($loose->count() === 1) $candidates = [$loose[0]];
                elseif ($loose->count() > 1) { $ambiguous++; $rows[] = [$du->device_user_id, $du->name, 'AMBIGUOUS', $loose->pluck('employee_id')->implode(', ')]; continue; }
            }

            if (!$candidates) { $notFound++; $rows[] = [$du->device_user_id, $du->name, 'NOT FOUND', '-']; continue; }
            if (count($candidates) !== 1) { $ambiguous++; $rows[] = [$du->device_user_id, $du->name, 'AMBIGUOUS', collect($candidates)->pluck('employee_id')->implode(', ')]; continue; }

            $emp = $candidates[0];
            $wouldMap++;
            $rows[] = [$du->device_user_id, $du->name, $emp->employee_id, $emp->full_name];
            if (!$dry) {
                // Ensure not already taken by another device_user_id
                $taken = DeviceUser::where('employee_id', $emp->employee_id)->where('device_user_id','!=',$du->device_user_id)->first();
                if ($taken) {
                    if ($this->option('replace')) {
                        $this->warn("Replace: {$taken->device_user_id} -> {$du->device_user_id} for {$emp->employee_id} ({$emp->full_name})");
                        $taken->delete();
                        $du->update(['employee_id'=>$emp->employee_id]);
                        // Professional: also sync employees.biometric_id (source of truth)
                        if (empty($emp->biometric_id) || $emp->biometric_id !== $du->device_user_id) {
                            $emp->update(['biometric_id' => $du->device_user_id]);
                        }
                    } else {
                        $this->warn("Skip {$du->device_user_id} -> {$emp->employee_id}: already mapped to {$taken->device_user_id}. Use --replace to migrate to device ID");
                        continue;
                    }
                } else {
                    $du->update(['employee_id'=>$emp->employee_id]);
                    // Professional: sync employees.biometric_id as canonical mapping
                    if (empty($emp->biometric_id) || $emp->biometric_id !== $du->device_user_id) {
                        $emp->update(['biometric_id' => $du->device_user_id]);
                    }
                }
            }
        }

        $this->table(['DeviceUser ID','Device Name','=> Employee ID','Employee Name'], $rows);
        $this->info("Result: would map $wouldMap | ambiguous $ambiguous | not found $notFound | dry-run=".($dry?'yes':'no'));
        if ($dry && $wouldMap>0) {
            $this->info("Run without --dry-run to apply: php artisan hrm:map-device-users");
        }
        if (!$dry && $wouldMap>0) {
            $this->info("Mapped $wouldMap. Now reprocess: php artisan hrm:reprocess-orphans --limit=5000");
            $stats = app(\Modules\HRM\Services\Attendance\AttendanceProcessingService::class)->orphanStats();
            $this->table(['Metric','Value'], collect($stats)->map(fn($v,$k)=>[$k, is_array($v)?json_encode($v):$v])->values()->toArray());
        }
        return 0;
    }
}
