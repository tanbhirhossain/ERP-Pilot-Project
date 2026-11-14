<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\CORE\Support\DataTableQuery;
use Modules\CORE\Support\PaginatedResource;
use Modules\PAYROLL\Actions\GenerateSalaryCutlistAction;
use Modules\PAYROLL\DTO\EmployeePayrollData;
use Modules\PAYROLL\Models\PayrollCalculationSnapshot;
use Modules\PAYROLL\Models\PayrollCutlist;
use Modules\PAYROLL\Models\PayrollCutlistItem;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSetting;
use Modules\PAYROLL\Models\PayrollSlip;

/**
 * The cutlist is the salary sheet's review twin: exactly the employee-level rows the salary calculation will
 * consume, but it is never final. Payroll regenerates it from HRM attendance data, adjusts what is
 * legitimately wrong (every adjustment carries a reason, logged per row), and approves it. When
 * Payroll settings require cutlist review, a salary sheet cannot be queued for a month until this page's
 * approval is in place; the approved deductions are then what the calculation engine pays.
 */
final class PayrollCutlistController extends Controller
{
    /** Reviewer-adjustable amounts; each maps 1:1 to a column the calculation engine reads from the cutlist item. */
    private const ADJUSTABLE = ['unpaid_leave_deduction', 'late_penalty_deduction', 'missing_punch_deduction', 'overtime_pay', 'extra_duty_pay'];

    public function index(Request $request): Response
    {
        $cutlists = PayrollCutlist::query()
            ->withCount('items')
            ->withSum('items', 'unpaid_leave_deduction')
            ->withSum('items', 'late_penalty_deduction')
            ->withSum('items', 'missing_punch_deduction')
            ->withSum('items', 'overtime_pay')
            ->withSum('items', 'extra_duty_pay')
            ->orderByDesc('pay_year')
            ->orderByDesc('pay_month')
            ->paginate(25)
            ->withQueryString();
        $sheets = PayrollSalarySheet::query()
            ->whereIn('payroll_cutlist_id', $cutlists->pluck('id')->all() ?: [0])
            ->get(['id', 'payroll_cutlist_id', 'status', 'pay_year', 'pay_month'])
            ->keyBy('payroll_cutlist_id');
        $rows = $cutlists->through(fn (PayrollCutlist $cutlist): array => [
            'id' => $cutlist->id,
            'pay_year' => (int) $cutlist->pay_year,
            'pay_month' => (int) $cutlist->pay_month,
            'status' => $cutlist->status,
            'generated_at' => $cutlist->generated_at?->toDateString(),
            'approved_at' => $cutlist->approved_at?->toDateString(),
            'employee_count' => (int) $cutlist->items_count,
            'deduction_total' => round((float) $cutlist->items_sum_unpaid_leave_deduction + (float) $cutlist->items_sum_late_penalty_deduction + (float) $cutlist->items_sum_missing_punch_deduction, 2),
            'earning_total' => round((float) $cutlist->items_sum_overtime_pay + (float) $cutlist->items_sum_extra_duty_pay, 2),
            'sheet' => $sheets->get($cutlist->id) !== null
                ? ['id' => $sheets->get($cutlist->id)->id, 'status' => $sheets->get($cutlist->id)->status]
                : null,
        ]);

        return Inertia::render('PAYROLL::Cutlists/Index', [
            'cutlists' => PaginatedResource::from($rows),
            'defaultYear' => (int) now()->format('Y'),
            'defaultMonth' => (int) now()->format('n'),
            'reviewRequired' => (bool) (PayrollSetting::query()->first()?->cutlist_review_required ?? false),
        ]);
    }

