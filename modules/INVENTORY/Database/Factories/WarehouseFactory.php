<?php

namespace Modules\INVENTORY\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\INVENTORY\Models\Warehouse;

class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;
    public function definition(): array
    {
        return [
            'name' => $this->faker->city().' Warehouse',
            'code' => 'WH-' . str_pad((string) $this->faker->unique()->numberBetween(1, 99), 3, '0', STR_PAD_LEFT),
            'type' => $this->faker->randomElement(['main', 'sub']),
            'city' => $this->faker->city(),
            'is_active' => true,
        ];
    }
}
