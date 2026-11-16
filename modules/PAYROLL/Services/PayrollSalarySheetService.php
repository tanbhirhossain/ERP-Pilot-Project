<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\PAYROLL\Actions\MarkPayrollSlipPaymentAction;
use Modules\PAYROLL\Interfaces\Repositories\PayrollSalarySheetRepositoryInterface;
use Modules\PAYROLL\Jobs\ProcessSalarySheetJob;
use Modules\PAYROLL\Models\PayrollCalculationSnapshot;
use Modules\PAYROLL\Models\PayrollCutlist;
use Modules\PAYROLL\Models\PayrollJournalPost;
use Modules\PAYROLL\Models\PayrollLoanMonthlyBatch;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSetting;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Models\PayrollWorkflowAudit;
use Illuminate\Support\Facades\Log;

final class PayrollSalarySheetService
{
    public function __construct(
        private readonly PayrollSalarySheetRepositoryInterface $sheets,
        private readonly MarkPayrollSlipPaymentAction $payment,
    ) {}

    /**
     * The journal service, resolved lazily.
     *
     * Release now posts the accrual itself. Injecting the journal service through the constructor
     * would have re-bound this class in every place that builds it by hand with the two arguments it
     * has always taken, for no benefit: the dependency is only needed on the release path.
     */
    private function journals(): PayrollJournalService
    {
        return app(PayrollJournalService::class);
    }

    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return $this->sheets->paginate($filters, $perPage);
    }

    public function findOrFail(int $id): PayrollSalarySheet
    {
        return $this->sheets->findOrFail($id);
    }

    public function dispatch(int $year, int $month, ?int $actorId, bool $includePunishments = false, array $excludedEmployeeIds = [], ?string $exclusionReason = null): PayrollSalarySheet
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw new \InvalidArgumentException('Enter a valid payroll year and month.');
        }
        $excludedEmployeeIds = array_values(array_unique(array_map('strval', $excludedEmployeeIds)));
        $exclusionReason = trim((string) $exclusionReason);
        if ($excludedEmployeeIds !== [] && $exclusionReason === '') {
            throw ValidationException::withMessages(['exclusion_reason' => 'A documented reason is required for payroll-period exclusions.']);
        }

        $sheet = DB::transaction(function () use ($year, $month, $actorId, $includePunishments, $excludedEmployeeIds, $exclusionReason): PayrollSalarySheet {
            // Serialize monthly loan allocation and sheet/exclusion changes for this period.
            $now = now();
            DB::table('payroll_loan_monthly_batches')->insertOrIgnore([
                'pay_year' => $year,
                'pay_month' => $month,
                'scope' => 'automatic',
                'employee_ids' => null,
                'status' => 'locked',
                'created_by' => $actorId,
                'locked_by' => $actorId,
                'locked_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $allocationBatch = PayrollLoanMonthlyBatch::query()
                ->where('pay_year', $year)->where('pay_month', $month)->lockForUpdate()->firstOrFail();
            if ($allocationBatch->status === 'open') {
                $allocationBatch->update(['status' => 'locked', 'locked_by' => $actorId, 'locked_at' => now()]);
            }

            $existingSheet = PayrollSalarySheet::query()->where('pay_year', $year)->where('pay_month', $month)->lockForUpdate()->first();
            if ($existingSheet && (bool) $existingSheet->include_punishments !== $includePunishments) {
                $existingMode = $existingSheet->include_punishments ? 'with punishment' : 'without punishment';
                throw ValidationException::withMessages(['include_punishments' => "A salary sheet already exists for this period {$existingMode}; the mode is immutable for an existing sheet."]);
            }
            if ($excludedEmployeeIds !== [] && $existingSheet !== null) {
                $hasSnapshot = PayrollCalculationSnapshot::query()->where('salary_sheet_id', $existingSheet->id)->exists();
                $hasSlips = PayrollSlip::query()->where('salary_sheet_id', $existingSheet->id)->exists();
                if ($existingSheet->status !== 'failed' || $hasSnapshot || $hasSlips) {
                    throw ValidationException::withMessages(['excluded_employee_ids' => 'Exclusions can only be added to a new sheet or retried failed sheet before its employee snapshot or slips exist. Existing payroll calculations are immutable.']);
                }
            }

            // Cutlist review gate: when payroll settings demand it, the month's cutlist must have been
            // generated, reviewed and approved before a NEW salary sheet can be queued. Retrying an existing
            // sheet is never blocked here — its figures are already frozen in its own snapshot/cutlist.
            $cutlistId = null;
            if ($existingSheet === null && (bool) (PayrollSetting::query()->first()?->cutlist_review_required ?? false)) {
                $review = PayrollCutlist::query()->where('pay_year', $year)->where('pay_month', $month)->lockForUpdate()->first();
                if ($review === null) {
                    throw ValidationException::withMessages(['payroll_cutlist_id' => 'Payroll settings require an approved cutlist before a salary sheet can be created for this month. Generate and approve one under Payroll → Salary Cutlists.']);
                }
                if ($review->status !== 'approved') {
                    throw ValidationException::withMessages(['payroll_cutlist_id' => "The cutlist for this month is still in review (status: {$review->status}). Adjust it if needed, then approve it under Payroll → Salary Cutlists before creating the salary sheet."]);
                }
                $cutlistId = $review->id;
            }

            $sheet = $this->sheets->createForPeriod($year, $month, $actorId, $includePunishments);
            if ($cutlistId !== null && $sheet->payroll_cutlist_id === null) {
                $sheet->update(['payroll_cutlist_id' => $cutlistId]);
            }
            if ($excludedEmployeeIds !== []) {
                foreach (array_chunk($excludedEmployeeIds, 250) as $chunk) {
                    $timestamp = now()->toDateTimeString();
                    $rows = array_map(fn (string $employeeId): array => [
                        'employee_id' => $employeeId,
                        'pay_year' => $year,
                        'pay_month' => $month,
                        'reason' => $exclusionReason,
                        'created_by' => $actorId,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ], $chunk);
                    DB::table('payroll_period_exclusions')->insertOrIgnore($rows);
                }
            }

            return $sheet;
        });
        if ((bool) $sheet->include_punishments !== $includePunishments) {
            $existingMode = $sheet->include_punishments ? 'with punishment' : 'without punishment';
            throw ValidationException::withMessages(['include_punishments' => "A salary sheet already exists for this period {$existingMode}; the mode is immutable for an existing sheet."]);
        }
        if (! in_array($sheet->status, ['ready', 'released'], true)) {
            ProcessSalarySheetJob::dispatch($sheet->id)->afterCommit();
        }

        return $sheet;
    }

    public function release(PayrollSalarySheet $sheet, ?int $actorId): PayrollSalarySheet
    {
        return DB::transaction(function () use ($sheet, $actorId): PayrollSalarySheet {
            $locked = PayrollSalarySheet::query()->lockForUpdate()->findOrFail($sheet->id);
            if ($locked->status !== 'ready') {
                throw ValidationException::withMessages(['salary_sheet' => 'Only a completed, ready salary sheet can be released.']);
            }
            $slipCount = PayrollSlip::query()->where('salary_sheet_id', $locked->id)->count();
            if ($slipCount === 0 || $slipCount !== (int) $locked->employee_count) {
                throw ValidationException::withMessages(['salary_sheet' => 'The sheet has no slips or its slip count does not match the completed employee count.']);
            }
            $locked->update(['status' => 'released']);
            PayrollWorkflowAudit::query()->create(['subject_type' => 'salary_sheet', 'subject_id' => $locked->id, 'event' => 'released', 'actor_id' => $actorId, 'occurred_at' => now()]);

            // Release and the accrual journal are two halves of one event: the moment a month stops
            // being recalculable and becomes a liability. Leaving the second half to a button meant
            // sheet 4 was released on 2026-11-15 and still had no accrual a day later — the payroll
            // and its 88,722.98 of withholding existed on the slips and nowhere in the ledger.
            //
            // A failure here must not un-release the month the business just signed off: the sheet is
            // released, the payable is real, and refusing to release over a chart-of-accounts problem
            // would leave payroll blocked by bookkeeping. The exception is logged loudly and the
            // thread on the finance screen reports the missing journal until somebody posts it.
            if (! PayrollJournalPost::query()->where('salary_sheet_id', $locked->id)->where('posting_type', 'salary_accrual')->where('status', 'posted')->exists()) {
                try {
                    $this->journals()->postSalaryAccrual($locked);
                } catch (\Throwable $e) {
                    Log::error('Salary sheet released but the accrual journal could not be posted', [
                        'sheet_id' => $locked->id,
                        'pay_year' => $locked->pay_year,
                        'pay_month' => $locked->pay_month,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return $locked->refresh();
        });
    }

    public function setSlipPaymentStatus(PayrollSlip $slip, string $status, ?int $actorId): PayrollSlip
    {
        return $this->payment->execute($slip, $status, $actorId);
    }
}
