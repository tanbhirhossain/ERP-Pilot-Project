<?php

namespace Modules\HRM\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\CORE\Support\OptionAccess;
use Modules\HRM\Models\ChecklistTemplate;
use Modules\HRM\Models\HrmAsset;
use Modules\HRM\Services\BiometricDeviceService;
use Modules\HRM\Services\DepartmentService;
use Modules\HRM\Services\DesignationService;
use Modules\HRM\Services\EmployeeService;
use Modules\HRM\Services\RosterShiftService;

/**
 * Typeahead source for the HRM pickers.
 *
 * This route was gated at the route level with a single coarse `hrm.view`, while every HRM *page*
 * is gated on its own specific permission (`leaves/index` needs `hrm.leaves.view`, not `hrm.view`).
 * The two seeded operational roles happen to hold `hrm.view`, so nothing visibly broke — but a
 * custom role granted `hrm.leaves.view` alone could open `LeaveRequests/Create.vue` and get a 403
 * from its own employee picker, which presents as an empty dropdown rather than a permission error.
 *
 * The gate now lives here, per type, any-of: `hrm.view` still opens everything (so no existing role
 * loses access), and each type additionally accepts the permissions of the pages that consume it.
 * The route-level middleware is removed rather than kept as a second gate, because a coarse gate in
 * front of a fine one can only ever reject requests the fine gate would allow.
 */
class OptionsController extends Controller
{
    /** @var array<string, list<string>> type => permissions, any of which is sufficient */
    private const PERMISSIONS = [
        // Consumed by 15 pages: devices, leaves, regularizations, assets, checklists, movements,
        // attendance fixes, probations, informed, swaps, duties and documents.
        'employees' => [
            'hrm.view', 'hrm.employees.view', 'hrm.employees.manage', 'hrm.reports.view',
            'hrm.devices.view', 'hrm.devices.manage',
            'hrm.leaves.view', 'hrm.leaves.manage', 'hrm.leaves.approve', 'hrm.leaves.balance.view',
            'hrm.leave-types.view', 'hrm.leave-types.manage', 'hrm.leave-year-end.view', 'hrm.leave-year-end.manage',
            'hrm.regularizations.view', 'hrm.regularizations.manage', 'hrm.regularizations.approve',
            'hrm.probations.view', 'hrm.probations.manage',
            'hrm.assets.view', 'hrm.assets.manage',
            'hrm.checklists.view', 'hrm.checklists.manage',
            'hrm.clearance.view', 'hrm.clearance.manage',
            'hrm.movements.view', 'hrm.movements.manage', 'hrm.movements.approve',
            'hrm.attendance.view', 'hrm.attendance.manage', 'hrm.attendance.sync',
            'hrm.informed.view', 'hrm.informed.manage',
            'hrm.swaps.view', 'hrm.swaps.manage',
            'hrm.duties.view', 'hrm.duties.manage',
            'hrm.documents.view', 'hrm.documents.manage', 'hrm.documents.verify',
            'hrm.overtimes.view', 'hrm.overtimes.manage', 'hrm.overtimes.approve',
            // PAYROLL is a consumer too: EmployeeSearchSelect.vue and EmployeeMultiSearchSelect.vue
            // resolve `route('hrm.api.options', { type: 'employees' })`, and eight payroll pages use
            // them. A payroll officer with no HRM grant at all still has to be able to fill them.
            'payroll.view', 'payroll.sheets.manage', 'payroll.sheets.release',
            'payroll.slips.view', 'payroll.slips.pay',
            'payroll.bank-advices.view', 'payroll.bank-advices.manage',
            'payroll.finance.view', 'payroll.finance.post', 'payroll.finance.void',
            'payroll.adjustments.manage', 'payroll.increments.manage', 'payroll.increments.approve',
            'payroll.loans.manage', 'payroll.loans.approve',
            'payroll.pf.view', 'payroll.pf.manage', 'payroll.bonus.manage', 'payroll.tax.manage',
            'payroll.settlements.manage', 'payroll.settlements.approve',
            'payroll.reopens.view', 'payroll.reopens.manage',
        ],
        // Holidays/Index, Movements/Create, Attendance/Fix/Index.
        'departments' => [
            'hrm.view', 'hrm.departments.view', 'hrm.departments.manage', 'hrm.organization.view',
            'hrm.organization.manage', 'hrm.holidays.view', 'hrm.holidays.manage',
            'hrm.movements.view', 'hrm.movements.manage',
            'hrm.attendance.view', 'hrm.attendance.manage',
        ],
        // Movements/Create.
        'designations' => [
            'hrm.view', 'hrm.designations.view', 'hrm.designations.manage', 'hrm.organization.view',
            'hrm.organization.manage', 'hrm.movements.view', 'hrm.movements.manage',
            'hrm.probations.view', 'hrm.regularizations.view',
        ],
        // Regularizations/Index.
        'shifts' => [
            'hrm.view', 'hrm.shifts.view', 'hrm.shifts.manage',
            'hrm.regularizations.view', 'hrm.regularizations.manage',
            'hrm.duties.view', 'hrm.duties.manage',
            'hrm.attendance.view', 'hrm.attendance.manage',
            'hrm.shift-allowances.view', 'hrm.shift-allowances.manage',
        ],
        'devices' => [
            'hrm.view', 'hrm.devices.view', 'hrm.devices.manage',
            'hrm.attendance.view', 'hrm.attendance.manage', 'hrm.attendance.sync',
        ],
        // EmployeeAssets/Index.
        'assets' => ['hrm.view', 'hrm.assets.view', 'hrm.assets.manage'],
        // Checklists/Index.
        'checklist-templates' => [
            'hrm.view', 'hrm.checklists.view', 'hrm.checklists.manage',
            'hrm.clearance.view', 'hrm.clearance.manage',
        ],
        'templates' => [
            'hrm.view', 'hrm.checklists.view', 'hrm.checklists.manage',
            'hrm.clearance.view', 'hrm.clearance.manage',
        ],
    ];

