<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assets born from a vendor bill remember which bill and line made them.
 *
 * The link is what lets a bill void cascade: fresh assets (no depreciation, not disposed) are
 * removed with the void, while depreciated ones block it by name. Manually registered assets
 * leave both columns NULL and are untouched by purchase flows.
 *
 * Schema only. No row is written here: content belongs in seeders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_fixed_assets', function (Blueprint $t): void {
            if (! Schema::hasColumn('acc_fixed_assets', 'bill_id')) {
                $t->unsignedBigInteger('bill_id')->nullable()->after('invoice_ref');
            }
            if (! Schema::hasColumn('acc_fixed_assets', 'bill_item_id')) {
                $t->unsignedBigInteger('bill_item_id')->nullable()->after('bill_id');
            }
        });

        $indexes = collect(Schema::getIndexes('acc_fixed_assets'))->mapWithKeys(fn ($i) => [strtolower($i['name']) => true]);

        if (! $indexes->has('acc_fixed_assets_bill_id_index')) {
            Schema::table('acc_fixed_assets', function (Blueprint $t): void {
                $t->index('bill_id', 'acc_fixed_assets_bill_id_index');
            });
        }
    }

    public function down(): void
    {
        $indexes = collect(Schema::getIndexes('acc_fixed_assets'))->mapWithKeys(fn ($i) => [strtolower($i['name']) => true]);

        Schema::table('acc_fixed_assets', function (Blueprint $t) use ($indexes): void {
            if ($indexes->has('acc_fixed_assets_bill_id_index')) {
                $t->dropIndex('acc_fixed_assets_bill_id_index');
            }
            foreach (['bill_item_id', 'bill_id'] as $col) {
                if (Schema::hasColumn('acc_fixed_assets', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
