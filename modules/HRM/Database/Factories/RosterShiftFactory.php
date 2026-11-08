<?php

namespace Modules\HRM\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\HRM\Models\Roster\RosterShift;

class RosterShiftFactory extends Factory
{
    protected $model = RosterShift::class;

    public function definition(): array
    {
        return [
            'shift_name' => $this->faker->randomElement(['Day', 'Night', 'Evening']).' Shift '.$this->faker->lexify('?'),
            'start_time' => $this->faker->randomElement(['09:00', '08:00', '22:00', '14:00']),
            'end_time' => $this->faker->randomElement(['17:00', '16:00', '06:00', '22:00']),
            'punch_in_window_start' => '08:00',
            'punch_in_window_end' => '10:00',
            'crosses_midnight' => false,
            'max_duration_hours' => 24,
        ];
    }

    public function night(): static
    {
        return $this->state(fn () => ['shift_name' => 'Night Shift', 'start_time' => '22:00', 'end_time' => '06:00', 'punch_in_window_start' => '21:00', 'punch_in_window_end' => '23:00', 'crosses_midnight' => true, 'max_duration_hours' => 12]);
    }

    public function flexible(): static
    {
        return $this->state(fn () => ['shift_name' => 'Flex Shift', 'start_time' => '09:00', 'end_time' => '17:00', 'punch_in_window_start' => '07:00', 'punch_in_window_end' => '11:00', 'crosses_midnight' => false, 'max_duration_hours' => 24]);
    }

    public function custom72(): static
    {
        return $this->state(fn () => ['shift_name' => 'Custom 48H', 'start_time' => '08:00', 'end_time' => '08:00', 'crosses_midnight' => true, 'max_duration_hours' => 72]);
    }
}
