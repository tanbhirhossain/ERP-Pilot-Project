<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\PAYROLL\Actions\ProcessFinalSettlementAction;
use Modules\PAYROLL\Models\GratuityPolicy;
use Modules\PAYROLL\Models\PayrollSettlement;
use Modules\PAYROLL\Models\PayrollWorkflowAudit;

final class PayrollSettlementService
{
    public function __construct(
        private readonly ProcessFinalSettlementAction $processor,
        private readonly PayrollJournalService $journals,
    ) {}

    public function paginate(int $perPage = 25, ?string $search = null): LengthAwarePaginator
    {
        return PayrollSettlement::query()->when($search, function ($q) use ($search): void {
            $needle = '%'.addcslashes(trim($search), '%_').'%';
            $q->where(fn ($g) => $g->where('employee_id', 'like', $needle)->orWhere('employee_name', 'like', $needle));
        })->latest('id')->paginate($perPage)->withQueryString();
    }

    public function gratuityPolicies()
    {
        return GratuityPolicy::query()->latest('id')->get();
    }

    public function saveGratuityPolicy(array $data): GratuityPolicy
    {
        return GratuityPolicy::query()->create($data);
    }

    public function create(array $data, ?int $actorId): PayrollSettlement
    {
        return $this->processor->execute($data, $actorId);
    }

    public function approve(PayrollSettlement $settlement, int $actorId): PayrollSettlement
    {
        return DB::transaction(function () use ($settlement, $actorId): PayrollSettlement {
            $locked = PayrollSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($locked->status !== 'pending') {
                throw new \RuntimeException('Only pending settlements can be approved.');
            }
            $locked->update(['status' => 'approved', 'approved_by' => $actorId, 'approved_at' => now()]);

            /*
             * Approval is the point the money becomes an obligation, so it is the point it belongs in
             * the books. `PayrollJournalService::postSettlement()` had been written and never called:
             * a paid-out gratuity left no journal, no trial-balance line and no P&L cost — the payroll
             * said 84,000 and the ledger said nothing.
             *
             * It must not block the payment. A chart of accounts that cannot take the posting is a
             * finance problem, not a reason to hold a leaver's money; the failure is logged at error
             * and surfaced by `pendingPostings()` so it is a queryable fact rather than a log line.
             * The same shape as the salary-payment posting, deliberately.
             */
            try {
                $this->journals->postSettlement($locked->refresh());
            } catch (\Throwable $e) {
                Log::error('Final settlement approved but not posted to the ledger', [
                    'settlement_id' => $locked->id,
                    'employee_id' => $locked->employee_id,
                    'amount' => $locked->net_settlement,
                    'error' => $e->getMessage(),
                ]);
            }

            return $locked->refresh();
        });
    }

    /**
     * Approved (or paid) settlements that have no ledger posting.
     *
     * Without this, a settlement whose journal failed is indistinguishable from one that posted
     * cleanly, because the payroll tables look identical either way.
     *
     * @return Collection<int, PayrollSettlement>
     */
    public function pendingPostings(): Collection
    {
        return PayrollSettlement::query()
            ->whereIn('status', ['approved', 'paid'])
            ->whereNotExists(fn ($q) => $q->from('payroll_journal_posts')
                ->whereColumn('payroll_journal_posts.settlement_id', 'payroll_settlements.id')
                ->where('payroll_journal_posts.status', 'posted'))
            ->get();
    }

    public function markPaid(PayrollSettlement $settlement, ?int $actorId): PayrollSettlement
    {
        return DB::transaction(function () use ($settlement, $actorId): PayrollSettlement {
            $locked = PayrollSettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($locked->status !== 'approved') {
                throw new \RuntimeException('Only approved settlements can be marked paid.');
            }
            $locked->update(['status' => 'paid']);
            PayrollWorkflowAudit::query()->create(['subject_type' => 'payroll_settlement', 'subject_id' => $locked->id, 'event' => 'paid', 'actor_id' => $actorId, 'occurred_at' => now()]);

            return $locked->refresh();
        });
    }
}
