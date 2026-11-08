<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_landed_costs', function (Blueprint $t) {
            $t->id();
            $t->string('lc_no')->unique(); // LC-00001
            $t->foreignId('receipt_id')->constrained('purchase_receipts')->cascadeOnDelete()->comment('GRN to allocate');
            $t->string('allocation_basis')->default('value')->comment('value|qty|weight');
            $t->enum('status', ['draft', 'allocated', 'cancelled'])->default('draft')->index();
            $t->decimal('total_cost', 15, 2)->default(0)->comment('Sum of lines');
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('posted_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('purchase_landed_cost_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('landed_cost_id')->constrained('purchase_landed_costs')->cascadeOnDelete();
            $t->string('name'); // Freight, Customs Duty, Insurance, Handling
            $t->string('reference_no')->nullable();
            $t->decimal('amount', 15, 2);
            $t->foreignId('account_id')->nullable()->constrained('acc_accounts')->nullOnDelete()->comment('Expense account for landed cost');
            $t->timestamps();
        });

        Schema::create('purchase_landed_cost_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('landed_cost_id')->constrained('purchase_landed_costs')->cascadeOnDelete();
            $t->foreignId('receipt_item_id')->constrained('purchase_receipt_items')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained('inventory_products')->restrictOnDelete();
            $t->decimal('qty', 12, 4);
            $t->decimal('base_value', 15, 2)->comment('Line value before allocation');
            $t->decimal('allocated_cost', 15, 2)->comment('Share of landed cost');
            $t->decimal('landed_unit_cost', 15, 4);
            $t->decimal('new_unit_cost', 15, 4)->comment('original + landed');
            $t->timestamps();
        });

        // BOM kitting / manufacturing orders
        Schema::create('inventory_kits', function (Blueprint $t) {
            $t->id();
            $t->string('kit_no')->unique(); // KIT-00001
            $t->foreignId('product_id')->constrained('inventory_products')->cascadeOnDelete()->comment('Finished kit/bundle');
            $t->foreignId('warehouse_id')->constrained('inventory_warehouses')->cascadeOnDelete();
            $t->decimal('qty_to_produce', 12, 4);
            $t->decimal('qty_produced', 12, 4)->default(0);
            $t->decimal('total_component_cost', 15, 2)->default(0);
            $t->decimal('unit_cost', 15, 4)->default(0);
            $t->enum('status', ['draft', 'in_progress', 'completed', 'cancelled'])->default('draft')->index();
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('inventory_kit_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('kit_id')->constrained('inventory_kits')->cascadeOnDelete();
            $t->foreignId('component_product_id')->constrained('inventory_products')->cascadeOnDelete();
            $t->decimal('required_qty', 12, 4);
            $t->decimal('consumed_qty', 12, 4)->default(0);
            $t->decimal('unit_cost', 15, 4)->default(0)->comment('Snapshot at production');
            $t->decimal('line_cost', 15, 2)->default(0);
            $t->boolean('is_optional')->default(false);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_kit_items');
        Schema::dropIfExists('inventory_kits');
        Schema::dropIfExists('purchase_landed_cost_allocations');
        Schema::dropIfExists('purchase_landed_cost_lines');
        Schema::dropIfExists('purchase_landed_costs');
    }
};
