<?php

namespace Modules\ACCOUNTS\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\Bank;
use Modules\ACCOUNTS\Models\FiscalYear;

/**
 * Seeds the Bangladesh standard Chart of Accounts, fiscal years (July–June)
 * with their monthly periods, and a demo bank.
 *
 * Fully idempotent and safe to re-run. It must be: a chart of accounts is content an operator
 * edits and extends, so this seeder adopts rows that already exist — restoring them into the
 * right place in the tree — instead of failing on the unique `code`.
 *
 * This seeder is the ONLY source of the chart. No migration provisions any account, and
 * `tests/Feature/CORE/MigrationDataSeparationTest.php` fails the build if one starts to.
 *
 * Historical bug (P0-B): this seeder began with `if (Account::exists()) return;`, and a PURCHASE
 * migration used to create account 2115 (GRNI) inside its `up()`. That single row made the guard
 * true, so on every fresh `migrate:fresh --seed` the ENTIRE COA and all fiscal years were skipped.
 * Downstream, `accountIdByCode('2100')` returned null and every posting service silently skipped
 * journal creation while still marking documents "posted" — an empty general ledger with no error
 * raised. Report item 38 moved that write here, where the rest of the chart already lived.
 */
class AccountsDemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedChartOfAccounts();
            $this->seedFiscalYears();
            $this->seedDemoBank();
        });
    }

    // ── Chart of Accounts ────────────────────────────────────────────────

    private function seedChartOfAccounts(): void
    {
        // Root groups
        $asset = $this->ensureAccount('1000', ['name' => 'Assets', 'account_type' => 'asset', 'nature' => 'debit', 'is_group' => true, 'is_system' => true]);
        $liab = $this->ensureAccount('2000', ['name' => 'Liabilities', 'account_type' => 'liability', 'nature' => 'credit', 'is_group' => true, 'is_system' => true]);
        $equity = $this->ensureAccount('3000', ['name' => 'Equity', 'account_type' => 'equity', 'nature' => 'credit', 'is_group' => true, 'is_system' => true]);
        $income = $this->ensureAccount('4000', ['name' => 'Income', 'account_type' => 'income', 'nature' => 'credit', 'is_group' => true, 'is_system' => true]);
        $expense = $this->ensureAccount('5000', ['name' => 'Expenses', 'account_type' => 'expense', 'nature' => 'debit', 'is_group' => true, 'is_system' => true]);

        $children = [
            // Assets
            [$asset, '1001', 'Cash in Hand', 'asset', 'debit', ['is_cash' => true]],
            [$asset, '1002', 'Cash at Bank', 'asset', 'debit', ['is_bank' => true, 'is_group' => true]],
            [$asset, '1100', 'Accounts Receivable', 'asset', 'debit', ['is_reconcile' => true]],
            [$asset, '1200', 'Inventory', 'asset', 'debit', []],
            [$asset, '1300', 'Advances & Prepayments', 'asset', 'debit', []],
            [$asset, '1310', 'TDS Receivable', 'asset', 'debit', []],
            [$asset, '1330', 'VAT Current Account (Input)', 'asset', 'debit', []],
            [$asset, '1400', 'Work in Progress', 'asset', 'debit', []],
            [$asset, '1500', 'Fixed Assets', 'asset', 'debit', ['is_group' => true]],
            // Liabilities
            [$liab, '2100', 'Accounts Payable', 'liability', 'credit', ['is_reconcile' => true]],
            [$liab, '2110', 'VAT Payable', 'liability', 'credit', []],
            // is_system: the migration that used to create this row set it, and losing it in the
            // extraction would have left the account GRNI accruals post to deletable from the
            // chart screen. is_posting needs no entry — Account's saving hook derives it.
            [$liab, '2115', 'Goods Received Not Invoiced (GRNI)', 'liability', 'credit', [
                'is_system' => true,
                'description' => 'Credited when goods are received against a purchase order, debited when the vendor bill is posted.',
            ]],
            [$liab, '2120', 'Accrued Expenses', 'liability', 'credit', []],
            [$liab, '2200', 'Loans & Borrowings', 'liability', 'credit', []],
            [$liab, '2310', 'TDS Payable', 'liability', 'credit', []],
            // Equity
            [$equity, '3100', 'Capital', 'equity', 'credit', ['is_system' => true]],
            [$equity, '3200', 'Retained Earnings', 'equity', 'credit', ['is_system' => true]],
            // Opening Balance Equity — the take-on suspense account (Phase 1 F4).
            // Opening COA/AR/AP/stock batches post their net here; the accountant
            // journals it to Retained Earnings once take-on is audited.
            [$equity, '3900', 'Opening Balance Equity', 'equity', 'credit', [
                'is_system' => true,
                'description' => 'Temporary suspense for go-live opening balances. Close to 3200 Retained Earnings after take-on is audited.',
            ]],
            // Income
            [$income, '4001', 'Sales Revenue', 'income', 'credit', []],
            [$income, '4002', 'Service Revenue', 'income', 'credit', []],
            [$income, '4900', 'Other Income', 'income', 'credit', []],
            // Expenses
            [$expense, '5001', 'Cost of Goods Sold', 'expense', 'debit', []],
            [$expense, '5100', 'Purchases', 'expense', 'debit', []],
            [$expense, '5101', 'Purchase Returns', 'expense', 'credit', []],
            [$expense, '5150', 'Carriage Inward (Freight)', 'expense', 'debit', []],
            [$expense, '5200', 'Salaries & Wages', 'expense', 'debit', []],
            [$expense, '5300', 'Rent Expense', 'expense', 'debit', []],
            [$expense, '5400', 'Utilities', 'expense', 'debit', []],
            [$expense, '5500', 'Bank Charges', 'expense', 'debit', []],
        ];

        foreach ($children as [$parent, $code, $name, $type, $nature, $extra]) {
            $this->ensureAccount($code, $extra + [
                'parent_id' => $parent->id,
                'name' => $name,
                'account_type' => $type,
                'nature' => $nature,
            ]);
        }

        // Bank sub-accounts
        $bankGroup = Account::where('code', '1002')->firstOrFail();
        foreach ([
            ['100201', 'Prime Bank — Main'],
            ['100202', 'DBBL — Operations'],
        ] as [$code, $name]) {
            $this->ensureAccount($code, [
                'parent_id' => $bankGroup->id,
                'name' => $name,
                'account_type' => 'asset',
                'nature' => 'debit',
                'is_bank' => true,
            ]);
        }
    }

    /**
     * Create the account if absent. If the row already exists — an operator created it, or an
     * older install still carries the one a migration used to write — adopt it into the correct
     * place in the tree rather than failing on the unique `code` constraint.
     */
    private function ensureAccount(string $code, array $attributes): Account
    {
        $account = Account::withTrashed()->where('code', $code)->first();

        if (! $account) {
            return Account::create($attributes + ['code' => $code, 'is_active' => true]);
        }

        $account->restore();

        $patch = array_filter([
            'parent_id' => $attributes['parent_id'] ?? null,
            'name' => $account->name ?: ($attributes['name'] ?? null),
            'account_type' => $account->account_type ?: ($attributes['account_type'] ?? null),
            'nature' => $account->nature ?: ($attributes['nature'] ?? null),
        ], fn ($v) => $v !== null && $v !== '');

        foreach (['is_system', 'is_group', 'is_bank', 'is_cash', 'is_reconcile'] as $flag) {
            if (! empty($attributes[$flag])) {
                $patch[$flag] = true;
            }
        }

        $patch['is_active'] = true;
        $account->fill($patch)->save();

        return $account->fresh();
    }

    // ── Fiscal years + monthly periods (BD July–June) ────────────────────

    private function seedFiscalYears(): void
    {
        $adminId = DB::table('users')->orderBy('id')->value('id');

        foreach ([
            ['name' => 'FY 2025-2026', 'code' => 'FY2526', 'start' => '2025-07-01', 'end' => '2026-06-30'],
            ['name' => 'FY 2026-2027', 'code' => 'FY2627', 'start' => '2026-07-01', 'end' => '2027-06-30'],
            ['name' => 'FY 2027-2028', 'code' => 'FY2728', 'start' => '2027-07-01', 'end' => '2028-06-30'],
        ] as $cfg) {
            $fy = FiscalYear::firstOrCreate(
                ['code' => $cfg['code']],
                [
                    'name' => $cfg['name'],
                    'start_date' => $cfg['start'],
                    'end_date' => $cfg['end'],
                    'status' => 'open',
                    'created_by' => $adminId,
                ]
            );

            $this->ensurePeriods($fy, $cfg['start'], $cfg['end']);
        }
    }

    private function ensurePeriods(FiscalYear $fy, string $start, string $end): void
    {
        $cursor = Carbon::parse($start)->startOfMonth();
        $last = Carbon::parse($end);

        while ($cursor->lte($last)) {
            $periodStart = $cursor->copy()->startOfMonth();
            $periodEnd = $cursor->copy()->endOfMonth();

            // Clamp to the fiscal year boundaries.
            if ($periodStart->lt(Carbon::parse($start))) {
                $periodStart = Carbon::parse($start);
            }
            if ($periodEnd->gt($last)) {
                $periodEnd = $last->copy();
            }

            // Key on month_no: that is the unique index
            // (acc_fiscal_periods_fiscal_year_id_month_no_unique). Keying on
            // start_date would miss existing rows and blow the constraint.
            $fy->periods()->firstOrCreate(
                ['month_no' => (int) $cursor->format('n')],
                [
                    'name' => $cursor->format('F Y'),
                    'start_date' => $periodStart->toDateString(),
                    'end_date' => $periodEnd->toDateString(),
                    'status' => 'open',
                ]
            );

            $cursor->addMonthNoOverflow();
        }
    }

    // ── Demo bank ────────────────────────────────────────────────────────

    private function seedDemoBank(): void
    {
        if (Bank::exists()) {
            return;
        }

        $coaBank = Account::where('code', '100201')->first();
        if (! $coaBank) {
            return;
        }

        Bank::create([
            'name' => 'Prime Bank Main',
            'code' => 'BANK-001',
            'bank_name' => 'Prime Bank Ltd',
            'branch_name' => 'Agrabad, Chattogram',
            'account_no' => '1234567890123',
            'account_holder' => 'ERP Pilot Ltd',
            'account_id' => $coaBank->id,
            'currency' => 'BDT',
            'opening_balance' => 500000,
            'current_balance' => 500000,
            'is_active' => true,
            'created_by' => DB::table('users')->orderBy('id')->value('id'),
        ]);
    }
}
