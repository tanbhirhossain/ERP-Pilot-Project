<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A vendor bill can buy fixed assets, not just goods.
 *
 * Each line carries a `line_type`: `goods` posts exactly as before, while `fixed_asset` lines
 * carry the register fields (category, name, life, method, salvage, in-service date, location)
 * so posting the bill capitalises the asset in the same transaction — no second registration,
 * and the register can never disagree with the ledger about what was bought.
 *
 * Schema only. No row is written here: content belongs in seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_bill_items', function (Blueprint $t): void {
            if (! Schema::hasColumn('purchase_bill_items', 'line_type')) {
                $t->string('line_type', 20)->default('goods')->after('cost_price')
                    ->comment('goods | fixed_asset');
            }
            if (! Schema::hasColumn('purchase_bill_items', 'asset_category_id')) {
                $t->unsignedBigInteger('asset_category_id')->nullable()->after('line_type');
            }
            if (! Schema::hasColumn('purchase_bill_items', 'asset_name')) {
                $t->string('asset_name', 150)->nullable()->after('asset_category_id');
            }
            if (! Schema::hasColumn('purchase_bill_items', 'asset_method')) {
                $t->string('asset_method', 30)->nullable()->after('asset_name')
                    ->comment('straight_line | declining_balance; blank inherits the category default');
            }
            if (! Schema::hasColumn('purchase_bill_items', 'asset_useful_life_years')) {
                $t->unsignedInteger('asset_useful_life_years')->nullable()->after('asset_method');
            }
            if (! Schema::hasColumn('purchase_bill_items', 'asset_salvage_value')) {
                $t->decimal('asset_salvage_value', 15, 2)->nullable()->after('asset_useful_life_years');
            }
            if (! Schema::hasColumn('purchase_bill_items', 'asset_db_factor')) {
                $t->decimal('asset_db_factor', 8, 2)->nullable()->after('asset_salvage_value');
            }
            if (! Schema::hasColumn('purchase_bill_items', 'asset_in_service_date')) {
                $t->date('asset_in_service_date')->nullable()->after('asset_db_factor');
            }
            if (! Schema::hasColumn('purchase_bill_items', 'asset_location')) {
                $t->string('asset_location', 160)->nullable()->after('asset_in_service_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_bill_items', function (Blueprint $t): void {
            foreach (['asset_location', 'asset_in_service_date', 'asset_db_factor', 'asset_salvage_value', 'asset_useful_life_years', 'asset_method', 'asset_name', 'asset_category_id', 'line_type'] as $col) {
                if (Schema::hasColumn('purchase_bill_items', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
