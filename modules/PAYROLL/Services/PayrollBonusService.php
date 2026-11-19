<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Modules\PAYROLL\Actions\GenerateFestivalBonusRunAction;
use Modules\PAYROLL\Actions\ReleaseFestivalBonusRunAction;
use Modules\PAYROLL\Models\FestivalBonusPolicy;
use Modules\PAYROLL\Models\FestivalBonusRun;

final class PayrollBonusService
{
    public function __construct(
        private readonly GenerateFestivalBonusRunAction $runs,
        private readonly ReleaseFestivalBonusRunAction $release,
        private readonly PayrollJournalService $journals,
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
        $released = $this->release->execute($run, $actorId);

        /*
         * A released bonus is money the company now owes, so it goes into the books here.
         * Nothing posted bonus at all before: `payroll_journal_posts.bonus_run_id` existed and was
         * never written, so releasing a run was an accounting no-op.
         *
         * Same policy as the settlement and the salary payment — record the failure, never block the
         * payout — and `pendingPostings()` makes an unposted run findable afterwards.
         */
        try {
            $this->journals->postBonus($released);
        } catch (\Throwable $e) {
            Log::error('Festival bonus released but not posted to the ledger', [
                'run_id' => $released->id,
                'amount' => $released->total_amount,
                'error' => $e->getMessage(),
            ]);
        }

        return $released->refresh();
    }

    /**
     * Released bonus runs with no ledger posting.
     *
     * @return Collection<int, FestivalBonusRun>
     */
    public function pendingPostings(): Collection
    {
        return FestivalBonusRun::query()
            ->where('status', 'released')
            ->whereNotExists(fn ($q) => $q->from('payroll_journal_posts')
                ->whereColumn('payroll_journal_posts.bonus_run_id', 'festival_bonus_runs.id')
                ->where('payroll_journal_posts.status', 'posted'))
            ->get();
    }

    public function generate(int $policyId, int $year, int $month, ?int $actorId): FestivalBonusRun
    {
        return $this->runs->execute($policyId, $year, $month, $actorId);
    }
}