    public function __invoke(Request $r, string $type): JsonResponse
    {
        if ($denied = OptionAccess::gate($r, $type, self::PERMISSIONS)) {
            return $denied;
        }

        $search = $r->query('search');
        $limit = max(1, min(500, (int) $r->query('limit', 200)));
        $items = match ($type) {
            'departments' => app(DepartmentService::class)->options($search, $limit),
            'designations' => app(DesignationService::class)->options($search, $limit),
            'employees' => app(EmployeeService::class)->options($search, $limit),
            'shifts' => app(RosterShiftService::class)->options($search, $limit),
            'devices' => app(BiometricDeviceService::class)->options($search, $limit),
            /*
             * EmployeeAssets/Index.vue and Checklists/Index.vue both pointed at
             * these two types and got a 404, so neither picker could be filled.
             */
            'assets' => HrmAsset::query()
                // The issue form only offers assets that are free to allocate.
                ->when(($r->query('status') ?: 'In Stock') !== 'any', fn ($q) => $q->where('status', $r->query('status') ?: 'In Stock'))
                ->when($search, function ($q, string $term) {
                    $needle = '%'.addcslashes($term, '%_\\').'%';
                    $q->where(fn ($g) => $g->where('name', 'like', $needle)->orWhere('asset_code', 'like', $needle)->orWhere('serial_no', 'like', $needle));
                })
                ->orderBy('name')->limit($limit)
                ->get(['id', 'asset_code', 'name', 'category', 'serial_no', 'status'])
                ->map(fn (HrmAsset $a) => [
                    'value' => $a->id,
                    'label' => $a->name,
                    'description' => trim($a->asset_code.($a->serial_no ? ' · S/N '.$a->serial_no : '').($a->category ? ' · '.$a->category : '')),
                    'meta' => $a->status,
                ]),
            'checklist-templates', 'templates' => ChecklistTemplate::query()
                ->where('is_active', true)
                ->when($search, function ($q, string $term) {
                    $needle = '%'.addcslashes($term, '%_\\').'%';
                    $q->where(fn ($g) => $g->where('name', 'like', $needle)->orWhere('category', 'like', $needle));
                })
                ->withCount('items')
                ->orderBy('category')->orderBy('name')->limit($limit)
                ->get(['id', 'name', 'category', 'items_count'])
                ->map(fn (ChecklistTemplate $t) => [
                    'value' => $t->id,
                    'label' => $t->name,
                    'description' => $t->category,
                    'meta' => $t->items_count.' items',
                ]),
        };

        // SearchableSelect remote mode consumes this envelope; bare Collection responses looked empty.
        return response()->json(['items' => $items->values()]);
    }
}
