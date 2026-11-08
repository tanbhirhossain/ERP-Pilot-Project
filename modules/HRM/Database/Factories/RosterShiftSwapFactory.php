<?php

namespace Modules\HRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HRM\Models\Roster\RosterShiftSwap;

class RosterShiftSwapFactory extends Factory
{
    protected $model = RosterShiftSwap::class;

    public function definition(): array
    {
        return ['swap_date' => $this->faker->date(), 'reason' => $this->faker->sentence(), 'status' => 'Pending'];
    }
}
