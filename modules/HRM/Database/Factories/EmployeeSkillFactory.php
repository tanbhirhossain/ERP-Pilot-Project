<?php

namespace Modules\HRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HRM\Models\Employee\EmployeeSkill;

class EmployeeSkillFactory extends Factory
{
    protected $model = EmployeeSkill::class;

    public function definition(): array
    {
        return ['skill_name' => $this->faker->randomElement(['PHP', 'Laravel', 'Vue', 'MySQL', 'Project Management']), 'proficiency' => $this->faker->randomElement(['Beginner', 'Intermediate', 'Advanced', 'Expert'])];
    }
}
