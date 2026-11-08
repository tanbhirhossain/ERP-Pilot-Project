# PAYROLL Finance Bridge — P-P0-1/2/3

**Status:** Code-shipped in this session (migrate + seed + build deferred to deploy — php/vite unavailable in sandbox, same as Step 3)

## What Was Built
Enterprise payroll → accounts posting with cost-center split + BEFTN bank advice. Every payroll sheet becomes auditable accounting + bank-ready disbursement.

### 1) Migration 000073 (3 tables + 2 patches)
- `payroll_journal_posts` — idempotency (unique `salary_sheet_id + posting_type`), cost_center_breakdown JSON, journal link, void support
- `payroll_bank_advices` — ADV-PAY-YYYYMM-###, bank_id, total_amount, status draft→confirmed→paid, file_path (xlsx/csv/beftn)
- `payroll_bank_advice_items` — per employee: bank_account_no, routing_no, net_amount, reference
- Patch `payroll_slips` → payment_journal_id + cost_center
- Patch `payroll_salary_sheets` → accrual_journal_id + accrual_cost_centers

### 2) COA Auto-Provisioning (ensurePayrollAccounts)
Creates if missing (under 5000 Expenses / 2000 Liabilities):
- **5210** Payroll - Basic & Gross Salary (debit)
- **5212** Payroll - Overtime & Extra Duty
- **5215** Payroll - Employer PF Contribution
- **2121** Salary Payable (credit) — net payable
- **2122** PF Payable — emp + employer
- **2311** Payroll Tax Payable (TDS) — uses 2310 family
- **2125** Payroll Deductions Payable — loans/other
All marked `is_system=true`, `is_posting=true`. Reuses existing 5200, 2120, 2310 if present.

### 3) Accrual JV (JV) — Dr Expense by Dept → Cr Payables
`PayrollJournalService::postSalaryAccrual()` called from Finance panel after **Release**:
```
Group slips by department (cost_center)
For each dept:
  Dr 5210 Gross minus OT (costCenter = dept)
  Dr 5212 OT (costCenter = dept)
  Dr 5215 Employer PF (costCenter = dept)
Cr 2121 Salary Payable (net_total)
Cr 2311 Tax Payable (sum tax from breakdown_json)
Cr 2122 PF Payable (emp + employer)
Cr 2125 Loan Recovery
Balance check + auto-balancing if rounding diff
→ JournalData via AccountsPostingProviderInterface.postJournal()
→ PayrollJournalPost record (idempotent: uniq_sheet_posting)
→ sheet.accrual_journal_id + accrual_cost_centers updated
```
Supports **void** via `voidJournal()` → status=void + reversal.

Uses `breakdown_json` to extract: tax, pf, loan, overtime, benefits. Falls back to totals if breakdown missing.

### 4) Payment PV (PV) — Dr Salary Payable → Cr Bank
`PayrollPaymentPostingService` hooks into `MarkPayrollSlipPaymentAction`:
- When slip → paid, resolves company bank `acc_banks` → `acc_accounts`
- Posts PV: Dr 2121 / Cr Bank (with costCenter)
- Idempotent via meta.slip_id
- Never blocks HR flow — logs warning if no bank configured

`PayrollJournalService::postSalaryPayment()` available for bulk.

### 5) BEFTN Bank Advice — Excel/CSV Ready for Prime Bank / DBBL
`PayrollBankAdviceService::generateForSheet()`:
- Groups slips by employee bank (resolves from HRM Employee bank fields or slip breakdown)
- One `PayrollBankAdvice` per disbursement bank (uniq_sheet_bank)
- Creates items with account_no, bank_name, branch, routing_no, net_amount
- Exports **xlsx** via Maatwebsite\Excel (fallback to CSV)
  - Header: Advice No, Period, Source Bank
  - Table: SL, Employee ID, Name, Dept, A/C No, Bank, Branch, Routing, Amount, Reference
  - Footer: Total + employee count
  - File: `storage/app/private/payroll_advices/YYYY/MM/payroll_advice_ADV-PAY-...xlsx`
- Lifecycle: **draft → confirmed → paid** (confirm → markPaid also updates slips)
- Download endpoint regenerates if file missing

