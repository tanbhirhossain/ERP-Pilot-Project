# HRM + PAYROLL — Gap Audit

**Date:** 2026-11-03 (Asia/Dhaka)  
**Modules audited:** `modules/HRM` (35 migrations, 50 Vue pages) + `modules/PAYROLL` (12 migrations, 19 Vue pages)  
**App:** ERP Pilot (`php artisan migrate:fresh --seed` clean, `npm run build` 13.18s 465 routes)  
**Auditor method:** code + DB schema + routes + services + UI walk; traced HRM→PAYROLL provider contracts (`HRM` exposes `EmployeePayrollDataProviderInterface` / `AttendancePayrollDataProviderInterface`, PAYROLL never touches HRM tables directly).

---

## 1. Executive Summary

**HRM is operationally usable** for a 200–300 employee factory: employee master, department/designation, shift/roster, biometric sync → pairing → daily reconciliation, leave requests with staged approval, overtime/extra-duty, holiday calendar, and attendance fix tools. Payroll on top of it is **surprisingly mature**: queued salary-sheet calculation with immutable snapshots, cutlist attendance/penalty batching, tax (progressive + slabs + employee override), PF accounts/ledger, loans with distinct-role multi-tier approval, increments with salary-history replay, festival bonus (idempotent/capped), final settlement, and preflight/benchmark tooling.

**Two systemic gaps block “ERP-grade” closure:**

1. **Employee lifecycle stops at 70%** — recruitment, documents/assets, transfer/promotion, probation/confirmation, and offboarding clearance are missing. You can hire ( `employees/create` ) and resign ( `hrm.resignations` ), but nothing in between that auditors ask for.
2. **PAYROLL is financially isolated** — slips calculate correctly and freeze a `breakdown_json`, but nothing posts to `modules/ACCOUNTS` (no `JournalData`), no bank advice file, no cost-center split. Finance cannot close books from payroll alone.

Everything else is incremental hardening (carry-forward, ESS, reports). The doc below grades every gap **P0–P3** with effort so you can sequence.

---

## 2. Current State Inventory

### 2.1 HRM — what exists today

