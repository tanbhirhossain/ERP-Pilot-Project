<?php

namespace Modules\CORE\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\CORE\Models\Role;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RolesExport implements FromQuery, WithHeadings, WithMapping, WithStyles
{
    public function __construct(
        private readonly Builder $query,
        private readonly int $maxRows,
    ) {}

    public function query(): Builder
    {
        return $this->query->limit($this->maxRows);
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return ['#', 'Role', 'Members', 'Permissions', 'Created'];
    }

    /**
     * @return list<mixed>
     */
    public function map(mixed $row): array
    {
        /** @var Role $row */
        return [
            $row->id,
            $row->name,
            (int) $row->users_count,
            $row->permissions_count ?? $row->permissions()->count(),
            $row->created_at?->format('Y-m-d H:i'),
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 11]],
        ];
    }
}
