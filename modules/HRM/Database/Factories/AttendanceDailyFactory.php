<?php
namespace Modules\HRM\Database\Factories;
use Modules\HRM\Models\Attendance\AttendanceDaily;
use Illuminate\Database\Eloquent\Factories\Factory;
class AttendanceDailyFactory extends Factory {
    protected $model = AttendanceDaily::class;
    public function definition(): array {
        return [
            'duty_date' => $this->faker->date(),
            'primary_punch_in' => $this->faker->dateTimeBetween('-7 days','now')->format('Y-m-d H:i:s'),
            'primary_punch_out' => $this->faker->dateTimeBetween('now','+1 day')->format('Y-m-d H:i:s'),
            'primary_worked_hours' => 8.0,
            'total_overtime_hours' => 0,
            'grand_total_hours' => 8.0,
            'status' => 'Present',
        ];
    }
}
