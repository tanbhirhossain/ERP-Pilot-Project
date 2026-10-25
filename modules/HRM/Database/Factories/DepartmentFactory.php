<?php

namespace Modules\HRM\Database\Factories;

use Modules\HRM\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    public function definition(): array
    {
        return [
            //
        ];
    }
}