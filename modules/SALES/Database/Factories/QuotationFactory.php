<?php

namespace Modules\SALES\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SALES\Models\Quotation;

class QuotationFactory extends Factory
{
    protected $model = Quotation::class;
    public function definition(): array
    {
        return [
            'quotation_no' => 'QT-' . $this->faker->unique()->numerify('#####'),
            'customer_id' => 1,
            'quotation_date' => now()->toDateString(),
            'currency' => 'BDT',
            'total_amount' => 1000,
            'status' => 'draft',
        ];
    }
}
