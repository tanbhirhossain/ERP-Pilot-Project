<?php

namespace Modules\PAYROLL\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Actions\ProcessFinalSettlementAction;
use Modules\PAYROLL\Models\GratuityPolicy;
use Modules\PAYROLL\Models\PayrollSettlement;
use Modules\PAYROLL\Models\PayrollWorkflowAudit;

final class PayrollSettlementService
{
    public function __construct(private readonly ProcessFinalSettlementAction $processor) {}

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

            return $locked->refresh();
        });
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
