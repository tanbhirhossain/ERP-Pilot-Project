<?php
namespace Modules\HRM\Database\Factories;
use Modules\HRM\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;
class DepartmentFactory extends Factory {
    protected $model = Department::class;
    public function definition(): array {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'code' => strtoupper($this->faker->unique()->lexify('DEPT-???')),
            'description' => $this->faker->sentence(),
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(0, 100),
        ];
    }
}
