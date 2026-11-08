<?php

namespace Modules\CORE\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\CORE\Models\User;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Streams every user matching the current DataTable view into a sheet —
 * chunked by Laravel-Excel so memory stays flat regardless of table size.
 */
class UsersExport implements FromQuery, WithHeadings, WithMapping, WithStyles
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
        return ['#', 'Name', 'Email', 'Roles', 'Status', 'Joined'];
    }

    /**
     * @return list<mixed>
     */
    public function map(mixed $row): array
    {
        /** @var User $row */
        return [
            $row->id,
            $row->name,
            $row->email,
            $row->roles->pluck('name')->implode(', '),
            $row->email_verified_at ? 'Verified' : 'Unverified',
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
