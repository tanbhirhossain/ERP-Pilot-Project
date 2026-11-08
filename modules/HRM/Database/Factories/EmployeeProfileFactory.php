<?php

namespace Modules\HRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HRM\Models\Employee\EmployeeProfile;

class EmployeeProfileFactory extends Factory
{
    protected $model = EmployeeProfile::class;

    public function definition(): array
    {
        return [
            'father_name' => $this->faker->name('male'),
            'mother_name' => $this->faker->name('female'),
            'present_address' => $this->faker->address(),
            'permanent_address' => $this->faker->address(),
            'emergency_contact_name' => $this->faker->name(),
            'emergency_contact_phone' => $this->faker->phoneNumber(),
            'emergency_contact_relation' => $this->faker->randomElement(['Father', 'Mother', 'Spouse', 'Sibling']),
            'nationality' => 'Bangladeshi',
        ];
    }
}
