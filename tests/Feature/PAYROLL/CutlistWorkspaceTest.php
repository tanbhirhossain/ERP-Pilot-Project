<?php

use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Models\PayrollCutlist;
use Modules\PAYROLL\Models\PayrollCutlistItem;
use Modules\PAYROLL\Services\PayrollCutlistWorkspaceService;

/**
 * The cutlist review workspace.
 *
 * The interesting cases here are not arithmetic. They are the three ways a review desk lies to the
 * person using it:
 *
 *   - it shows a day count beside zero money and lets them read a fault into it;
 *   - it lets a month be signed off over rows nobody looked at, or over rows that have already been
 *     paid from;
 *   - it keeps a sign-off attached to figures that were replaced after it was given.
 *
 * Each test below pins one of those, because each one was true of the screen this replaces.
 */

function cwItem(PayrollCutlist $cutlist, string $employee, array $overrides = []): PayrollCutlistItem
{
    // `payroll_cutlist_items.employee_id` is a real foreign key into `employees`.
    if (! DB::table('employees')->where('employee_id', $employee)->exists()) {
        DB::table('employees')->insert([
            'employee_id' => $employee, 'first_name' => "Emp {$employee}", 'full_name' => "Emp {$employee}",
            'employment_type' => 'Permanent', 'status' => 'Active', 'is_active' => 1,
            'allow_overtime' => 1, 'duty_hours' => 8, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return PayrollCutlistItem::query()->create(array_merge([
        'payroll_cutlist_id' => $cutlist->id,
        'employee_id' => $employee,
        'employee_name' => "Emp {$employee}",
        'unpaid_leave_days' => 0,
        'late_count' => 0,
        'early_out_count' => 0,
        'punishment_days' => 0,
        'punishable' => false,
        'missing_punch_count' => 0,
        'approved_overtime_minutes' => 0,
        'approved_extra_duty_minutes' => 0,
        'unpaid_leave_deduction' => 0,
        'late_penalty_deduction' => 0,
        'missing_punch_deduction' => 0,
        'overtime_pay' => 0,
        'extra_duty_pay' => 0,
        'source_breakdown' => [],
    ], $overrides));
}

function cwCutlist(int $year, int $month, array $overrides = []): PayrollCutlist
{
    return PayrollCutlist::query()->create(array_merge([
        'pay_year' => $year, 'pay_month' => $month, 'status' => 'generated', 'generated_at' => now(),
    ], $overrides));
}

it('counts a punishment day as money only where the policy charges it', function (): void {
    $cutlist = cwCutlist(2026, 10);
    // Five days recorded, not punishable: costs nothing.
    cwItem($cutlist, 'C1', ['punishment_days' => 5, 'punishable' => false, 'late_penalty_deduction' => 0]);
    // Eight days recorded and punishable: the day rate lands as money.
    cwItem($cutlist, 'C2', [
        'punishment_days' => 8, 'punishable' => true, 'late_penalty_deduction' => 3000,
        'source_breakdown' => ['punishable' => true, 'calculation_details' => ['punishment' => [
            'eligible' => true, 'affected_days' => 8, 'salary_day_rate' => 1500, 'days_per_salary_day' => 3, 'deduction' => 3000,
        ]]],
    ]);

    $service = new PayrollCutlistWorkspaceService;
    $ctx = $service->context($cutlist->id);

    expect($ctx['aggregate']['flagged'])->toBe(2)
        ->and($ctx['aggregate']['chargeable'])->toBe(1)
        ->and($ctx['aggregate']['deductions'])->toBe(3000.0)
        // And the two rows are separable, which is the whole point of the segment.
        ->and(collect($ctx['segments'])->firstWhere('key', 'flagged')['count'])->toBe(1);
});

it('explains a row that records days but charges nothing', function (): void {
    $cutlist = cwCutlist(2026, 10);
    $item = cwItem($cutlist, 'C3', [
        'punishment_days' => 8,
        'punishable' => false,
        'late_penalty_deduction' => 0,
        'source_breakdown' => ['punishable' => false, 'late_dates' => ['2026-10-10', '2026-10-12'],
            'calculation_details' => ['punishment' => ['eligible' => false, 'deduction' => 0]]],
    ]);

    $explain = (new PayrollCutlistWorkspaceService)->explain($item);

    // The sentence has to say *why* it is zero, or the row looks like a bug.
    expect($explain['lines'][0]['text'])->toContain('not punishable')
        ->and($explain['dates']['late'])->toBe(['2026-10-10', '2026-10-12']);
});

it('explains a charged row with its day rate', function (): void {
    $cutlist = cwCutlist(2026, 10);
    $item = cwItem($cutlist, 'C4', [
        'punishment_days' => 8, 'punishable' => true, 'late_penalty_deduction' => 3000,
        'source_breakdown' => ['punishable' => true, 'calculation_details' => ['punishment' => [
            'eligible' => true, 'affected_days' => 8, 'salary_day_rate' => 1500, 'days_per_salary_day' => 3, 'deduction' => 3000,
        ]]],
    ]);

    $explain = (new PayrollCutlistWorkspaceService)->explain($item);

    expect($explain['lines'][0]['text'])->toContain('1,500.00')
        ->and($explain['lines'][0]['text'])->toContain('3,000.00')
        ->and($explain['lines'][0]['tone'])->toBe('danger');
});

it('blocks a sign-off when a salary sheet already consumed the rows', function (): void {
    $cutlist = cwCutlist(2026, 10);
    cwItem($cutlist, 'C5', ['late_penalty_deduction' => 500, 'punishment_days' => 1, 'punishable' => true]);
    DB::table('payroll_salary_sheets')->insert([
        'pay_year' => 2026, 'pay_month' => 10, 'status' => 'released', 'payroll_cutlist_id' => $cutlist->id,
        'employee_count' => 1, 'processed_count' => 1, 'gross_total' => 0, 'deduction_total' => 0, 'net_total' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $readiness = (new PayrollCutlistWorkspaceService)->readiness($cutlist->id);

    expect($readiness['blocking_failures'])->toBeGreaterThan(0)
        ->and(collect($readiness['checks'])->firstWhere('key', 'consumed')['ok'])->toBeFalse();
});

it('blocks a sign-off on a cutlist with no rows', function (): void {
    $cutlist = cwCutlist(2026, 11);

    $readiness = (new PayrollCutlistWorkspaceService)->readiness($cutlist->id);

    expect(collect($readiness['checks'])->firstWhere('key', 'rows')['ok'])->toBeFalse()
        ->and($readiness['blocking_failures'])->toBeGreaterThan(0);
});

it('allows a sign-off with nothing but advisory notes', function (): void {
    $cutlist = cwCutlist(2026, 12);
    cwItem($cutlist, 'C6');

    $readiness = (new PayrollCutlistWorkspaceService)->readiness($cutlist->id);

    expect($readiness['blocking_failures'])->toBe(0);
});

it('turns a reviewer adjustment into a readable before and after', function (): void {
    $cutlist = cwCutlist(2026, 10);
    cwItem($cutlist, 'C7', [
        'punishment_days' => 8, 'punishable' => true, 'late_penalty_deduction' => 0,
        'source_breakdown' => ['review' => [[
            'at' => '2026-11-15 10:00:00', 'by' => 1, 'name' => 'Super Admin',
            'reason' => 'Waived per shift log SL-88',
            'changes' => ['late_penalty_deduction' => ['from' => 3000, 'to' => 0]],
        ]]],
    ]);

    $trail = (new PayrollCutlistWorkspaceService)->recentAdjustments($cutlist->id);

    expect($trail)->toHaveCount(1)
        ->and($trail[0]['name'])->toBe('Super Admin')
        ->and($trail[0]['reason'])->toBe('Waived per shift log SL-88')
        // The field is translated: an auditor should not have to know a column name.
        ->and($trail[0]['changes'][0]['field'])->toBe('Punishment deduction')
        ->and($trail[0]['changes'][0]['from'])->toBe(3000.0)
        ->and($trail[0]['changes'][0]['to'])->toBe(0.0);
});

it('keeps the money on a cutlist whose sheet already ran as the money it proposed', function (): void {
    $cutlist = cwCutlist(2026, 10);
    cwItem($cutlist, 'C8', ['missing_punch_deduction' => 250, 'missing_punch_count' => 2]);
    cwItem($cutlist, 'C9', ['overtime_pay' => 374.85, 'approved_overtime_minutes' => 500]);

    $overview = (new PayrollCutlistWorkspaceService)->overview();
    $row = collect($overview['rows'])->firstWhere('id', $cutlist->id);

    expect($row['deductions'])->toBe(250.0)
        ->and($row['earnings'])->toBe(374.85)
        ->and($row['at_stake'])->toBe(624.85);
});
