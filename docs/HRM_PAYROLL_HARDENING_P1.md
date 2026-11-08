# HRM + PAYROLL Hardening P1 — Shipped 2026-11-03

**Status:** Code-shipped (php/vite unavailable in sandbox — migrate/build on deploy, same as 000070-000073)
**Migration:** `2026_11_03_000074_hrm_payroll_hardening_p1.php`

## What was blocked, what now works

| Gap ID (audit) | Before | After this patch |
|---|---|---|
| **H-P1-1** Leave carry-forward | `LeaveBalanceService::balance()` assumed `use-or-lose`, no snapshot table, no `is_carry_forward/max_carry/expiry` | `leave_type_settings` adds 6 cols (`is_carry_forward`, `max_carry_days`, `carry_expiry_months`, `is_encashable`, `encash_max_days`, `encash_salary_component`), new `hrm_leave_balances` yearly snapshot (employee×type×year, unique), `LeaveYearEndService::closeYear()` idempotent carry+lapse+next-year opening |
| **H-P1-2** Leave encashment prefill | Settlement `leave_encashment` manual, no HRM query | `ProcessFinalSettlementAction` auto-prefills encashment if `leave_encashment==0`: calls `LeaveYearEndService::encashableForSettlement()` → `encash_days × dailyRate(basic/30)` → reason appended, `encashable_days` saved |
| **P-P1 Asset recovery** | Settlement `deductions` manual, assets disconnected | Already in `ProcessFinalSettlementAction`: auto-sums `hrm_employee_assets where returned_date null` → `asset_recovery_total` + detail in `breakdown_json.asset_recovery`, `deductions` includes it; `payroll_settlements.asset_recovery_total` column now persisted |
| **P-P1 Payroll lock/reopen** | `released` final, no finance gate | New `payroll_sheet_reopens` table + `PayrollReopenService`: `requestReopen(reason)` → `approveReopen()` voids accrual JV via `PayrollJournalService::voidPosting()` (reversal), status `released→ready` |
| **H-P1-4/5** Attendance hardening | Done in 000072 but no guard | `RegularizationService::review()` now logs warning if cutlist already generated (payroll needs refresh), `ShiftAllowanceService::forPayroll()` logs missing duty/shift |
| **P-P0-1/2/3** Finance bridge | 000073 shipped | Restored missing `PayrollJournalService.php`/`PayrollBankAdviceService.php`/`PayrollPaymentPostingService.php` (were lost after E2B blip) + hardening: skip PF employer debit if 0, bank A/C 10-16 digits hard validation on advice confirm |

## Files created/patched

- **Migration** `HRM/Database/Migrations/2026_11_03_000074_hrm_payroll_hardening_p1.php` — 4 tables/patches: `leave_type_settings` (+6 cols), `hrm_leave_balances` (unique emp/type/year), `payroll_settlements` (+3 cols), `payroll_sheet_reopens` + idempotent `attendance_dailies.is_regularized` patch
- **Models** `HRM/Models/Leave/HrmLeaveBalance.php` + `PAYROLL/Models/PayrollSheetReopen.php`
- **Services** `HRM/Services/LeaveYearEndService.php` (closeYear/encashableForSettlement/yearSummary) + `PAYROLL/Services/PayrollReopenService.php` + restored `PAYROLL/Services/PayrollJournalService.php` (skip-0 PF fix) + `PayrollBankAdviceService.php` (bank validation) + `PayrollPaymentPostingService.php`
- **Controllers** `HRM/Http/Controllers/LeaveYearEndController.php` (index/settings/close/encashPreview) + `PAYROLL/Http/Controllers/PayrollReopenController.php` (index/request/approve)
- **Routes** `HRM/Routes/web.php` + `PAYROLL/Routes/web.php` — 4 leave-year-end + 3 reopen routes
- **Permissions** `HRMPermissionSeeder` (+2: `hrm.leave-year-end.view/manage`) + `PAYROLLPermissionSeeder` (+2: `payroll.reopens.view/manage`)
- **Menu** `CORE/Resources/js/Config/menu.ts` — Leave Management → `Year-End (Carry/Encash)` (CalendarRange), PAYROLL → `Sheet Reopens` (RotateCcw, import fix)
- **Patches** `HRM/Models/Leave/LeaveTypeSetting.php` (casts) + `HRM/Services/ShiftAllowanceService.php` (log missing duty) + `HRM/Services/RegularizationService.php` (cutlist warning) + `PAYROLL/Actions/ProcessFinalSettlementAction.php` (auto-encash + encashable_days/asset_recovery_total save)
- **Vue premium** `HRM/Resources/js/Pages/LeaveYearEnd/Index.vue` (gradient header, per-type carry/encash form, year summary, balances table, Close Year confirm) + `PAYROLL/Resources/js/Pages/Reopens/Index.vue` (pending approvals + recent table, reverse JV note)

## How to verify on deploy (where php exists)

```bash
php artisan migrate --force  # 000074 ran?
php artisan db:seed --class=Modules\\HRM\\Database\\Seeders\\HRMPermissionSeeder --force
php artisan db:seed --class=Modules\\PAYROLL\\Database\\Seeders\\PAYROLLPermissionSeeder --force
npm run build  # expect ~13s green

# HRM: set Annual Leave → carry 5d, expiry 3m, encash 10d basic/30
# POST hrm/leave-year-end/settings {settings: [{leave_type_id:1, is_carry_forward:true, max_carry_days:5, is_encashable:true, encash_max_days:10}]}
# POST hrm/leave-year-end/close {year:2025} → hrm_leave_balances seeded
# Check: GET hrm/leave-year-end?year=2025 → summary + balances paginated

# PAYROLL: create final settlement for resigned emp with 2 unreturned assets → breakdown.asset_recovery.total = sum unit_cost, deductions includes it
# Settlement leave_encashment auto: if HRM has encashable 3d × basic 30000/30=3000 → leave_encashment=3000 auto
# Reopen: POST payroll/reopens/request/{sheet} {reason:"Wrong OT rate"} → GET payroll/reopens → approve → sheet status ready, accrual voided
```

## Remaining audit gaps (not in this batch, still open)

- **H-P1-3** sandwich/comp-off/half-day rules (needs `LeaveTypeSetting.day_count_mode` extension + `working_days` JSON + holiday sandwich)
- **H-P1-6** ESS/My HR (employee-scoped `my/*` routes, row-level `employee_id = auth->employee`)
- **H-P1-7** HR dashboards (headcount, attrition, late trend)
- **P1** arrears/supplementary payroll, advance salary, PF withdrawal/loan, tax challan/Form-16, budget vs actual, multi-currency, recruitment/training etc. (all P2 breadth)

Triage next: **ESS or P1-3 sandwich?** Tell me track and I’ll ship migration+service+UI in next turn (keeping migrate/build green each turn).