| Domain | DB Tables (migration) | Services / Jobs | Routes (`hrm.*`) | Vue Pages | Notes |
|---|---|---|---|---|---|
| **Org structure** | `organizations`, `departments`, `designations` ( `044859/044918/044945` ) | `OrganizationService`, `DepartmentService`, `DesignationService` | `organizations.*`, `departments.*`, `designations.*` | `Organization/Index`, `Departments/*` (3), `Designations/*` (3) | Single-org model; departments flat, no tree budget/headcount |
| **Employee master** | `employees` + `employee_profiles|_salaries|_education|_experiences|_skills|_policies|_supervisors|_fallback_off_days|_resignations` ( `045111–045247`, `053559`, `060144`, `160000`, `100000×2` ) | `EmployeeService`, `EmployeeSupervisorService`, `EmployeeResignationService` | `employees.*`, `supervisors.*`, `resignations.*` | `Employees/{Index,Create,Edit,Show}` (4), `Supervisors/*` (4), `Resignations/Index` | `employee_id` (string FK, e.g. `EMP-0001`) is the canonical key everywhere; `user_id` nullable link to `users`; `status` enum Active/Inactive/On Leave/Suspended/Terminated/Resigned + `is_active`; salary history is effective-dated with `is_current` |
| **Biometric / device sync** | `biometric_devices`, `device_users` (+ global make `120000`), `attendance_sync_settings`, `attendance_sync_log` | `BiometricDeviceService`, `Attendance/AttendanceSyncService` (disables device during bulk, client-side date filter), pipeline jobs | `devices.*`, `device-users.*`, `attendance.sync.*` | `BiometricDevices/*` (3), `DeviceUsers/Index`, `AttendanceSync/Index` | ZK Teco via `jmrashed/zkteco`; commands `hrm:sync-attendance`, `hrm:run-pipeline`, `hrm:fix-pairing`; vendor bulk-transfer warning surfaced in UI |
| **Roster** | `roster_shifts`, `roster_duties`, `roster_shift_swaps` (+ `add_buffer_and_fallback`) | `RosterShiftService`, `RosterDutyService` (Excel + bulk-week), `RosterShiftSwapService` | `shifts.*`, `duties.*` (incl. `excel`, `excel-data`, `excel-save`, `bulk-week`, `bulk`), `swaps.*` | `RosterShifts/*` (3), `RosterDuties/{Index,Create,Edit,Excel,BulkWeek}` (5), `RosterSwaps/*` (3) | Shift has `in/out/buffer/fallback`; duty assignment is per employee/date |
| **Attendance** | `attendance_logs`, `attendance_pairs`, `attendance_dailies` (+ `add_off_day_status`) | `AttendancePipeline`, `AttendanceProcessingService` (long-gap guard), `AttendanceDayReconciliationService`, `Attendance/Pipeline` jobs | `attendance.logs|pairs|dailies.*`, `attendance.fix.*`, `attendance.pipeline.*` (device/all/stage) | `Attendance/{Logs,Pairs,Dailies,Fix}/*` (5) | Daily has `Present/Late/Early Out/Absent/On Leave/Holiday/Missing In|Out/Extra Duty/Late & Early Out` + `auto_shift`/`fallback_off_days` logic |
| **Overtime / Extra Duty** | `roster_overtimes` (`Overtime/Extra Duty/Holiday Duty`, `Pending/Approved/Rejected/Cancelled`) | `RosterOvertimeService` | `hrm.overtimes.*`, `hrm.extra-duty.*` | `Overtimes/{Index,Show}`, `ExtraDuty/Index` | OT hours flow into `payroll_cutlist_items.approved_overtime_minutes` + `extra_duty_minutes` |
| **Leave** | `leave_types`, `leave_requests`, `leave_type_settings` (day_count/accrual/proration, working_days, approval_steps), `leave_approval_actions`, `leave_request_workflows`, `hrm_holidays` | `LeaveTypeService`, `LeaveRequestService`, `LeaveBalanceService` | `leave-types.*`, `leaves.*` (incl. `balances`, `balance` JSON), `holidays.*`, `informed.*` | `LeaveTypes/*` (2), `LeaveRequests/{Index,Create,Show,Balances}` (4), `Holidays/Index`, `Informed/*` (3) | Balance: `annual/monthly/daily` accrual × `proration none/monthly/daily`; `calendar_days` vs `working_days` (skips holidays + non-working weekdays); `Informed` = lightweight absence notice |
| **Permissions** | — | `HRMPermissionSeeder` | — | — | 24 perms (`hrm.view`, `departments.*`, `devices.*`, `employees.*`, `shifts/duties/swaps.*`, `overtimes.*`, `holidays.*`, `leave-types|leaves.*`, `informed.*`, `attendance.*`); auto-granted to `Super Admin/Admin/Administrator` |

### 2.2 PAYROLL — what exists today

