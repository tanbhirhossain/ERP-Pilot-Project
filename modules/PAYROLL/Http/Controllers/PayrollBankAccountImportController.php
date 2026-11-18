<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\PAYROLL\Services\PayrollBankAccountImportService;

/**
 * Import the staff spreadsheet's bank columns into each salary record.
 *
 * Two steps on purpose. The first shows what the file would change and touches nothing; the second
 * does it. An import that writes 900 salary records the moment a file is chosen is a mistake waiting
 * for a tired afternoon, and the preview is also where the officer finds out that 14 doctors have no
 * salary to attach to.
 */
class PayrollBankAccountImportController extends Controller
{
    public function __construct(private readonly PayrollBankAccountImportService $svc) {}

    public function index(Request $r): Response
    {
        $path = $r->session()->get('bank_import_path');
        $preview = $path !== null && is_file($path) ? $this->svc->preview($path) : null;

        return Inertia::render('PAYROLL::Settings/BankAccounts', [
            'preview' => $preview,
            'uploadedName' => $r->session()->get('bank_import_name'),
            'applied' => $r->session()->get('bank_import_applied'),
        ]);
    }

    public function preview(Request $r): RedirectResponse
    {
        $r->validate(['file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480']]);

        $file = $r->file('file');
        $name = $file->getClientOriginalName();
        $path = $file->storeAs('bank-imports', uniqid('bank-', true).'.'.$file->getClientOriginalExtension(), 'local');

        $absolute = \Illuminate\Support\Facades\Storage::disk('local')->path($path);
        $preview = $this->svc->preview($absolute);

        $r->session()->put('bank_import_path', $absolute);
        $r->session()->put('bank_import_name', $name);

        $message = sprintf(
            '%s read: %d of %d rows carry an account number, %d are paid in cash, and %d problems need a decision before anything is written.',
            $name,
            $preview['bank_rows'],
            $preview['total_rows'],
            $preview['cash_rows'],
            count($preview['problems']),
        );

        return back()->with('success', $message);
    }

    public function apply(Request $r): RedirectResponse
    {
        $path = $r->session()->get('bank_import_path');

        if ($path === null || ! is_file($path)) {
            return back()->with('error', 'Upload the spreadsheet again — the file from the last upload is no longer here.');
        }

        try {
            $result = $this->svc->apply($path, $r->user()?->id);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        $r->session()->forget(['bank_import_path', 'bank_import_name']);
        $r->session()->put('bank_import_applied', [
            'updated' => $result['updated'],
            'skipped' => $result['skipped'],
        ]);

        return redirect()->route('payroll.bank-accounts.index')
            ->with('success', sprintf(
                '%d salary records updated. %d rows were left alone — the reasons are listed below.',
                $result['updated'],
                $result['skipped'],
            ));
    }
}
