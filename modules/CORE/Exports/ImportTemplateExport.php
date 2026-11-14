<?php

namespace Modules\CORE\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * A downloadable import template: the importer's headers + one example row, so
 * the operator starts from the exact column names the parser expects.
 */
class ImportTemplateExport implements FromArray, ShouldAutoSize, WithHeadings
{
    /**
     * @param  list<string>  $headers
     * @param  list<string|float|int>  $example
     */
    public function __construct(
        private readonly array $headers,
        private readonly array $example,
    ) {}

    public function headings(): array
    {
        return $this->headers;
    }

    public function array(): array
    {
        return [$this->example];
    }
}
