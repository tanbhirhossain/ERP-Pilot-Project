<?php

use Modules\CORE\Models\Role;
use Modules\CORE\Models\User;
use Modules\HRM\Models\Employee\Employee;
use Modules\PAYROLL\Database\Seeders\PAYROLLPermissionSeeder;
use Modules\PAYROLL\Models\PfAccount;
use Modules\PAYROLL\Models\PfPolicy;
use Modules\PAYROLL\Services\ProvidentFundService;

function createPfAllocationTestPolicy(int $minimumServiceMonths = 0): PfPolicy
{
    return PfPolicy::query()->create([
        'name' => 'Allocation regression policy',
        'employee_contribution_percent' => 5,
        'employer_contribution_percent' => 5,
        'minimum_service_months' => $minimumServiceMonths,
        'contribution_base' => 'basic',
        'is_active' => true,
        'effective_from' => '2020-01-01',
    ]);
}

test('selected PF allocation creates an account once and leaves existing balances untouched', function () {
    $role = Role::query()->create(['name' => 'Admin', 'guard_name' => 'web']);
    $this->seed(PAYROLLPermissionSeeder::class);
    $manager = User::factory()->create();
    $manager->assignRole($role);

    $employee = Employee::factory()->create([
        'joining_date' => '2025-01-31',
        'status' => 'Active',
        'is_active' => true,
    ]);
    $policy = createPfAllocationTestPolicy(1);

    $this->actingAs($manager)
        ->from(route('payroll.pf.index'))
        ->post(route('payroll.pf.allocate'), [
            'scope' => 'selected',
            'policy_id' => $policy->id,
            'employee_ids' => [$employee->employee_id],
        ])
        ->assertRedirect(route('payroll.pf.index'))
        ->assertSessionHasNoErrors();

    $account = PfAccount::query()->where('employee_id', $employee->employee_id)->firstOrFail();
    expect((int) $account->pf_policy_id)->toBe((int) $policy->id)
        ->and($account->eligible_from?->toDateString())->toBe('2025-02-28')
        ->and((float) $account->employee_balance)->toBe(0.0)
        ->and((float) $account->employer_balance)->toBe(0.0);

    $account->update(['employee_balance' => 125.50, 'employer_balance' => 150.25]);
    $secondAllocation = app(ProvidentFundService::class)->allocateAccounts($policy->id, [$employee->employee_id]);

    expect($secondAllocation)->toBe(['created' => 0, 'skipped' => 1])
        ->and((float) $account->fresh()->employee_balance)->toBe(125.5)
        ->and((float) $account->fresh()->employer_balance)->toBe(150.25)
        ->and(PfAccount::query()->where('employee_id', $employee->employee_id)->count())->toBe(1);
});

test('allocate all creates PF accounts only for active and on-leave employees', function () {
    $active = Employee::factory()->create(['status' => 'Active', 'is_active' => true]);
    $onLeave = Employee::factory()->create(['status' => 'On Leave', 'is_active' => true]);
    $inactive = Employee::factory()->create(['status' => 'Inactive', 'is_active' => false]);
    $policy = createPfAllocationTestPolicy();

    $result = app(ProvidentFundService::class)->allocateAccounts($policy->id, [], true);

    expect($result)->toBe(['created' => 2, 'skipped' => 0])
        ->and(PfAccount::query()->where('employee_id', $active->employee_id)->exists())->toBeTrue()
        ->and(PfAccount::query()->where('employee_id', $onLeave->employee_id)->exists())->toBeTrue()
        ->and(PfAccount::query()->where('employee_id', $inactive->employee_id)->exists())->toBeFalse();
});
