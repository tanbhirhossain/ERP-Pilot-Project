<?php

namespace Modules\HRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HRM\Models\Employee\EmployeePolicy;

class EmployeePolicyFactory extends Factory
{
    protected $model = EmployeePolicy::class;

    public function definition(): array
    {
        return [
            'grace_time_in' => $this->faker->numberBetween(5, 30),
            'grace_time_out' => $this->faker->numberBetween(5, 30),
            'shift_type' => $this->faker->randomElement(['Fixed', 'Flexible', 'Custom']),
            'is_punishable' => true,
            'duty_hours' => $this->faker->randomElement([6, 7, 8]),
            'auto_shift' => true,
            'buffer_minutes' => 90,
            'allow_fallback' => true,
        ];
    }

    public function fixed(): static
    {
        return $this->state(fn () => ['shift_type' => 'Fixed']);
    }

    public function flexible(): static
    {
        return $this->state(fn () => ['shift_type' => 'Flexible']);
    }

    public function custom(): static
    {
        return $this->state(fn () => ['shift_type' => 'Custom']);
    }
}
