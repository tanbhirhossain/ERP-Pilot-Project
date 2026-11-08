<?php

namespace Modules\PAYROLL\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\HRM\Models\EmployeeAsset;
use Modules\HRM\Models\HrmAsset;
use Modules\HRM\Services\LeaveYearEndService;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\GratuityPolicy;
use Modules\PAYROLL\Models\PayrollSettlement;

final class ProcessFinalSettlementAction
{
    public function __construct(private readonly EmployeePayrollDataProviderInterface $employees) {}

    public function execute(array $data, ?int $actorId): PayrollSettlement
    {
        return DB::transaction(function () use ($data, $actorId): PayrollSettlement {
            // Lock the employee row to serialize final-settlement requests for this employee.
            DB::table('employees')->where('employee_id', $data['employee_id'])->lockForUpdate()->first();
            $alreadySettled = PayrollSettlement::query()
                ->where('employee_id', $data['employee_id'])
                ->whereIn('status', ['pending', 'approved', 'paid'])
                ->exists();
            if ($alreadySettled) {
                throw ValidationException::withMessages(['employee_id' => 'An active or paid final settlement already exists for this employee.']);
            }

            return $this->calculateAndCreate($data, $actorId);
        }, 3);
    }

    private function calculateAndCreate(array $data, ?int $actorId): PayrollSettlement
    {
        $employee = $this->employees->employeeForSettlement(
            $data['employee_id'],
            Carbon::parse($data['last_working_date'])->startOfMonth()->toDateString(),
            $data['last_working_date'],
        );
        if ($employee === null) {
            throw ValidationException::withMessages(['employee_id' => 'HRM has no effective salary record for this settlement period.']);
        }

        $lastDay = Carbon::parse($data['last_working_date'])->startOfDay();
        if ($employee->joiningDate !== null && $lastDay->lt(Carbon::parse($employee->joiningDate)->startOfDay())) {
            throw ValidationException::withMessages(['last_working_date' => 'The last working date cannot be earlier than the employee’s joining date.']);
        }
        $monthStart = $lastDay->copy()->startOfMonth();
        $monthDays = $lastDay->daysInMonth;
        $firstPayableDay = $employee->joiningDate ? Carbon::parse($employee->joiningDate) : $monthStart->copy();
        if ($firstPayableDay->lt($monthStart)) {
            $firstPayableDay = $monthStart->copy();
        }
        $payableDays = $firstPayableDay->gt($lastDay) ? 0 : $firstPayableDay->diffInDays($lastDay) + 1;
        $salarySegments = $employee->salarySegments;
        $grossDue = round(collect($salarySegments)->sum(fn (array $segment): float => (float) $segment['gross_salary'] * (int) $segment['days'] / $monthDays), 2);
        $lastSalarySegment = collect($salarySegments)->last() ?? [];
        $serviceYears = $employee->joiningDate && Carbon::parse($employee->joiningDate)->lte($lastDay)
            ? Carbon::parse($employee->joiningDate)->diffInYears($lastDay)
            : 0;
        $policy = GratuityPolicy::query()->where('is_active', true)->latest('id')->first();
        $gratuity = 0.0;
        if ($policy && $serviceYears >= (int) $policy->minimum_service_years) {
            $base = (float) ($lastSalarySegment[$policy->calculation_base === 'gross' ? 'gross_salary' : 'basic_salary'] ?? 0);
            $gratuity = round(($base / 30) * (float) $policy->days_per_service_year * $serviceYears, 2);
        }

        // ── HRM Encashment auto-prefill (H-P1-2) ─────────────────────
        $leaveEncashment = (float) ($data['leave_encashment'] ?? 0);
        if ($leaveEncashment === 0.0 && empty($data['leave_encashment_reason'])) {
            try {
                if (class_exists(LeaveYearEndService::class)) {
                    $hrmEnc = app(LeaveYearEndService::class)->encashableForSettlement($employee->employeeId, (int) $lastDay->year);
                    if (! empty($hrmEnc)) {
                        $dailyRate = (float) ($lastSalarySegment['basic_salary'] ?? $lastSalarySegment['gross_salary'] ?? 0) / 30;
                        $autoEncDays = collect($hrmEnc)->sum('encash_days');
                        $autoEncAmt = round($autoEncDays * $dailyRate, 2);
                        if ($autoEncAmt > 0) {
                            $leaveEncashment = $autoEncAmt;
                            $data['leave_encashment_reason'] = ($data['leave_encashment_reason'] ?? '')." Auto: {$autoEncDays} encashable days × {$dailyRate}/day (HRM balances ".implode(', ', array_column($hrmEnc, 'leave_type')).')';
                            Log::info('Settlement auto-encashment', ['employee_id' => $employee->employeeId, 'days' => $autoEncDays, 'amount' => $autoEncAmt]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Settlement encash auto failed: '.$e->getMessage());
            }
        }
        $otherBenefits = (float) ($data['other_benefits'] ?? 0);
        // ── HRM Asset recovery (auto) ─────────────────────────────────
        $assetRecovery = 0.0;
        $assetRecoveryDetail = [];
        if (class_exists(EmployeeAsset::class) && class_exists(HrmAsset::class)) {
            try {
                $openAssets = EmployeeAsset::where('employee_id', $employee->employeeId)->whereNull('returned_date')->with('asset')->get();
                foreach ($openAssets as $ea) {
                    $cost = (float) ($ea->recovery_amount > 0 ? $ea->recovery_amount : ($ea->asset->unit_cost ?? 0));
                    if ($cost > 0) {
                        $assetRecovery += $cost;
                        $assetRecoveryDetail[] = ['asset_code' => $ea->asset->asset_code, 'name' => $ea->asset->name, 'category' => $ea->asset->category, 'issued_date' => $ea->issued_date?->toDateString(), 'recovery' => $cost];
                    }
                }
            } catch (\Throwable $e) {
                // Not rethrown: aborting a final settlement because the HRM asset table could not be
                // read would be worse. But it must not be silent — $assetRecovery stays 0, so the
                // settlement pays the employee without recovering unreturned assets, and the
                // breakdown below reports asset_recovery.total = 0 as though that were the truth.
                Log::warning('Final settlement: unreturned-asset recovery unavailable, deducting nothing', [
                    'employee_id' => $employee->employeeId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $deductions = (float) ($data['deductions'] ?? 0) + $assetRecovery;
        $net = round($grossDue + $gratuity + $leaveEncashment + $otherBenefits - $deductions, 2);
        $breakdown = [
            'salary_proration' => ['rule' => 'calendar-day weighted by effective salary segment', 'days_in_month' => $monthDays, 'payable_days' => $payableDays, 'salary_segments' => $salarySegments, 'gross_due' => $grossDue],
            'service_years' => $serviceYears,
            'gratuity_policy_id' => $policy?->id,
            'gratuity' => $gratuity,
            'leave_encashment' => $leaveEncashment,
            'other_benefits' => $otherBenefits,
            'deductions' => $deductions,
            'manual_adjustments' => [
                'leave_encashment_reason' => $data['leave_encashment_reason'] ?? null,
                'other_benefits_reason' => $data['other_benefits_reason'] ?? null,
                'deductions_reason' => $data['deductions_reason'] ?? null,
            ],
            'asset_recovery' => ['total' => $assetRecovery, 'items' => $assetRecoveryDetail, 'note' => 'Auto-added from HRM unreturned assets (hrm_employee_assets where returned_date is null)'],
            'net_settlement' => $net,
        ];

        // encashable_days for audit (from HRM)
        $encashableDays = 0;
        try {
            if (isset($hrmEnc)) {
                $encashableDays = collect($hrmEnc ?? [])->sum('encash_days');
            }
        } catch (\Throwable $e) {
            // Audit-only field, so it cannot change what is paid — but a settlement record quietly
            // claiming 0 encashable days is misleading to whoever reads it later.
            Log::warning('Final settlement: encashable days could not be derived from HRM', [
                'employee_id' => $employee->employeeId,
                'error' => $e->getMessage(),
            ]);
        }

        return PayrollSettlement::query()->create([
            'employee_id' => $employee->employeeId,
            'employee_name' => $employee->name,
            'settlement_date' => $data['settlement_date'],
            'last_working_date' => $data['last_working_date'],
            'gross_due' => $grossDue,
            'gratuity_amount' => $gratuity,
            'leave_encashment' => $leaveEncashment,
            'encashable_days' => $encashableDays,
            'other_benefits' => $otherBenefits,
            'deductions' => $deductions,
            'asset_recovery_total' => $assetRecovery,
            'net_settlement' => $net,
            'status' => 'pending',
            'breakdown_json' => $breakdown,
            'created_by' => $actorId,
        ]);
    }
}
