<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->string('count_no')->unique();
            $table->foreignId('warehouse_id')->constrained('inventory_warehouses')->cascadeOnDelete();
            $table->enum('status', ['draft', 'counting', 'pending_review', 'reconciled', 'cancelled'])->default('draft');
            $table->date('count_date');
            $table->text('notes')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['warehouse_id', 'status']);
        });

        Schema::create('inventory_count_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('count_id')->constrained('inventory_stock_counts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('inventory_locations')->nullOnDelete();
            $table->decimal('system_qty', 15, 4)->default(0);
            $table->decimal('counted_qty', 15, 4)->nullable();
            $table->decimal('variance', 15, 4)->default(0);
            $table->decimal('variance_cost', 15, 2)->default(0);
            $table->enum('action', ['none', 'adjust', 'recount'])->default('none');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['count_id', 'product_id']);
        });

        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->id();
            $table->string('lot_no')->unique();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('supplier_lot_no')->nullable();
            $table->foreignId('supplier_partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->decimal('qty_received', 15, 4)->default(0);
            $table->decimal('qty_remaining', 15, 4)->default(0);
            $table->boolean('is_expired')->default(false);
            $table->timestamps();
            $table->index(['product_id', 'expiry_date']);
        });

        Schema::create('inventory_serials', function (Blueprint $table): void {
            $table->id();
            $table->string('serial_no')->unique();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('inventory_product_variants')->nullOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('inventory_lots')->nullOnDelete();
            $table->foreignId('warehouse_id')->nullable()->constrained('inventory_warehouses')->nullOnDelete();
            $table->enum('status', ['in_stock', 'reserved', 'sold', 'returned', 'damaged', 'expired'])->default('in_stock');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['product_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_serials');
        Schema::dropIfExists('inventory_lots');
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_stock_counts');
    }
};
