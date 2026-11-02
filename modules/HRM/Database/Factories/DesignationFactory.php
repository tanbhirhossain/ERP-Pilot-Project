<?php
namespace Modules\HRM\Database\Factories;
use Modules\HRM\Models\Designation;
use Illuminate\Database\Eloquent\Factories\Factory;
class DesignationFactory extends Factory {
    protected $model = Designation::class;
    public function definition(): array {
        return [
            'name' => $this->faker->jobTitle(),
            'code' => strtoupper($this->faker->unique()->lexify('DSG-???')),
            'description' => $this->faker->sentence(),
            'grade' => 'Grade '.$this->faker->numberBetween(1,20),
            'level' => $this->faker->numberBetween(1,10),
            'is_active' => true,
        ];
    }
}