    public function generate(Request $request, GenerateSalaryCutlistAction $generate): RedirectResponse
    {
        $data = $request->validate([
            'pay_year' => ['required', 'integer', 'between:2000,2100'],
            'pay_month' => ['required', 'integer', 'between:1,12'],
            'overwrite_adjustments' => ['sometimes', 'boolean'],
        ]);
        $existing = PayrollCutlist::query()->where('pay_year', (int) $data['pay_year'])->where('pay_month', (int) $data['pay_month'])->first();
        if ($existing !== null) {
            if (in_array($existing->status, ['approved', 'locked'], true)) {
                throw ValidationException::withMessages(['pay_month' => "This month's cutlist is {$existing->status}. Reopen it for review before regenerating — regenerating approved figures would invalidate the sign-off."]);
            }
            if (! ($data['overwrite_adjustments'] ?? false)) {
                $adjusted = $existing->items()->whereNotNull('source_breakdown')->whereRaw('CAST(source_breakdown AS TEXT) LIKE ?', ['%"review"%'])->exists();
                if ($adjusted) {
                    throw ValidationException::withMessages(['overwrite_adjustments' => 'Reviewer adjustments exist on this cutlist. Regenerating rebuilds every row from HRM and erases them — tick the confirmation to overwrite, or reopen and adjust row by row.']);
                }
            }
        }
        $sheet = PayrollSalarySheet::query()->where('pay_year', (int) $data['pay_year'])->where('pay_month', (int) $data['pay_month'])->first();
        $snapshot = $sheet ? PayrollCalculationSnapshot::query()->where('salary_sheet_id', $sheet->id)->first() : null;
        $employeeSnapshot = $snapshot
            ? collect($snapshot->snapshot_json['employees'] ?? [])->map(fn (array $employee) => EmployeePayrollData::fromArray($employee))->all()
            : null;
        $cutlist = $generate->execute((int) $data['pay_year'], (int) $data['pay_month'], $request->user()?->id, false, $employeeSnapshot);

        return back()->with('success', 'Attendance salary cutlist generated from HRM for '.$cutlist->items()->count().' employees. Review the rows, adjust anything that is wrong, then approve.');
    }

    public function show(Request $request, PayrollCutlist $cutlist): Response
    {
        $allowedSorts = ['employee_name', 'employee_id', 'unpaid_leave_days', 'punishment_days', 'overtime_pay', 'extra_duty_pay'];
        $dt = DataTableQuery::fromRequest($request, $allowedSorts, [], 'employee_name', 'asc');
        $query = PayrollCutlistItem::query()->where('payroll_cutlist_id', $cutlist->id);
        if ($dt->search !== null && trim($dt->search) !== '') {
            $needle = '%'.addcslashes(trim($dt->search), '%_').'%';
            $query->where(fn ($q) => $q->where('employee_id', 'like', $needle)->orWhere('employee_name', 'like', $needle));
        }
        $sort = in_array($dt->sort, $allowedSorts, true) ? $dt->sort : 'employee_name';
        $items = $query->orderBy($sort, $dt->direction)->paginate($dt->perPage)->withQueryString();

        $sheetIds = $cutlist->salarySheets()->pluck('id')->all();
        $consumed = $sheetIds !== []
            && (PayrollSalarySheet::query()->whereIn('id', $sheetIds)->whereIn('status', ['queued', 'processing', 'ready', 'released'])->exists()
                || PayrollSlip::query()->whereIn('salary_sheet_id', $sheetIds)->exists());

        return Inertia::render('PAYROLL::Cutlists/Show', [
            'cutlist' => $cutlist,
            'items' => PaginatedResource::from($items),
            'editable' => $cutlist->status === 'generated' && ! $consumed,
            'consumedBySheet' => $consumed,
            'reviewRequired' => (bool) (PayrollSetting::query()->first()?->cutlist_review_required ?? false),
        ]);
    }

