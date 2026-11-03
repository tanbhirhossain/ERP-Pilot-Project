<?php

namespace Modules\ACCOUNTS\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\ACCOUNTS\Models\Account;
use Modules\ACCOUNTS\Models\FiscalYear;

class AccountsDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Account::exists()) return;

        // ── Root groups (Bangladesh standard COA) ────────────────────────
        $roots = [
            ['code' => '1000', 'name' => 'Assets', 'account_type' => 'asset', 'nature' => 'debit', 'is_group' => true],
            ['code' => '2000', 'name' => 'Liabilities', 'account_type' => 'liability', 'nature' => 'credit', 'is_group' => true],
            ['code' => '3000', 'name' => 'Equity', 'account_type' => 'equity', 'nature' => 'credit', 'is_group' => true],
            ['code' => '4000', 'name' => 'Income', 'account_type' => 'income', 'nature' => 'credit', 'is_group' => true],
            ['code' => '5000', 'name' => 'Expenses', 'account_type' => 'expense', 'nature' => 'debit', 'is_group' => true],
        ];
        foreach ($roots as $r) Account::create($r + ['is_active' => true, 'is_system' => true, 'level' => 1]);

        $asset = Account::where('code', '1000')->first();
        $liab = Account::where('code', '2000')->first();
        $equity = Account::where('code', '3000')->first();
        $income = Account::where('code', '4000')->first();
        $expense = Account::where('code', '5000')->first();

        $children = [
            // Assets
            ['parent_id' => $asset->id, 'code' => '1001', 'name' => 'Cash in Hand', 'account_type' => 'asset', 'nature' => 'debit', 'is_cash' => true],
            ['parent_id' => $asset->id, 'code' => '1002', 'name' => 'Cash at Bank', 'account_type' => 'asset', 'nature' => 'debit', 'is_bank' => true, 'is_group' => true],
            ['parent_id' => $asset->id, 'code' => '1100', 'name' => 'Accounts Receivable', 'account_type' => 'asset', 'nature' => 'debit', 'is_reconcile' => true],
            ['parent_id' => $asset->id, 'code' => '1200', 'name' => 'Inventory', 'account_type' => 'asset', 'nature' => 'debit'],
            ['parent_id' => $asset->id, 'code' => '1300', 'name' => 'Advances & Prepayments', 'account_type' => 'asset', 'nature' => 'debit'],
            ['parent_id' => $asset->id, 'code' => '1310', 'name' => 'TDS Receivable', 'account_type' => 'asset', 'nature' => 'debit'],
            ['parent_id' => $asset->id, 'code' => '1330', 'name' => 'VAT Current Account (Input)', 'account_type' => 'asset', 'nature' => 'debit'],
            ['parent_id' => $asset->id, 'code' => '1400', 'name' => 'Work in Progress', 'account_type' => 'asset', 'nature' => 'debit'],
            ['parent_id' => $asset->id, 'code' => '1500', 'name' => 'Fixed Assets', 'account_type' => 'asset', 'nature' => 'debit', 'is_group' => true],
            // Liabilities
            ['parent_id' => $liab->id, 'code' => '2100', 'name' => 'Accounts Payable', 'account_type' => 'liability', 'nature' => 'credit', 'is_reconcile' => true],
            ['parent_id' => $liab->id, 'code' => '2110', 'name' => 'VAT Payable', 'account_type' => 'liability', 'nature' => 'credit'],
            ['parent_id' => $liab->id, 'code' => '2115', 'name' => 'Goods Received Not Invoiced (GRNI)', 'account_type' => 'liability', 'nature' => 'credit'],
            ['parent_id' => $liab->id, 'code' => '2120', 'name' => 'Accrued Expenses', 'account_type' => 'liability', 'nature' => 'credit'],
            ['parent_id' => $liab->id, 'code' => '2200', 'name' => 'Loans & Borrowings', 'account_type' => 'liability', 'nature' => 'credit'],
            ['parent_id' => $liab->id, 'code' => '2310', 'name' => 'TDS Payable', 'account_type' => 'liability', 'nature' => 'credit'],
            // Equity
            ['parent_id' => $equity->id, 'code' => '3100', 'name' => 'Capital', 'account_type' => 'equity', 'nature' => 'credit', 'is_system' => true],
            ['parent_id' => $equity->id, 'code' => '3200', 'name' => 'Retained Earnings', 'account_type' => 'equity', 'nature' => 'credit', 'is_system' => true],
            // Income
            ['parent_id' => $income->id, 'code' => '4001', 'name' => 'Sales Revenue', 'account_type' => 'income', 'nature' => 'credit'],
            ['parent_id' => $income->id, 'code' => '4002', 'name' => 'Service Revenue', 'account_type' => 'income', 'nature' => 'credit'],
            ['parent_id' => $income->id, 'code' => '4900', 'name' => 'Other Income', 'account_type' => 'income', 'nature' => 'credit'],
            // Expenses
            ['parent_id' => $expense->id, 'code' => '5001', 'name' => 'Cost of Goods Sold', 'account_type' => 'expense', 'nature' => 'debit'],
            ['parent_id' => $expense->id, 'code' => '5100', 'name' => 'Purchases', 'account_type' => 'expense', 'nature' => 'debit'],
            ['parent_id' => $expense->id, 'code' => '5101', 'name' => 'Purchase Returns', 'account_type' => 'expense', 'nature' => 'credit'],
            ['parent_id' => $expense->id, 'code' => '5150', 'name' => 'Carriage Inward (Freight)', 'account_type' => 'expense', 'nature' => 'debit'],
            ['parent_id' => $expense->id, 'code' => '5200', 'name' => 'Salaries & Wages', 'account_type' => 'expense', 'nature' => 'debit'],
            ['parent_id' => $expense->id, 'code' => '5300', 'name' => 'Rent Expense', 'account_type' => 'expense', 'nature' => 'debit'],
            ['parent_id' => $expense->id, 'code' => '5400', 'name' => 'Utilities', 'account_type' => 'expense', 'nature' => 'debit'],
            ['parent_id' => $expense->id, 'code' => '5500', 'name' => 'Bank Charges', 'account_type' => 'expense', 'nature' => 'debit'],
        ];
        foreach ($children as $c) Account::create($c + ['is_active' => true, 'level' => 2]);

        // Sub-accounts under Bank
        $bankGroup = Account::where('code', '1002')->first();
        foreach ([
            ['code' => '100201', 'name' => 'Prime Bank — Main', 'account_type' => 'asset', 'nature' => 'debit', 'is_bank' => true],
            ['code' => '100202', 'name' => 'DBBL — Operations', 'account_type' => 'asset', 'nature' => 'debit', 'is_bank' => true],
        ] as $b) {
            Account::create($b + ['parent_id' => $bankGroup->id, 'is_active' => true, 'level' => 3]);
        }

        // Fiscal Years — ensure BD FY July-June coverage for current date (Chattogram)
        $fys = [
            ['name'=>'FY 2025-2026','code'=>'FY2526','start'=>'2025-07-01','end'=>'2026-06-30'],
            ['name'=>'FY 2026-2027','code'=>'FY2627','start'=>'2026-07-01','end'=>'2027-06-30'],
            ['name'=>'FY 2027-2028','code'=>'FY2728','start'=>'2027-07-01','end'=>'2028-06-30'],
        ];
        foreach ($fys as $cfg) {
            if (FiscalYear::where('code', $cfg['code'])->exists()) continue;
            $fy = FiscalYear::create(['name'=>$cfg['name'],'code'=>$cfg['code'],'start_date'=>$cfg['start'],'end_date'=>$cfg['end'],'status'=>'open','created_by'=>1]);
            $cursor = \Carbon\Carbon::parse($cfg['start']);
            while ($cursor->lte(\Carbon\Carbon::parse($cfg['end']))) {
                $fy->periods()->create(['name'=>$cursor->format('F Y'),'month_no'=>(int)$cursor->format('n'),'start_date'=>$cursor->copy()->startOfMonth()->toDateString(),'end_date'=>$cursor->copy()->endOfMonth()->toDateString(),'status'=>'open']);
                $cursor->addMonthNoOverflow();
            }
        }

        // Demo Bank linked to COA 100201
        $accBank = Account::where('code', '100201')->first();
        if ($accBank && ! \Modules\ACCOUNTS\Models\Bank::exists()) {
            \Modules\ACCOUNTS\Models\Bank::create([
                'name' => 'Prime Bank Main', 'code' => 'BANK-001', 'bank_name' => 'Prime Bank Ltd', 'branch_name' => 'Agrabad, Chattogram',
                'account_no' => '1234567890123', 'account_holder' => 'ERP Pilot Ltd', 'account_id' => $accBank->id,
                'currency' => 'BDT', 'opening_balance' => 500000, 'current_balance' => 500000, 'is_active' => true, 'created_by' => 1,
            ]);
        }
    }
}
