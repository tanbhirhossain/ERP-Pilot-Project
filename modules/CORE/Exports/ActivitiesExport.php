<?php

namespace Modules\CORE\Exports;

use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Modules\CORE\Models\Activity;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ActivitiesExport implements FromQuery, WithHeadings, WithMapping, WithStyles
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
        return ['#', 'Timestamp', 'Log', 'Event', 'Description', 'Causer', 'Subject', 'IP'];
    }

    /**
     * @return list<mixed>
     */
    public function map(mixed $row): array
    {
        /** @var Activity $row */
        $subject = $row->subject !== null
            ? class_basename((string) $row->subject_type).' #'.($row->subject->getKey() ?? '')
            : '—';

        return [
            $row->id,
            $row->created_at?->format('Y-m-d H:i:s'),
            $row->log_name,
            $row->event,
            $row->description,
            $row->causer?->name ?? 'System',
            $subject,
            $row->properties?->get('ip') ?? '—',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 11]],
        ];
    }
}
