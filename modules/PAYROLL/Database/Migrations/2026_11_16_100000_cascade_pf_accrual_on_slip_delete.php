<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make a deleted salary slip take its provident-fund accrual with it.
 *
 * The schema cannot express this relation. `pf_ledgers` reaches a slip through a polymorphic pair
 * (`reference_type`/`reference_id`) — the same columns also point at `pf_withdrawals` — so there is no
 * foreign key to hang `ON DELETE CASCADE` on, which is why deleting payroll left the accrual behind
 * and P19b had to repair 3,433 rows by hand.
 *
 * A model event would not be enough on its own: `PayrollSlip::where(...)->delete()` is a single SQL
 * statement and Eloquent does not fire model events for mass deletes. A trigger sees every row
 * regardless of which path removed it, which is the whole point of putting it here rather than in
 * PHP.
 *
 * Settled rows are deliberately left alone. A settled contribution has cash behind it in the fund;
 * deleting it would make the ledger agree with a payroll that no longer exists while disagreeing
 * with the bank. Leaving them means the orphan shows up in `PfLedgerIntegrityService::audit()` and
 * somebody has to decide — which is the correct outcome for money that has actually moved.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            // The application targets SQLite today. On a server database the same guarantee belongs
            // in a foreign key or an application-level cascade, not in a trigger written in SQLite's
            // dialect — so this migration is a no-op there rather than a silent half-measure.
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE TRIGGER IF NOT EXISTS payroll_slips_pf_accrual_cascade
AFTER DELETE ON payroll_slips
FOR EACH ROW
BEGIN
    DELETE FROM pf_ledgers
     WHERE reference_type = 'payroll_slip'
       AND reference_id = OLD.id
       AND status NOT IN ('settled', 'paid');
END;
SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS payroll_slips_pf_accrual_cascade');
    }
};
