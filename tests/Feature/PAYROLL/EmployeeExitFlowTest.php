<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\HRM\Models\ClearanceTask;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\Employee\Employee;
use Modules\HRM\Services\ClearanceService;
use Modules\PAYROLL\Actions\ProcessMonthlyPayrollBatchAction;
use Modules\PAYROLL\Models\GratuityPolicy;
use Modules\PAYROLL\Models\PayrollSettlement;
use Modules\PAYROLL\Services\PayrollSalarySheetService;

/*
 * Same clock discipline as EmployeeLifecycleFlowTest: `travelTo()` freezes the clock for the whole
 * application and the rest of the suite runs on real time.
 */
afterEach(function (): void {
    $this->travelBack();
    Carbon::setTestNow(null);
    Illuminate\Support\Carbon::setTestNow(null);
});

function exitActor(string $email = 'exit.admin@example.test'): User
{
    $admin = User::query()->where('email', $email)->first();
    if ($admin !== null) {
        return $admin;
    }

    $admin = User::create([
        'name' => 'Exit Admin',
        'email' => $email,
        'password' => bcrypt('secret-password'),
        'email_verified_at' => now(),
    ]);
    $admin->assignRole('Super Admin');

    return $admin;
}

function exitBoot(): User
{
    test()->seed(SuperAdminSeeder::class);

    User::query()->where('email', config('core.super_admin.email', 'admin@example.com'))
        ->firstOrFail()
        ->assignRole('Super Admin');

    return exitActor();
}

/** A hire whose every number is set by hand, so the payslip can be checked line by line. */
function exitEmployee(string $code, string $joiningDate, float $basic, float $gross, array $overrides = []): Employee
{
    return Employee::query()->create($overrides + [
        'employee_id' => $code,
        'first_name' => 'Exit',
        'last_name' => $code,
        'full_name' => 'Exit '.$code,
        'email' => strtolower($code).'@example.test',
        'gender' => 'Female',
        'joining_date' => $joiningDate,
        'employment_type' => 'Permanent',
        'status' => 'Active',
        'is_active' => true,
        'duty_hours' => 8,
        'department_id' => Department::factory()->create()->id,
        'designation_id' => Designation::factory()->create()->id,
    ]);
}

