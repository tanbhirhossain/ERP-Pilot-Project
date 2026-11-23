<?php

namespace Modules\ACCOUNTS\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\AssetCategory;

/**
 * Makes the fixed-asset register usable: posting accounts under 1500 plus five starter
 * categories with their ledger mapping and depreciation defaults.
 *
 * Fully idempotent and safe to re-run: accounts are adopted into the tree like
 * AccountsDemoSeeder does, and categories are keyed on `code`. It is NOT wired into
 * DatabaseSeeder — run it where assets are wanted:
 *
 *     php artisan db:seed --class="Modules\ACCOUNTS\Database\Seeders\FixedAssetSetupSeeder"
 */
class FixedAssetSetupSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $fa = $this->ensureGroup('1500', 'Fixed Assets', 'asset', 'debit', '1000');
            $exp = $this->ensureGroup('5000', 'Expenses', 'expense', 'debit', null);

            $depExpense = $this->ensureAccount('5600', [
                'parent_id' => $exp->id, 'name' => 'Depreciation Expense',
                'account_type' => 'expense', 'nature' => 'debit',
            ]);

            $classes = [
                ['IT Equipment & Computers', 'IT', '1510', '1520', 'straight_line', 4, 5.0, 2.0],
                ['Office Equipment', 'OFFICE', '1511', '1521', 'straight_line', 5, 5.0, 2.0],
                ['Furniture & Fixtures', 'FURN', '1512', '1522', 'straight_line', 10, 10.0, 2.0],
                ['Vehicles', 'VEH', '1513', '1523', 'declining_balance', 5, 10.0, 2.0],
                ['Buildings', 'BLDG', '1514', '1524', 'straight_line', 20, 0.0, 2.0],
            ];

            foreach ($classes as [$name, $code, $assetCode, $accumCode, $method, $life, $salvagePct, $dbFactor]) {
                $assetAccount = $this->ensureAccount($assetCode, [
                    'parent_id' => $fa->id, 'name' => $name,
                    'account_type' => 'asset', 'nature' => 'debit',
                ]);
                $accumAccount = $this->ensureAccount($accumCode, [
                    'parent_id' => $fa->id, 'name' => "Accumulated Depreciation — {$name}",
                    'account_type' => 'asset', 'nature' => 'credit',
                ]);

                // Keyed on code: re-running adopts the row instead of doubling it. (AssetCategory
                // carries no soft deletes, so there is nothing trashed to revive.)
                AssetCategory::updateOrCreate(['code' => $code], [
                    'name' => $name,
                    'asset_account_id' => $assetAccount->id,
                    'accum_dep_account_id' => $accumAccount->id,
                    'dep_expense_account_id' => $depExpense->id,
                    'default_method' => $method,
                    'default_useful_life_years' => $life,
                    'default_salvage_pct' => $salvagePct,
                    'default_db_factor' => $dbFactor,
                    'is_active' => true,
                ]);
            }
        });
    }

    private function ensureGroup(string $code, string $name, string $type, string $nature, ?string $parentCode): Account
    {
        $parentId = $parentCode ? Account::withTrashed()->where('code', $parentCode)->value('id') : null;

        return $this->ensureAccount($code, [
            'parent_id' => $parentId, 'name' => $name, 'account_type' => $type,
            'nature' => $nature, 'is_group' => true, 'is_system' => true,
        ]);
    }

    /** Same adopt-don't-fail contract as AccountsDemoSeeder::ensureAccount. */
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
}
