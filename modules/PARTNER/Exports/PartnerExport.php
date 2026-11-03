<?php

namespace Modules\PARTNER\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class PartnerExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize
{
    public function __construct(private readonly Builder $query) {}

    public function query(): Builder
    {
        return $this->query;
    }

    public function headings(): array
    {
        return ['Code', 'Name', 'Type', 'Group', 'Phone', 'Email', 'Credit Limit', 'Balance', 'Verified', 'Active', 'Created At'];
    }

    public function map($partner): array
    {
        return [
            $partner->partner_code,
            $partner->name,
            ucfirst($partner->type),
            $partner->group?->name ?? '—',
            $partner->phone ?? '—',
            $partner->email ?? '—',
            number_format((float) $partner->credit_limit, 2),
            number_format((float) $partner->current_balance, 2),
            $partner->is_verified ? 'Yes' : 'No',
            $partner->is_active ? 'Active' : 'Inactive',
            $partner->created_at?->format('d M Y'),
        ];
    }
}
