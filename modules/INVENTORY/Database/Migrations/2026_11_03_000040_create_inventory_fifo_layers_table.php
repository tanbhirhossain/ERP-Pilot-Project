<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_fifo_layers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained('inventory_warehouses')->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->string('lot_no')->nullable()->index();
            $table->foreignId('stock_move_id')->constrained('inventory_stock_moves')->cascadeOnDelete();
            $table->decimal('qty_in', 15, 4);
            $table->decimal('qty_remaining', 15, 4);
            $table->decimal('unit_cost', 15, 4);
            $table->decimal('total_cost', 15, 2);
            $table->timestamp('received_at')->useCurrent()->index();
            $table->timestamps();
            $table->index(['product_id', 'warehouse_id', 'received_at']);
            $table->index(['product_id', 'variant_id', 'warehouse_id']);
        });

        // extend lots with valuation linkage for UI
        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->decimal('unit_cost', 15, 4)->default(0)->after('qty_remaining');
            $table->foreignId('warehouse_id')->nullable()->constrained('inventory_warehouses')->nullOnDelete()->after('supplier_partner_id');
            $table->foreignId('initial_move_id')->nullable()->constrained('inventory_stock_moves')->nullOnDelete()->after('warehouse_id');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_lots', function (Blueprint $table): void {
            $table->dropColumn(['unit_cost', 'warehouse_id', 'initial_move_id']);
        });
        Schema::dropIfExists('inventory_fifo_layers');
    }
};
