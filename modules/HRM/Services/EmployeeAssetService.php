<?php

namespace Modules\HRM\Services;

use Illuminate\Support\Facades\DB;
use Modules\HRM\Models\HrmAsset;
use Modules\HRM\Models\EmployeeAsset;

class EmployeeAssetService
{
    public function nextCode(): string
    {
        $last = HrmAsset::withTrashed()->orderByDesc('id')->value('asset_code');
        $n = $last ? (int) preg_replace('/\D/','',$last) : 0;
        return 'AST-'.str_pad((string)($n+1), 5, '0', STR_PAD_LEFT);
    }

    public function createAsset(array $data): HrmAsset
    {
        $data['asset_code'] = $this->nextCode();
        $data['created_by'] = auth()->id();
        $data['status'] = $data['status'] ?? 'In Stock';
        return HrmAsset::create($data);
    }

    public function issue(string $employeeId, int $assetId, array $data): EmployeeAsset
    {
        return DB::transaction(function () use ($employeeId, $assetId, $data) {
            $asset = HrmAsset::findOrFail($assetId);
            if (!$asset->isAvailable()) throw new \DomainException("Asset {$asset->asset_code} is not available (status: {$asset->status}).");
            // Prevent duplicate open assignment of same asset
            $open = EmployeeAsset::where('asset_id',$assetId)->whereNull('returned_date')->exists();
            if ($open) throw new \DomainException('Asset already assigned and not yet returned.');
            $assignment = EmployeeAsset::create([
                'asset_id' => $assetId,
                'employee_id' => $employeeId,
                'issued_date' => $data['issued_date'] ?? now()->toDateString(),
                'expected_return_date' => $data['expected_return_date'] ?? null,
                'issue_condition' => $data['issue_condition'] ?? 'Good',
                'remarks' => $data['remarks'] ?? null,
                'issued_by' => auth()->id(),
            ]);
            $asset->update(['status'=>'Assigned']);
            return $assignment->load('asset','employee');
        });
    }

    public function returnAsset(EmployeeAsset $assignment, array $data): EmployeeAsset
    {
        return DB::transaction(function () use ($assignment, $data) {
            if (!$assignment->isOpen()) throw new \DomainException('Asset already returned.');
            $assignment->update([
                'returned_date' => $data['returned_date'] ?? now()->toDateString(),
                'return_condition' => $data['return_condition'] ?? 'Good',
                'recovery_amount' => $data['recovery_amount'] ?? 0,
                'remarks' => $data['remarks'] ?? $assignment->remarks,
                'returned_by' => auth()->id(),
            ]);
            $newStatus = match($data['return_condition'] ?? 'Good') {
                'Damaged' => 'Damaged',
                'Lost' => 'Lost',
                default => 'Returned',
            };
            $assignment->asset()->update(['status'=>$newStatus]);
            return $assignment->fresh('asset');
        });
    }

    public function unreturnedValue(string $employeeId): float
    {
        return (float) EmployeeAsset::where('employee_id',$employeeId)->whereNull('returned_date')
            ->with('asset')->get()->sum(fn($ea)=> (float) ($ea->asset->unit_cost ?? 0));
    }

    public function recoveryTotal(string $employeeId): float
    {
        return (float) EmployeeAsset::where('employee_id',$employeeId)->sum('recovery_amount');
    }
}
