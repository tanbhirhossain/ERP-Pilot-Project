<?php

namespace Modules\PARTNER\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\PARTNER\Models\PartnerGroup;

class PartnerGroupFactory extends Factory
{
    protected $model = PartnerGroup::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'code' => strtoupper($this->faker->unique()->lexify('???')) . '-' . $this->faker->numerify('###'),
            'type' => $this->faker->randomElement(['vendor', 'customer', 'both']),
            'description' => $this->faker->sentence(),
            'sort_order' => $this->faker->numberBetween(0, 100),
            'is_active' => true,
        ];
    }
}
