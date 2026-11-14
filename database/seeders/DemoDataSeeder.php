<?php

namespace Database\Seeders;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\ACCOUNTS\Models\Bank;
use Modules\ACCOUNTS\Models\Journal;
use Modules\ACCOUNTS\Services\BankService;
use Modules\ACCOUNTS\Services\JournalService;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Models\Leave\LeaveType;
use Modules\HRM\Models\Roster\RosterOvertime;
use Modules\HRM\Services\Attendance\AttendanceProcessingService;
use Modules\HRM\Services\LeaveRequestService;
use Modules\HRM\Services\LeaveTypeService;
use Modules\HRM\Services\RosterDutyService;
use Modules\HRM\Services\RosterOvertimeService;
use Modules\INVENTORY\Models\Adjustment;
use Modules\INVENTORY\Models\Kit;
use Modules\INVENTORY\Models\Product;
use Modules\INVENTORY\Models\Transfer;
use Modules\INVENTORY\Models\Warehouse;
use Modules\INVENTORY\Services\AdjustmentService;
use Modules\INVENTORY\Services\KitService;
use Modules\INVENTORY\Services\TransferService;
use Modules\PARTNER\Models\Partner;
use Modules\PAYROLL\Actions\ProcessMonthlyPayrollBatchAction;
use Modules\PAYROLL\Models\PayrollSalarySheet;
use Modules\PAYROLL\Models\PayrollSlip;
use Modules\PAYROLL\Services\PayrollBankAdviceService;
use Modules\PAYROLL\Services\PayrollJournalService;
use Modules\PAYROLL\Services\PayrollLoanService;
use Modules\PAYROLL\Services\PayrollSalarySheetService;
use Modules\PAYROLL\Services\ProvidentFundService;
use Modules\PURCHASE\Models\PurchaseOrder;
use Modules\PURCHASE\Services\GoodsReceiptService;
use Modules\PURCHASE\Services\PurchaseOrderService;
use Modules\PURCHASE\Services\VendorBillService;
use Modules\SALES\DTO\InvoiceLineData;
use Modules\SALES\DTO\PosSaleData;
use Modules\SALES\Models\Delivery;
use Modules\SALES\Models\Invoice;
use Modules\SALES\Models\Order;
use Modules\SALES\Models\PosSession;
use Modules\SALES\Models\Quotation;
use Modules\SALES\Services\DeliveryService;
use Modules\SALES\Services\InvoiceService;
use Modules\SALES\Services\OrderService;
use Modules\SALES\Services\PosService;
use Modules\SALES\Services\QuotationService;

/**
 * Optional demo dataset (`php artisan erp:demo-data`).
 *
 * Why this exists: a fresh `migrate:fresh --seed` left 129 of 167 tables empty, and every
 * table a demo needs to show was among them — no attendance despite 998 employees, no
 * payroll at all, one quotation, one zero-item POS sale, no invoices, bills, payments or
 * journals. The shipped `SalesDemoSeeder` and `PurchaseDemoSeeder` create almost nothing,
 * and both carry the `if (Model::exists()) return;` guard that silently stopped
 * `AccountsDemoSeeder` from ever running (see that seeder's docblock).
 *
 * Design rules, all deliberate:
 *
 *  1. Documents go through the real services (`InvoiceService::post()`,
 *     `PayrollSalarySheetService::dispatch()`, `AttendanceProcessingService::processPending()`,
 *     ...), never raw inserts, so document numbers, journals, stock movements and ledger
 *     entries are produced by the same code the UI uses and the demo cannot reach a state
 *     the application itself could not.
 *  2. The exception is genuine reference/config data with no owning service (holidays, leave
 *     balances, the singleton payroll-settings row, tax configuration). Those are inserted
 *     directly and are listed in `REFERENCE_INSERTS` below so the compromise is visible
 *     rather than buried.
 *  3. Opt-in: `DatabaseSeeder` does NOT call this. CI runs the suite on every build and a
 *     payroll + attendance pass would dominate the runtime.
 *  4. Idempotent by marker, not by "does any row exist" — the guard that broke
 *     `AccountsDemoSeeder`. A second run is a no-op; nothing is deleted or duplicated.
 *  5. One transaction, so a failure leaves no half-built dataset behind.
 *
 * Scope: one department's workforce and the two seeded warehouses. Payroll is scoped with
 * the domain's own exclusion mechanism (a documented reason is mandatory), which is exactly
 * how a payroll officer would limit a period, and it keeps a full seed in seconds.
 */
class DemoDataSeeder extends Seeder
{
    /** Written into the notes of demo documents; also the idempotence marker. */
    private const MARKER = 'Demo dataset (erp:demo-data)';

    /** The payroll/attendance period the demo runs: October of FY 2026-27. */
    private const PAY_YEAR = 2026;

    private const PAY_MONTH = 10;

    /** Reference/config rows written directly because no service owns them. */
    public const REFERENCE_INSERTS = [
        'roster_shifts',
        'hrm_holidays',
        'hrm_leave_balances',
        'payroll_settings',
        'tax_configurations',
        'tax_slabs',
        'acc_bank_statements',
        'acc_bank_transactions',
    ];

    private User $actor;

    /** @var Collection<int, Employee> */
    private $employees;

    /** @var array<int, string> */
    private array $excludedEmployeeIds = [];

    private Warehouse $warehouse;

    private Warehouse $secondWarehouse;

    /** @var Collection<int, Product> */
    private $products;

    private Partner $vendor;

