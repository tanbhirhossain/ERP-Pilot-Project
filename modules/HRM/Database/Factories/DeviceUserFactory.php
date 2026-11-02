<?php
namespace Modules\HRM\Database\Factories;
use Modules\HRM\Models\DeviceUser;
use Illuminate\Database\Eloquent\Factories\Factory;
class DeviceUserFactory extends Factory {
    protected $model = DeviceUser::class;
    public function definition(): array {
        return [
            'device_id' => null, // global mapping — no device binding
            'uid' => (string) $this->faker->numberBetween(1, 9999),
            'device_user_id' => (string) $this->faker->unique()->numberBetween(1, 99999),
            'name' => $this->faker->name(),
            'employee_id' => null, // link explicitly via ->forEmployee() or withEmployee()
        ];
    }

    /** Global mapping with employee linked */
    public function mapped(\Modules\HRM\Models\Employee\Employee $employee = null): static {
        return $this->state(function (array $attrs) use ($employee) {
            if (!$employee) {
                $employee = \Modules\HRM\Models\Employee\Employee::factory()->create();
            }
            return [
                'employee_id' => $employee->employee_id,
                'device_id' => null,
            ];
        });
    }
}
