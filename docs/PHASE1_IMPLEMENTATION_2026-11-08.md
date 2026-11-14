# Phase 1 — Enterprise Core: Implementation Report

**Date:** 2026-11-08
**Baseline:** `f8546a3` (master)
**Status:** ✅ Complete — all gates green, no regressions

This report records what was built for Phase 1 of the enterprise roadmap (see
`ENTERPRISE_GAP_AUDIT_2026-11-08.md`), how it was verified, and how to operate it.
Phase 0 was intentionally skipped at the user's direction; nothing here forces it.

---

## 1. What shipped

| Gap | Feature | Where |
|-----|---------|-------|
| **F2** | In-app notifications + header bell | `CORE/Services/NotificationService`, `Notifications/SystemNotification`, `NotificationController`, `Components/NotificationBell.vue`, `Pages/Notifications/Index.vue` |
| **F3** | Approval matrix + maker-checker / SoD | `CORE/Services/ApprovalService`, `Models/ApprovalPolicy` + `ApprovalRequest`, `ApprovalController`, `Pages/Approvals/{Index,Policies}.vue` |
| **F4** | Opening-balance take-on wizard | `ACCOUNTS/Services/OpeningBalanceService`, `OpeningBalanceController`, `Pages/OpeningBalances/Index.vue` |
| **F5** | Excel/CSV bulk imports | `CORE/Services/ImportService` + `Imports/{ImportContract,SpreadsheetReader}`, `ImportController`, `Pages/Imports/Index.vue`; importers for products, partners, COA, opening stock |
| **F9** | Employee Self-Service (ESS) | `HRM/Http/Controllers/MyHrController`, `Pages/My/{Dashboard,Payslips,Payslip,Leave,Attendance,NotLinked}.vue` |
| **F10** | Contract / tier pricing engine | `PARTNER/Services/PricingService` + `PricingProviderInterface`, `SALES/Support/LinePricer`, wired into Order/Quotation/Invoice + POS lookup |
| **F11** | Credit-limit enforcement on every credit path | `SALES/Services/CreditControlService`, wired into Order confirm, Invoice post, POS sale |
| **U3** | ECharts visualisations | `CORE/Components/BaseChart.vue` (tree-shaken bar/line/pie) |
| **U4/U5** | Business KPI dashboard | `CORE/Services/BusinessDashboardService`, revenue-vs-purchases + AR-aging charts on the dashboard |

**Schema added** (one migration, `2026_11_08_000001_phase1_notifications_approvals`):
`notifications` (Laravel standard), `approval_policies`, `approval_requests`,
`opening_balance_batches`. The `3900 Opening Balance Equity` account is seeded by
`AccountsDemoSeeder` (never a migration, per the data-separation contract).

---

## 2. Key design decisions

- **Approval gate precedes the document transaction.** `assertAllowed()` runs
  *before* `DB::transaction` in PO confirm / Vendor Bill post / Invoice post, so a
  refusal never rolls back a half-created approval request. Policies ship
  **inactive** — installing Phase 1 changes no existing behaviour until an admin
  activates one.
- **Segregation of duties is server-side.** The raiser cannot approve their own
  request even if they hold the approver permission; a different authorised user
  must decide. A request is only honoured when its amount still matches.
- **One credit rule, three doors.** `CreditControlService` is the single exposure
  arithmetic (open AR + unposted drawer dues + new amount vs limit). POS now
  delegates to it instead of carrying its own copy, so SO→INV can no longer bypass
  a limit the till would enforce. `credit_limit <= 0` means *no limit*.
- **Explicit price always wins.** `LinePricer` only fills a *blank* line price from
  the engine (partner list → default list → shelf), so negotiated overrides and
  every existing test's arithmetic are preserved.
- **Opening balances are idempotent by reference** and post balanced `JV` journals
  against equity (3900). `JV` type deliberately avoids the auto AR/AP creation that
  `RV`/`SV`/`PV`/`BV` trigger, so the sub-ledger rows are created exactly once.