    private Partner $customer;

    private ?Journal $manualJournal = null;

    public function run(): void
    {
        if ($this->alreadySeeded()) {
            $this->command?->info('Demo data already present — skipping.');

            return;
        }

        $this->context();

        DB::transaction(function (): void {
            // `SeedCommand` wraps every seeder in `Model::unguarded()` (SeedCommand.php:70),
            // which quietly changes how the application behaves: a key the model's `$fillable`
            // excludes is *discarded* over HTTP but *inserted* under a seeder. A service that
            // writes a column its table does not have therefore crashes only when seeded —
            // `PosService::sell()` passes per-line discount_amount/vat_rate/vat_amount, none of
            // which exist on `pos_sale_items`. The demo has to reproduce what the running
            // application does, so guarding is restored for its whole duration.
            Model::reguard();

            // Services stamp created_by from auth()->id(), so the demo needs an actor.
            Auth::login($this->actor);

            $this->referenceData();
            $this->roster();
            $this->attendance();
            $this->leave();
            $this->purchaseChain();
            $this->inventoryDocuments();
            $this->salesChain();
            $this->posSales();
            $this->accountsEntries();
            $this->payrollRun();

            Auth::logout();
            Model::unguard();
        });

        // `dispatch()` queued ProcessSalarySheetJob with afterCommit(), and the seeder already
        // ran that work inline. Drop the queued copy so a later worker cannot reprocess a
        // released sheet.
        DB::table('jobs')->where('payload', 'like', '%ProcessSalarySheetJob%')->delete();

        $this->command?->info(sprintf(
            'Demo data created for %d employees (%s).',
            $this->employees->count(),
            self::MARKER
        ));
    }

    private function alreadySeeded(): bool
    {
        return Schema::hasTable('sales_orders')
            && DB::table('sales_orders')->where('notes', 'like', self::MARKER.'%')->exists();
    }

    private function context(): void
    {
        $this->actor = User::query()->orderBy('id')->firstOrFail();

        // The department with the most active staff, so attendance and payroll have a cohort.
        $departmentId = Employee::query()
            ->where('is_active', true)
            ->whereNotNull('department_id')
            ->select('department_id')
            ->groupBy('department_id')
            ->orderByRaw('COUNT(*) DESC')
            ->value('department_id');

        $this->employees = Employee::query()
            ->where('department_id', $departmentId)
            ->where('is_active', true)
            ->orderBy('employee_id')
            ->limit(14)
            ->get();

        if ($this->employees->isEmpty()) {
            throw new \RuntimeException('DemoDataSeeder needs active employees with a department; run RealEmployeeBasicSeeder first.');
        }

        // Everyone else is excluded from the demo payroll period. The domain requires a
        // documented reason for exclusions, so one is given.
        $this->excludedEmployeeIds = Employee::query()
            ->whereNotIn('employee_id', $this->employees->pluck('employee_id')->all())
            ->pluck('employee_id')
            ->all();

        $this->warehouse = Warehouse::query()->orderBy('id')->firstOrFail();
        $this->secondWarehouse = Warehouse::query()->orderBy('id')->skip(1)->first() ?? $this->warehouse;
        $this->products = Product::query()->orderBy('id')->limit(8)->get();

        if ($this->products->count() < 6) {
            throw new \RuntimeException('DemoDataSeeder needs at least 6 products; run InventoryDemoSeeder first.');
        }

        $this->vendor = Partner::query()->where('type', 'vendor')->orderBy('id')->firstOrFail();
        $this->customer = Partner::query()->where('type', 'customer')->orderBy('id')->firstOrFail();
    }

    private function date(int $day): string
    {
        return sprintf('%d-%02d-%02d', self::PAY_YEAR, self::PAY_MONTH, $day);
    }

