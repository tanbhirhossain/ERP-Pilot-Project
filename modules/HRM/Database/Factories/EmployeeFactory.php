<?php
namespace Modules\HRM\Database\Factories;
use Modules\HRM\Models\Employee\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;
class EmployeeFactory extends Factory {
    protected $model = Employee::class;
    public function definition(): array {
        $first = $this->faker->firstName();
        $last = $this->faker->lastName();
        static $seq = 0; $seq++;
        return [
            'employee_id' => 'EMP-'.str_pad($seq,4,'0',STR_PAD_LEFT),
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $first.' '.$last,
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => $this->faker->phoneNumber(),
            'gender' => $this->faker->randomElement(['Male','Female']),
            'date_of_birth' => $this->faker->date('Y-m-d','2000-01-01'),
            'joining_date' => $this->faker->date('Y-m-d'),
            'employment_type' => $this->faker->randomElement(['Permanent','Contract','Probation']),
            'status' => 'Active',
            'blood_group' => $this->faker->randomElement(['A+','B+','O+','AB+']),
            'is_active' => true,
        ];
    }
}
