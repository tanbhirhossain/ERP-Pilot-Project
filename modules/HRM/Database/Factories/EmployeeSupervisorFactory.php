<?php

namespace Modules\HRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HRM\Models\Employee\EmployeeSupervisor;

class EmployeeSupervisorFactory extends Factory
{
    protected $model = EmployeeSupervisor::class;

    public function definition(): array
    {
        $isEmp = $this->faker->boolean(50);

        return [
            'supervisor_type' => $this->faker->randomElement(['Supervisor', 'Manager', 'Incharge', 'Department Head', 'Reporting Manager']),
            'is_active' => $this->faker->boolean(85),
            'is_primary' => false,
            'effective_from' => $this->faker->optional()->date(),
            'remarks' => $this->faker->optional()->sentence(),
            // supervisor_id OR supervisor_employee_id will be set by seeder; leave null here, flexibility handled in seeder/state
        ];
    }

    public function asUser(int $userId): static
    {
        return $this->state(fn () => ['supervisor_id' => $userId, 'supervisor_employee_id' => null]);
    }

    public function asEmployee(string $empId): static
    {
        return $this->state(fn () => ['supervisor_id' => null, 'supervisor_employee_id' => $empId]);
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }
}
