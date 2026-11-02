<?php
namespace Modules\HRM\Database\Factories;
use Modules\HRM\Models\Employee\EmployeeEducation;
use Illuminate\Database\Eloquent\Factories\Factory;
class EmployeeEducationFactory extends Factory {
    protected $model = EmployeeEducation::class;
    public function definition(): array {
        return ['degree'=>$this->faker->randomElement(['BSc','MSc','BBA','MBA']),'institute'=>$this->faker->company().' University','board'=>'Dhaka','passing_year'=> (string)$this->faker->numberBetween(2005,2023),'grade'=>'First Class'];
    }
}
