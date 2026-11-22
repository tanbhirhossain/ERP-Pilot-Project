<?php

namespace Modules\CORE\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\CORE\Models\CoreSetting;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders a tabular report as a branded, professional PDF document (item 52).
 *
 * The defect this replaces: report pages (Trial Balance, P&L, Balance Sheet,
 * POS day-end, …) only had a browser `window.print()` button, which prints the
 * rendered screen — a "screenshot of the page" with app chrome, theme colours
 * and rounded cards, not a document. This endpoint takes the page's own
 * headings + rows + meta and renders a clean paper sheet server-side, the same
 * way the document PDFs (Phase 2 — U16) already do.
 *
 * Why the client sends the data instead of the server re-querying:
 *  - It is uniform across every report — one endpoint, no per-report query
 *    logic to write and drift from the screen.
 *  - The PDF reflects exactly the view the operator is looking at (filters,
 *    search, sort) rather than a fresh query that might disagree.
 *
 * This is an export of client-supplied values, not a server-verified financial report.
 * Access to the originating page is enforced by that page; the optional permission
 * here is only an additional check, never proof that the submitted values are genuine.
 */
class ReportPdfController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $data = $request->validate([
            'title'       => ['required', 'string', 'max:200'],
            'subtitle'    => ['nullable', 'string', 'max:200'],
            'permission'  => ['nullable', 'string', 'max:120'],
            'orientation' => ['nullable', 'in:portrait,landscape'],
            'meta'        => ['nullable', 'array'],
            'meta.*'      => ['nullable', 'string', 'max:200'],
            'headings'    => ['required', 'array', 'min:1'],
            'headings.*'  => ['string', 'max:120'],
            'rows'        => ['nullable', 'array'],
            'rows.*'      => ['array'],
            'footer'      => ['nullable', 'string', 'max:200'],
        ]);

        // These rows come from the caller, not the database. The permission is an
        // optional additional check; it cannot establish the provenance of the data.
        if (($data['permission'] ?? null) !== null && $data['permission'] !== '') {
            abort_unless($request->user()->can($data['permission']), 403);
        } else {
            abort_unless($request->user(), 403);
        }

        $rows = Collection::wrap($data['rows'] ?? []);
        $maxRows = (int) config('core.exports.pdf_max_rows', 2000);
        $truncated = false;

        if ($rows->count() > $maxRows) {
            $rows = $rows->take($maxRows)->values();
            $truncated = true;
        }

        $html = $this->pdfHtml(
            title: $data['title'],
            subtitle: $data['subtitle'] ?? '',
            meta: $data['meta'] ?? [],
            headings: $data['headings'],
            rows: $rows,
            truncated: $truncated,
            maxRows: $maxRows,
            footer: $data['footer'] ?? '',
            generatedBy: (string) $request->user()->name,
        );

        $orientation = $data['orientation'] ?? 'landscape';

        return Pdf::loadHtml($html)
            ->setPaper('a4', $orientation)
            ->setOptions([
                'isFontSubsettingEnabled' => true,
                'isRemoteEnabled' => false,
                'defaultFont' => 'DejaVu Sans',
            ])
            ->download($this->filename($data['title']));
    }

    /**
     * Professional report sheet: letterhead, title block, meta grid, data
     * table (dark header, banded rows, right-aligned numerics) and colophon.
     *
     * @param  list<string>  $headings
     * @param  Collection<int, array<int, mixed>>  $rows
     * @param  array<string, string>  $meta
     */
    private function pdfHtml(
        string $title,
        string $subtitle,
        array $meta,
        array $headings,
        Collection $rows,
        bool $truncated,
        int $maxRows,
        string $footer,
        string $generatedBy,
    ): string {
        $company = $this->company();
        $generatedAt = now()->format('d M Y H:i');
        $appName = (string) config('app.name', 'ERP');

        $metaCells = '';
        foreach ($meta as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $metaCells .= '<tr><th>'.$this->e($key).'</th><td>'.$this->e((string) $value).'</td></tr>';
        }
        $metaCells .= '<tr><th>Generated</th><td>'.$this->e($generatedAt).'</td>'
            .'<th>Rows</th><td>'.number_format($rows->count()).'</td></tr>';

        $head = '';
        foreach ($headings as $heading) {
            $head .= '<th class="num-col">'.$this->e($heading).'</th>';
        }

        $body = '';
        foreach ($rows as $row) {
            $cells = '';
            $values = array_values($row);
            foreach ($values as $i => $value) {
                $numeric = is_numeric($value) && $value !== '' && $value !== null;
                $text = is_scalar($value) || $value === null
                    ? (string) $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE);
                $cells .= '<td class="'.($numeric ? 'ta-right mono' : '').'">'.$this->e($text).'</td>';
            }
            $body .= '<tr>'.$cells.'</tr>';
        }

        $empty = $body === ''
            ? '<tr><td class="empty" colspan="'.max(1, count($headings)).'">No data to display.</td></tr>'
            : '';

        $note = $truncated
            ? '<p class="note">Note: the result set exceeds the PDF limit of '.number_format($maxRows)
                .' rows — only the first '.number_format($maxRows).' rows are rendered.</p>'
            : '';

        $subtitleHtml = $subtitle !== ''
            ? '<p class="subtitle">'.$this->e($subtitle).'</p>'
            : '';

        $footerHtml = $footer !== ''
            ? '<span>'.$this->e($footer).'</span>'
            : '<span>'.$this->e($appName).'</span>';

        return <<<HTML
        <!DOCTYPE html>
        <html>
        <head>
        <meta charset="utf-8">
        <style>
            * { box-sizing: border-box; }
            body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #111827; font-size: 10px; margin: 0; line-height: 1.4; }

            .letterhead { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2.5px solid #111827; padding-bottom: 8px; margin-bottom: 14px; }
            .letterhead .org { font-size: 8px; letter-spacing: 2px; text-transform: uppercase; color: #6b7280; }
            .letterhead h1 { font-size: 18px; margin: 3px 0 0; font-weight: 700; }
            .letterhead .addr { font-size: 7.5px; color: #6b7280; margin-top: 4px; text-align: right; max-width: 45%; }

            .subtitle { font-size: 9.5px; color: #6b7280; margin: 0 0 12px; font-style: italic; }

            table.meta { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
            table.meta th { text-align: left; color: #6b7280; font-weight: 700; padding: 2px 14px 2px 0; text-transform: uppercase; font-size: 7.5px; letter-spacing: .8px; vertical-align: top; }
            table.meta td { padding: 2px 0; font-size: 9px; font-weight: 600; }

            table.data { width: 100%; border-collapse: collapse; }
            table.data th { background: #111827; color: #ffffff; text-align: left; padding: 6px 7px; font-size: 8px; text-transform: uppercase; letter-spacing: .5px; font-weight: 700; }
            table.data th.num-col, table.data td.ta-right { text-align: right; }
            table.data td { padding: 5px 7px; border-bottom: 1px solid #e5e7eb; font-size: 9px; vertical-align: top; }
            table.data td.mono { font-variant-numeric: tabular-nums; font-family: 'DejaVu Sans Mono', monospace; }
            table.data tr:nth-child(even) td { background: #f9fafb; }
            table.data tr:nth-child(odd) td { background: #ffffff; }
            td.empty { padding: 14px; text-align: center; color: #9ca3af; }

            .note { margin-top: 10px; color: #b45309; font-size: 8.5px; }

            .colophon { margin-top: 16px; padding-top: 7px; border-top: 1px solid #e5e7eb; color: #9ca3af; font-size: 7.5px; display: flex; justify-content: space-between; }
        </style>
        </head>
        <body>
            <div class="letterhead">
                <div>
                    <div class="org">{$this->e($company['name'])}</div>
                    <h1>{$this->e($title)}</h1>
                    {$subtitleHtml}
                </div>
                <div class="addr">
                    <div>BIN: {$this->e($company['bin'])}</div>
                    <div>{$this->e($company['address'])}</div>
                    <div>{$this->e($company['phone'])} · {$this->e($company['email'])}</div>
                </div>
            </div>

            <p class="note">UNVERIFIED CLIENT-SUPPLIED DATA — This is a view export, not a system-verified financial record. Check the source report before use.</p>

            <table class="meta">
                {$metaCells}
            </table>

            {$note}

            <table class="data">
                <thead><tr>{$head}</tr></thead>
                <tbody>{$body}{$empty}</tbody>
            </table>

            <div class="colophon">
                <span>Prepared by {$this->e($generatedBy)}</span>
                {$footerHtml}
                <span>Generated {$this->e($generatedAt)}</span>
            </div>
        </body>
        </html>
        HTML;
    }

    private function filename(string $title): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', $title);
        $slug = trim(strtolower($slug), '-');

        return ($slug !== '' ? $slug : 'report').'-'.now()->toDateString().'.pdf';
    }

    /**
     * Company identity for the letterhead — the same stored keys the document
     * PDFs use, so every generated sheet carries one consistent identity.
     *
     * @return array{name: string, address: string, bin: string, phone: string, email: string}
     */
    private function company(): array
    {
        $stored = CoreSetting::whereIn('key', ['company.name', 'company.address', 'company.bin', 'company.phone', 'company.email'])
            ->pluck('value', 'key');

        return [
            'name' => (string) ($stored['company.name'] ?? 'ERP Pilot Ltd.'),
            'address' => (string) ($stored['company.address'] ?? 'Chattogram EPZ, Bangladesh'),
            'bin' => (string) ($stored['company.bin'] ?? '001234567-0101'),
            'phone' => (string) ($stored['company.phone'] ?? '+880 31 000 0000'),
            'email' => (string) ($stored['company.email'] ?? 'accounts@erppilot.example'),
        ];
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
