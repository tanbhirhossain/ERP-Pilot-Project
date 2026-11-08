<?php

namespace Modules\PAYROLL\Actions;

use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Models\FestivalBonusRun;
use Modules\PAYROLL\Models\PayrollWorkflowAudit;

final class ReleaseFestivalBonusRunAction
{
    public function execute(FestivalBonusRun $run, ?int $actorId): FestivalBonusRun
    {
        return DB::transaction(function () use ($run, $actorId): FestivalBonusRun {
            $locked = FestivalBonusRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'generated') {
                throw new \RuntimeException('Only generated bonus runs can be released.');
            }
            $locked->update(['status' => 'released']);
            PayrollWorkflowAudit::query()->create(['subject_type' => 'festival_bonus_run', 'subject_id' => $locked->id, 'event' => 'released', 'actor_id' => $actorId, 'occurred_at' => now()]);

            return $locked->refresh();
        });
    }
}
