<?php

namespace Modules\PAYROLL\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\PAYROLL\Models\FestivalBonusItem;
use Modules\PAYROLL\Models\FestivalBonusPolicy;
use Modules\PAYROLL\Models\FestivalBonusRun;
use Modules\PAYROLL\Models\PayrollSlip;

final class GenerateFestivalBonusRunAction
{
    public function __construct(private readonly CalculateFestivalBonusAction $calculator) {}

    public function execute(int $policyId, int $year, int $month, ?int $actorId): FestivalBonusRun
    {
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) {
            throw ValidationException::withMessages(['pay_month' => 'Enter a valid bonus year and month.']);
        }

        return DB::transaction(function () use ($policyId, $year, $month, $actorId): FestivalBonusRun {
            // Serialize generation for the policy so concurrent requests cannot create duplicate runs.
            $policy = FestivalBonusPolicy::query()->where('is_active', true)->lockForUpdate()->findOrFail($policyId);
            $periodRuns = FestivalBonusRun::query()
                ->where('festival_bonus_policy_id', $policy->id)
                ->where('pay_year', $year)
                ->where('pay_month', $month)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();
            if ($periodRuns->count() > 1) {
                throw ValidationException::withMessages(['pay_month' => 'Duplicate bonus runs already exist for this policy and period; reconcile them before generating another run.']);
            }
            $run = $periodRuns->first();

            if ($run && in_array($run->status, ['generated', 'released'], true)) {
                return $run;
            }
            $completedRuns = FestivalBonusRun::query()->where('festival_bonus_policy_id', $policy->id)
                ->where('pay_year', $year)->whereIn('status', ['generated', 'released'])->count();
            if ($completedRuns >= (int) $policy->bonuses_per_year) {
                throw ValidationException::withMessages(['pay_month' => 'This policy has reached its configured bonus-run limit for the year.']);
            }

            if ($run === null) {
                $run = FestivalBonusRun::query()->create([
                    'festival_bonus_policy_id' => $policy->id,
                    'pay_year' => $year,
                    'pay_month' => $month,
                    'status' => 'draft',
                    'created_by' => $actorId,
                ]);
            } else {
                $run->items()->delete();
            }

            PayrollSlip::query()
                ->where('pay_year', $year)
                ->where('pay_month', $month)
                ->whereHas('salarySheet', fn ($query) => $query->whereIn('status', ['ready', 'released']))
                ->orderBy('id')
                ->chunkById(100, function ($slips) use ($policy, $run): void {
                    foreach ($slips as $slip) {
                        $item = $this->calculator->execute($policy, $slip);
                        if (! $item['eligible'] || $item['amount'] <= 0) {
                            continue;
                        }

                        FestivalBonusItem::query()->create([
                            'festival_bonus_run_id' => $run->id,
                            'employee_id' => $slip->employee_id,
                            'employee_name' => $slip->employee_name,
                            'payroll_slip_id' => $slip->id,
                            'amount' => $item['amount'],
                            'breakdown_json' => $item,
                        ]);
                    }
                });

            $itemCount = $run->items()->count();
            if ($itemCount === 0) {
                throw ValidationException::withMessages(['festival_bonus_policy_id' => 'No eligible finalized payroll slips produced a payable bonus.']);
            }

            $run->update([
                'status' => 'generated',
                'employee_count' => $itemCount,
                'total_amount' => $run->items()->sum('amount'),
            ]);

            return $run->refresh();
        }, 3);
    }
}
