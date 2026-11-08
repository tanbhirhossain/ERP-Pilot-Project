<?php

namespace Modules\PAYROLL\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\PAYROLL\Interfaces\HRM\EmployeePayrollDataProviderInterface;
use Modules\PAYROLL\Models\SalaryIncrement;

final class ApproveSalaryIncrementAction
{
    public function __construct(private readonly EmployeePayrollDataProviderInterface $employees) {}

    public function execute(SalaryIncrement $increment, int $approverId, ?string $comments = null): SalaryIncrement
    {
        return DB::transaction(function () use ($increment, $approverId, $comments): SalaryIncrement {
            $locked = SalaryIncrement::query()->lockForUpdate()->findOrFail($increment->id);
            if ($locked->status !== 'pending') {
                throw ValidationException::withMessages(['increment' => 'Only pending salary increments can be approved.']);
            }
            if ((int) $locked->requested_by === $approverId) {
                throw ValidationException::withMessages(['increment' => 'The requester cannot approve their own salary increment.']);
            }

            $this->employees->applySalaryRevision(
                $locked->employee_id,
                (float) $locked->new_basic,
                (float) $locked->new_gross,
                $locked->effective_date->toDateString(),
                (float) $locked->previous_basic,
                (float) $locked->previous_gross,
                (int) $locked->id,
            );

            $locked->update([
                'status' => 'applied',
                'approved_by' => $approverId,
                'approved_at' => now(),
                'decision_comments' => $comments,
            ]);

            return $locked->refresh();
        }, 3);
    }
}
