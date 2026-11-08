<?php

/**
 * The POS terminal's picker data.
 *
 * The customer list was built with `where('type','customer')->orWhere('is_customer', true)`.
 * `is_customer` is not a column on `partners` and appears in no migration.
 * SQLite and MySQL silently coerce an unresolvable double-quoted identifier into
 * a string literal, so that predicate was permanently false — and on PostgreSQL
 * the same query is a hard error. The practical effect was that a partner whose
 * type is `both` (customer *and* vendor) never appeared at the till, and
 * inactive partners did.
 */

use Inertia\Testing\AssertableInertia as Assert;
use Modules\CORE\Database\Seeders\SuperAdminSeeder;
use Modules\CORE\Models\User;
use Modules\PARTNER\Models\Partner;

function posAdmin(): User
{
    test()->seed(SuperAdminSeeder::class);

    $admin = User::where('email', config('core.super_admin.email', 'admin@example.com'))->first();
    expect($admin)->not->toBeNull();

    return $admin;
}

beforeEach(function () {
    $this->actingAs(posAdmin());
});

/**
 * The props the terminal was actually rendered with.
 *
 * @return array<string, mixed>
 */
function posProps(): array
{
    $response = test()->get('/sales/pos/terminal');
    $response->assertOk();

    return $response->viewData('page')['props'] ?? [];
}

/** @return list<int> */
function posOptionValues(string $key): array
{
    return array_map(
        static fn ($option) => is_array($option) ? ($option['value'] ?? null) : null,
        array_values((array) (posProps()[$key] ?? []))
    );
}

test('the POS terminal renders for a user who may sell', function () {
    $this->get('/sales/pos/terminal')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('SALES::Pos/Terminal'));
});

test('a partner who is both a customer and a vendor is offered at the till', function () {
    // The regression: the dead `is_customer` predicate meant only type='customer'
    // rows were returned, so dual-role partners could not be selected.
    $dual = Partner::factory()->create([
        'name' => 'Dual Role Trading',
        'type' => 'both',
        'is_active' => true,
    ]);

    expect(posOptionValues('customers'))->toContain($dual->id);
});

test('inactive partners are not offered at the till', function () {
    // Warehouses and banks were already filtered by active(); customers were not.
    $inactive = Partner::factory()->create([
        'name' => 'Closed Down Ltd',
        'type' => 'customer',
        'is_active' => false,
    ]);
    $active = Partner::factory()->create([
        'name' => 'Still Trading Ltd',
        'type' => 'customer',
        'is_active' => true,
    ]);

    $values = posOptionValues('customers');

    expect($values)->not->toContain($inactive->id)
        ->and($values)->toContain($active->id);
});

test('every POS picker option carries a numeric value and a label', function () {
    // SearchableSelect renders `label` and submits `value`; a raw Eloquent model
    // in either position produces a blank row or an unpostable id.
    Partner::factory()->create(['type' => 'customer', 'is_active' => true]);

    $props = posProps();

    foreach (['warehouses', 'customers', 'banks'] as $key) {
        $options = (array) ($props[$key] ?? []);

        foreach ($options as $index => $option) {
            $at = "{$key}[{$index}]";

            expect(is_array($option), "{$at} is not an option array")->toBeTrue()
                ->and(array_key_exists('value', $option), "{$at} has no value")->toBeTrue()
                ->and(array_key_exists('label', $option), "{$at} has no label")->toBeTrue()
                ->and($option['label'], "{$at} has a blank label")->not->toBe('');

            if ($options !== []) {
                expect($option['value'], "{$at} value must be an id")->toBeInt();
            }
        }
    }
});

test('the customer list matches the authoritative customers() scope', function () {
    // Pins the controller to the model scope, so the two cannot drift again.
    Partner::factory()->create(['type' => 'customer', 'is_active' => true]);
    Partner::factory()->create(['type' => 'both', 'is_active' => true]);
    Partner::factory()->create(['type' => 'vendor', 'is_active' => true]);
    Partner::factory()->create(['type' => 'customer', 'is_active' => false]);

    $expected = Partner::query()->customers()->active()->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();

    $actual = posOptionValues('customers');
    sort($actual);

    expect($actual)->toBe($expected);
});
