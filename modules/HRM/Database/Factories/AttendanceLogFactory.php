<?php
namespace Modules\HRM\Database\Factories;
use Modules\HRM\Models\Attendance\AttendanceLog;
use Illuminate\Database\Eloquent\Factories\Factory;
class AttendanceLogFactory extends Factory {
    protected $model = AttendanceLog::class;
    public function definition(): array {
        return [
            'device_user_id' => (string) $this->faker->numberBetween(1,999),
            'punch_time' => $this->faker->dateTimeBetween('-7 days','now')->format('Y-m-d H:i:s'),
            'punch_type' => 'AUTO',
            'is_processed' => false,
        ];
    }
    public function processed(): static { return $this->state(fn()=>['is_processed'=>true]); }
    public function forEmployee(string $employeeId): static { return $this->state(fn()=>['employee_id'=>$employeeId]); }
}
