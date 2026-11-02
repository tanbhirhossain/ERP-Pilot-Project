<?php

namespace Modules\PAYROLL\Actions;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\CORE\Models\User;
use Modules\PAYROLL\Models\PayrollLoan;
use Modules\PAYROLL\Models\PayrollLoanApproval;
use Modules\PAYROLL\Models\PayrollLoanInstallment;
use Modules\PAYROLL\Models\PayrollSetting;
use Modules\PAYROLL\Services\PayrollLoanApprovalSteps;

final class ApprovePayrollLoanAction
{
    public function approve(PayrollLoan $loan, int $actorId, ?string $comments = null): PayrollLoan
    {
        return DB::transaction(function () use ($loan, $actorId, $comments): PayrollLoan {
            $locked = PayrollLoan::query()->lockForUpdate()->findOrFail($loan->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['approval' => 'Only pending loan requests can be approved.']);
            }
            if ((int) $locked->requested_by === $actorId) {
                throw ValidationException::withMessages(['approval' => 'A requester cannot approve their own payroll loan.']);
            }
            if ((float) $locked->interest_rate !== 0.0) {
                throw ValidationException::withMessages(['approval' => 'Payroll loans must remain strictly interest-free (0%).']);
            }

            $steps = PayrollLoanApprovalSteps::normalize($locked->approval_steps ?: PayrollSetting::query()->first()?->loan_approval_steps);
            $step = (int) $locked->current_approval_step;
            if (! isset($steps[$step])) {
                throw ValidationException::withMessages(['approval' => 'No loan approval stage remains.']);
            }
            $this->assertStageRole($actorId, $steps[$step]['role']);
            if (PayrollLoanApproval::query()->where('payroll_loan_id', $locked->id)->where('actor_id', $actorId)->exists()) {
                throw ValidationException::withMessages(['approval' => 'A different approver must complete each configured loan approval stage.']);
            }

            PayrollLoanApproval::query()->create([
                'payroll_loan_id' => $locked->id,
                'step_number' => $step + 1,
                'step_name' => $steps[$step]['label'],
                'decision' => 'approved',
                'actor_id' => $actorId,
                'comments' => $comments,
                'decided_at' => now(),
            ]);

            $locked->update(['current_approval_step' => $step + 1]);
            if ($step + 1 >= count($steps)) {
                $locked->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()]);
                $this->allocateInstallments($locked);
                $locked->update(['status' => 'active']);
            }

            return $locked->refresh();
        });
    }

    public function reject(PayrollLoan $loan, int $actorId, ?string $comments = null): PayrollLoan
    {
        return DB::transaction(function () use ($loan, $actorId, $comments): PayrollLoan {
            $locked = PayrollLoan::query()->lockForUpdate()->findOrFail($loan->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['approval' => 'Only pending loan requests can be rejected.']);
            }
            if ((int) $locked->requested_by === $actorId) {
                throw ValidationException::withMessages(['approval' => 'A requester cannot decide their own payroll loan.']);
            }
            $steps = PayrollLoanApprovalSteps::normalize($locked->approval_steps ?: PayrollSetting::query()->first()?->loan_approval_steps);
            $step = (int) $locked->current_approval_step;
            if (! isset($steps[$step])) throw ValidationException::withMessages(['approval' => 'No loan approval stage remains.']);
            $this->assertStageRole($actorId, $steps[$step]['role']);
            if (PayrollLoanApproval::query()->where('payroll_loan_id', $locked->id)->where('actor_id', $actorId)->exists()) {
                throw ValidationException::withMessages(['approval' => 'A different approver must complete each configured loan approval stage.']);
            }
            PayrollLoanApproval::query()->create([
                'payroll_loan_id' => $locked->id,
                'step_number' => $step + 1,
                'step_name' => $steps[$step]['label'],
                'decision' => 'rejected',
                'actor_id' => $actorId,
                'comments' => $comments,
                'decided_at' => now(),
            ]);
            $locked->update(['status' => 'rejected', 'approved_by' => $actorId, 'approved_at' => now()]);
            return $locked->refresh();
        });
    }

    private function assertStageRole(int $actorId, string $role): void
    {
        $approver = User::query()->find($actorId);
        if ($approver === null || ! $approver->hasAnyRole([$role, 'Super Admin'])) {
            throw ValidationException::withMessages(['approval' => "This loan stage requires the {$role} role."]);
        }
    }

    private function allocateInstallments(PayrollLoan $loan): void
    {
        $principal = round((float) $loan->principal_amount, 2);
        $count = max(1, (int) $loan->installment_count);
        $standardAmount = round($principal / $count, 2);
        $dueDate = Carbon::parse($loan->first_due_date)->startOfDay();
        $remaining = $principal;

        for ($index = 1; $index <= $count; $index++) {
            $amount = $index === $count ? $remaining : min($remaining, $standardAmount);
            PayrollLoanInstallment::query()->create([
                'payroll_loan_id' => $loan->id,
                'installment_number' => $index,
                'due_date' => $dueDate->copy()->addMonthsNoOverflow($index - 1)->toDateString(),
                'amount' => $amount,
                'status' => 'scheduled',
            ]);
            $remaining = round($remaining - $amount, 2);
        }

        $loan->update(['installment_amount' => $standardAmount, 'interest_rate' => 0]);
    }
}
