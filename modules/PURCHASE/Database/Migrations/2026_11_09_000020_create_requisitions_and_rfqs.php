<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F18 — purchase requisition + RFQ: the request → quote → compare → award cycle that currently
 * doesn't exist, so procurement starts (wrongly) at the purchase order.
 *
 * Six tables, in three pairs. Each pair is a header and its lines, and each pair is *incomplete on
 * purpose* — nothing here pretends to be the document that commits money:
 *
 *   purchase_requisitions / _items      — an internal request. Estimated, not priced. `product_id`
 *                                        is nullable because a request is often exactly "we need a
 *                                        thing we don't stock"; the description is what carries it.
 *   purchase_rfqs / _rfq_lines          — what we asked the market for, one line per requested item.
 *                                        `requisition_id` is nullable: an RFQ can stand alone as a
 *                                        sourcing enquiry.
 *   purchase_rfq_quotes / _quote_items  — one vendor's answer, and its per-line price. Unique
 *                                        (rfq, vendor): one vendor, one answer per round.
 *
 * Money is only ever *derived* here: every total column is computed by the service from its lines and
 * is recomputed on every write, exactly like `PurchaseOrderService::enrichTotals()`. A quote stores no
 * VAT on purpose — a comparison is between pre-tax offers, and tax is settled on the bill (F17's
 * withholding and Mushak lines already read that side).
 *
 * The awarded PO is created in `draft`, never confirmed: confirming is a different permission, runs
 * the approval matrix again, and is the point where a commitment becomes real. Keeping the award short
 * of it means this slice adds a front end to procurement without inventing a second path to the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_requisitions', function (Blueprint $table): void {
            $table->id();
            $table->string('req_no')->unique();
            $table->string('title');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // A string, not an HRM FK — the same call `acc_budgets.department` makes: a requisition outlives
            // a reorganisation, and a name on a document is a record, not a live relation.
            $table->string('department', 120)->nullable();
            $table->foreignId('warehouse_id')->constrained('inventory_warehouses')->cascadeOnUpdate()->restrictOnDelete();
            $table->date('need_by')->nullable()->index();
            $table->text('justification')->nullable();
            // The expense head this will land against. Optional, because most requests come from a
            // storekeeper who does not know the COA — but when it *is* set, submitting checks the budget.
            $table->foreignId('account_id')->nullable()->constrained('acc_accounts')->nullOnDelete();
            $table->decimal('estimated_amount', 15, 2)->default(0);
            $table->enum('status', ['draft', 'submitted', 'approved', 'rejected', 'converted', 'cancelled'])
                ->default('draft')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->foreignId('converted_order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'need_by']);
        });

        Schema::create('purchase_requisition_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('inventory_products')->cascadeOnUpdate()->nullOnDelete();
            $table->string('description');
            $table->foreignId('uom_id')->nullable()->constrained('inventory_uoms')->nullOnDelete();
            $table->decimal('qty', 15, 2)->default(0);
            $table->decimal('expected_unit_price', 15, 2)->default(0);
            $table->decimal('line_estimate', 15, 2)->default(0);
            // What an award has already bought against this line. This is what makes a second RFQ over
            // the same requisition safe rather than a duplicate order waiting to happen.
            $table->decimal('qty_ordered', 15, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_rfqs', function (Blueprint $table): void {
            $table->id();
            $table->string('rfq_no')->unique();
            $table->foreignId('requisition_id')->nullable()->constrained('purchase_requisitions')->nullOnDelete();
            $table->string('subject');
            $table->enum('status', ['draft', 'sent', 'closed', 'cancelled'])->default('draft')->index();
            $table->date('due_on')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->text('notes')->nullable();
            // The round is decided once. `awarded_quote_id` is what makes a second award impossible
            // without asking anyone to remember to close the RFQ. It is *not* a foreign key: the quotes
            // table already points here, and an FK in both directions is a circular constraint that no
            // driver agrees on how to drop. The service keeps it honest, and a test proves it.
            $table->unsignedBigInteger('awarded_quote_id')->nullable();
            $table->timestamp('awarded_at')->nullable();
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'due_on']);
        });

        Schema::create('purchase_rfq_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('purchase_rfqs')->cascadeOnDelete();
            $table->foreignId('requisition_item_id')->nullable()->constrained('purchase_requisition_items')->nullOnDelete();
            $table->string('description');
            $table->decimal('qty', 15, 2)->default(0);
            $table->foreignId('uom_id')->nullable()->constrained('inventory_uoms')->nullOnDelete();
            $table->timestamps();

            $table->index('rfq_id');
        });

        Schema::create('purchase_rfq_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('purchase_rfqs')->cascadeOnDelete();
            $table->foreignId('vendor_id')->constrained('partners')->cascadeOnUpdate()->restrictOnDelete();
            $table->string('quote_ref')->nullable();
            $table->date('quoted_on')->nullable();
            // A quote past its own validity is refused at award, not quietly honoured.
            $table->date('valid_until')->nullable();
            $table->enum('status', ['received', 'withdrawn'])->default('received');
            $table->decimal('total_amount', 15, 2)->default(0);
            // Integer, deliberately: `days` matches the money-column pattern, and a lead time is not money.
            $table->unsignedInteger('lead_time_days')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['rfq_id', 'vendor_id']);
        });

        Schema::create('purchase_rfq_quote_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quote_id')->constrained('purchase_rfq_quotes')->cascadeOnDelete();
            $table->foreignId('rfq_line_id')->constrained('purchase_rfq_lines')->cascadeOnDelete();
            $table->decimal('unit_price', 15, 2);
            // A vendor may offer less than was asked for; the comparison has to be able to say so.
            $table->decimal('qty', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->unique(['quote_id', 'rfq_line_id']);
        });

        // The awarded quote names the lines it won, per requisition line, because a vendor can be
        // chosen for *some* lines. One row per (requisition line, winning quote).
        Schema::create('purchase_rfq_awards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rfq_id')->constrained('purchase_rfqs')->cascadeOnDelete();
            $table->foreignId('quote_id')->constrained('purchase_rfq_quotes')->cascadeOnDelete();
            $table->foreignId('rfq_line_id')->constrained('purchase_rfq_lines')->cascadeOnDelete();
            $table->foreignId('requisition_item_id')->nullable()->constrained('purchase_requisition_items')->nullOnDelete();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('qty', 15, 2);
            $table->foreignId('order_id')->nullable()->constrained('purchase_orders')->nullOnDelete();
            $table->timestamps();

            // One award per requested line, ever. Re-awarding is a deliberate act (a new RFQ round),
            // not an edit that silently re-prices yesterday's PO.
            $table->unique('rfq_line_id');
            $table->index('rfq_id');
        });

        // The other side of the audit trail: the PO knows what it answered.
        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('purchase_orders', 'requisition_id')) {
                // No explicit index: every driver that matters here builds one for the FK, and a second
                // index on the same column is a rollback hazard for no read speed-up.
                $table->foreignId('requisition_id')->nullable()->after('warehouse_id')
                    ->constrained('purchase_requisitions')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // The PO side first: `purchase_orders.requisition_id` points at the requisition table, so it
        // has to be gone before that table is. Index, then FK, then column — the order MySQL requires,
        // and harmless on SQLite where the pair is a no-op.
        Schema::table('purchase_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('purchase_orders', 'requisition_id')) {
                $table->dropForeign(['requisition_id']);
                $table->dropColumn('requisition_id');
            }
        });

        // Children before parents, exactly the reverse of `up()`.
        foreach ([
            'purchase_rfq_awards',
            'purchase_rfq_quote_items',
            'purchase_rfq_quotes',
            'purchase_rfq_lines',
            'purchase_rfqs',
            'purchase_requisition_items',
            'purchase_requisitions',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
