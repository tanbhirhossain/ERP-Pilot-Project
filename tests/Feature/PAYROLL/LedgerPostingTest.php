<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\FiscalYear;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Department;
use Modules\HRM\Models\Designation;
use Modules\HRM\Models\Employee\Employee;
use Modules\PAYROLL\Actions\ProcessMonthlyPayrollBatchAction;
use Modules\PAYROLL\Models\FestivalBonusPolicy;
use Modules\PAYROLL\Models\FestivalBonusRun;
use Modules\PAYROLL\Models\PayrollSettlement;
use Modules\PAYROLL\Services\PayrollBonusService;
use Modules\PAYROLL\Services\PayrollJournalService;
use Modules\PAYROLL\Services\PayrollSalarySheetService;
use Modules\PAYROLL\Services\PayrollSettlementService;

afterEach(function (): void {
    $this->travelBack();
    Carbon::setTestNow(null);
    Illuminate\Support\Carbon::setTestNow(null);
});

function ledgerAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);
    $admin = User::query()->where('email', config('core.super_admin.email', 'admin@example.com'))->firstOrFail();
    $admin->assignRole('Super Admin');

    return $admin;
}

/** A journal cannot be posted without an open fiscal year covering its date. */
function ledgerFiscalYear(int $year = 2026): void
{
    if (FiscalYear::query()
        ->where('start_date', '<=', $year.'-01-01')->where('end_date', '>=', $year.'-12-31')->exists()) {
        return;
    }

    FiscalYear::query()->create([
        'name' => 'FY '.$year,
        'code' => 'FY'.$year,
        'start_date' => $year.'-01-01',
        'end_date' => ($year + 1).'-12-31',
        'status' => 'open',
    ]);
}

