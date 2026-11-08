<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_product_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->string('variant_code')->unique();
            $table->string('name');
            $table->json('attributes')->nullable();
            $table->string('sku')->nullable()->unique();
            $table->string('barcode')->nullable()->unique();
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->decimal('selling_price', 15, 2)->default(0);
            $table->decimal('mrp', 15, 2)->default(0);
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['product_id', 'is_active']);
        });

        Schema::create('inventory_product_suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->foreignId('partner_id')->constrained('partners')->cascadeOnDelete();
            $table->string('supplier_sku')->nullable();
            $table->decimal('last_purchase_price', 15, 2)->nullable();
            $table->unsignedSmallInteger('lead_time_days')->default(0);
            $table->decimal('min_order_qty', 12, 2)->default(1);
            $table->boolean('is_primary')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'partner_id']);
            $table->index(['partner_id']);
        });

        Schema::create('inventory_boms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->foreignId('component_product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->decimal('qty', 12, 4);
            $table->foreignId('uom_id')->nullable()->constrained('inventory_uoms')->nullOnDelete();
            $table->boolean('is_optional')->default(false);
            $table->decimal('wastage_pct', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'component_product_id']);
        });

        Schema::create('inventory_product_images', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('file_name')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['product_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_product_images');
        Schema::dropIfExists('inventory_boms');
        Schema::dropIfExists('inventory_product_suppliers');
        Schema::dropIfExists('inventory_product_variants');
    }
};
