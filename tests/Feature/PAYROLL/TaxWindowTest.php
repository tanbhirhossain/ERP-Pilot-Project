<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\PAYROLL\Models\TaxConfiguration;

afterEach(function (): void {
    $this->travelBack();
    Carbon::setTestNow(null);
    \Illuminate\Support\Carbon::setTestNow(null);
});

function nbrSchedule(int $id, string $from, ?string $to = null, bool $active = true): TaxConfiguration
{
    $config = TaxConfiguration::query()->create([
        'name' => 'NBR',
        'mode' => 'progressive',
        'fixed_monthly_tax' => 0,
        'annual_exemption' => 350000,
        'taxable_salary_percent' => 100,
        'is_active' => $active,
        'effective_from' => $from,
        'effective_to' => $to,
    ]);

    foreach ([
        [0, 350000, 0, 0],
        [350000, 450000, 5, 1],
        [450000, 750000, 10, 2],
        [750000, 1150000, 15, 3],
        [1150000, 1650000, 20, 4],
        [1650000, null, 25, 5],
    ] as [$lower, $upper, $rate, $order]) {
        DB::table('tax_slabs')->insert([
            'tax_configuration_id' => $config->id,
            'lower_limit' => $lower,
            'upper_limit' => $upper,
            'rate_percent' => $rate,
            'sort_order' => $order,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return $config;
}

/** How the payroll capture action resolves the schedule for a period: the end date decides. */
function taxScheduleOn(string $periodEnd): ?TaxConfiguration
{
    return TaxConfiguration::query()->effectiveOn($periodEnd)->orderByDesc('effective_from')->orderByDesc('id')->first();
}

/**
 * A tax schedule dated a month late silently pays everybody tax-free.
 *
 * The NBR schedule was stored with `effective_from` = 2026-12-01. Nothing was wrong with the slabs;
 * they were a month out of place. The consequence was not an exception or a red test — it was that
 * November resolved to no configuration at all, and a payroll run would have withheld zero taka from
 * a thousand people while the October slips still showed tax, because those had been recalculated.
 * A missing schedule is indistinguishable from a deliberate tax holiday unless somebody looks.
 */
test('a tax schedule covers the month it is dated for, and no month before it', function (): void {
    nbrSchedule(1, '2026-11-01 00:00:00');

    expect(taxScheduleOn('2026-11-30'))->not->toBeNull()
        ->and(taxScheduleOn('2026-11-30')->id)->toBe(1)
        ->and(taxScheduleOn('2026-12-31')->id)->toBe(1)
        // October is deliberately not covered: the schedule starts in November.
        ->and(taxScheduleOn('2026-10-31'))->toBeNull();
});

test('an inactive schedule is never selected, however well its dates fit', function (): void {
    nbrSchedule(1, '2026-11-01 00:00:00', null, false);

    expect(taxScheduleOn('2026-11-30'))->toBeNull();
});

test('the newest schedule wins when two of them cover the same month', function (): void {
    nbrSchedule(1, '2026-07-01 00:00:00');
    $later = nbrSchedule(2, '2026-11-01 00:00:00');

    expect(taxScheduleOn('2026-11-30')->id)->toBe($later->id);
});