    public function approve(Request $request, PayrollCutlist $cutlist): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);
        if ($cutlist->status === 'approved') {
            throw ValidationException::withMessages(['note' => 'This cutlist is already approved.']);
        }
        if ($cutlist->status !== 'generated') {
            throw ValidationException::withMessages(['note' => "Only a generated cutlist can be approved (current status: {$cutlist->status}). Generate it first."]);
        }
        $employeeCount = (int) $cutlist->items()->count();
        if ($employeeCount === 0) {
            throw ValidationException::withMessages(['note' => 'Nothing to approve: this cutlist has no employee rows yet. Generate it from HRM first.']);
        }
        $cutlist->update(['status' => 'approved', 'approval_note' => trim($data['note']), 'approved_by' => $request->user()?->id, 'approved_at' => now()]);

        $suffix = (bool) (PayrollSetting::query()->first()?->cutlist_review_required ?? false)
            ? 'Payroll can now create the salary sheet for this period.'
            : 'Note: payroll settings do not currently require an approved cutlist, so the sheet can be queued without this sign-off.';

        return back()->with('success', "Cutlist approved for {$cutlist->pay_year}-".str_pad((string) $cutlist->pay_month, 2, '0', STR_PAD_LEFT)." ({$employeeCount} employees). {$suffix}");
    }

    public function reopen(Request $request, PayrollCutlist $cutlist): RedirectResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:1000']]);
        if ($cutlist->status !== 'approved') {
            throw ValidationException::withMessages(['note' => 'Only an approved cutlist needs reopening (current status: '.$cutlist->status.').']);
        }
        $sheetIds = $cutlist->salarySheets()->pluck('id')->all();
        if ($sheetIds !== [] && PayrollSlip::query()->whereIn('salary_sheet_id', $sheetIds)->exists()) {
            throw ValidationException::withMessages(['note' => 'Salary slips already exist for this cutlist. Use a payroll reopen request to change paid salaries; editing the review now would only desynchronise it from the slips.']);
        }
        $cutlist->update(['status' => 'generated', 'approved_by' => null, 'approved_at' => null, 'approval_note' => null]);

        return back()->with('success', 'Cutlist reopened for review: '.trim($data['note']));
    }

    public function updateItem(Request $request, PayrollCutlist $cutlist, PayrollCutlistItem $item): RedirectResponse
    {
        abort_unless((int) $item->payroll_cutlist_id === (int) $cutlist->id, 404);
        $rules = ['reason' => ['required', 'string', 'min:3', 'max:1000']];
        foreach (self::ADJUSTABLE as $field) {
            $rules[$field] = ['sometimes', 'numeric', 'min:0', 'max:99999999.99'];
        }
        $data = $request->validate($rules);

        return DB::transaction(function () use ($request, $cutlist, $item, $data) {
            $locked = PayrollCutlist::query()->whereKey($cutlist->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'generated') {
                throw ValidationException::withMessages(['reason' => 'Adjustments are only allowed while the cutlist is under review (status: generated). Reopen it first.']);
            }
            $sheetIds = $locked->salarySheets()->pluck('id')->all();
            if ($sheetIds !== [] && PayrollSlip::query()->whereIn('salary_sheet_id', $sheetIds)->exists()) {
                throw ValidationException::withMessages(['reason' => 'This cutlist already fed salary slips; edits would desynchronise the review from the paid figures. Use a payroll reopen request.']);
            }

            $changes = [];
            $updates = [];
            foreach (self::ADJUSTABLE as $field) {
                if (! array_key_exists($field, $data) || $data[$field] === null) {
                    continue;
                }
                $to = round((float) $data[$field], 2);
                $from = (float) $item->{$field};
                if (abs($to - $from) >= 0.005) {
                    $changes[$field] = ['from' => $from, 'to' => $to];
                    $updates[$field] = $to;
                }
            }
            if ($changes === []) {
                return back()->with('info', 'No amounts changed — nothing recorded.');
            }

            $breakdown = is_array($item->source_breakdown) ? $item->source_breakdown : [];
            $review = is_array($breakdown['review'] ?? null) ? $breakdown['review'] : [];
            $review[] = [
                'at' => now()->toDateTimeString(),
                'by' => $request->user()?->id,
                'name' => $request->user()?->name,
                'reason' => trim($data['reason']),
                'changes' => $changes,
            ];
            $breakdown['review'] = $review;
            $updates['source_breakdown'] = $breakdown;
            $item->update($updates);

            return back()->with('success', 'Adjustment recorded for '.$item->employee_name.'. It applies when the salary sheet is calculated.');
        });
    }
}
