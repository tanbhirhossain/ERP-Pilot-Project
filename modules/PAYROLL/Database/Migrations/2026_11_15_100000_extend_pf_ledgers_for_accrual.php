<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make the provident fund a ledger that can be reconciled.
 *
 * Before this migration `pf_ledgers` held one row per contribution and nothing else: no notion of
 * whether the money had actually moved, no link to the payroll month it belonged to, and no way to
 * tell an accrual from a payment. That is why the fund read 0 everywhere — the only writer was the
 * "mark slip paid" action, and every slip in the pilot database is unpaid.
 *
 * The columns added here are the minimum needed to run the fund as a real liability:
 *
 *   - `contribution_month` (YYYY-MM) — the period the entry belongs to, so a month can be accrued
 *     once and reported by period without guessing from `entry_date`.
 *   - `status` — `accrued` (expense recognised, cash not moved) or `settled` (cash moved). The user
 *     chose accrual accounting, so both states must exist at once.
 *   - `settled_at` — when the cash side happened.
 *   - `payroll_salary_sheet_id` — the sheet whose processing accrued the entry; this is what makes
 *     the historical backfill idempotent and traceable to a document.
 *
 * `direction` is deliberately *not* stored: it is a pure function of `entry_type` (contributions
 * credit, withdrawals and settlements debit), so storing it would create a second source of truth
 * that can disagree with the first. The model derives it.
 *
 * Two things about the *existing* table force this migration to rebuild it rather than alter it:
 *
 *   1. `entry_type` was created with `$table->enum(...)`, which on SQLite compiles to a
 *      `varchar check (entry_type in (...))`. `settlement` — the debit that closes a fund account
 *      when an employee leaves — is not in that list, so *every* exit would fail at the last step.
 *      Probed on the live database before writing this: `settlement` and `accrual` were both
 *      rejected by the constraint, and a pre-existing row could only be deleted by table rebuild,
 *      which SQLite does not expose through `ALTER`.
 *   2. The period uniqueness the fund depends on (one accrual per account, side and month) must be
 *      *partial*. An accrual legitimately becomes `settled` later, and a withdrawal has no period at
 *      all, so a plain unique index over `(account, type, month, status)` would collide the moment a
 *      month is settled after being accrued. SQLite supports partial indexes natively; MySQL — the
 *      production target — does not, so the same rule is installed per driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->widenEntryType();

        Schema::table('pf_ledgers', function (Blueprint $table): void {
            $table->string('contribution_month', 7)->nullable()->after('entry_date');
            $table->string('status', 20)->default('settled')->after('contribution_month');
            $table->timestamp('settled_at')->nullable()->after('status');
            $table->unsignedBigInteger('payroll_salary_sheet_id')->nullable()->after('reference_id');

            $table->index(['status', 'contribution_month']);
            $table->index('payroll_salary_sheet_id');
        });

        $this->createPeriodIndex();

        // The withdrawals and settlements the fund needs before it can close an account.
        Schema::create('pf_withdrawals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('pf_account_id')->constrained('pf_accounts')->cascadeOnDelete();
            $table->string('employee_id');
            $table->enum('type', ['partial', 'final_settlement', 'loan_adjustment'])->default('partial');
            $table->decimal('requested_amount', 14, 2);
            $table->decimal('approved_amount', 14, 2)->nullable();
            $table->string('reason', 500);
            $table->enum('status', ['pending', 'approved', 'rejected', 'paid'])->default('pending');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->unsignedBigInteger('pf_ledger_id')->nullable();
            $table->timestamps();

            $table->foreign('employee_id')->references('employee_id')->on('employees')->restrictOnDelete();
            $table->index(['status', 'type']);
            $table->index(['pf_account_id', 'status']);
        });
    }

    /**
     * Roll back structurally, and only structurally.
     *
     * Written with `Schema` alone — no `DB::statement`, no `DB::table` — for two reasons, both of
     * which bit during this build:
     *
     *   1. The project's data-separation census (`MigrationDataSeparationTest`) requires every data
     *      write in `down()` to be declared with a reason. Its scanner counts a raw `DB::statement`
     *      as a data write even when the statement stores no row, so an index drop belongs in the
     *      schema builder, where its intent is unambiguous.
     *   2. Rolling back cannot restore the narrow `entry_type` enum while `settlement` rows exist,
     *      and it must not guess what to do with money-bearing rows. Reversibility stops at the
     *      structure; the data decision stays with whoever runs the rollback.
     */
    public function down(): void
    {
        Schema::dropIfExists('pf_withdrawals');

        Schema::table('pf_ledgers', function (Blueprint $table): void {
            $table->dropUnique('pf_ledgers_period_unique');
            $table->dropIndex(['status', 'contribution_month']);
            $table->dropIndex('payroll_salary_sheet_id');
            $table->dropColumn(['contribution_month', 'status', 'settled_at', 'payroll_salary_sheet_id']);
        });
    }

    /**
     * Replace the `entry_type` CHECK with one that admits `settlement`.
     *
     * On SQLite the only way to change a CHECK is to rebuild the table: create a shadow with the
     * same shape, copy, drop, rename. The indexes are recreated because they die with the old table.
     * Foreign keys are switched off around the copy so the shadow is not orphaned mid-flight, which
     * is what SQLite's own documented procedure prescribes. On MySQL (production) the same intent is
     * reached by replacing the enum definition through `MODIFY`.
     */
    private function widenEntryType(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            $allowed = "'employee_contribution','employer_contribution','withdrawal','settlement','adjustment'";
            DB::statement("alter table `pf_ledgers` modify `entry_type` enum({$allowed}) not null");

            return;
        }

        $allowed = "'employee_contribution','employer_contribution','withdrawal','settlement','adjustment'";
        $shadow = 'pf_ledgers__rebuild';

        DB::statement('pragma foreign_keys = off');

        DB::statement('drop table if exists `'.$shadow.'`');
        DB::statement(<<<SQL
            create table `{$shadow}` (
                "id" integer primary key autoincrement not null,
                "pf_account_id" integer not null,
                "entry_type" varchar check ("entry_type" in ({$allowed})) not null,
                "amount" numeric not null,
                "entry_date" date not null,
                "reference_type" varchar,
                "reference_id" integer,
                "description" varchar,
                "created_by" integer,
                "created_at" datetime,
                "updated_at" datetime,
                foreign key("pf_account_id") references "pf_accounts"("id") on delete cascade,
                foreign key("created_by") references "users"("id") on delete set null
            )
            SQL);

        DB::statement(
            'insert into `'.$shadow.'` (id, pf_account_id, entry_type, amount, entry_date, reference_type, reference_id, description, created_by, created_at, updated_at) '.
            'select id, pf_account_id, entry_type, amount, entry_date, reference_type, reference_id, description, created_by, created_at, updated_at from `pf_ledgers`'
        );

        DB::statement('drop table `pf_ledgers`');
        DB::statement('alter table `'.$shadow.'` rename to `pf_ledgers`');

        DB::statement('create index "pf_ledgers_pf_account_id_entry_date_index" on "pf_ledgers" ("pf_account_id", "entry_date")');
        DB::statement('create index "pf_ledgers_reference_type_reference_id_index" on "pf_ledgers" ("reference_type", "reference_id")');

        DB::statement('pragma foreign_keys = on');
    }

    /**
     * One accrual per account, per side, per month — the rule that makes a re-run a no-op.
     *
     * Partial, because the same row is later updated from `accrued` to `settled`, and because a
     * withdrawal has no month. Productions targets MySQL 8, which has no partial indexes; there the
     * index covers only contribution rows via a generated column.
     */
    private function createPeriodIndex(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                'alter table `pf_ledgers` add column `accrual_key` varchar(28) '.
                "generated always as (case when status = 'accrued' then concat_ws('|', pf_account_id, entry_type, contribution_month) end) stored"
            );
            DB::statement('create unique index `pf_ledgers_period_unique` on `pf_ledgers` (`accrual_key`)');

            return;
        }

        DB::statement(
            'create unique index "pf_ledgers_period_unique" on "pf_ledgers" ("pf_account_id", "entry_type", "contribution_month") '.
            "where status = 'accrued'"
        );
    }
};