- **Imports are two-phase and all-or-nothing.** Preview validates and writes
  nothing; commit re-validates then persists in one transaction. Each module
  registers its own importer into the shared `ImportService` singleton (DIP).
- **ESS is row-scoped by session, never by request.** The employee id comes from
  `employees.user_id`; a tampered payslip id returns 404, not a colleague's slip.
  An unlinked user gets an actionable "ask HR to link your account" page.
- **The dashboard is a read-only reporting layer.** `BusinessDashboardService`
  aggregates *posted* documents with indexed queries, cached for a minute; it
  imports no module models and writes nothing.

---

## 3. Verification (all green)

| Check | Result |
|-------|--------|
| **Phase 1 feature tests** (5 new files) | **37 passed / 131 assertions** |
| **Regression suite** (Sales, Purchase, Accounts, E2E) | **123 passed / 769 assertions** |
| RouteIntegrityTest | 4/4 ✓ |
| MigrationDataSeparationTest | 9/9 ✓ |
| MoneyColumnCastTest | 7/7 ✓ (pins 157→160 models, 242→245 columns) |
| OptionEndpointAuthorisationTest | 11/11 ✓ (pin 179→183 permissions) |
| ShellBindingTest | 3/3 ✓ |
| PrintContractTest | 11/11 ✓ (pins 396→409 files, 16→17 printers, 5→6 regions) |
| CiWorkflowContractTest | 8/8 ✓ |
| DemoDataIntegrityTest | 9/9 ✓ |
| **Vitest** | **260 passed** |
| **Production build** | ✓ 24.9s |
| **ESLint** (`--max-warnings=0`) | ✓ clean |
| **Pint** (`--test`) | ✓ 928 files |
| **`migrate:fresh --seed`** | ✓ 183 perms, 3 inactive policies, 3900 equity, all tables |

The regression suite includes the POS credit-limit tests and the full
PO→GRN→Bill→Sale→Invoice E2E flow, confirming the new gates and pricing preserve
existing behaviour exactly.

---

## 4. Operator guide

- **Notifications** — the bell in the header polls every 45s; click an item to mark
  read and follow its link. Full inbox at **Notifications**.
- **Approvals** — inbox at **Approvals**; define thresholds at **Approvals →
  Policies**. Activate a policy to enforce it. Decisions are audit-tracked.
- **Opening balances** — **Accounts → Opening Balances**, four tabs (COA / AR / AP /
  Stock). Each tab posts one batch; a reference can only be posted once.
- **Imports** — **Data Import** (Administration). Download a template, upload,
  **Preview**, then **Commit**. Nothing is written until commit.
- **ESS** — **My HR** in the sidebar. Requires the user to be linked to an employee
  (Employees → edit → Linked user). Payslips print through the shared print engine.
- **Pricing** — assign a price list to a customer (or mark one default). Blank line
  prices on quotations/orders/invoices and POS scans resolve the contract/tier price.
- **Credit** — set `credit_limit` on a customer; SO confirm, Invoice post and POS
  credit sales all enforce it. `0` = unlimited.

---

## 5. Configuration

- `config/sales.php` → `credit` block controls credit-control behaviour.
- Approval policies are data (seeded inactive); manage them in the UI, not config.
- New permissions: `approvals.view`, `approvals.manage`, `imports.manage`,
  `accounts.opening.manage` (granted to Super Admin / Administrator; Manager gets
  approvals + imports; Staff gets `approvals.view`). ESS needs no permission — it is
  self-scoped and available to every signed-in user.

## 6. Out of scope (later phases)

Real-time websockets (the bell polls), multi-level approval chains, partial
approvals, and the remaining Phase 2/3 items from the audit. The approval matrix is
single-level by design for Phase 1; the schema (`priority`, `min_amount`) leaves
room to extend it.