| Capability | Tables | Services / Actions | Routes (`payroll.*`) | Vue Pages | Maturity |
|---|---|---|---|---|---|
| **Settings** | `payroll_settings` (currency, punishment `×3` days, OT/ED multipliers, missing-punch penalty, divisor `30`) | `PayrollSettingsService` | `settings.*` | `Settings/Index` | ✅ Config |
| **Salary templates** | `salary_benefit_templates`, `salary_deduction_templates`, `employee_recurring_adjustments` | `PayrollAdjustmentService` | `adjustments.*` | `Adjustments/Index` | ✅ Auto-apply + employee overrides |
| **Tax** | `tax_configurations`, `tax_slabs`, `employee_tax_profiles[_versions]` | `TaxConfigurationService`, `CalculateEmployeeTaxAction` (annualize monthly → /12, exemption, taxable %) | `tax.*` | `Tax/Index` | ✅ Needs prefill before prod |
| **PF** | `pf_policies`, `pf_accounts`, `pf_ledgers` | `ProvidentFundService`, PF branch in `PayrollCalculationEngineService` | `pf.*` | `ProvidentFund/Index` | ✅ Ledger internal, not in ACCOUNTS |
| **Increments** | `salary_increments` (+ decision metadata) | `SalaryIncrementService` + `ApproveSalaryIncrementAction` → HRM salary-history via provider | `increments.*` | `Increments/{Index,Create}` | ✅ Before/after, effective-date, proration-aware |
| **Loans** | `payroll_loans|_approvals|_installments|_monthly_allocations|_monthly_batches` | `PayrollLoanService`, `PayrollLoanApprovalSteps`, `ApplyLoanInstallmentAction` | `loans.*` (incl. `allocate-installments|monthly`) | `Loans/{Index,Create}` | ✅ Distinct-role tiers, monthly-exact principal |
| **Cutlist (attendance → penalty)** | `payroll_cutlists`, `payroll_cutlist_items` (unpaid leave, late/early/punishment, missing-punch, OT/ED pay, `source_breakdown` JSON) | batched HRM queries (×50), `GenerateSalaryCutlistAction` | `cutlists.*` | `Cutlists/Show` | ✅ Batched; benchmark command exists |
| **Salary sheets & slips** | `payroll_salary_sheets` (queued→processing→ready→released→failed), `payroll_slips` (with `breakdown_json` v1) | `PayrollSalarySheetService`, `PayrollCalculationEngineService` (calendar-day weighted proration, `snapshot`-frozen), `ProcessSalarySheetJob` (chunk 50, tx/chunk), `CapturePayrollCalculationSnapshotAction`, `MarkPayrollSlipPaymentAction` | `sheets.*`, `cutlists.generate`, `slips.*` | `SalarySheets/{Index,Create,Show}`, `PaySlips/Show`, `Dashboard/Index` | ✅ Queued, snapshot-immutable, release-gated payment |
| **Bonus** | `festival_bonus_policies`, `festival_bonus_runs|_items` | `PayrollBonusService` (idempotent, cap/year, needs finalized sheet) | `bonuses.*` | `Bonuses/Index` | ✅ Policy/year/month dedup via lock |
| **Settlement** | `payroll_settlements`, `gratuity_policies` | `PayrollSettlementService`, `ProcessFinalSettlementAction` (gratuity calc, manual leave encashment) | `settlements.*` | `Settlements/{Index,Create}` | ✅ Resignation-exclusion check; manual encashment by choice |
| **Ops** | `payroll_calculation_snapshots`, `payroll_workflow_audits`, `payroll_compliance_audits` (legacy), `payroll_period_exclusions` | `PayrollPreflightCommand`, `PayrollAttendanceBenchmarkCommand`, `PayrollDashboardService` | — | `Dashboard/Index` | ✅ `preflight --strict` + benchmark |

> Payroll never writes `acc_journals`; it writes its own PF ledger and audit tables. See §3.2 P0.

---

## 3. Gap Analysis

### 3.1 HRM Gaps

#### P0 — Blocks payroll correctness / audit / legal

