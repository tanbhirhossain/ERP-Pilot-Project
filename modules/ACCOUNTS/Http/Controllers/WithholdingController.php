<?php

namespace Modules\ACCOUNTS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\ACCOUNTS\Models\Bank;
use Modules\ACCOUNTS\Models\TaxDeposit;
use Modules\ACCOUNTS\Models\Withholding;
use Modules\ACCOUNTS\Models\WithholdingRule;
use Modules\ACCOUNTS\Services\WithholdingService;
use Modules\PURCHASE\Models\VendorBill;
use Modules\SALES\Models\Invoice;

/**
 * F17 — TDS/VDS withholding workspace: configure rates, deduct on posted vendor
 * bills, record VDS suffered on sales, certify Mushak 6.6 certificates, and
 * deposit withheld tax to NBR via treasury challans.
 */
class WithholdingController extends Controller
{
    public function __construct(private readonly WithholdingService $svc) {}

    public function index(Request $r): Response
    {
        $tab = $r->input('tab', 'inward');

        return Inertia::render('ACCOUNTS::Tax/Withholding', [
            'tab' => $tab,
            'pending' => $this->svc->pendingSummary(),
            'rules' => WithholdingRule::orderBy('type')->orderBy('name')->get(),
            'inward' => Withholding::inward()->with(['partner:id,name', 'bill:id,bill_no', 'rule:id,name,type', 'deposit:id,deposit_no'])
                ->latest('period')->limit(200)->get(),
            'outward' => Withholding::outward()->with(['partner:id,name', 'invoice:id,invoice_no'])
                ->latest('period')->limit(200)->get(),
            'deposits' => TaxDeposit::with('bank:id,name')->latest('deposit_date')->limit(100)->get(),
            'ruleOptions' => WithholdingRule::active()->get()->map(fn ($x) => [
                'value' => $x->id, 'label' => strtoupper($x->type).' · '.$x->name.' ('.$x->rate.'%)',
            ])->values(),
            'billOptions' => VendorBill::where('status', 'posted')->with('vendor:id,name')
                ->latest('bill_date')->limit(150)->get()
                ->map(fn ($b) => ['value' => $b->id, 'label' => $b->bill_no.' · '.($b->vendor?->name ?? '—'), 'description' => 'Base '.number_format((float) $b->subtotal, 2)])->values(),
            'invoiceOptions' => Invoice::whereIn('status', ['posted', 'paid', 'partially_paid', 'overdue'])->with('customer:id,name')
                ->latest('invoice_date')->limit(150)->get()
                ->map(fn ($i) => ['value' => $i->id, 'label' => $i->invoice_no.' · '.($i->customer?->name ?? '—')])->values(),
            'bankOptions' => Bank::where('is_active', true)->get()->map(fn ($b) => ['value' => $b->id, 'label' => $b->name])->values(),
        ]);
    }

    public function storeRule(Request $r)
    {
        $data = $r->validate([
            'name' => ['required', 'string', 'max:150'],
            'type' => ['required', 'in:tds,vds'],
            'section' => ['nullable', 'string', 'max:120'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'threshold' => ['nullable', 'numeric', 'min:0'],
            'effective_from' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        WithholdingRule::create($data + ['is_active' => true]);

        return back()->with('success', 'Withholding rule added.');
    }

    public function toggleRule(WithholdingRule $rule)
    {
        $rule->update(['is_active' => ! $rule->is_active]);

        return back()->with('success', 'Rule '.($rule->is_active ? 'activated' : 'deactivated').'.');
    }

    public function applyToBill(Request $r)
    {
        $data = $r->validate([
            'bill_id' => ['required', 'integer', 'exists:purchase_bills,id'],
            'rule_id' => ['required', 'integer', 'exists:tax_withholding_rules,id'],
        ]);
        $bill = VendorBill::findOrFail($data['bill_id']);
        $rule = WithholdingRule::findOrFail($data['rule_id']);
        $w = $this->svc->applyToBill($bill, $rule);

        return back()->with('success', $w
            ? strtoupper($w->type).' of '.number_format((float) $w->amount, 2).' recorded on '.$bill->bill_no.'.'
            : 'Bill base is below the rule threshold — no deduction required.');
    }

    public function recordOutward(Request $r)
    {
        $data = $r->validate([
            'invoice_id' => ['required', 'integer', 'exists:sales_invoices,id'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'base' => ['required', 'numeric', 'min:0'],
            'certificate_no' => ['nullable', 'string', 'max:120'],
        ]);
        $invoice = Invoice::findOrFail($data['invoice_id']);
        $this->svc->recordOutward($invoice, (float) $data['rate'], (float) $data['base'], $data['certificate_no'] ?? null);

        return back()->with('success', 'Outward VDS recorded.');
    }

    public function certify(Request $r, Withholding $withholding)
    {
        $data = $r->validate(['certificate_no' => ['required', 'string', 'max:120']]);
        $this->svc->certify($withholding, $data['certificate_no']);

        return back()->with('success', 'VDS certificate recorded — now claimable in Mushak 9.1.');
    }

    public function deposit(Request $r)
    {
        $data = $r->validate([
            'withholding_ids' => ['required', 'array', 'min:1'],
            'withholding_ids.*' => ['integer', 'exists:tax_withholdings,id'],
            'period' => ['required', 'date'],
            'deposit_date' => ['required', 'date'],
            'bank_id' => ['nullable', 'integer', 'exists:acc_banks,id'],
            'challan_no' => ['nullable', 'string', 'max:120'],
            'treasury_code' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $deposit = $this->svc->deposit($data['withholding_ids'], $data);

        return back()->with('success', 'Deposit '.$deposit->deposit_no.' recorded — '.number_format((float) $deposit->amount, 2).' marked deposited.');
    }
}