function ledgerEmployee(string $code, string $joining, float $basic, float $gross): void
{
    Employee::query()->create([
        'employee_id' => $code, 'first_name' => 'Ledger', 'last_name' => $code,
        'full_name' => 'Ledger '.$code, 'status' => 'Active', 'is_active' => true,
        'joining_date' => $joining, 'employment_type' => 'Permanent', 'gender' => 'Female', 'duty_hours' => 8,
        'department_id' => Department::factory()->create()->id,
        'designation_id' => Designation::factory()->create()->id,
    ]);

    DB::table('employee_salaries')->insert([
        'employee_id' => $code, 'basic_salary' => $basic, 'gross_salary' => $gross,
        'house_rent' => round($gross * .3, 2), 'medical_allowance' => round($gross * .05, 2),
        'other_allowance' => round($gross - $basic - $gross * .35, 2), 'net_salary' => $gross,
        'effective_from' => $joining, 'is_current' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * Money that leaves the payroll module has to arrive in the books.
 *
 * Both of these postings were missing, and both were invisible from the payroll screens: the
 * settlement showed the right net, the bonus run showed the right total, and the ledger showed
 * neither. `payroll_journal_posts.settlement_id` and `.bonus_run_id` had been in the schema since
 * the bridge migration with no row ever written against them.
 */
test('an approved final settlement reaches the ledger, balanced, with gratuity in its own account', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-12-01 10:00:00'));
    $admin = ledgerAdmin();
    $this->actingAs($admin);

    ledgerFiscalYear(2026);
    ledgerEmployee('LED-001', '2019-01-01', 20000, 30000);

    $this->post(route('payroll.settlements.gratuity-policies.store'), [
        'name' => 'Ledger gratuity 30/30 basic',
        'minimum_service_years' => 5,
        'days_per_service_year' => 30,
        'calculation_base' => 'basic',
    ])->assertSessionHasNoErrors();

    $this->post(route('payroll.settlements.store'), [
        'employee_id' => 'LED-001',
        'settlement_date' => '2026-10-31',
        'last_working_date' => '2026-10-15',
        'leave_encashment' => 2000,
        'other_benefits' => 500,
        'deductions' => 1000,
    ])->assertRedirect();

    $settlement = PayrollSettlement::query()->where('employee_id', 'LED-001')->firstOrFail();

    // Nothing is in the books until it is approved — a draft settlement is a proposal, not an obligation.
    expect(DB::table('payroll_journal_posts')->where('settlement_id', $settlement->id)->count())->toBe(0)
        ->and(app(PayrollSettlementService::class)->pendingPostings())->toHaveCount(0);

    // Through the approve route — the wiring itself is what is under test here, not the method.
    // `postSettlement()` existed and worked for months with nothing calling it.
    $this->post(route('payroll.settlements.approve', $settlement->id))->assertSessionHasNoErrors();

    $post = DB::table('payroll_journal_posts')->where('settlement_id', $settlement->id)->first();
    expect($post)->not->toBeNull()
        ->and($post->posting_type)->toBe('settlement')
        ->and($post->status)->toBe('posted')
        ->and((float) $post->total_debit)->toBe(round((float) $post->total_credit, 2));

    // The debit side is the cost: 14,516.13 salary + 140,000 gratuity + 2,000 encashment + 500 other.
    expect(round((float) $post->total_debit, 2))->toBe(157016.13)
        // The identity: what is booked equals what is owed plus what was withheld.
        ->and(round((float) $post->total_debit, 2))->toBe(round((float) $settlement->net_settlement + (float) $settlement->deductions, 2));

    $code = fn (string $c): int => (int) DB::table('acc_accounts')->where('code', $c)->value('id');
    $lines = DB::table('acc_journal_lines')->where('journal_id', $post->journal_id)->get();

    $sum = fn (string $account) => round((float) DB::table('acc_journal_lines')
        ->where('journal_id', $post->journal_id)->where('account_id', $code($account))->sum('debit'), 2);
    $credit = fn (string $account) => round((float) DB::table('acc_journal_lines')
        ->where('journal_id', $post->journal_id)->where('account_id', $code($account))->sum('credit'), 2);

    // Gratuity is an expense of its own and sits in Gratuity Payable, not inside Salary Payable.
    expect($sum('5214'))->toBe(140000.0)
        ->and($credit('2123'))->toBe(140000.0)
        // Salary + benefits: 14,516.13 + 2,000 + 500.
        ->and($sum('5210'))->toBe(14516.13)
        ->and($sum('5211'))->toBe(2500.0)
        // Salary Payable is net minus gratuity: 156,016.13 - 140,000 = 16,016.13.
        // (The 1,000 deduction is not part of it — net already had it taken off.)
        ->and($credit('2121'))->toBe(16016.13)
        // Deductions are the difference between owed and paid, not an extra credit.
        ->and($credit('2125'))->toBe(1000.0)
        ->and(round((float) $lines->sum('debit'), 2))->toBe(round((float) $lines->sum('credit'), 2));

    // Approved and posted is NOT reported as pending.
    expect(app(PayrollSettlementService::class)->pendingPostings())->toHaveCount(0);
});

test('a released festival bonus run reaches the ledger once, and a second release does not double-book it', function (): void {
    @set_time_limit(0);
    $this->travelTo(Carbon::parse('2026-12-01 10:00:00'));
    $admin = ledgerAdmin();
    $this->actingAs($admin);

    ledgerFiscalYear(2026);
    ledgerEmployee('LED-002', '2020-01-01', 20000, 30000);

    // A payslip has to exist first: the run is generated from finalized slips.
    $this->put(route('payroll.settings.update'), [
        'currency' => 'BDT', 'punishment_days_per_salary_day' => 3, 'overtime_multiplier' => 1,
        'extra_duty_multiplier' => 1, 'missing_punch_penalty_amount' => 0, 'unpaid_leave_day_divisor' => 30,
        'missing_attendance_policy' => 'waive', 'loan_approval_steps_text' => 'Payroll=Super Admin',
        'cutlist_review_required' => false,
    ])->assertSessionHasNoErrors();

    Bus::fake();
    $sheet = app(PayrollSalarySheetService::class)->dispatch(2026, 10, $admin->id, false);
    app(ProcessMonthlyPayrollBatchAction::class)->execute($sheet->id);
    app(PayrollSalarySheetService::class)->release($sheet->refresh(), $admin->id);

    $this->post(route('payroll.bonuses.policies.store'), [
        'name' => 'Ledger festival bonus',
        'calculation_type' => 'fixed',
        'amount' => 10000,
        'calculation_base' => 'basic',
        'minimum_service_months' => 0,
        'bonuses_per_year' => 2,
    ])->assertSessionHasNoErrors();

    $policy = FestivalBonusPolicy::query()->where('name', 'Ledger festival bonus')->firstOrFail();

    $this->post(route('payroll.bonuses.generate'), [
        'festival_bonus_policy_id' => $policy->id,
        'pay_year' => 2026,
        'pay_month' => 10,
    ])->assertSessionHasNoErrors();

    $run = FestivalBonusRun::query()->where('festival_bonus_policy_id', $policy->id)->firstOrFail();
    expect($run->status)->toBe('generated')
        ->and((float) $run->total_amount)->toBe(10000.0)
        // Generated is not released: nothing is owed yet, so nothing is in the books.
        ->and(DB::table('payroll_journal_posts')->where('bonus_run_id', $run->id)->count())->toBe(0);

    $this->post(route('payroll.bonuses.release', $run->id))->assertSessionHasNoErrors();

    $post = DB::table('payroll_journal_posts')->where('bonus_run_id', $run->id)->first();
    expect($post)->not->toBeNull()
        ->and($post->posting_type)->toBe('bonus')
        ->and(round((float) $post->total_debit, 2))->toBe(10000.0)
        ->and(round((float) $post->total_credit, 2))->toBe(10000.0);

    $code = fn (string $c): int => (int) DB::table('acc_accounts')->where('code', $c)->value('id');
    $debit = fn (string $a) => round((float) DB::table('acc_journal_lines')->where('journal_id', $post->journal_id)->where('account_id', $code($a))->sum('debit'), 2);
    $credit = fn (string $a) => round((float) DB::table('acc_journal_lines')->where('journal_id', $post->journal_id)->where('account_id', $code($a))->sum('credit'), 2);

    expect($debit('5213'))->toBe(10000.0)
        ->and($credit('2124'))->toBe(10000.0);

    // Posting the same run again is a no-op, not a second expense.
    app(PayrollJournalService::class)->postBonus($run->refresh());
    expect(DB::table('payroll_journal_posts')->where('bonus_run_id', $run->id)->count())->toBe(1);

    expect(app(PayrollBonusService::class)->pendingPostings())->toHaveCount(0);
});