    /** Holidays, the payroll-settings row and a tax configuration. */
    private function referenceData(): void
    {
        // Per-name guard: the base seed now carries a calendar of its own (AMZOctober2026CalendarSeeder
        // puts Durga puja on the books), so "is the table empty at all" would silently skip all three
        // demo holidays and take the hrm_holidays floor down with it.
        $now = now();
        $demoHolidays = [];
        foreach ([
            ['Eid-ul-Fitr', self::PAY_YEAR.'-03-20', 'Festival Holiday'],
            ['Independence Day', self::PAY_YEAR.'-03-26', 'Public Holiday'],
            ['Victory Day', self::PAY_YEAR.'-12-16', 'Public Holiday'],
        ] as [$name, $date, $type]) {
            if (DB::table('hrm_holidays')->where('name', $name)->exists()) {
                continue;
            }

            $demoHolidays[] = ['name' => $name, 'holiday_date' => $date, 'holiday_type' => $type, 'notes' => self::MARKER, 'created_at' => $now, 'updated_at' => $now];
        }

        if ($demoHolidays !== []) {
            DB::table('hrm_holidays')->insert($demoHolidays);
        }

        if (! DB::table('payroll_settings')->exists()) {
            DB::table('payroll_settings')->insert([
                'currency' => 'BDT',
                'punishment_days_per_salary_day' => 1,
                'overtime_multiplier' => '1.5',
                'extra_duty_multiplier' => '2',
                'missing_punch_penalty_amount' => '200',
                'unpaid_leave_day_divisor' => '30',
                // Cast `array` on PayrollSetting, so this column holds JSON approval tiers —
                // not a count. Writing an int here decodes back as an int and
                // PayrollLoanApprovalSteps::normalize(?array) then throws a TypeError on the
                // first loan request. Labels and roles must each be unique across tiers.
                // The roles named here must actually be held by somebody: the approval action
                // asserts the stage role per approver, and every stage needs a different one.
                // `Administrator` is defined but assigned to no user in the shipped seeders, so
                // the tiers use Manager and Super Admin, which are.
                'loan_approval_steps' => json_encode([
                    ['label' => 'Manager', 'role' => 'Manager'],
                    ['label' => 'Payroll Head', 'role' => 'Super Admin'],
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (! DB::table('tax_configurations')->exists()) {
            $configId = DB::table('tax_configurations')->insertGetId([
                'name' => 'Bangladesh Salary Tax '.self::PAY_YEAR,
                // The column is an enum: only 'fixed' and 'progressive' are accepted.
                'mode' => 'progressive',
                'fixed_monthly_tax' => '0',
                'annual_exemption' => '350000',
                'taxable_salary_percent' => '100',
                'is_active' => true,
                'effective_from' => self::PAY_YEAR.'-07-01',
                'effective_to' => (self::PAY_YEAR + 1).'-06-30',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $slabs = [];
            foreach ([[0, 350000, 0], [350001, 450000, 5], [450001, 850000, 10], [850001, 1350000, 15], [1350001, 1850000, 20], [1850001, 0, 25]] as $index => [$lower, $upper, $rate]) {
                $slabs[] = [
                    'tax_configuration_id' => $configId,
                    'lower_limit' => (string) $lower,
                    // 0 means "and above" for the top slab.
                    'upper_limit' => (string) $upper,
                    'rate_percent' => (string) $rate,
                    'sort_order' => $index + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('tax_slabs')->insert($slabs);
        }
    }

    /**
     * A month of biometric punches for the cohort, then the real processing pipeline that
     * turns them into punch pairs and daily attendance rows.
     */
    private function attendance(): void
    {
        $rows = [];
        $now = now();

        foreach ($this->employees as $index => $employee) {
            foreach (range(1, 28) as $day) {
                // Friday and Saturday are the Bangladeshi weekend, so the off-day
                // classification has something real to work with.
                if (date('N', strtotime($this->date($day))) >= 6 && $index % 3 !== 0) {
                    continue;
                }

                $deviceUser = 'DEMO-'.$employee->employee_id;

                // Most days are clean against the 09:00-18:00 roster; a deliberate minority
                // is late (every fourth employee) or leaves early (every fifth), so the
                // cutlist, the approval queue and the attendance report all have variety.
                $inTime = ($index % 4 === 0) ? ' 09:22:00' : ' 08:5'.($index % 10).':00';

                // Two employees stay until 19:45, which is more than the 0.5h threshold
                // `syncFromAttendance('Overtime')` requires, so the overtime queue, its
                // approvals and the payroll cutlist's overtime pay all have real rows.
                $outTime = match (true) {
                    $index % 6 === 3 => ' 19:45:00',
                    $index % 5 === 0 => ' 16:3'.($index % 10).':00',
                    default => ' 18:0'.($index % 10).':00',
                };

                foreach ([$this->date($day).$inTime, $this->date($day).$outTime] as $punchTime) {
                    $rows[] = [
                        'employee_id' => $employee->employee_id,
                        'device_user_id' => $deviceUser,
                        'punch_time' => $punchTime,
                        'punch_type' => 'AUTO',
                        'is_processed' => false,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('attendance_logs')->insert($chunk);
        }

        app(AttendanceProcessingService::class)->processPending(count($rows));

        // Overtime requests are derived from attendance, not created by hand: this is the
        // same call the attendance approval queue uses. Most stay Pending (a realistic
        // approval backlog); two are approved so the cutlist has paid overtime to show.
        $rosterOvertime = app(RosterOvertimeService::class);
        // The kind is a label, not a slug: only 'Overtime' and 'Extra Duty' are accepted.
        $rosterOvertime->syncFromAttendance(
            'Overtime',
            $this->date(1),
            $this->date(28)
        );

        $approved = 0;
        foreach (RosterOvertime::query()->where('status', 'Pending')->orderBy('id')->get() as $overtime) {
            if ($approved >= 2) {
                break;
            }
            $rosterOvertime->approve($overtime, (float) $overtime->hours, $this->actor->id, 'Approved for the demo dataset');
            $approved++;
        }
    }

    /**
     * Real shifts and a duty roster for the cohort.
     *
     * Without this the only shift in the database is the seeded 00:00-23:59 fallback, so the
     * processor expects every employee in at midnight and out at 23:59 and classifies all
     * 320 attendance days as "Late & Early Out" — a dataset that looks broken on screen.
     */
    private function roster(): void
    {
        $now = now();

        // Named lookup, deliberately: `RosterShiftSeeder` is now registered in the main DatabaseSeeder,
        // so real shift rows exist on a fresh base seed. Probing "does any non-fallback shift exist"
        // (the old guard) made this block skip while the lookup below still wanted 'General Day'
        // specifically — shift_id came back 0 and every demo duty hit the foreign key.
        if (! DB::table('roster_shifts')->where('shift_name', 'General Day')->exists()) {
            DB::table('roster_shifts')->insert([
                [
                    'shift_name' => 'General Day',
                    'start_time' => '09:00:00',
                    'end_time' => '18:00:00',
                    'crosses_midnight' => false,
                    'max_duration_hours' => 10,
                    'is_fallback' => false,
                    'buffer_before' => 10,
                    'buffer_after' => 10,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ]);
        }

        $shiftId = (int) DB::table('roster_shifts')->where('shift_name', 'General Day')->value('id');
        abort_if($shiftId <= 0, 500, 'DemoDataSeeder: the \'General Day\' shift is missing after insert.');
        $duties = app(RosterDutyService::class);

        foreach ($this->employees as $employee) {
            foreach (range(1, 28) as $day) {
                if (date('N', strtotime($this->date($day))) >= 6) {
                    continue;
                }

                $duties->create([
                    'employee_id' => $employee->employee_id,
                    'shift_id' => $shiftId,
                    'roster_date' => $this->date($day),
                    'scheduled_start_time' => '09:00:00',
                    'scheduled_end_time' => '18:00:00',
                    'remarks' => self::MARKER,
                ]);
            }
        }
    }

    /** Leave types, a couple of requests (one approved), and the balances they draw down. */
    private function leave(): void
    {
        $types = app(LeaveTypeService::class);
        $requests = app(LeaveRequestService::class);

        $leaveTypes = [];
        foreach ([
            ['Casual Leave', 'CL', 10],
            ['Sick Leave', 'SL', 14],
            ['Annual Leave', 'AL', 20],
            ['Maternity Leave', 'ML', 90],
            ['Study Leave', 'STL', 7],
        ] as [$name, $code, $days]) {
            // `LeaveTypeSeeder` is registered in the main DatabaseSeeder now, so the canonical types
            // already exist on a fresh base seed and `leave_types.name` is UNIQUE — a blind create threw.
            // Reuse whatever the catalogue provides (untouched, entitlement and all) and only add the
            // demo-only types it does not carry. Same pattern as the shifts and holidays below.
            $existing = LeaveType::query()->where('name', $name)->first();

            if ($existing !== null) {
                $leaveTypes[] = $existing;

                continue;
            }

            $leaveTypes[] = $types->create([
                'name' => $name,
                'code' => $code,
                'days_per_year' => $days,
                'is_paid' => true,
                'is_active' => true,
                'description' => self::MARKER,
            ]);
        }

        // Leave is dated in NOVEMBER, deliberately after the October attendance window.
        // `LeaveRequestService::syncApprovedLeaveToAttendance()` looks up the day with
        // `AttendanceDaily::firstOrNew(['duty_date' => 'Y-m-d'])`; the `date` cast serialises
        // that as `2026-10-12 00:00:00`, which does not match a stored DATE on SQLite, so the
        // lookup misses and the save() becomes an INSERT that violates the
        // (employee_id, duty_date) unique key. Approving leave over an already-processed
        // attendance day therefore throws on SQLite (MySQL coerces the comparison and works).
        // Reported as finding 33; worked around here rather than silently fixed.
        foreach ($this->employees->take(4) as $index => $employee) {
            $type = $leaveTypes[$index % count($leaveTypes)];
            $from = sprintf('%d-11-%02d', self::PAY_YEAR, 9 + $index);
            $days = 1 + ($index % 3);

            $request = $requests->create([
                'employee_id' => $employee->employee_id,
                'leave_type_id' => $type->id,
                'from_date' => $from,
                'to_date' => date('Y-m-d', strtotime($from.' +'.($days - 1).' days')),
                'days' => $days,
                'reason' => self::MARKER,
            ]);

            if ($index % 2 === 0) {
                $requests->approve($request);
            }
        }

        // Balances are reference data with no owning service; only fill gaps the approval
        // flow has not already written, so nothing is double-counted.
        $now = now();
        $rows = [];
        foreach ($this->employees as $index => $employee) {
            $type = $leaveTypes[$index % count($leaveTypes)];
            $exists = DB::table('hrm_leave_balances')
                ->where('employee_id', $employee->employee_id)
                ->where('leave_type_id', $type->id)
                ->where('year', self::PAY_YEAR)
                ->exists();
            if ($exists) {
                continue;
            }

            $entitlement = (int) $type->days_per_year;
            $availed = $index % 2 === 0 ? min(2, $entitlement) : 0;
            $rows[] = [
                'employee_id' => $employee->employee_id,
                'leave_type_id' => $type->id,
                'year' => self::PAY_YEAR,
                'opening_balance' => 0,
                'entitlement' => $entitlement,
                'carry_forward' => 0,
                'availed' => $availed,
                'pending' => 0,
                'encashed' => 0,
                'lapsed' => 0,
                'available' => $entitlement - $availed,
                'is_closed' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rows !== []) {
            DB::table('hrm_leave_balances')->insert($rows);
        }
    }

    /** Vendor PO -> goods receipt -> vendor bill -> part payment, through the real services. */
    private function purchaseChain(): void
    {
        $purchaseOrders = app(PurchaseOrderService::class);
        $receipts = app(GoodsReceiptService::class);
        $bills = app(VendorBillService::class);

        foreach ([0, 1] as $batch) {
            $items = [];
            foreach ($this->products->take(4) as $offset => $product) {
                $items[] = [
                    'product_id' => $product->id,
                    'qty' => 40 + ($batch * 10) + $offset,
                    'unit_price' => (float) ($product->purchase_price ?: 500) + $offset,
                    'vat_rate' => $offset % 2 === 0 ? 5 : 0,
                ];
            }

            /** @var PurchaseOrder $order */
            $order = $purchaseOrders->create([
                'vendor_id' => $this->vendor->id,
                'warehouse_id' => $this->warehouse->id,
                'order_date' => $this->date(2 + $batch),
                'notes' => self::MARKER,
                'items' => $items,
            ]);
            $purchaseOrders->confirm($order);
            $order->refresh();

            $receiptItems = $order->items->map(fn ($line): array => [
                'product_id' => $line->product_id,
                'order_item_id' => $line->id,
                'qty' => (float) $line->qty,
                'unit_price' => (float) $line->unit_price,
                'vat_rate' => (float) ($line->vat_rate ?? 0),
            ])->all();

            $grn = $receipts->create([
                'order_id' => $order->id,
                'vendor_id' => $this->vendor->id,
                'warehouse_id' => $this->warehouse->id,
                'receipt_date' => $this->date(3 + $batch),
                'items' => $receiptItems,
            ]);
            $receipts->post($grn);
            $grn->refresh();

            // Bill lines reference goods-receipt lines (`receipt_item_id`);
            // `purchase_bill_items` has no `order_item_id` column.
            $billItems = $grn->items->map(fn ($line): array => [
                'receipt_item_id' => $line->id,
                'product_id' => $line->product_id,
                'qty' => (float) $line->qty,
                'unit_price' => (float) $line->unit_price,
                'vat_rate' => (float) ($line->vat_rate ?? 0),
            ])->all();

            $bill = $bills->create([
                'vendor_id' => $this->vendor->id,
                'warehouse_id' => $this->warehouse->id,
                'order_id' => $order->id,
                'receipt_id' => $grn->id,
                'bill_date' => $this->date(4 + $batch),
                'notes' => self::MARKER,
                'items' => $billItems,
            ]);
            $bills->post($bill);
            $bill->refresh();

            $bills->recordPayment($bill, [
                'amount' => round((float) $bill->total_amount * 0.6, 2),
                'payment_date' => $this->date(15 + $batch),
                'method' => 'bank',
                'reference_no' => 'DEMO-PAY-'.($batch + 1),
            ]);
        }
    }

    /** A shipped transfer, a posted adjustment and an assembled-but-not-completed kit. */
    private function inventoryDocuments(): void
    {
        $transfers = app(TransferService::class);

        /** @var Transfer $transfer */
        $transfer = $transfers->create([
            'from_warehouse_id' => $this->warehouse->id,
            'to_warehouse_id' => $this->secondWarehouse->id,
            'reason' => 'Rebalance stock between warehouses — '.self::MARKER,
            'notes' => self::MARKER,
            'items' => [
                ['product_id' => $this->products->get(0)->id, 'qty_requested' => 10],
                ['product_id' => $this->products->get(1)->id, 'qty_requested' => 5],
            ],
        ]);
        // create() returns a model whose enum `status` is still null: the DB default
        // ('draft') is not read back, and Transfer::canShip() compares strictly, so the
        // model must be refreshed before any transition.
        $transfer->refresh();
        $transfers->ship($transfer);
        $transfers->receive($transfer->refresh());

        $adjustments = app(AdjustmentService::class);

        /** @var Adjustment $adjustment */
        $adjustment = $adjustments->create([
            'warehouse_id' => $this->warehouse->id,
            'reason' => 'Cycle count variance — '.self::MARKER,
            'notes' => self::MARKER,
            'items' => [
                ['product_id' => $this->products->get(2)->id, 'qty' => -2, 'reason' => 'Damaged in storage'],
                ['product_id' => $this->products->get(3)->id, 'qty' => 3, 'reason' => 'Found during the count'],
            ],
        ]);
        $adjustment->refresh();
        $adjustments->approve($adjustment);
        $adjustments->post($adjustment->refresh());

        /** @var Kit $kit */
        $kit = app(KitService::class)->create([
            'product_id' => $this->products->get(5)->id,
            'warehouse_id' => $this->warehouse->id,
            'qty_to_produce' => 2,
            'notes' => self::MARKER,
            'items' => [
                ['component_product_id' => $this->products->get(3)->id, 'required_qty' => 2],
                ['component_product_id' => $this->products->get(4)->id, 'required_qty' => 1],
            ],
        ]);

        // KitService::complete() posts Dr and Cr against the same inventory account (finding
        // P1.20 — reported, not fixed). The kit is therefore left incomplete rather than
        // writing a knowingly unbalanced document into the demo ledger.
        unset($kit);
    }

    /** Quotation -> order -> delivery -> three invoices with payments, via the services. */
    private function salesChain(): void
    {
        $quotations = app(QuotationService::class);
        $orders = app(OrderService::class);
        $deliveries = app(DeliveryService::class);
        $invoices = app(InvoiceService::class);

        $lineFor = function (int $offset, float $qty): array {
            $product = $this->products->get($offset % $this->products->count());

            return [
                'product_id' => $product->id,
                'qty' => $qty,
                'unit_price' => (float) ($product->selling_price ?: 900) + $offset,
                'vat_rate' => $offset % 2 === 0 ? 5 : 0,
            ];
        };

        /** @var Quotation $quotation */
        $quotation = $quotations->create([
            'customer_id' => $this->customer->id,
            'quotation_date' => $this->date(5),
            'expiry_date' => $this->date(25),
            'currency' => 'BDT',
            'notes' => self::MARKER,
            'items' => [$lineFor(0, 5), $lineFor(1, 3)],
        ]);
        $quotation->refresh();
        $quotations->send($quotation);
        $quotations->accept($quotation->refresh());

        /** @var Order $order */
        $order = $orders->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => $this->date(6),
            'notes' => self::MARKER,
            'items' => [$lineFor(0, 8), $lineFor(2, 6), $lineFor(4, 4)],
        ]);
        $orders->confirm($order);
        $order->refresh();

        // A second order, confirmed and deliberately not yet delivered, so the order list
        // shows both an in-progress and a fulfilled document.
        $pendingOrder = $orders->create([
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'order_date' => $this->date(18),
            'delivery_date' => $this->date(26),
            'notes' => self::MARKER,
            'items' => [$lineFor(5, 12), $lineFor(6, 4), $lineFor(7, 9)],
        ]);
        $orders->confirm($pendingOrder->refresh());

        $deliveryItems = $order->items->map(fn ($line): array => [
            'product_id' => $line->product_id,
            'order_item_id' => $line->id,
            'qty' => (float) $line->qty,
        ])->all();

        /** @var Delivery $delivery */
        $delivery = $deliveries->create([
            'order_id' => $order->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'delivery_date' => $this->date(8),
            'notes' => self::MARKER,
            'items' => $deliveryItems,
        ]);
        $deliveries->ship($delivery);

        // One fully paid, one part-paid, one outstanding invoice.
        foreach ([0, 1, 2] as $sequence) {
            /** @var Invoice $invoice */
            $invoice = $invoices->create([
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'invoice_date' => $this->date(11 + $sequence),
                'due_date' => $this->date(21 + $sequence),
                'currency' => 'BDT',
                'notes' => self::MARKER,
                'items' => [$lineFor($sequence, 6 + $sequence), $lineFor($sequence + 1, 3)],
            ]);
            $invoices->post($invoice);
            $invoice->refresh();

            $ratio = [1.0, 0.5, 0.0][$sequence];
            if ($ratio > 0) {
                $invoices->recordPayment($invoice, [
                    'amount' => round((float) $invoice->total_amount * $ratio, 2),
                    'payment_date' => $this->date(14 + $sequence),
                    'method' => $sequence === 0 ? 'cash' : 'bank',
                    'reference_no' => 'DEMO-RCPT-'.($sequence + 1),
                ]);
            }
        }
    }

    /**
     * Two POS days: one counted and closed, one still open at the till.
     *
     * The closed day is the demo's Z-report. Under the old model each of these sales created and
     * posted its own `sales_invoices` row, so the demo showed four invoices nobody wrote and four
     * open receivables for cash already in the drawer; the day-end posting (report item 39) replaced
     * that with a single journal written when the drawer closed. Demo data has to show the model the
     * application actually runs, so this creates the whole story: cash and card takings, a credit
     * sale that raises a receivable dated from the customer's own terms, a counted close with a
     * variance to explain, and — in the next day's open drawer — a part collection against that
     * credit sale, which is what gives the user-wise collection report something to show.
     */
    private function posSales(): void
    {
        $pos = app(PosService::class);
        $bank = Bank::query()->orderBy('id')->first();

        // A counter debtor with no credit limit and their own payment terms. A limit would make the
        // credit sale depend on what else the demo owes, and the day-end receivable is dated from
        // `payment_terms_days`, so 15 here is what the AR aging screen will show.
        $debtor = Partner::query()->firstOrCreate(
            ['partner_code' => 'CUST-POS-DEMO-1'],
            [
                'type' => 'customer',
                'name' => 'Counter Credit Customer',
                'credit_limit' => 0,
                'payment_terms_days' => 15,
                'is_active' => true,
                'notes' => self::MARKER,
            ]
        );

        $closed = PosSession::query()->firstOrCreate(['session_no' => 'POS-DEMO-1'], [
            'warehouse_id' => $this->warehouse->id,
            'opened_by' => $this->actor->id,
            'opening_cash' => '5000',
            'status' => 'open',
            'opened_at' => $this->date(10).' 09:00:00',
            'notes' => self::MARKER,
        ]);

        $creditSale = null;

        foreach ([0, 1, 2, 3] as $sequence) {
            $product = $this->products->get($sequence % $this->products->count());
            $second = $this->products->get(($sequence + 3) % $this->products->count());

            // cash, card, cash, then a credit sale to the named debtor
            $method = match (true) {
                $sequence === 3 => 'due',
                $sequence % 2 === 1 => 'bank',
                default => 'cash',
            };

            $sale = $pos->sell(new PosSaleData(
                warehouseId: $this->warehouse->id,
                lines: [
                    new InvoiceLineData(
                        productId: $product->id,
                        qty: 2 + $sequence,
                        unitPrice: (float) ($product->selling_price ?: 900),
                        vatRate: 5,
                    ),
                    // A discounted second line, so the receipt shows both a discount and VAT.
                    new InvoiceLineData(
                        productId: $second->id,
                        qty: 1,
                        unitPrice: (float) ($second->selling_price ?: 900),
                        discountPct: 5,
                        vatRate: 5,
                    ),
                ],
                paymentMethod: $method,
                customerId: $method === 'cash' ? null : $debtor->id,
                customerName: $method === 'cash' ? 'Walk-in Customer' : $debtor->name,
                bankId: $method === 'bank' ? $bank?->id : null,
                sessionId: $closed->id,
            ));

            if ($method === 'due') {
                $creditSale = $sale;
            }
        }

        // Count the drawer and close it. The count is 50.00 short of what the day says it should
        // hold, which is the point: a demo whose variance is always zero teaches nobody what the
        // cash-difference column is for.
        $closed->refresh();
        $expectedCents = (int) round((float) $closed->opening_cash * 100)
            + (int) round((float) $closed->sales()->where('status', '!=', 'void')->sum('cash_amount') * 100);
        $shortByCents = 5000; // 50.00 BDT

        $pos->closeSession($closed, [
            'closing_cash' => round(($expectedCents - $shortByCents) / 100, 2),
            'notes' => self::MARKER.' — counted at the close, 50.00 short.',
        ]);

        // ---- The next day: an open drawer, a sale, and part of yesterday's credit collected ----
        $open = PosSession::query()->firstOrCreate(['session_no' => 'POS-DEMO-2'], [
            'warehouse_id' => $this->warehouse->id,
            'opened_by' => $this->actor->id,
            'opening_cash' => '3000',
            'status' => 'open',
            'opened_at' => $this->date(11).' 09:00:00',
            'notes' => self::MARKER,
        ]);

        $product = $this->products->first();
        $pos->sell(new PosSaleData(
            warehouseId: $this->warehouse->id,
            lines: [new InvoiceLineData(
                productId: $product->id,
                qty: 3,
                unitPrice: (float) ($product->selling_price ?: 900),
                vatRate: 5,
            )],
            paymentMethod: 'cash',
            customerName: 'Walk-in Customer',
            sessionId: $open->id,
        ));

        if ($creditSale !== null && (float) $creditSale->due_amount > 0) {
            // Half of it, at the counter, into today's drawer — so today's collection total is the
            // cashier's, not the day the sale was rung up on.
            $pos->collectDue($creditSale->fresh(), [
                'amount' => round((float) $creditSale->due_amount / 2, 2),
                'method' => 'cash',
                'session_id' => $open->id,
                'notes' => self::MARKER.' — part payment at the counter.',
            ]);
        }
    }

    /** Bank accounts, a posted manual journal, and the bank-side statement lines for it. */
    private function accountsEntries(): void
    {
        $banks = app(BankService::class);
        $byCode = fn (string $code): ?int => DB::table('acc_accounts')->where('code', $code)->value('id');

        foreach ([['100201', 'Prime Bank PLC'], ['100202', 'Dutch-Bangla Bank PLC']] as $index => [$code, $bankName]) {
            $accountId = $byCode($code);
            if ($accountId === null || Bank::query()->where('account_id', $accountId)->exists()) {
                continue;
            }

            $banks->create([
                'name' => $bankName.' — Demo',
                'code' => 'DEMO-BANK-'.($index + 1),
                'bank_name' => $bankName,
                'account_no' => '100'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT),
                'account_id' => $accountId,
            ]);
        }

        $journals = app(JournalService::class);

        /** @var Journal $journal */
        $journal = $journals->create([
            'journal_date' => $this->date(15),
            // acc_journals.type is an enum of voucher codes (JV/PV/RV/CV/BV/SV), not prose.
            // Note the migration lists 'PV' twice — once for Payment Voucher and once for
            // Purchase Voucher — so the two cannot be told apart; see finding 34.
            'type' => 'PV',
            'narration' => 'October office rent paid by cheque — '.self::MARKER,
            'lines' => [
                ['account_id' => $byCode('5300'), 'debit' => '45000', 'credit' => '0', 'description' => 'Rent Expense — October'],
                // 1002 "Cash at Bank" is a group header: ChartOfAccountsGuard rejects postings
                // to it, so the credit goes to the child account the demo bank is opened on.
                ['account_id' => $byCode('100201'), 'debit' => '0', 'credit' => '45000', 'description' => 'Paid from Prime Bank — Main'],
            ],
        ]);
        // Same trap as Transfer: create() does not read the DB default back, so `status`
        // is null on the returned instance and post() rejects it. Refresh first.
        $journal->refresh();
        $journals->post($journal);
        $this->manualJournal = $journal->refresh();

        $bank = Bank::query()->orderBy('id')->first();
        if ($bank === null) {
            return;
        }

        // `acc_bank_statements` holds imported statement LINES and `acc_bank_transactions`
        // the bank-side ledger; neither has a service, so both are written directly.
        $now = now();
        $statementRows = [];
        $runningBalance = 250000.0;
        foreach ([
            [$this->date(14), 'Customer receipt DEMO-RCPT-1', 'DEMO-RCPT-1', 61400.0],
            [$this->date(15), 'Vendor payment DEMO-PAY-1', 'DEMO-PAY-1', -48000.0],
            [$this->date(15), 'Office rent cheque — '.self::MARKER, $this->manualJournal->journal_no, -45000.0],
            [$this->date(16), 'Customer receipt DEMO-RCPT-2', 'DEMO-RCPT-2', 24000.0],
        ] as [$txnDate, $description, $reference, $amount]) {
            $runningBalance += $amount;
            $statementRows[] = [
                'bank_id' => $bank->id,
                'txn_date' => $txnDate,
                'description' => $description,
                'reference_no' => $reference,
                'amount' => (string) $amount,
                'balance' => (string) round($runningBalance, 2),
                'is_reconciled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        DB::table('acc_bank_statements')->insert($statementRows);

        // type is an enum (deposit/withdrawal/transfer_in/transfer_out/fee/interest) and
        // status is (pending/cleared/bounced/void) — not the debit/credit vocabulary the
        // journal lines use.
        DB::table('acc_bank_transactions')->insert([
            [
                'bank_id' => $bank->id,
                'journal_id' => $this->manualJournal->id,
                'type' => 'withdrawal',
                'amount' => '45000',
                'reference_no' => $this->manualJournal->journal_no,
                'transaction_date' => $this->date(15),
                'status' => 'cleared',
                'description' => 'Office rent cheque — '.self::MARKER,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'bank_id' => $bank->id,
                'journal_id' => null,
                'type' => 'deposit',
                'amount' => '61400',
                'reference_no' => 'DEMO-RCPT-1',
                'transaction_date' => $this->date(14),
                'status' => 'cleared',
                'description' => 'Customer receipt — '.self::MARKER,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'bank_id' => $bank->id,
                'journal_id' => null,
                'type' => 'withdrawal',
                'amount' => '48000',
                'reference_no' => 'DEMO-PAY-1',
                'transaction_date' => $this->date(15),
                'status' => 'cleared',
                'description' => 'Vendor bill part payment — '.self::MARKER,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'bank_id' => $bank->id,
                'journal_id' => null,
                'type' => 'fee',
                'amount' => '325',
                'reference_no' => 'DEMO-FEE-1',
                'transaction_date' => $this->date(28),
                'status' => 'pending',
                'description' => 'Account maintenance charge — '.self::MARKER,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    /**
     * The monthly payroll for the cohort: PF policy and accounts, an approved loan with its
     * instalment schedule, the salary sheet, its ledger accrual and the bank advice.
     */
    private function payrollRun(): void
    {
        $employeeIds = $this->employees->pluck('employee_id')->all();

        $pf = app(ProvidentFundService::class);
        $policy = $pf->savePolicy([
            'name' => 'Staff Provident Fund '.self::PAY_YEAR,
            'employee_contribution_percent' => '10',
            'employer_contribution_percent' => '10',
            'minimum_service_months' => 0,
            'contribution_base' => 'basic',
            'effective_from' => self::PAY_YEAR.'-07-01',
            'is_active' => true,
        ]);
        $pf->allocateAccounts($policy->id, $employeeIds, false);

        // Loan approval enforces three real rules: the requester cannot approve their own
        // loan, every stage's approver must hold that stage's role, and no approver may
        // complete two stages. So the request comes from a third party and each stage is
        // approved by a different role holder.
        $requester = $this->userWithRole('Auditor');
        $manager = $this->userWithRole('Manager');
        $payrollHead = $this->userWithRole('Super Admin');

        if ($requester !== null && $manager !== null && $payrollHead !== null) {
            $loans = app(PayrollLoanService::class);
            $loan = $loans->request([
                'employee_id' => $employeeIds[0],
                'principal_amount' => '60000',
                'interest_rate' => '0',
                'installment_count' => 12,
                'first_due_date' => $this->date(28),
                'purpose' => 'Medical expense — '.self::MARKER,
            ], $requester->id);
            $loans->approve($loan, $manager->id, 'Approved for the demo dataset');
            $loans->approve($loan->refresh(), $payrollHead->id, 'Second stage approval');
            // scope is one of single/selected/all, and allocation must happen BEFORE the
            // salary sheet for the period exists — the sheet's calculation snapshot is
            // immutable once created.
            $loans->allocateMonthlyInstallments(self::PAY_YEAR, self::PAY_MONTH, 'single', [$employeeIds[0]], $payrollHead->id);
        }

        $sheets = app(PayrollSalarySheetService::class);
        $sheet = $sheets->dispatch(
            self::PAY_YEAR,
            self::PAY_MONTH,
            $this->actor->id,
            false,
            $this->excludedEmployeeIds,
            'Demo dataset scope: only the sampled department is payroll-processed.'
        );
        // dispatch() only queues ProcessSalarySheetJob; the sheet becomes 'ready' when the
        // batch action has actually run, and release() refuses anything else. A seeder has no
        // queue worker, so the action is executed inline here.
        app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);
        $sheet->refresh();

        $sheets->release($sheet, $this->actor->id);
        $sheet->refresh();

        $payrollJournals = app(PayrollJournalService::class);
        $payrollJournals->ensurePayrollAccounts();
        $payrollJournals->postSalaryAccrual($sheet);

        app(PayrollBankAdviceService::class)->generateForSheet($sheet);

        // Paying the slips is what writes the PF ledger: MarkPayrollSlipPaymentAction posts
        // the employee and employer contributions for each paid slip, and the same action posts
        // the per-slip bank journal (Dr salary payable / Cr bank) through the finance bridge.
        //
        // This used to stop at the slip status on purpose. `payroll_journal_posts` carried
        // `unique(salary_sheet_id, posting_type)`, so the second slip of the sheet violated the
        // constraint, the transaction rolled the journal back, and two layers of
        // `catch (Throwable) { Log::… }` meant the demo showed paid slips that were missing from
        // the ledger — finding 35, and the reason this dataset once had 14 paid slips and one
        // payment journal. The idempotence key now carries `posting_scope`, so all of them post.
        foreach (PayrollSlip::query()->where('salary_sheet_id', $sheet->id)->orderBy('id')->get() as $slip) {
            $sheets->setSlipPaymentStatus($slip, 'paid', $this->actor->id);
        }
    }

    private function userWithRole(string $role): ?User
    {
        return User::query()
            ->whereHas('roles', fn ($query) => $query->where('roles.name', $role))
            ->orderBy('id')
            ->first();
    }

    /** The sheet this seeder produced, for callers that want to report on it. */
    public function sheet(): ?PayrollSalarySheet
    {
        return PayrollSalarySheet::query()
            ->where('pay_year', self::PAY_YEAR)
            ->where('pay_month', self::PAY_MONTH)
            ->first();
    }
}