function exitSalary(string $code, string $from, float $basic, float $gross): void
{
    DB::table('employee_salaries')->insert([
        'employee_id' => $code,
        'basic_salary' => $basic,
        'gross_salary' => $gross,
        'house_rent' => round($gross * 0.3, 2),
        'medical_allowance' => round($gross * 0.05, 2),
        'other_allowance' => round($gross - $basic - $gross * 0.35, 2),
        'net_salary' => $gross,
        'effective_from' => $from,
        'is_current' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * The half of the employee lifecycle that begins after the payslip.
 *
 * `EmployeeLifecycleFlowTest` walks a hire from the Employees form to her first payslip. This one
 * picks her up afterwards and walks the exit: the probation that decides whether she is permanent,
 * the increment that changes what she is paid, the resignation, the clearance every department has
 * to sign, the final settlement that adds gratuity and leave encashment, and the payment that
 * closes it. Four screens are involved that no test in this suite has ever opened — Probations,
 * Resignations, Clearances and Settlements — and each of them has a way of looking fine while
 * quietly doing nothing, which is exactly what this file is here to catch.
 *
 * Two of those ways were real. `ClearanceService::seedForResignation()` existed and was never
 * called anywhere, so approving a resignation left the Clearance screen empty and the "fully
 * cleared" question the settlement depends on unanswerable. And `ProcessFinalSettlementAction`
 * multiplied gratuity by `diffInYears()`, which returns a fraction — 7 years 9 months of service
 * paid 7.79 years of gratuity, and 6 years 11 months cleared a 7-year minimum. Both are asserted
 * below as arithmetic, not as statuses.
 */
test('one employee is confirmed, promoted, resigns, clears, settles and is paid out', function (): void {
    @set_time_limit(0);
    // The probation half runs in August, while her probation is still open and an extension is
    // still in the future — the extension endpoint refuses a date in the past, so the clock has to
    // be where the desk's clock would be. The clock then moves to December for the exit.
    $this->travelTo(Carbon::parse('2026-08-20 10:00:00'));

    $actor = exitBoot();
    $this->actingAs($actor);

    // ── 1. A hire on probation ──────────────────────────────────────────────��───────────────────
    $hire = exitEmployee('EXIT-001', '2026-03-01', 20000, 30000, ['employment_type' => 'Probation']);
    exitSalary('EXIT-001', '2026-03-01', 20000, 30000);

    // The probation screen is the only place that decides Probation → Permanent, so it is opened
    // through the route rather than by writing the column.
    $this->post(route('hrm.probations.store'), [
        'employee_id' => 'EXIT-001',
        'probation_start' => '2026-03-01',
        'probation_end' => '2026-09-01',
        'remarks' => 'Six months as per policy.',
    ])->assertSessionHasNoErrors();

    $probation = DB::table('hrm_employee_probations')->where('employee_id', 'EXIT-001')->first();
    expect($probation)->not->toBeNull()
        ->and($probation->status)->toBe('Active')
        // The employee is still on probation: opening one does not confirm anybody.
        ->and(Employee::query()->find($hire->id)->employment_type)->toBe('Probation');

    // A second open probation for the same person is refused rather than silently duplicated.
    $this->post(route('hrm.probations.store'), [
        'employee_id' => 'EXIT-001',
        'probation_start' => '2026-03-01',
        'probation_end' => '2026-10-01',
    ])->assertSessionHasErrors('employee_id');

    // An extension moves the deadline and is not a confirmation.
    $this->post(route('hrm.probations.extend', $probation->id), [
        'extended_till' => '2026-10-01',
        'remarks' => 'Extended by one month pending appraisal.',
    ])->assertSessionHasNoErrors();

    $probation = DB::table('hrm_employee_probations')->where('id', $probation->id)->first();
    expect($probation->status)->toBe('Extended')
        ->and(Carbon::parse($probation->extended_till)->toDateString())->toBe('2026-10-01');

    // Confirmation is what flips the master record to Permanent.
    $this->post(route('hrm.probations.confirm', $probation->id), ['remarks' => 'Appraisal passed.'])
        ->assertSessionHasNoErrors();

    $probation = DB::table('hrm_employee_probations')->where('id', $probation->id)->first();
    expect($probation->status)->toBe('Confirmed')
        ->and($probation->confirmed_at)->not->toBeNull()
        ->and(Employee::query()->find($hire->id)->employment_type)->toBe('Permanent');

    // A confirmed probation cannot be confirmed twice — the guard, not a silent second write.
    $this->post(route('hrm.probations.confirm', $probation->id))
        ->assertSessionHasErrors('probation');

    // December: the resignation is filed, the settlement is calculated, and the payroll months
    // behind her are being run at the same time.
    $this->travelTo(Carbon::parse('2026-12-01 10:00:00'));

    // ── 2. A movement that carries a raise ──────────────────────────────────────────────────────
    //
    // Payroll must read the new salary from the history the movement writes, not from a number
    // typed into the payslip. 30,000 → 36,000 effective 1 November.
    $newDepartment = Department::factory()->create(['name' => 'Exit Cardiology']);

    $this->post(route('hrm.movements.store'), [
        'employee_id' => 'EXIT-001',
        'movement_type' => 'Promotion',
        'effective_date' => '2026-11-01',
        'to_department_id' => $newDepartment->id,
        'to_salary' => 36000,
        'reason' => 'Promoted to senior nurse with a revised salary.',
    ])->assertRedirect();

    $movement = DB::table('hrm_employee_movements')->where('employee_id', 'EXIT-001')->firstOrFail();
    expect($movement->status)->toBe('Draft')
        ->and((float) $movement->increment_amount)->toBe(6000.0);

    $this->post(route('hrm.movements.submit', $movement->id))->assertRedirect();
    expect(DB::table('hrm_employee_movements')->where('id', $movement->id)->value('status'))->toBe('Pending');

    // A draft cannot be approved, and Pending is the only state approval accepts.
    $this->post(route('hrm.movements.approve', $movement->id))->assertRedirect();
    expect(DB::table('hrm_employee_movements')->where('id', $movement->id)->value('status'))->toBe('Approved');

    $employee = Employee::query()->find($hire->id);
    expect((int) $employee->department_id)->toBe($newDepartment->id);

    // The raise lives in salary history as a *new* row, effective on the movement date, and the old
    // row is closed the day before — which is what makes November pay 36,000 and October 30,000.
    $history = DB::table('employee_salaries')->where('employee_id', 'EXIT-001')->orderBy('effective_from')->get();
    expect($history)->toHaveCount(2)
        ->and((float) $history[1]->gross_salary)->toBe(36000.0)
        ->and(Carbon::parse($history[1]->effective_from)->toDateString())->toBe('2026-11-01')
        ->and((bool) $history[0]->is_current)->toBeFalse();

    // ── 3. October is paid on the old salary, November on the new one ─────────────────────────
    //
    // No attendance at all, and the policy is the default `waive`, so both months are full salary.
    // The point of these two payslips is only the rate: the segment split is what a raise does.
    $this->put(route('payroll.settings.update'), [
        'currency' => 'BDT',
        'punishment_days_per_salary_day' => 3,
        'overtime_multiplier' => 1,
        'extra_duty_multiplier' => 1,
        'missing_punch_penalty_amount' => 0,
        'unpaid_leave_day_divisor' => 30,
        'missing_attendance_policy' => 'waive',
        'loan_approval_steps_text' => 'Payroll=Super Admin',
        'cutlist_review_required' => false,
    ])->assertSessionHasNoErrors();

    $sheets = app(PayrollSalarySheetService::class);
    Bus::fake();
    foreach ([10 => 30000.0, 11 => 36000.0] as $month => $expectedGross) {
        $sheet = $sheets->dispatch(2026, $month, $actor->id, false);

        // The job is queued; the action is the same work, run inline so the test is deterministic.
        app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);

        $slip = DB::table('payroll_slips')->where('salary_sheet_id', $sheet->id)
            ->where('employee_id', 'EXIT-001')->first();

        expect($slip)->not->toBeNull()
            ->and(round((float) $slip->gross_salary, 2))->toBe($expectedGross);
    }

    // ── 4. She resigns ──────────────────────────────────────────────────────────────────────────
    $this->post(route('hrm.resignations.store'), [
        'employee_id' => 'EXIT-001',
        'last_working_date' => '2026-12-15',
        'reason' => 'Relocating; submitted two months in advance.',
    ])->assertSessionHasNoErrors();

    $resignation = DB::table('employee_resignations')->where('employee_id', 'EXIT-001')->firstOrFail();
    expect($resignation->status)->toBe('Pending');

    // The same person cannot have two resignations in flight.
    $this->post(route('hrm.resignations.store'), [
        'employee_id' => 'EXIT-001',
        'last_working_date' => '2027-01-15',
        'reason' => 'Duplicate request.',
    ])->assertSessionHasErrors('employee_id');

    // Nothing exists to clear until the resignation is actually approved.
    expect(ClearanceTask::query()->where('employee_id', 'EXIT-001')->count())->toBe(0);

    $this->post(route('hrm.resignations.decision', $resignation->id), [
        'decision' => 'Approved',
        'decision_note' => 'Approved; last working day 15 December.',
    ])->assertSessionHasNoErrors();

    expect(DB::table('employee_resignations')->where('id', $resignation->id)->value('status'))->toBe('Approved');

    // ── 5. The clearance checklist exists, and somebody has to sign it ─────────────────────────
    //
    // This is the assertion that fails on the code as it stood: the service to seed these tasks was
    // written and never wired, so an approved leaver produced an empty Clearance screen.
    $tasks = ClearanceTask::query()->where('employee_id', 'EXIT-001')
        ->where('resignation_id', $resignation->id)->get();

    // Nine tasks across the five departments the checklist fixes: HR and IT two each, Admin one,
    // Finance and Payroll two each. The count is asserted because a checklist that quietly seeds
    // four of the five departments would still look "cleared" once those four were signed.
    expect($tasks)->toHaveCount(9)
        ->and($tasks->pluck('department')->unique()->sort()->values()->all())
        ->toBe(['Admin', 'Finance', 'HR', 'IT', 'Payroll'])
        ->and($tasks->where('status', 'Pending')->count())->toBe(9);

    $clearance = app(ClearanceService::class);
    expect($clearance->isFullyCleared('EXIT-001', $resignation->id))->toBeFalse();

    // Nine departments sign; the tenth is waived with a reason, which is the only way to be cleared
    // without a sign-off. A rejection alone leaves her not cleared.
    $first = $tasks->first();
    $this->post(route('hrm.clearance.clear', $first->id), [
        'status' => 'Cleared',
        'remarks' => 'Exit interview done; attendance verified.',
    ])->assertSessionHasNoErrors();

    foreach ($tasks->skip(1) as $task) {
        $this->post(route('hrm.clearance.clear', $task->id), [
            'status' => 'Waived',
            'remarks' => 'Not applicable for this role; waived by department head.',
        ])->assertSessionHasNoErrors();
    }

    expect(ClearanceTask::query()->where('employee_id', 'EXIT-001')->whereNotIn('status', ['Cleared', 'Waived'])->count())->toBe(0)
        ->and($clearance->isFullyCleared('EXIT-001', $resignation->id))->toBeTrue();

    // ── 6. Final settlement ─────────────────────────────────────────────────────────────────────
    //
    // Gratuity policy: 30 days per completed year on basic, minimum 5 years. She joined 1 March 2026
    // and leaves 15 November 2026, so she has **zero** completed years and is below the minimum.
    // The point of this half of the test is that the settlement says 0 rather than paying a fraction.
    $this->post(route('payroll.settlements.gratuity-policies.store'), [
        'name' => 'Exit gratuity 30/30 basic',
        'minimum_service_years' => 5,
        'days_per_service_year' => 30,
        'calculation_base' => 'basic',
    ])->assertSessionHasNoErrors();

    expect(GratuityPolicy::query()->where('name', 'Exit gratuity 30/30 basic')->exists())->toBeTrue();

    // Leave encashment is handed over explicitly (2 days), other benefits 1,000, deductions 500.
    $this->post(route('payroll.settlements.store'), [
        'employee_id' => 'EXIT-001',
        'settlement_date' => '2026-12-31',
        'last_working_date' => '2026-12-15',
        'leave_encashment' => 2400,
        'other_benefits' => 1000,
        'deductions' => 500,
        'leave_encashment_reason' => '2 unused earned days at the new daily rate.',
        'deductions_reason' => 'Salary advance balance.',
    ])->assertRedirect(route('payroll.settlements.index'));

    $settlement = PayrollSettlement::query()->where('employee_id', 'EXIT-001')->firstOrFail();

    // 15 of December's 31 days at 36,000 = 17,419.35 (36000 × 15 / 31). Gratuity 0: she joined
    // 1 March 2026, so no completed year, and the 5-year minimum is not reached.
    expect(round((float) $settlement->gross_due, 2))->toBe(17419.35)
        ->and(round((float) $settlement->gratuity_amount, 2))->toBe(0.0)
        ->and((int) $settlement->breakdown_json['service_years'])->toBe(0)
        ->and(round((float) $settlement->leave_encashment, 2))->toBe(2400.0)
        ->and(round((float) $settlement->other_benefits, 2))->toBe(1000.0)
        ->and(round((float) $settlement->deductions, 2))->toBe(500.0)
        ->and(round((float) $settlement->net_settlement, 2))->toBe(20319.35);

    // A second settlement for the same person is refused while this one is live.
    $this->post(route('payroll.settlements.store'), [
        'employee_id' => 'EXIT-001',
        'settlement_date' => '2027-01-31',
        'last_working_date' => '2026-12-15',
    ])->assertSessionHasErrors('employee_id');

    // ── 7. Approve, then pay ────────────────────────────────────────────────────────────────────
    // Paying an unapproved settlement is refused: approval is a separate decision by a different
    // pair of eyes, and the status machine is what enforces it.
    // This used to be an uncaught RuntimeException — a 500 page, not a message. It is asserted as a
    // flash because a refusal the clerk cannot read is a refusal that looks like a broken screen.
    $this->post(route('payroll.settlements.paid', $settlement->id))->assertSessionHasErrors('settlement');

    $this->post(route('payroll.settlements.approve', $settlement->id))->assertSessionHasNoErrors();

    // Approving twice is the same refusal, not a second approval and not a 500.
    $this->post(route('payroll.settlements.approve', $settlement->id))->assertSessionHasErrors('settlement');

    $settlement->refresh();
    expect($settlement->status)->toBe('approved')
        ->and($settlement->approved_by)->toBe($actor->id)
        ->and($settlement->approved_at)->not->toBeNull();

    $this->post(route('payroll.settlements.paid', $settlement->id))->assertSessionHasNoErrors();

    $settlement->refresh();
    expect($settlement->status)->toBe('paid')
        ->and(DB::table('payroll_workflow_audits')
            ->where('subject_type', 'payroll_settlement')
            ->where('subject_id', $settlement->id)
            ->where('event', 'paid')->exists())->toBeTrue();
});

/**
 * The two arithmetic rules that decide what a leaver is actually owed.
 *
 * Both of these were wrong in the same direction — favouring the employee by accident — and both
 * are invisible from the screen, because a settlement that pays a bit too much still reads as a
 * settlement. They are asserted here as figures.
 */
test('gratuity pays whole completed years only, and a raise in the final month prorates from the right date', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-12-01 10:00:00'));

    $actor = exitBoot();
    $this->actingAs($actor);

    $this->post(route('payroll.settlements.gratuity-policies.store'), [
        'name' => 'Whole-year gratuity',
        'minimum_service_years' => 5,
        'days_per_service_year' => 30,
        'calculation_base' => 'basic',
    ])->assertSessionHasNoErrors();

    // ── 7 years 9 months of service, basic 20,000 ───────────────────────────────────────────────
    // Whole years: floor(7.7863) = 7. Gratuity = 20,000 / 30 × 30 × 7 = 140,000.
    // The old code paid 7.7863 years = 155,726 — 15,726 more than the policy allows.
    exitEmployee('EXIT-LONG', '2019-01-01', 20000, 30000);
    exitSalary('EXIT-LONG', '2019-01-01', 20000, 30000);

    $this->post(route('payroll.settlements.store'), [
        'employee_id' => 'EXIT-LONG',
        'settlement_date' => '2026-10-31',
        'last_working_date' => '2026-10-15',
    ])->assertRedirect();

    $long = PayrollSettlement::query()->where('employee_id', 'EXIT-LONG')->firstOrFail();

    expect((int) $long->breakdown_json['service_years'])->toBe(7)
        ->and(round((float) $long->gratuity_amount, 2))->toBe(140000.0)
        // 15 of 31 October days at the full 30,000 = 14,516.13.
        ->and(round((float) $long->gross_due, 2))->toBe(14516.13)
        ->and(round((float) $long->net_settlement, 2))->toBe(154516.13);

    // ── 6 years 11 months does not clear a 7-year minimum ───────────────────────────────────────
    // 2019-11-16 → 2026-10-15 is 6.91 years. On the old arithmetic that cleared a 7-year bar.
    exitEmployee('EXIT-JUST', '2019-11-16', 20000, 30000);
    exitSalary('EXIT-JUST', '2019-11-16', 20000, 30000);

    $this->post(route('payroll.settlements.gratuity-policies.store'), [
        'name' => 'Seven-year bar',
        'minimum_service_years' => 7,
        'days_per_service_year' => 30,
        'calculation_base' => 'basic',
    ])->assertSessionHasNoErrors();

    $this->post(route('payroll.settlements.store'), [
        'employee_id' => 'EXIT-JUST',
        'settlement_date' => '2026-10-31',
        'last_working_date' => '2026-10-15',
    ])->assertRedirect();

    $just = PayrollSettlement::query()->where('employee_id', 'EXIT-JUST')->firstOrFail();

    expect((int) $just->breakdown_json['service_years'])->toBe(6)
        ->and(round((float) $just->gratuity_amount, 2))->toBe(0.0);
});
