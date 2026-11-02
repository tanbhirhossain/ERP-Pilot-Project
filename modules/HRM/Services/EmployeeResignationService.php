<?php

namespace Modules\HRM\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\Employee\EmployeeResignation;

final class EmployeeResignationService
{
    public function paginate(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        return EmployeeResignation::query()->with('employee:id,employee_id,full_name')
            ->when(!empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['search']), function ($q) use ($filters): void {
                $needle = '%'.addcslashes(trim($filters['search']), '%_').'%';
                $q->where(fn ($nested) => $nested->where('employee_id', 'like', $needle)->orWhereHas('employee', fn ($e) => $e->where('full_name', 'like', $needle)));
            })
            ->orderByDesc('id')->paginate(min(100, max(1, $perPage)))->withQueryString();
    }

    public function request(array $data, ?int $actorId): EmployeeResignation
    {
        if (EmployeeResignation::query()->where('employee_id', $data['employee_id'])->where('status', 'Pending')->exists()) {
            throw new \RuntimeException('This employee already has a pending resignation request.');
        }
        return EmployeeResignation::query()->create($data + ['status' => 'Pending', 'requested_by' => $actorId]);
    }

    public function decide(EmployeeResignation $resignation, string $decision, ?int $actorId, ?string $note = null): EmployeeResignation
    {
        if (!in_array($decision, ['Approved', 'Rejected'], true)) throw new \InvalidArgumentException('Invalid resignation decision.');
        return DB::transaction(function () use ($resignation, $decision, $actorId, $note): EmployeeResignation {
            $record = EmployeeResignation::query()->lockForUpdate()->findOrFail($resignation->id);
            if ($record->status !== 'Pending') throw new \RuntimeException('Only pending resignation requests may be decided.');
            $record->update(['status' => $decision, 'approved_by' => $actorId, 'approved_at' => now(), 'decision_note' => $note]);
            return $record->refresh();
        });
    }
}
