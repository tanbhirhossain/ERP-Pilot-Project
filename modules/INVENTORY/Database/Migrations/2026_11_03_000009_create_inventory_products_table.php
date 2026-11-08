<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique();
            $table->string('barcode')->nullable()->unique();
            $table->string('slug')->nullable()->unique();
            $table->foreignId('category_id')->nullable()->constrained('inventory_categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('inventory_brands')->nullOnDelete();
            $table->foreignId('uom_id')->nullable()->constrained('inventory_uoms')->nullOnDelete();
            $table->enum('type', ['stockable', 'consumable', 'service', 'bundle'])->default('stockable');
            $table->text('description')->nullable();
            $table->string('short_description')->nullable();
            $table->string('hs_code')->nullable();
            $table->decimal('weight', 10, 3)->nullable();
            $table->string('weight_uom')->nullable();
            $table->string('dimensions')->nullable();
            $table->boolean('is_serialized')->default(false);
            $table->boolean('is_batch_tracked')->default(false);
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
            $table->decimal('reorder_level', 12, 2)->default(0);
            $table->decimal('reorder_qty', 12, 2)->default(0);
            $table->enum('valuation_method', ['weighted', 'fifo'])->default('weighted');
            // Pricing
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->decimal('mrp', 15, 2)->default(0);
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->string('currency', 3)->default('BDT');
            // Media
            $table->string('image')->nullable();
            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->enum('status', ['draft', 'active', 'discontinued'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['category_id', 'is_active']);
            $table->index(['brand_id']);
            $table->index(['type', 'status']);
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->fullText(['name', 'sku', 'barcode']);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_products');
    }
};
