<?php

namespace Modules\PAYROLL\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

final class PayrollCutlistController extends Controller
{
    public function generate(Request $request, GenerateSalaryCutlistAction $generate): RedirectResponse
    {
        $data = $request->validate(['pay_year' => ['required', 'integer', 'between:2000,2100'], 'pay_month' => ['required', 'integer', 'between:1,12']]);
        $sheet = PayrollSalarySheet::query()->where('pay_year', (int) $data['pay_year'])->where('pay_month', (int) $data['pay_month'])->first();
        $snapshot = $sheet ? PayrollCalculationSnapshot::query()->where('salary_sheet_id', $sheet->id)->first() : null;
        $employeeSnapshot = $snapshot
            ? collect($snapshot->snapshot_json['employees'] ?? [])->map(fn (array $employee) => EmployeePayrollData::fromArray($employee))->all()
            : null;
        $cutlist = $generate->execute((int) $data['pay_year'], (int) $data['pay_month'], $request->user()?->id, false, $employeeSnapshot);

        return back()->with('success', 'Attendance salary cutlist generated from HRM for '.$cutlist->items()->count().' employees.');
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

        return Inertia::render('PAYROLL::Cutlists/Show', ['cutlist' => $cutlist, 'items' => PaginatedResource::from($items)]);
    }
}
