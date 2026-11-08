<?php

namespace Modules\HRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HRM\Models\Roster\RosterDuty;

class RosterDutyFactory extends Factory
{
    protected $model = RosterDuty::class;

    public function definition(): array
    {
        return [
            'roster_date' => $this->faker->date(),
            'scheduled_start_time' => '09:00:00',
            'scheduled_end_time' => '17:00:00',
            'status' => $this->faker->randomElement(['Scheduled', 'Working', 'Completed']),
        ];
    }
}
