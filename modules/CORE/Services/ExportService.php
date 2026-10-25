<?php

namespace Modules\CORE\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

/**
 * Streams screen-matching exports to disk-backed downloads.
 *
 * - Excel: Laravel-Excel walks the query in chunks → flat memory even for
 *   very large tables (bounded by config('core.exports.excel_max_rows')).
 * - PDF: a fixed paper document, so it is capped stricter and rendered from
 *   an already-mapped row collection.
 */
class ExportService
{
    public function excel(FromQuery $export, string $filename): Response
    {
        return Excel::download($export, $this->filename($filename, 'xlsx'));
    }

    /**
     * @param  list<string>  $headings
     * @param  Collection<int, array<int, mixed>>|list<array<int, mixed>>  $rows
     * @param  array<string, string>  $meta  e.g. ['Filter' => 'Role: Admin']
     */
    public function pdf(string $title, array $headings, $rows, string $filename, array $meta = []): Response
    {
        $rows = Collection::wrap($rows)->values();
        $truncated = false;
        $maxRows = (int) config('core.exports.pdf_max_rows');

        if ($rows->count() > $maxRows) {
            $rows = $rows->take($maxRows)->values();
            $truncated = true;
        }

        $html = $this->pdfHtml($title, $headings, $rows, $meta, $truncated, $maxRows);

        return Pdf::loadHtml($html)
            ->setPaper('a4', 'landscape')
            ->download($this->filename($filename, 'pdf'));
    }

    /**
     * @param  list<string>  $headings
     * @param  Collection<int, array<int, mixed>>  $rows
     * @param  array<string, string>  $meta
     */
    private function pdfHtml(
        string $title,
        array $headings,
        Collection $rows,
        array $meta,
        bool $truncated,
        int $maxRows,
    ): string {
        $generatedAt = now()->format('Y-m-d H:i:s');
        $appTitle = (string) config('app.name', 'Application');

        $metaRows = collect($meta)
            ->filter(fn (string $value): bool => $value !== '')
            ->map(fn (string $value, string $key): string => '<tr><th>'.e($key).'</th><td>'.e($value).'</td></tr>')
            ->implode("\n");

        $head = implode('', array_map(fn (string $heading): string => '<th>'.e($heading).'</th>', $headings));

        $body = $rows->map(function (array $row): string {
            $cells = '';
            foreach (array_values($row) as $value) {
                $cells .= '<td>'.e(is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE)).'</td>';
            }

            return '<tr>'.$cells.'</tr>';
        })->implode("\n");

        $note = $truncated
            ? '<p class="note">Note: the result set exceeds the PDF limit of '.number_format($maxRows).' rows — only the first '.number_format($maxRows).' rows are rendered.</p>'
            : '';

        $columnCount = max(1, count($headings));

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
        <meta charset="utf-8">
        <style>
            * { box-sizing: border-box; }
            body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #111827; font-size: 11px; margin: 0; }
            .header { border-bottom: 3px solid #111827; padding-bottom: 10px; margin-bottom: 14px; }
            .brand { font-size: 10px; letter-spacing: 2px; text-transform: uppercase; color: #6b7280; }
            h1 { font-size: 20px; margin: 4px 0 0; }
            .meta { width: 100%; margin-bottom: 14px; border-collapse: collapse; }
            .meta th { text-align: left; color: #6b7280; font-weight: 600; padding: 3px 14px 3px 0; text-transform: uppercase; font-size: 9px; letter-spacing: 1px; }
            .meta td { padding: 3px 0; }
            table.data { width: 100%; border-collapse: collapse; }
            table.data th { background: #111827; color: #ffffff; text-align: left; padding: 7px 8px; font-size: 10px; text-transform: uppercase; letter-spacing: .6px; }
            table.data td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
            table.data tr:nth-child(even) td { background: #f9fafb; }
            .note { margin-top: 12px; color: #b45309; font-size: 10px; }
            .footer { margin-top: 18px; padding-top: 8px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 9px; display: flex; justify-content: space-between; }
        </style>
        </head>
        <body>
            <div class="header">
                <div class="brand">{$appTitle} &mdash; Audit-grade export</div>
                <h1>{$title}</h1>
            </div>
            <table class="meta">
                {$metaRows}
                <tr><th>Generated</th><td>{$generatedAt}</td>
                <th>Rows</th><td>{$rows->count()}</td></tr>
            </table>
            {$note}
            <table class="data">
                <thead><tr>{$head}</tr></thead>
                <tbody>
        {$body}
                </tbody>
            </table>
            <div class="footer">
                <span>Generated by {$appTitle}</span>
                <span>{$generatedAt}</span>
            </div>
        </body>
        </html>
        HTML;
    }

    private function filename(string $base, string $extension): string
    {
        return sprintf('%s-%s.%s', $base, now()->format('Ymd-His'), $extension);
    }
}
