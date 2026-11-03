<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_moves', function (Blueprint $table): void {
            $table->id();
            $table->string('move_no')->unique();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('inventory_warehouses')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->decimal('qty', 15, 4);
            $table->enum('direction', ['in', 'out'])->default('in');
            $table->foreignId('uom_id')->nullable()->constrained('inventory_uoms')->nullOnDelete();
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->string('reference_type')->default('manual');
            $table->string('reference_no')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('lot_no')->nullable();
            $table->string('serial_no')->nullable();
            $table->text('reason')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('moved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moved_at')->useCurrent();
            $table->timestamps();
            $table->index(['product_id', 'moved_at']);
            $table->index(['warehouse_id', 'product_id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['lot_no']);
            $table->index(['move_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_stock_moves');
    }
};
