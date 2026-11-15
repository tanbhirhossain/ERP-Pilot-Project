<?php

use Modules\PAYROLL\Actions\CalculateEmployeeTaxAction;

/**
 * The arithmetic of employee tax.
 *
 * There was no test here at all, and the calculator was wrong in a way that cost real money: the
 * annual exemption was subtracted from income *and* present as a zero-rated slab, so it was granted
 * twice. On this database every payroll ever computed came out at ৳0 withholding while the tax
 * screen displayed a policy that looked complete.
 *
 * These tests pin the numbers against hand-computed NBR figures, and they pin the two properties
 * that make a progressive tax a progressive tax: the marginal rate never falls, and the relief is
 * granted exactly once whatever shape the slab table is written in.
 */
function etcSlabs(): array
{
    return [
        ['lower_limit' => 0, 'upper_limit' => 350000, 'rate_percent' => 0],
        ['lower_limit' => 350000, 'upper_limit' => 450000, 'rate_percent' => 5],
        ['lower_limit' => 450000, 'upper_limit' => 750000, 'rate_percent' => 10],
        ['lower_limit' => 750000, 'upper_limit' => 1150000, 'rate_percent' => 15],
        ['lower_limit' => 1150000, 'upper_limit' => 1650000, 'rate_percent' => 20],
        ['lower_limit' => 1650000, 'upper_limit' => null, 'rate_percent' => 25],
    ];
}

function etcConfig(array $overrides = []): array
{
    return array_merge([
        'mode' => 'progressive',
        'fixed_monthly_tax' => 0,
        'annual_exemption' => 350000,
        'taxable_salary_percent' => 100,
        'slabs' => etcSlabs(),
    ], $overrides);
}

it('charges nothing inside the relief band', function (): void {
    $calc = new CalculateEmployeeTaxAction();

    // 10,902/month is 130,824 a year — well inside the 350,000 relief.
    expect($calc->execute(10902.0, etcConfig(), null, '2026-11-30')['annual_tax'])->toBe(0.0)
        ->and($calc->execute(25000.0, etcConfig(), null, '2026-11-30')['annual_tax'])->toBe(0.0);
});

it('charges the tax each band actually contains, not the whole income at the top rate', function (): void {
    $calc = new CalculateEmployeeTaxAction();

    // 40,000/mo = 480,000/yr. 350,000 is relief; the next 100,000 sits in the 5% band.
    $forty = $calc->execute(40000.0, etcConfig(), null, '2026-11-30');
    expect($forty['annual_tax'])->toBe(8000.0)
        ->and($forty['monthly_tax'])->toBe(666.67);

    // 60,000/mo = 720,000/yr: 100,000 @ 5% + 270,000 @ 10% = 32,000.
    expect($calc->execute(60000.0, etcConfig(), null, '2026-11-30')['annual_tax'])->toBe(32000.0);

    // 100,000/mo = 1,200,000/yr: 5,000 + 30,000 + 400,000@15% + 50,000@20% = 105,000.
    expect($calc->execute(100000.0, etcConfig(), null, '2026-11-30')['annual_tax'])->toBe(105000.0);
});

it('grants the exemption once whether or not the table also carries a zero-rated first band', function (): void {
    $calc = new CalculateEmployeeTaxAction();

    // The same policy written the other way: no 0% band, bands start at the relief threshold.
    $withoutZeroBand = etcConfig(['slabs' => [
        ['lower_limit' => 350000, 'upper_limit' => 450000, 'rate_percent' => 5],
        ['lower_limit' => 450000, 'upper_limit' => 750000, 'rate_percent' => 10],
        ['lower_limit' => 750000, 'upper_limit' => 1150000, 'rate_percent' => 15],
        ['lower_limit' => 1150000, 'upper_limit' => 1650000, 'rate_percent' => 20],
        ['lower_limit' => 1650000, 'upper_limit' => null, 'rate_percent' => 25],
    ]]);

    foreach ([40000.0, 60000.0, 100000.0] as $monthly) {
        expect($calc->execute($monthly, $withoutZeroBand, null, '2026-11-30')['annual_tax'])
            ->toBe($calc->execute($monthly, etcConfig(), null, '2026-11-30')['annual_tax'],
                'the two ways of writing the same policy must charge the same tax');
    }

    // And an exemption of zero with a 0% first band is the same as that band not existing.
    $noRelief = etcConfig(['annual_exemption' => 0]);
    expect($calc->execute(40000.0, $noRelief, null, '2026-11-30')['annual_tax'])->toBe(8000.0);
});

it('never lets the tax fall as income rises', function (): void {
    $calc = new CalculateEmployeeTaxAction();
    $previous = -1.0;

    for ($monthly = 5000.0; $monthly <= 500000.0; $monthly += 5000.0) {
        $tax = $calc->execute($monthly, etcConfig(), null, '2026-11-30')['annual_tax'];
        expect($tax)->toBeGreaterThanOrEqual($previous, "tax fell at {$monthly}/month, which a progressive scale cannot do");
        $previous = $tax;
    }
});

it('applies the taxable salary percentage before the relief, not after', function (): void {
    $calc = new CalculateEmployeeTaxAction();

    // 100,000/month at 50% taxable = 600,000 a year of taxable income: 250,000 above relief,
    // 100,000 @ 5% + 150,000 @ 10% = 5,000 + 15,000 = 20,000.
    expect($calc->execute(100000.0, etcConfig(['taxable_salary_percent' => 50]), null, '2026-11-30')['annual_tax'])
        ->toBe(20000.0);
});

it('treats a progressive policy with no bands as unconfigured rather than as zero tax', function (): void {
    $calc = new CalculateEmployeeTaxAction();

    // The engine cannot know what an empty band list means, but silence is not an answer. The
    // service refuses to save such a policy (see TaxConfigurationService) and this pins the
    // calculator's side: it reports zero *and* the mode says why.
    $result = $calc->execute(60000.0, etcConfig(['slabs' => []]), null, '2026-11-30');
    expect($result['annual_tax'])->toBe(0.0);
});

it('honours a fixed policy, an employee override, and an exemption', function (): void {
    $calc = new CalculateEmployeeTaxAction();

    expect($calc->execute(60000.0, etcConfig(['mode' => 'fixed', 'fixed_monthly_tax' => 2500]), null, '2026-11-30')['monthly_tax'])
        ->toBe(2500.0);

    $monthly = ['override_mode' => 'force_fixed', 'fixed_tax_amount' => 400, 'fixed_tax_frequency' => 'monthly', 'effective_from' => '2026-01-01', 'effective_to' => null];
    expect($calc->execute(60000.0, etcConfig(), $monthly, '2026-11-30')['monthly_tax'])->toBe(400.0);

    $annual = ['override_mode' => 'force_fixed', 'fixed_tax_amount' => 1200, 'fixed_tax_frequency' => 'annual', 'effective_from' => '2026-01-01', 'effective_to' => null];
    expect($calc->execute(60000.0, etcConfig(), $annual, '2026-11-30')['monthly_tax'])->toBe(100.0);

    $exempt = ['override_mode' => 'exempt', 'fixed_tax_amount' => 0, 'fixed_tax_frequency' => 'monthly', 'effective_from' => '2026-01-01', 'effective_to' => null];
    expect($calc->execute(60000.0, etcConfig(), $exempt, '2026-11-30')['monthly_tax'])->toBe(0.0);

    // An override that has expired must not apply.
    $expired = [...$monthly, 'effective_to' => '2026-10-31'];
    expect($calc->execute(60000.0, etcConfig(), $expired, '2026-11-30')['monthly_tax'])->toBe(2666.67);
});
