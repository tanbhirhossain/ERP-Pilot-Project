<?php

namespace Modules\CORE\Imports;

use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Reads the first sheet of an uploaded spreadsheet into normalized rows.
 *
 * The header row is lower-cased, trimmed and snake_cased so "Unit Price",
 * "unit price" and "Unit_Price" all resolve to `unit_price`. Every importer
 * therefore reads the same keys regardless of how the operator titled the
 * columns in Excel.
 */
class SpreadsheetReader implements ToArray
{
    /** @var list<array<string,mixed>> */
    private array $rows = [];

    public function array(array $array): void
    {
        if ($array === []) {
            return;
        }

        $headers = array_map(fn ($h) => self::normalizeHeader((string) $h), array_shift($array));

        foreach ($array as $raw) {
            // Skip fully-empty rows (Excel loves to emit trailing blanks).
            $values = array_values($raw);
            if (count(array_filter($values, fn ($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }

            $row = [];
            foreach ($headers as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $row[$key] = $values[$i] ?? null;
            }
            $this->rows[] = $row;
        }
    }

    /** @return list<array<string,mixed>> */
    public function rows(): array
    {
        return $this->rows;
    }

    public static function normalizeHeader(string $header): string
    {
        $h = strtolower(trim($header));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? '';

        return trim($h, '_');
    }

    /**
     * Parse an uploaded file into normalized rows.
     *
     * @return list<array<string,mixed>>
     */
    public static function read(string $path): array
    {
        $reader = new self;
        Excel::import($reader, $path);

        return $reader->rows();
    }
}
