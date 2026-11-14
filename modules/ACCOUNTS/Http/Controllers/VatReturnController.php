<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Services\VatReturnService;

/**
 * F17 — Mushak 9.1 monthly VAT return.
 *
 * Read-only working paper plus a branded PDF. Gated on accounts.reports.view,
 * the same permission as every other financial report.
 */
class VatReturnController extends Controller
{
    public function __construct(private readonly VatReturnService $returns) {}

    public function index(Request $r): Response
    {
        $month = $this->month($r);

        return Inertia::render('ACCOUNTS::Reports/VatReturn', [
            'month' => $month,
            'return' => $this->returns->forMonth($month),
        ]);
    }

    public function pdf(Request $r)
    {
        $month = $this->month($r);
        $data = $this->returns->forMonth($month);

        $pdf = Pdf::loadView('accounts::mushak91', ['ret' => $data, 'generatedAt' => now()->format('d M Y H:i')])
            ->setPaper('a4')
            ->setOptions(['isFontSubsettingEnabled' => true, 'isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans']);

        return $pdf->download('Mushak-9.1-'.$month.'.pdf');
    }

    private function month(Request $r): string
    {
        $month = (string) $r->input('month', now()->format('Y-m'));
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 422, 'Month must be YYYY-MM.');

        return $month;
    }
}