| # | Gap | Impact | Current Workaround | Files / Schema Affected | Effort* |
|---|---|---|---|---|---|
| H-P0-1 | **No employee documents repository** (NID, photo, certificates, appointment letter, contract, nominee) | Audit failure; manual drives; leave/PF proofs untraceable | `employees.photo` string only; `Partner/Documents` exists but HRM has none | New `employee_documents` (polymorphic or FK `employee_id`, `type`, `file_id`, expiry, verified_by) + media library | **M** |
| H-P0-2 | **No asset assignment** (ID card, laptop, SIM, uniform, locker, tools) | Offboarding leakage; no settlement deduction | None | New `hrm_assets` + `employee_assets` (issue/return, condition, cost) → PAYROLL settlement auto-query | **M** |
| H-P0-3 | **No transfer / promotion / designation-change workflow** | History is salary-only; org reporting breaks | Only `designation_id`/`department_id` on employee + payroll `salary_increments` | New `employee_movements` (type `transfer|promotion|demotion|designation_change`, effective date, approval, salary delta optional) | **L→M** |
| H-P0-4 | **No probation / confirmation** | No auto-confirm; probation leave PF rules unenforced | `employment_type=Probation` enum but no dates/status/action | Extend `employees` or new `employee_probations` (start, due, confirmed_by, extension), scheduler | **S→M** |
| H-P0-5 | **Resignation ≠ clearance** — resignation `decide` (Approve/Reject) does not trigger asset/handover/IT/PF/Accounts clearance | Finance releases PF/gratuity without checklist | Payroll settlement is manual + resigned-employee exclusion only | Add `resignation_clearances` (department checks) gating `settlements.approve` | **M** |

#### P1 — Operationally painful (every month)

