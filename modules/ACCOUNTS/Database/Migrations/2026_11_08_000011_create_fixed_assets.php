<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F15 — Fixed assets & depreciation.
 *
 * Four tables:
 *   acc_asset_categories        — an asset class that maps to the ledger: the gross fixed-asset
 *                                 account, the accumulated-depreciation (contra) account, and the
 *                                 depreciation-expense account, plus the default method / life /
 *                                 salvage / declining-balance factor. Putting the COA on the
 *                                 category (not the asset) is what makes a run post correctly for
 *                                 a whole class at once.
 *   acc_fixed_assets            — the register. One row per depreciable asset. `net_book_value` and
 *                                 `accumulated_dep` are denormalised running balances, maintained by
 *                                 the depreciation engine; `status` stops a disposed asset depreciating.
 *   acc_depreciation_runs       — one header per accounting period (YYYY-MM). A run writes a single
 *                                 balanced journal (Dr expense, Cr accumulated depreciation), so the
 *                                 whole month is one auditable posting.
 *   acc_asset_depreciations     — one line per asset per period: the opening NBV, the charge, and the
 *                                 running accumulated / NBV after. Unique (asset, period) is the
 *                                 idempotency guard — a month can be computed once per asset.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_asset_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 40)->unique();
            $table->foreignId('asset_account_id')->constrained('acc_accounts')->restrictOnDelete()
                ->comment('Gross fixed-asset (cost) account');
            $table->foreignId('accum_dep_account_id')->constrained('acc_accounts')->restrictOnDelete()
                ->comment('Accumulated depreciation (contra-asset)');
            $table->foreignId('dep_expense_account_id')->constrained('acc_accounts')->restrictOnDelete()
                ->comment('Depreciation expense (P&L)');
            $table->enum('default_method', ['straight_line', 'declining_balance'])->default('straight_line');
            $table->unsignedInteger('default_useful_life_years')->default(5);
            $table->decimal('default_salvage_pct', 5, 2)->default(0)->comment('Salvage as % of cost when the asset does not override it');
            $table->decimal('default_db_factor', 4, 2)->default(2.00)->comment('Declining-balance multiplier (2.00 = double-declining)');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('acc_fixed_assets', function (Blueprint $table): void {
            $table->id();
            $table->string('asset_code', 40)->unique();
            $table->string('name');
            $table->foreignId('category_id')->constrained('acc_asset_categories')->restrictOnDelete();
            $table->foreignId('supplier_partner_id')->nullable()->constrained('partners')->nullOnDelete();
            $table->string('invoice_ref', 60)->nullable();
            $table->date('purchase_date')->index();
            $table->date('in_service_date')->nullable()->comment('Depreciation starts here; defaults to purchase_date');
            $table->decimal('cost', 15, 2);
            $table->decimal('salvage_value', 15, 2)->default(0);
            $table->enum('method', ['straight_line', 'declining_balance'])->nullable()->comment('Null = inherit category default');
            $table->unsignedInteger('useful_life_years')->nullable()->comment('Null = inherit category default');
            $table->decimal('db_factor', 4, 2)->nullable()->comment('Null = inherit category default');
            $table->decimal('accumulated_dep', 15, 2)->default(0);
            $table->decimal('net_book_value', 15, 2)->default(0)->comment('cost - accumulated_dep; maintained by the engine');
            $table->enum('status', ['draft', 'in_use', 'fully_depreciated', 'disposed'])->default('draft')->index();
            $table->string('location', 160)->nullable();
            $table->date('disposed_date')->nullable();
            $table->decimal('disposal_proceeds', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['category_id', 'status']);
        });

        Schema::create('acc_depreciation_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('run_no', 40)->unique();
            $table->string('period', 7)->unique()->comment('YYYY-MM; one run per month');
            $table->date('run_date')->comment('Posting date = last day of the period');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->unsignedInteger('line_count')->default(0);
            $table->foreignId('journal_id')->nullable()->constrained('acc_journals')->nullOnDelete();
            $table->string('status', 20)->default('posted');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('acc_asset_depreciations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('acc_depreciation_runs')->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained('acc_fixed_assets')->cascadeOnDelete();
            $table->string('period', 7)->index();
            $table->decimal('opening_nbv', 15, 2);
            $table->decimal('charge', 15, 2);
            $table->decimal('accumulated_after', 15, 2);
            $table->decimal('closing_nbv', 15, 2);
            $table->enum('method', ['straight_line', 'declining_balance']);
            $table->string('basis', 160)->nullable()->comment('Human-readable formula snapshot for the line');
            $table->timestamps();

            $table->unique(['asset_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_asset_depreciations');
        Schema::dropIfExists('acc_depreciation_runs');
        Schema::dropIfExists('acc_fixed_assets');
        Schema::dropIfExists('acc_asset_categories');
    }
};
