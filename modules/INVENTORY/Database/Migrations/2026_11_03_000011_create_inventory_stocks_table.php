<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('inventory_warehouses')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->string('lot_no')->nullable();
            $table->string('serial_no')->nullable();
            $table->decimal('qty_on_hand', 15, 4)->default(0);
            $table->decimal('qty_reserved', 15, 4)->default(0);
            $table->decimal('qty_available', 15, 4)->default(0); // computed: on_hand - reserved
            $table->decimal('avg_cost', 15, 4)->default(0);
            $table->decimal('total_value', 15, 2)->default(0); // on_hand * avg_cost
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'variant_id', 'warehouse_id', 'location_id', 'lot_no'], 'inv_stocks_unique');
            $table->index(['warehouse_id', 'product_id']);
            $table->index(['product_id', 'qty_on_hand']);
            $table->index(['lot_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stocks');
    }
};
