<?php

namespace Modules\INVENTORY\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\INVENTORY\Models\Category;

class CategoryFactory extends Factory
{
    protected $model = Category::class;
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'code' => strtoupper($this->faker->unique()->lexify('???')).'-'.$this->faker->numerify('###'),
            'is_active' => true,
            'sort_order' => $this->faker->numberBetween(0, 100),
        ];
    }
}