### 6) Routes (PAYROLL::finance + bank-advices)
```
payroll/finance/sheets/{sheet}          → SheetFinance (accrual + bank)
payroll/finance/sheets/{sheet}/post-accrual
payroll/finance/posts/{post}/void
payroll/bank-advices                    → index
payroll/bank-advices/generate/{sheet}   → generate
payroll/bank-advices/{advice}           → show
payroll/bank-advices/{advice}/download
payroll/bank-advices/{advice}/confirm
payroll/bank-advices/{advice}/paid
```
Perms: `payroll.finance.view/post/void`, `payroll.bank-advices.view/manage` (patched via PAYROLLPermissionSeeder)

### 7) Vue — Premium Enterprise UI
- **Finance/SheetFinance.vue** — gradient header (net/gross/emps/status), accounts health badges, accrual card (posted vs ready to post + cost-center grid + posts table), bank advice cards (generate/confirm/paid + download), void with reason prompt
- **Finance/BankAdvices.vue** — paginated table with status pills, download, amount
- **Finance/BankAdviceShow.vue** — advice header (total/emps/status), lifecycle buttons, items table with footer total, BEFTN note

Integrated with existing **SalarySheets/Show.vue** → new **Finance Bridge** button (indigo) when status ready/released.

### 8) Menu
- PAYROLL → **Bank Advices (BEFTN)** → payroll.bank-advices.index (icon CreditCard)

## Accounting Example
Sheet 2026-11: Gross 1,200,000, OT 45,000, Tax 80,000, PF Emp 96,000, PF Emplr 96,000, Loan 20,000, Net 1,004,000
Engineering (60%): Gross 720k, OT 30k, Tax 48k, PF 57.6k, Net 602.4k
Sales (40%): Gross 480k, OT 15k, Tax 32k, PF 38.4k, Net 401.6k
```
JV  PAY-202611-00001  2026-11-30  "Payroll accrual 2026-11 (2 emps)"
Dr 5210 Payroll Basic - Engineering          690,000  [Engineering]
Dr 5212 Overtime - Engineering                30,000  [Engineering]
Dr 5215 PF Employer - Engineering            57,600  [Engineering]
Dr 5210 Payroll Basic - Sales                465,000  [Sales]
Dr 5212 Overtime - Sales                      15,000  [Sales]
Dr 5215 PF Employer - Sales                  38,400  [Sales]
  Cr 2121 Salary Payable                                1,004,000
  Cr 2311 Payroll Tax Payable                             80,000
  Cr 2122 PF Payable (emp+emplr)                         192,000
  Cr 2125 Loan Recovery                                   20,000
  ─────────────────────────────────────────────────────────────
  Dr 1,296,000 = Cr 1,296,000 ✓
```

## Deploy Steps (on server where php + vite exist)
```bash
php artisan migrate --force
php artisan db:seed --class=Modules\\PAYROLL\\Database\\Seeders\\PAYROLLPermissionSeeder
npm run build
# Verify:
php artisan tinker --execute="app(Modules\\PAYROLL\\Services\\PayrollJournalService::class)->ensurePayrollAccounts();"
```

## Future (P-P1)
- Settlement posting auto on approve
- Cost-center hierarchy (project/branch)
- Auto-split gross into Basic/HRA/Conveyance from settings
- Multi-currency + FX
- Reconciliation report: payroll vs GL vs bank

## Files
- modules/PAYROLL/Database/Migrations/2026_11_03_000073_create_payroll_finance_bridge.php
- modules/PAYROLL/Models/PayrollJournalPost.php + PayrollBankAdvice.php + PayrollBankAdviceItem.php
- modules/PAYROLL/Services/PayrollJournalService.php (core, 400+ lines)
- modules/PAYROLL/Services/PayrollBankAdviceService.php (BEFTN Excel)
- modules/PAYROLL/Services/PayrollPaymentPostingService.php (slip→PV hook)
- modules/PAYROLL/Http/Controllers/PayrollFinanceController.php
- modules/PAYROLL/Resources/js/Pages/Finance/*.vue (3 premium pages)
- Patch: modules/PAYROLL/Actions/MarkPayrollSlipPaymentAction.php (auto PV)
- Patch: modules/PAYROLL/Routes/web.php (+10 routes)
- Patch: modules/PAYROLL/Database/Seeders/PAYROLLPermissionSeeder.php (+5 perms)
- Patch: modules/CORE/Resources/js/Config/menu.ts (Bank Advices)
- Patch: modules/PAYROLL/Resources/js/Pages/SalarySheets/Show.vue (Finance Bridge button)
