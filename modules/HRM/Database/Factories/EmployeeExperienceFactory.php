<?php
namespace Modules\HRM\Database\Factories;
use Modules\HRM\Models\Employee\EmployeeExperience;
use Illuminate\Database\Eloquent\Factories\Factory;
class EmployeeExperienceFactory extends Factory {
    protected $model = EmployeeExperience::class;
    public function definition(): array {
        return ['company_name'=>$this->faker->company(),'designation'=>$this->faker->jobTitle(),'department'=>$this->faker->word(),'from_date'=>$this->faker->date(),'to_date'=>$this->faker->date(),'is_current'=>false];
    }
}
