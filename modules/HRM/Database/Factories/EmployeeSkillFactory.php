<?php
namespace Modules\HRM\Database\Factories;
use Modules\HRM\Models\Employee\EmployeeSkill;
use Illuminate\Database\Eloquent\Factories\Factory;
class EmployeeSkillFactory extends Factory {
    protected $model = EmployeeSkill::class;
    public function definition(): array {
        return ['skill_name'=>$this->faker->randomElement(['PHP','Laravel','Vue','MySQL','Project Management']),'proficiency'=>$this->faker->randomElement(['Beginner','Intermediate','Advanced','Expert'])];
    }
}
