<?php

namespace Modules\INVENTORY\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\INVENTORY\Models\Product;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        $name = $this->faker->words(3, true);
        $prefix = config('inventory.sku_prefix', 'SKU-');
        return [
            'name' => Str::title($name),
            'sku' => $prefix . str_pad((string) $this->faker->unique()->numberBetween(1, 9999), 5, '0', STR_PAD_LEFT),
            'barcode' => '880' . $this->faker->numerify('##########'),
            'category_id' => \Modules\INVENTORY\Models\Category::inRandomOrder()->first()?->id,
            'brand_id' => \Modules\INVENTORY\Models\Brand::inRandomOrder()->first()?->id,
            'uom_id' => \Modules\INVENTORY\Models\Uom::inRandomOrder()->first()?->id,
            'type' => $this->faker->randomElement(['stockable', 'stockable', 'stockable', 'consumable']),
            'description' => $this->faker->sentence(10),
            'cost_price' => $this->faker->randomFloat(2, 10, 500),
            'selling_price' => $this->faker->randomFloat(2, 20, 800),
            'mrp' => $this->faker->randomFloat(2, 20, 1000),
            'vat_rate' => $this->faker->randomElement([0, 5, 15]),
            'reorder_level' => $this->faker->numberBetween(5, 20),
            'reorder_qty' => $this->faker->numberBetween(20, 100),
            'valuation_method' => $this->faker->randomElement(['weighted', 'weighted', 'fifo']),
            'is_active' => true,
            'status' => 'active',
        ];
    }
}