| # | Gap | Impact | Current | Effort |
|---|---|---|---|---|
| H-P1-1 | **Leave carry-forward / year-end close** not defined | “Use or lose” is assumed; liability unknown | `LeaveBalanceService::balance()` computes `entitlement` but `available = max(0, ent–taken–pending)` with no carried balance; no `carry`/`lapse`/`encash` columns | **M** — add `leave_balances` snapshot + `carry/encash` settings per type |
| H-P1-2 | **Leave encashment auto-prefill missing** | Settlement leave amount is manual; Payroll deliberately left it manual (audit §remaining #1) | `ProcessFinalSettlementAction` expects `leave_encashment_reason`; no HRM query for encashable balance | **S** — HRM prefills: eligible types + cutoff + salary basis + override flag |
| H-P1-3 | **No leave comp-off / sandwich / half-day guard beyond basic** | Policy cannot express “Wed leave sandwiched by holidays = 3 days” etc. | `LeaveTypeSetting.day_count_mode calendar/working_days` only; no sandwich/rule JSON | **M** |
| H-P1-4 | **No attendance regularization** (missed punch → employee requests correction, manager approves) | HR edits DB or uses Fix Pairing | `AttendanceFixController` is admin-only; no `attendance_regularizations` workflow | **M** |
| H-P1-5 | **No shift allowance / OT policy** (max OT hrs, rate per shift type, holiday OT x2) | Payroll uses flat multipliers from `payroll_settings` | OT is free-text hours; no `ot_policies` by `shift_type`/`grade` | **M** |
| H-P1-6 | **No ESS / manager self-service** | Employee cannot view own attendance/leaves/payslip; manager cannot approve on behalf | All `hrm.*` needs `hrm.leaves.*` etc.; no `my/*` scope; Payroll slips readable only via sheet | **M→L** |
| H-P1-7 | **Reports & analytics** minimal | No headcount, attrition, leave liability, late trend, OT cost | Only duty Excel + attendance logs/pairs/dailies lists | **M** — add `HRMReportsController` (headcount by dept, turnover, attendance monthly, leave consumption) |

#### P2 — Completeness (expected in an ERP, not urgent)

| Gap | Notes | Effort |
|---|---|---|
| **Recruitment / Applicant tracking** (requisition → vacancy → candidate → offer → hire) | No tables/routes; `Employee` is post-hire only | **L** |
| **Onboarding checklist** (docs, account creation, induction) | Could reuse `employee_documents` + task list | **M** |
| **Training & certification** (need, enrollment, expiry) | `skills` is free-form; no `trainings` / `employee_trainings` | **M** |
| **Performance / appraisal / KPI / 360** | No tables; leave/PF decisions have no performance input | **L** |
| **Disciplinary / warning / grievance letters** | No `employee_warnings` etc. | **M** |
| **Work calendar versioning per location** | `holidays` is date+optional department; no location/dept version + carry | **S→M** |
| **Organizational hierarchy** (reporting tree, matrix) | `EmployeeSupervisor` is flexible (user or employee can supervise) but flat; no org-tree view | **M** |
| **Bulk import UI** | `RealEmployeeImportSeeder*` is dev-only; no CSV import for 500 hires with validation | **M** |
| **Mobile / geofenced punch** | Biometric only (ZK); no mobile/web check-in | **L** |

#### P3 — Polish

Notices, letter templates (appointment/confirmation/warning), service book PDF, employee directory search, org-chart rendering.

---

### 3.2 PAYROLL Gaps

#### P0 — Must fix before finance close

| # | Gap | Impact | Detail | Effort |
|---|---|---|---|---|
| **P-P0-1** | **No ACCOUNTS posting bridge** | Payroll calculates but Finance never sees it; trial balance is wrong | `PayrollCalculationEngineService` & `MarkPayrollSlipPaymentAction` never call `AccountsPostingProviderInterface`; PF uses its own `pf_ledgers`, not `acc_journals`. Need `PayrollJournalService` → `JournalData(type='PV' salary, 'JV' PF/gratuity/tax advance)` per sheet/payment | **L** — one new service + dim accounts seeding |
| **P-P0-2** | **No pay disbursement artifact** | Bank cannot pay 700 employees manually | No BEFTN CSV/Excel by bank branch, no `bank_advices` table, no cheque list. `payroll_slips.payment_status` exists but no export | **M** |
| **P-P0-3** | **No cost-center / department split posting** | Salary expense cannot hit profit centers | `payroll_slips` stores `department` snapshot but posting would need `dimension_id` per line | **M** |

#### P1 — Accuracy / control

| Gap | Detail | Effort |
|---|---|---|
| **Arrears / supplementary payroll** | Mid-month increment needs retro for prior month(s) — today calendar-weighted only for current month. No `arrear_sheets` | **M** |
| **Advance salary** (mid-month) | Loans exist (zero-interest, tiered approval) but not salary advance deduction; no `advance_requests` | **S→M** |
| **PF withdrawal / loan on PF** | `pf_accounts/ledgers` tracks contributions, but no withdrawal/loan flow; settlement is separate | **M** |
| **Tax artifacts** | Progressive + fixed + exempt work, but no tax-challan, no annual tax certificate / Form-16 export | **M** |
| **Auto leave encashment** | Depends on H-P1-2; settlement today is manual by design | **S** |
| **Asset recovery auto-deduction** | Settlement `deductions` manual; should auto-pull from `employee_assets` unreturned cost | **S** (after H-P0-2) |
| **Payroll lock / reopen** | `released` is final, but Finance has no `reopen` with approval + reversal journal | **M** |
| **Budget vs actual** | No `payroll_budgets` by dept/month to compare `sheets.net_total` | **M** |
| **Multi-currency** | `currency` in `payroll_settings` + `breakdown_json` is display only; posting should respect `CORE` currency | **S** |

#### P2 — Feature breadth

Bonus is festival-only (by design; cap/year enforced). Add **performance/ad-hoc bonus** if needed. Loan is interest-free by design (`PayrollLoanService` fixes 0%); add interest-bearing tier if policy changes. Add **overtime policy** per grade linking HRM OT hours to payroll rate (today flat multipliers). Add **statutory ESI** if jurisdictionally required.

---

### 3.3 Cross-Cutting

- **Notifications:** approvals (leave, OT, loan, increment, bonus, settlement) rely on page visits + `Informed` panel; no email/SMS/push. Add `HRMNotificationService` via `CORE` mail queue.
- **ESS portal:** unified “My HR” (attendance calendar, leave apply, OT request, payslips, PF statement, loan status) missing — requires role `employee` with row-level scoping (`employee_id = auth user’s employee`).
- **Roles matrix:** HRM 24 perms + PAYROLL ~15 perms granted to admin roles, but no seeded HR Manager / Dept Head / Payroll Executive roles. Onboarding docs should define matrix.
- **Performance:** payroll `preflight` + `benchmark-attendance --employees=750` exist; HRM `AttendancePipeline` is already batched (device bulk), yet no ESS load test at 1k concurrent.
- **Data quality:** `employee_id` string FK is consistent; `is_active` + `status` can diverge — add constraint or derive one from the other.
- **Media:** `photo` is a string path; migrate to `CORE` media library (S3-ready) when adding documents.
- **Localization:** BD tax slabs / PF policy are configurable (good); keep English + Bangla labels in Vue.

---

## 4. Prioritized Roadmap (proposed)

### Phase A — Make payroll closable (2–3 sprints)

1. **P-P0-1** ACCOUNTS bridge: `PayrollJournalService` posts per-release journal (Dr Salary Expense by dept cost-center, Cr Salary Payable; on payment Cr Bank, Dr Payable; PF employer Cr PF Payable; tax Cr Tax Payable). Seed `acc_accounts` `5010 Salary`, `2110 Salary Payable`, `2120 PF Payable`, etc. (L)
2. **P-P0-2** Bank advice export: `payroll.bank-advices` (BDT BEFTN CSV + PDF cheques) from `payroll_slips` where `payment_status=unpaid` (M)
3. **H-P0-1 + H-P0-2** Employee documents + asset assignment (bare-min CRUD + storage) so settlement auto-pull works later (M+M)
4. Add seeded roles `HR Manager`, `Payroll Executive`, `Dept Head` with perm bundles + email on approvals (S)

### Phase B — Monthly payroll correctness

5. **H-P0-3** Transfer/promotion workflow (adds history for reporting) (M)
6. **H-P1-1** Carry-forward + year-end close + **H-P1-2** auto-encashment prefill to settlement (M)
7. **H-P1-4** Attendance regularization (employee → manager → HR) so cutlist disputes stop (M)
8. **P-P0-3** Cost-center split + budget-vs-actual report (M)

### Phase C — Completeness & ESS

9. Probation/confirmation, onboarding checklist, OT/shift policy, regularization, ESS “My HR” (slips + PF statement + leave/OT apply) (L)
10. Recruitment, training, appraisal, disciplinary, org-chart, HR dashboards (L)

---

## 5. Effort Scale

**S** 0.5–1.5d · **M** 2–5d · **L** 6–10d (incl. migration + services + Vue + perms + seed + tests). Estimates assume existing pipeline reuse (e.g., attendance jobs, payroll snapshot/queue patterns).

---

## 6. Immediate Next-Step Recommendation

If you want finance to sign off next payroll, do **Phase A.1 + A.2** — posting + bank file — before everything else. If HR ops pain is higher, do **Phase B.7** (regularization) first; it cuts payroll rework the most.

Tell me which track to start and I’ll ship migration → service → UI → E2E in the next turn (I keep `migrate:fresh --seed` + `npm run build` green each turn).

---

### References (workspace paths)

- `modules/HRM/Database/Migrations/2026_10_25_045111_create_employees_table.php` + `…/045127_create_employee_salaries_table.php` + `…/071538_create_attendance_dailies_table.php`
- `modules/HRM/Services/{EmployeeService, LeaveBalanceService, RosterDutyService, Attendance/*}`
- `modules/HRM/Resources/js/Config/menu.ts` (`hrm.*`, `roster.*`, `leave-management`, `attendance`)
- `modules/PAYROLL/Services/PayrollCalculationEngineService.php` + `Services/HRM/HrmAttendancePayrollDataProvider.php`
- `modules/PAYROLL/Database/Migrations/2026_10_31_150200_create_payroll_execution_tables.php` + `…/150000_*configuration*` + `…/150800_*snapshots*`
- `modules/PAYROLL/GAP_AUDIT.md`, `modules/PAYROLL/README.md`
- `modules/ACCOUNTS/Interfaces/AccountsPostingProviderInterface.php` — not called from PAYROLL (root cause of P-P0-1)
