<?php

/**
 * Phase 3 — the tax override picker, and the silence that followed it.
 *
 * Two reports from the field: "i cannot see out all employee in Employee Tax override form and its not
 * selectable", and "Tax Allocation i have no idea where it is". The first is the picker's and the form's
 * server side, and it is pinned here:
 *
 *  - the typeahead has to return the whole roster, not the first slice of it — the front end used to show
 *    50 names and the component that fetched them could not be typed into (it cleared its own query), so
 *    employee #51 alphabetically was unreachable;
 *  - an employee the screen cannot price (no salary history covering the effective date) is refused with a
 *    `salary` field error thrown deep in the payroll provider — the page rendered errors for its own six
 *    inputs and nothing else, so that refusal was invisible: press Save, get nothing.
 *
 * The second report is a naming problem, not a missing feature — see the Vue test on `menuMatchesQuery`.
 *
 * Helpers are prefixed `ptx*`.
 */

use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\EmployeeSalary;
use Spatie\Permission\Models\Permission;
use Tests\Feature\HRM\HrmTestHelper;

uses(HrmTestHelper::class);

function ptxUser(int $salt = 1): User
{
    Permission::firstOrCreate(['name' => 'payroll.tax.manage', 'guard_name' => 'web']);
    $user = User::forceCreate(['name' => "PTX {$salt}", 'email' => "ptx{$salt}@example.test", 'password' => bcrypt('secret-password')]);
    $user->givePermissionTo('payroll.tax.manage');

    return $user;
}

/**
 * Create `$count` employees and hand back their codes, so a caller can name the one that must still be findable.
 *
 * @return list<string>
 */
function ptxRoster(int $count): array
{
    $codes = [];

    for ($i = 1; $i <= $count; $i++) {
        $codes[] = test()->createEmployee()->employee_id;
    }

    return $codes;
}

test('the employee typeahead returns the whole roster, not a slice of it', function () {
    // 55 employees: one more than the picker used to render, so a truncation cannot hide.
    $codes = ptxRoster(55);

    $items = $this->actingAs(ptxUser())
        ->getJson('/hrm/api/options/employees?search=&limit=200')
        ->assertOk()
        ->json('items');

    $values = array_column($items, 'value');

    // `>=`, not `===`: whatever the suite seeds, the point is that nothing the roster holds is dropped at a
    // round number.
    expect($values)->toHaveCount(count($codes))
        ->and(array_diff($codes, $values))->toBe([], 'the typeahead left employees out of the list entirely');
});

test('an employee past the old fifty-row window is findable by search', function () {
    $codes = ptxRoster(55);
    $late = $codes[54];

    $hit = $this->actingAs(ptxUser())
        ->getJson('/hrm/api/options/employees?search='.urlencode($late).'&limit=200')
        ->assertOk()
        ->json('items');

    expect(array_column($hit, 'value'))->toContain($late);
});

test('the tax page seeds the picker with the roster rather than a fixed handful', function () {
    ptxRoster(12);

    $props = $this->actingAs(ptxUser())->get(route('payroll.tax.index'))
        ->assertOk()
        ->viewData('page')['props'];

    expect(count($props['employeeOptions']))->toBeGreaterThanOrEqual(12)
        // The list the page hands over is only a seed now; the component browses and searches on its own.
        ->and($props['employeeOptions'][0])->toHaveKeys(['value', 'label']);
});

test('an employee with no salary history is refused with a message the page can show', function () {
    $e = $this->createEmployee();

    $this->actingAs(ptxUser())
        ->post(route('payroll.tax.profiles.store'), [
            'employee_id' => $e->employee_id,
            'override_mode' => 'force_fixed',
            'fixed_tax_amount' => 2500,
            'fixed_tax_frequency' => 'monthly',
            'reason' => 'Settled per the board minute of 12 October.',
        ])
        // Not a 500, and not silence: the payroll provider's refusal has to arrive as a field error.
        ->assertRedirect()
        ->assertSessionHasErrors('salary');

    $this->assertDatabaseCount('employee_tax_profiles', 0);

    $message = session('errors')->first('salary');

    expect($message)->toContain($e->employee_id)
        ->and($message)->toContain(now()->toDateString());
});

test('an employee with a salary gets a versioned profile and an audit row', function () {
    $e = $this->createEmployee();

    EmployeeSalary::query()->create([
        'employee_id' => $e->employee_id,
        'basic_salary' => 40000,
        'gross_salary' => 56000,
        'house_rent' => 12000,
        'medical_allowance' => 1500,
        'net_salary' => 56000,
        'is_current' => true,
        'effective_from' => now()->subYear()->toDateString(),
    ]);

    $this->actingAs(ptxUser())
        ->post(route('payroll.tax.profiles.store'), [
            'employee_id' => $e->employee_id,
            'override_mode' => 'force_fixed',
            'fixed_tax_amount' => 2500,
            'fixed_tax_frequency' => 'monthly',
            'reason' => 'Settled per the board minute of 12 October.',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    $this->assertDatabaseHas('employee_tax_profiles', [
        'employee_id' => $e->employee_id,
        'override_mode' => 'force_fixed',
    ]);
    $this->assertDatabaseCount('payroll_employee_tax_profile_versions', 1);

    // The audit row is the whole point of versioning this table; without it the override is unsourced.
    $this->assertDatabaseHas('payroll_tax_audits', [
        'employee_id' => $e->employee_id,
        'event_type' => 'employee_profile_saved',
    ]);
});

test('every error key the server can return for the override form has somewhere to land', function () {
    // The class of bug this pins: a page that renders six `form.errors.*` paragraphs and silently drops a
    // seventh key. Reading the rules out of the controller keeps this honest as either side changes.
    $controller = file_get_contents(base_path('modules/PAYROLL/Http/Controllers/TaxConfigurationController.php'));
    $page = file_get_contents(base_path('modules/PAYROLL/Resources/js/Pages/Tax/Index.vue'));

    preg_match('/storeProfile.*?\$data = \$request->validate\(\[(?<rules>.*?)\]\);/s', $controller, $m);

    expect($m['rules'] ?? '')->not->toBe('', 'the shape of storeProfile() changed and this test went blind');

    preg_match_all("/^\\s*'([a-z_.]+)' => \\[/m", $m['rules'], $keys);

    $fields = array_values(array_unique(array_map(
        static fn (string $key): string => explode('.', $key)[0],
        $keys[1],
    )));

    $bucketed = str_contains($page, 'looseProfileErrors');
    $missing = [];

    foreach ($fields as $field) {
        if (! str_contains($page, "profile.errors.{$field}") && ! $bucketed) {
            $missing[] = $field;
        }
    }

    // 'salary' is not a rule key — it is thrown from the payroll provider — so it must reach the bucket.
    expect($missing)->toBe([])
        ->and($bucketed)->toBeTrue()
        ->and(count($fields))->toBeGreaterThan(4, 'the override form has fewer validated fields than the page claims');
});
