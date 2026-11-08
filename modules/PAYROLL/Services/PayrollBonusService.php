<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\PAYROLL\Actions\GenerateFestivalBonusRunAction;
use Modules\PAYROLL\Actions\ReleaseFestivalBonusRunAction;
use Modules\PAYROLL\Models\FestivalBonusPolicy;
use Modules\PAYROLL\Models\FestivalBonusRun;

final class PayrollBonusService
{
    public function __construct(
        private readonly GenerateFestivalBonusRunAction $runs,
        private readonly ReleaseFestivalBonusRunAction $release,
    ) {}

    public function policies()
    {
        return FestivalBonusPolicy::query()->latest('id')->get();
    }

    public function recentRuns(int $perPage = 25, ?string $search = null): LengthAwarePaginator
    {
        return FestivalBonusRun::query()->with('policy:id,name')->withCount('items')->when($search, function ($q) use ($search): void {
            $needle = '%'.addcslashes(trim($search), '%_').'%';
            $q->where(fn ($g) => $g->where('pay_year', 'like', $needle)->orWhereHas('policy', fn ($p) => $p->where('name', 'like', $needle)));
        })->latest('id')->paginate(min(100, max(1, $perPage)))->withQueryString();
    }

    public function savePolicy(array $data): FestivalBonusPolicy
    {
        return FestivalBonusPolicy::query()->create($data);
    }

    public function release(FestivalBonusRun $run, ?int $actorId): FestivalBonusRun
    {
        return $this->release->execute($run, $actorId);
    }

    public function generate(int $policyId, int $year, int $month, ?int $actorId): FestivalBonusRun
    {
        return $this->runs->execute($policyId, $year, $month, $actorId);
    }
}
