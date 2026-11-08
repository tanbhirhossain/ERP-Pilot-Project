# ERP-Pilot-Project — Full Audit & Hardening Report

**Date:** 4 November 2026
**Repository:** `github.com/tanbhirhossain/ERP-Pilot-Project`
**Branch / base commit:** `master` @ `f3e014a13f550f9736ac73cf3e99dd1e52b717af`
**Scope:** entire project — bugs, gaps, security, data integrity, UI/UX, build, tests
**Method:** everything below was verified by *running* the code (Pest, Vitest, Vite, ESLint, `artisan`, direct DB queries, kernel-level HTTP dispatch of every menu route). Nothing is inferred from reading alone.

---

## 1. Executive summary

The project is architecturally sound and unusually well-built for its stage: a modular Laravel 12 / Inertia 2 / Vue 3 ERP with **8 modules, 546 routes, 168 tables, 407 foreign keys, 475 indexes, 191 explicit DB transactions and 78 `lockForUpdate` calls**. Decimal money columns on the core ledgers, a full `spatie/laravel-activitylog` audit trail, and a real design system in `modules/CORE` are all genuine strengths worth preserving.

It was, however, **not deployable**. The audit found four defects that would each cause silent financial or security failure in production:

| # | Defect | Impact if shipped |
|---|---|---|
| 1 | Chart of Accounts seeder never ran | Invoices/bills marked **"posted" with zero ledger entries and no error** |
| 2 | HRM had **0 of 181** routes authorised | Any authenticated user could read/modify all HR, salary and PII data |
| 3 | Two **public unauthenticated** biometric endpoints | SSRF into the internal network + anonymous writes to attendance |
| 4 | Open self-registration granting a session with no role | Chained with #2 into full HRM access for anyone on the internet |
| 5 | Off days classified as `Absent` (P0-J) | **False absenteeism penalties** on every employee's contracted day off |
| 6 | Every typeahead picker returned an empty list (P0-K) | Purchase, sales, inventory and accounts documents **could not be filled in** |
| 7 | CSP blocked Ziggy's inline bootstrap (§3 Security headers) | **Blank login page** — the SPA threw at mount |

Plus **32 routes pointing at six controllers that did not exist**, and **7 Inertia pages referenced by controllers but never written** — i.e. a large set of menu items that crashed or 500'd on click.

**All of the above are now fixed and covered by regression tests.** Current state:

| Gate | Before | After |
|---|---|---|
| Pest (PHP) | 191 passed / **2 failed** | **248 passed / 0 failed** (1,269 assertions) |
| Vitest (JS) | 169 passed | **197 passed** |
| ESLint | **140 errors** | **0 errors, 0 warnings** |
| `npm run build` | OK | OK |
| `composer audit` | not run | **clean** |
| `npm audit --omit=dev` | **2 high**, 3 mod, 1 low | **0 high**, 3 mod, 1 low |
| Routes → missing controllers | **32 broken refs** | **0** |
| Menu routes that 500/crash | **11 of 92** | **0** |
| Inertia render targets with no page | **7** | **0** |
| Permissions enforced but never seeded | **6 phantom** | **0** |
| Permissions seeded (total) | 120 | **177** at item 1 → **178** at item 39, which adds `pos.reports.view` |
| HRM routes with permission middleware | **0 / 181** | **182 / 183** at the route level, and the 183rd (`api/options/{type}`) gated per type inside its controller — P1.3 |
| `app.js` bundle | 329.96 kB (gzip 108.40) | **67.52 kB (gzip 16.17)** |
| Worst stray chunk (`Button.vue…`) | 126.62 kB (gzip 38.61) | **1.55 kB (gzip 0.75)** |
| CI pipeline | none | `.github/workflows/ci.yml` (PHP × MySQL × SQLite matrix, front end, audits) |

**269 files modified, 63 files added** (+6,964 / −2,763 lines across the tracked files; the 63 new files add 13,989 more). Measured against `f3e014a` with `git status --porcelain -uall`, excluding build output — see §6.

---

## 2. P0 — Critical (all fixed)

### P0-B · The general ledger was being silently emptied
**The single most severe defect in the project.**

`AccountsDemoSeeder::run()` began with `if (Account::exists()) return;`. But migration `2026_11_03_000075_add_grni_to_receipts.php` inserts Account `2115` inside `up()`. Migrations run before seeders, so the guard was **always true** and the seeder always bailed: no Chart of Accounts, no fiscal years, no periods, no demo bank. `acc_accounts` held exactly **1 row**.

The cascade was the dangerous part. `VendorBillService::post()` did:

```php
$apId = $this->accounts->accountIdByCode(config('accounts.codes.payable')); // 2100
if ($apId) { /* post the journal */ }        // ← silently skipped
// …but 30 lines later, unconditionally:
$bill->update(['status' => 'posted', 'journal_id' => null]);
```

So vendor bills, sales invoices and POS receipts were stamped **`posted`** with **no accounting entries at all**, and nothing logged an error. The books would balance to zero while the UI reported success. This same `accountIdByCode()` + `if ($id) { … }` shape appeared at **24 call sites** across ACCOUNTS, SALES, PURCHASE and INVENTORY. Fixing the seeder put the accounts back, but left the code free to do it again the moment a chart diverged — that is **P1.19**, now fixed.

**Fix:** rewrote the seeder to adopt-or-create accounts by code, wrapped in `DB::transaction`, with fiscal periods keyed on `month_no` (the previous `start_date` key collided with a unique index because SQLite's `date` cast stores `Y-m-d H:i:s` while MySQL stores `Y-m-d`).

**Root cause closed — item 38.** Rewriting the seeder put the accounts back, but the migration was still writing content, so the collision could recur the moment any other seeder grew an existence guard — and two of them (`SalesDemoSeeder`, `PurchaseDemoSeeder`) still have one. `2026_11_03_000075` now contains only its DDL, and account 2115 is created by the seeder that already owned the other 34. See §3 *Migration/data separation*.

**Result:** 35 accounts / 3 fiscal years / 36 periods / 1 bank / 0 orphaned references, verified across 3 clean re-runs. The 2 previously-failing E2E tests (`PurchaseAndSalesE2ETest`) now pass — they were failing *because* of this.

> **Residual debt (still open):** that `INSERT` belongs in a seeder, not in a deployed migration. A migration that writes seed data will keep defeating guards like this one.

### P0-F · HRM had no authorisation whatsoever
Every other module gated 92–100% of its routes. HRM gated **0 of 181**. Any logged-in user — including a self-registered one with no role (see P0-H) — could read and modify employees, salaries, bank accounts, leave, probation and disciplinary records.

The subtle part: `modules/HRM/Database/Seeders/HRMPermissionSeeder.php` **already existed** with a complete 57-permission ACL and role grants. It had simply **never been wired into `DatabaseSeeder`** — zero call sites anywhere in the codebase. The permissions were orphaned, not absent.

**Fix, in four parts:**
1. Wired `HRMPermissionSeeder` into `DatabaseSeeder`.
2. Applied `PermissionMiddleware` to **all 182 HRM route lines**, deriving each permission from the route name and HTTP verb. Route names do not match permission resources 1:1, so an explicit alias map was needed (`organizations→organization`, `device-users→devices`, `extra-duty→overtimes`, `overtime-policies→ot-policies`, `resignations→employees`, `supervisors→employees`).
3. **Caught a bug my own transform introduced:** `api/options/{type}` has route name `api.options` with no matching resource, so it received a literal `':None'` — an unmatchable permission string that would have 403'd a UI-critical endpoint permanently. Re-gated on `hrm.view` — a coarse grant that P1.3 has since replaced with a per-type gate, because `hrm.view` on the route rejected callers whose *specific* grant the consuming page accepts.
4. **Prevented an authorisation-induced regression.** Gating HRM immediately left Manager, Auditor and Staff with **0** `hrm.*` permissions — locked out of a module they could previously use. Added deliberate default grants: Manager gets a 50-permission operational subset (excluding probation termination and year-end management), Auditor gets 27 read-only permissions, Staff gets none by design.

**Result:** permissions 120 → **177**; phantom permissions **6 → 0**; role grants Super Admin 57/57, Administrator 57, Manager 50, Auditor 27, Staff 0. All 112 existing HRM tests still pass — the new ACL broke nothing.

### P0-G · Public unauthenticated biometric endpoints
Two routes sat under `mw=[web]` only — no `auth`, no permission — beneath a `// remove after fix` comment:

- `GET /test-device-compare?ip=&port=` → passed attacker-controlled host/port straight into `new ZKTeco()->connect()`. This is an **SSRF and internal port scanner** that also returned raw attendance samples, with a hardcoded `date('27-10-2026')`.
- `GET /test-service-sync?ip=` → invoked `AttendanceSyncService::syncDevice()`, an **anonymous write** into attendance records.

**Fix:** both deleted. The root debug pair was replaced with a `hrm/debug/*` group that is non-production-only and gated behind `PermissionMiddleware:hrm.attendance.manage`.

### P0-H · Open self-registration
`POST /register` created a `User`, fired `Registered`, and called `Auth::login()` — with **no role assigned**. Combined with P0-F this meant an anonymous internet visitor could obtain a session with full HRM access.

**Fix:** gated behind `config('core.auth.allow_registration')` (env `CORE_ALLOW_REGISTRATION`, default **false**), with `abort_unless(..., 404)` in both controller actions and role assignment only if `CORE_DEFAULT_REGISTRATION_ROLE` is explicitly set.

Two refinements found during verification:
- The gate was originally in `routes/auth.php` (`if (config(...))` around the route definitions). That makes the switch depend on the **route cache**, so flipping it in production would need `route:cache`, not just `config:cache`. Moved the gate into the controller so routes are always registered and the decision is per-request.
- **This broke the front end.** `route('register')` in `Login.vue` and `Welcome.vue` throws in Ziggy when the route doesn't exist. Fixed by sharing a `canRegister` flag from `HandleInertiaRequests` and guarding both links with `v-if`.

### P0-A · 32 routes pointed at six controllers that did not exist
`php artisan route:list` could not even complete — it died with `ReflectionException: Class EmployeeDocumentController does not exist`. A reflection sweep of the whole route table found **32 broken references** across 6 missing HRM controllers: `EmployeeDocumentController`, `EmployeeAssetController`, `EmployeeProbationController`, `EmployeeMovementController`, `ChecklistController`, `ClearanceController`. All six were **linked from the sidebar menu** — so employees' documents, assets, probation, transfers, onboarding checklists and exit clearance all crashed on click.

**Fix:** wrote all six controllers plus a new `ProbationService` (none existed), matching each Vue page's exact `defineProps` contract and each service's real signature. Also added the missing `ClearanceTask::clearedBy()` relation the clearance audit trail needs.

Two further gaps surfaced while wiring them:

- **Onboarding/offboarding checklists could never be completed.** `Checklists/Index.vue` shipped the literal text *"use API to toggle items; full per-item UI can be added in iteration 2"*. A `toggle()` function existed but nothing called it, so `progress_pct` would stay 0 forever. **Built the missing per-item UI** — expandable task list with Pending/Done/Skipped/N-A buttons, required-item markers, completion counts, and the responsible role.
- **The payroll reopen flow was entirely unreachable.** `requestReopen()` was defined but never invoked from any template, and `SheetFinance.vue` contains zero references to reopening. A released salary sheet with an error could only be reopened by hand-crafting an HTTP POST. Worse, the controller didn't catch the service's `DomainException`, so "sheet is not released" surfaced as a **500 error page**. **Fixed:** added an eligible-sheets picker to the controller, built the request panel with a proper form (replacing `window.prompt()`), routed approval through the shared `ConfirmDialog` (replacing `window.confirm()`), and handled `DomainException` as a validation error in both actions.

### P0-C · ACCOUNTS route shadowing made Bank Reconciliation unreachable
`Route::get('banks/{bank}')` was declared at line 44, `banks/reconcile` at line 70. Laravel matched `reconcile` as a `{bank}` parameter, then failed model binding → **404**. The Bank Reconciliation menu item (1 GET + 3 POST routes) was dead from its own navigation link. **Fix:** reordered so the literal segment precedes the wildcard.

### P0-D · Six phantom permissions → permanent 403
In Spatie v6, `hasPermissionTo()` on an **unknown** permission name returns `false` rather than throwing. So a route gated on an unseeded permission is a **permanent 403 for everyone**. `enable_wildcard_permission = true` let Super Admin pass via `*`, which hid the problem entirely.

**Fix:** seeded the 6 missing permissions (`accounts.banks.view`, `inventory.counts.view`, `inventory.kits.view/manage`, `purchase.landed.view/manage`), removed one dead permission, and removed an erroneous one I had added myself. Verified with a route-table walk cross-referenced against `Permission::pluck('name')`.

> **Detector lesson:** my first version of that checker matched the string `'permission:'` and reported a clean "PHANTOM 0". Spatie actually registers `Spatie\Permission\Middleware\PermissionMiddleware:<perm>`. The denominator was zero — a silent false pass.

### P0-E · Seven Inertia pages referenced but never written
Scanning all **218** literal `Inertia::render()` targets against `resources/js/app.ts`'s resolution rules found 7 missing `.vue` files: five HRM report screens (Headcount, Attrition, Leave, Attendance, OT), `LeaveYearEnd/EncashPreview`, and `AttendanceDebug/Index`.

**Fix:** built the six real pages (HR reports with department/designation/status breakdowns, monthly attrition trend, leave liability with carry-forward and lapse, attendance trend with lateness-by-department, OT cost, and the encashment preview).

For `AttendanceDebug/Index` the correct fix was **deletion, not creation**: `AttendanceDebugController::index()` is not routed anywhere — the live endpoint `attendance/debug-orphans` returns JSON. Building a page nobody can reach would have added dead UI, so the unreachable method and its now-unused `Inertia` import were removed instead.

### P0-I · Two high-severity npm vulnerabilities
`npm audit --omit=dev` reported **2 high**, 3 moderate, 1 low — notably **`vue` ≤ 3.5.41: XSS via missing CR in attribute-name blacklist** (`GHSA-g2v6-rqmx-r4w6`). `vue@3.5.13` was pinned in the lockfile while `^3.5.13` already permitted the fix, so `npm audit fix` was a no-op.

**Fix:** updated to `vue@3.5.44`. **Result: 0 high**, 3 moderate, 1 low. Build, Vitest and Pest all still green.

> `deepmerge` ≥ 4.2.0 (prototype poisoning, `GHSA-gcqx-w48m-w2h4`) has **no fixed release** — 4.3.1 is the latest and is itself vulnerable. It arrives transitively via `@inertiajs/core@2.0.3`. The only remedy is a major upgrade to `@inertiajs/vue3@3.x`, which is a breaking change and out of scope for this pass. Recorded as an accepted risk with a named remediation path.

### P0-J · Off days were classified as `Absent`

**Reported by the user:** "in HRM Attendance the off day not detect, currently Off day is showing as Absent … if roster duty not found then the system automatically detects the shift and auto-makes Late/Early status — that all works, but somehow the Off Day was missing."

Two independent defects combined to produce it.

**1. The off-day rule required the roster to be empty.** The reconciler only consulted an employee's fallback off day behind a `!$roster && !$duty` guard. But `RosterDutyRepository::bulkAssign()` rosters **every calendar day** in the selected window with `status = 'Scheduled'` and a shift — it does not skip weekends. So after any bulk rostering a duty row existed for the off day, the guard was false, and the day fell through to `Absent`.

The fallback off day had become unreachable in exactly the situation the user described: auto shift detection worked (it reads the duty's `shift_id`), while off-day detection silently did not.

**2. The range queries could not see the roster at all.** The reconciler, `AttendanceDailyRepository` and `RosterOvertimeService` matched roster duties, holidays and leave with `whereBetween('roster_date', [$from, $to])`. Those columns are Eloquent `date` casts, so Eloquent writes `2026-10-23 00:00:00`. On **SQLite** that string is stored verbatim, and `'2026-10-23 00:00:00' > '2026-10-23'`, so an inclusive `Y-m-d` range matched **nothing**. On MySQL the real `DATE` type truncates and it matches.

Verified directly in Tinker: the same row returned **0** under `whereBetween` and **1** under `whereDate`. Diagnostic instrumentation across five scenarios showed `rosterRowsSeen = 0` in all of them.

> **Consequence for the test suite:** Pest runs on `sqlite :memory:`, so the whole suite was blind to every roster/duty/holiday range query. This class of bug passes locally and fails only in production on MySQL — or, as here, hides a second bug behind the first.

**Fix.** New `modules/HRM/Services/Attendance/OffDayResolver.php`, registered as a singleton in `HRMServiceProvider`, plus a new `modules/HRM/Config/hrm.php` merged from the provider:

| Step | Rule | Result |
|---|---|---|
| 1 | Duty `status === 'Off'` | off day — explicit HR intent always wins |
| 2 | No fallback configured | not an off day |
| 3 | `dayOfWeek !== fallback` | not an off day — strict `!==` after an explicit null check, so **weekday `0` (Sunday) is never mistaken for "unset"** |
| 4 | Fallback matches, no duty | off day (the original undisputed rule) |
| 5 | Fallback matches, duty exists | off day unless overridden by config |

All nine `whereBetween`-on-a-date-cast call sites across the three files were replaced with `whereDate(...)`/`whereDate(...)` pairs (a private `dateRange()` helper in the reconciler).

**Why the default is safe.** `hrm.attendance.roster_overrides_fallback_off_day` defaults to `false`, i.e. the contracted off day wins over a duty that merely exists. This cannot mislabel worked time: any date carrying real punches is classified **from the punches** (`Extra Duty`), and `deduplicateDailyRows()` ranks punch-bearing rows highest. The flag can only move a *no-punch* day between `Absent` (a penalty) and `Off Day` (no penalty). Deployments that deliberately roster staff onto their off day can restore the strict behaviour with `HRM_ROSTER_OVERRIDES_FALLBACK_OFF_DAY=true` — no code change.

Nothing in the working pipeline was touched: auto shift detection, `Late`, `Early Out`, `Missing In`, `Missing Out`, `Present`, night-shift pending handling, reconciliation idempotency and the one-row-per-calendar-day invariant are all unchanged and all still covered by tests.

**Regression guard.** New `tests/Feature/HRM/OffDayClassificationTest.php` — **14 tests / 24 assertions**, including a bulk-rostered off day asserted to be `Off Day`, a check that the reconciler can actually *see* duties (asserting `count() == 1` **before** asserting status, so a false-negative harness cannot pass), the Sunday-`0` edge case, both config modes, and the punch path yielding `Extra Duty` with hours preserved.

The guard was **proved sensitive**: flipping the config default to `true` makes the bulk-rostered test fail (`- 'Present' / - 'Off Day' / + 'Absent'`); restoring the fix makes it pass.

Also updated: the stale help text in `Employees/Create.vue` and `Edit.vue`, which still read "Used only when no roster duty exists".

**Two related items found while fixing this:**
- `EmployeeDocumentService::expiringCounts()` had the same `whereBetween`-on-a-`date`-cast defect and was fixed the same way.
- **`WorkCalendar` is dead code.** `hrm_work_calendars` has `weekend_days` (default `[5, 6]`) and an `isWeekend(Carbon)` method, and there are **zero call sites** anywhere in the codebase. Company-level weekend configuration is therefore not consulted when classifying off days — only the per-employee fallback is. Wiring it in is a behaviour change requiring a decision on precedence, so it is recorded here rather than guessed at.

**Still open — same defect class.** Seven further `whereBetween`-on-a-`date`-cast sites were found and are *not yet* fixed: `RosterDutyController.php:155`, `HrmReportsService.php:92,98`, `LeaveRequestService.php:74`, `AttendanceProcessingService.php:453`, `LeaveBalanceService.php:27`. They are latent on MySQL and wrong on SQLite; each needs the same `whereDate` treatment.

### P0-K · Every typeahead picker in the ERP returned an empty list

**Reported by the user:** vendor / warehouse / product selectors dead on Purchase Order, Vendor Bill, Purchase Return, Goods Receipt, Quotation, Sales Order, Delivery, Invoice, Sales Return and Journal Entry — "even everywhere" — and the POS product search showing nothing despite stock existing.

**Four independent defects**, each of which alone would have produced the reported symptom. All four had to be found, because fixing any three still leaves broken pickers:

| # | Defect | Effect |
|---|---|---|
| 1 | `SearchableSelect` read only `{items:[…]}`, but PARTNER, INVENTORY, SALES and ACCOUNTS all return `{data:[…]}` | List always empty, UI said "No matching results" |
| 2 | **21 call sites** passed `api-url=`, but the prop is `endpoint` | Attribute fell through to the root `<div>`; those selectors had no endpoint at all |
| 3 | `PartnerRepository::options()` returned `partner_code` as `value` | Every consuming form validates `exists:partners,id`, so picking a vendor posted `"VEN-9595"` and failed |
| 4 | No `purchase/api/options` route existed; HRM lacked `assets` and `checklist-templates` types | `/purchase/api/options/receipts`, `/hrm/api/options/assets`, `/hrm/api/options/checklist-templates` all **404** |

Defect 1 is the headline: the endpoints were answering **200 with real rows** — 11 vendors, 11 customers, 16 products, 2 warehouses, 28 accounts — and the component threw all of it away. HRM and CORE use `{items:[…]}`, which is why HRM's pickers were the only ones that worked; the component had been written against them.

**Fixes**

- `unwrapOptions()` extracted to `modules/CORE/Resources/js/Utils/optionsPayload.ts` and made tolerant of `data`, `items`, `options`, `results`, a bare array, and a nested paginated `{data:{data:[…]}}`. An unrecognised shape degrades to `[]` rather than throwing, so a future module cannot take every picker down.
- All 21 `api-url=` → `endpoint=`, and 5 `:error=` → `:invalid=` (validation state was being silently dropped, so server errors never highlighted the field). `Deliveries/Create.vue` also pointed at `/sales/api/options/customers`, which SALES does not implement — repointed to `/partner/api/options/customers`.
- `PartnerRepository::options()` now returns `value => $p->id`, with the code moved to a new `meta` field.
- New `modules/PURCHASE/Http/Controllers/OptionsController.php` (`receipts`/`orders`/`bills`), **authorised per type** — receipts require `purchase.receipts.view`, orders `purchase.orders.view`, bills `purchase.bills.view`. It was the only options endpoint born gated, and became the model for P1.3 — which has since put all eight behind one shared gate and widened these lists from a single permission to any-of.
- HRM `OptionsController` gained `assets` (in-stock only, the issue flow) and `checklist-templates`.
- `SelectOption` gained `meta?: string` and `disabled?: boolean`.

Verified by requesting all 15 distinct endpoint URLs as the super admin: **all 200**, all in a readable envelope, all with the right `value` type. (HRM employees legitimately return a *string* `value` — `employees.employee_id` is a varchar business key, unlike partners.)

**Component polish** (the same component is on every one of those pages, so it is the highest-leverage UI work available):

- **Error state distinct from empty.** A 403/500 now renders "Couldn't load options" with a *Try again* button instead of "No matching results". This is precisely why defect 1 shipped invisibly — a broken request and an empty result set looked identical.
- **Selected labels now survive.** Endpoint mode replaces the list on every keystroke, so a chosen item fell out of `sourceOptions` and the closed control degraded to showing the raw value (`11` instead of "Amparo Lang Enterprise"). A `seen` cache fixes it.
- Matched text highlighted with `<mark>`; option `meta` rendered as a right-aligned badge (partner code, product price, warehouse code).
- Skeleton rows while loading; richer empty state; live result count with `aria-live="polite"`.
- `scrollIntoView` on keyboard navigation (guarded with `?.()` — it is absent in jsdom and some webviews), plus Home/End and Tab-to-close.
- a11y: `aria-controls`, `aria-activedescendant`, `aria-haspopup`, `aria-busy`, `aria-invalid`, per-option ids via Vue 3.5 `useId()`.
- Products now expose their **selling price** and warehouses their code, so a cashier can see the price before adding to the cart.

#### Two further defects found while fixing the POS

**`Input.vue` exposed nothing, so `ref.focus()` was a silent no-op.** `<script setup>` components are closed by default. The POS terminal refocuses the barcode field after every scan — `barcodeRef.value?.focus()` resolved to `undefined` and the optional call did nothing, so a hardware scanner typed into whatever held focus last and the sale was lost. Fixed at the primitive: `Input.vue` now `defineExpose`s `focus`/`blur`/`select`/`setSelectionRange`/`el`.

**`PosController` queried a column that does not exist.**

```php
Partner::where('type', 'customer')->orWhere('is_customer', true)
```

`is_customer` is not on `partners` and appears in no migration. **SQLite and MySQL silently coerce an unresolvable double-quoted identifier into a string literal**, so `"is_customer" = true` became `'is_customer' = 1` — permanently false. The query "worked", returned 11 rows, and quietly dropped every partner whose type is `both` (customer *and* vendor), who therefore could not be served at the till. It also skipped the `active()` filter that the warehouse and bank pickers beside it both apply. **On PostgreSQL the same line is a hard error.**

Confirmed rather than assumed: `select is_customer from partners` → `no such column`, while the Eloquent form returned `count=11` with `"is_customer" = ?` visibly present in the query log. Now `Partner::query()->customers()->active()`, matching the authoritative scope.

> **Generalises:** an unresolvable double-quoted identifier is a silent string literal on SQLite/MySQL and an exception on PostgreSQL. Any `where('column_that_does_not_exist', …)` in this codebase is invisible to the SQLite test suite.

**Terminal UI polish:** the ghost-line bug (a failed lookup pushed `{qty:1, unit_price:0}` with no product name, which submitted as a real zero-price item) now surfaces an error instead; oversell warning when qty exceeds available stock; cart rows keyed by `product_id` rather than index (splicing with an index key re-used the DOM node and left stale input in the next line); clear-cart action; feedback banners that self-clear and are announced via `aria-live`; 7 hardcoded light-only colours given `dark:` variants or semantic tokens; the disabled Sell button now says *why* it is disabled.

**Guards added — 23 tests, each proven sensitive by re-introducing the defect:**

| File | Tests | Proven by |
|---|---|---|
| `tests/Feature/SelectorEndpointIntegrityTest.php` | 9 | Walks the seam the failures lived in: scrapes every `endpoint=`/`api-url=` literal out of the templates, asserts the route exists, answers 200 for an authorised user, and returns an envelope the component reads. Reverting `value` to `partner_code`, one `endpoint` to `api-url`, or deleting the PURCHASE route each fail the matching test. |
| `modules/CORE/Resources/js/Utils/optionsPayload.test.ts` | 10 | Every envelope shape. Restoring `ENVELOPE_KEYS = ['items']` fails 4. |
| `modules/CORE/Resources/js/Components/SearchableSelect.test.ts` | 17 | Mounts the real component against a mocked `fetch` — remote and static modes, debounce, error-vs-empty, label persistence, keyboard nav, multi-select, disabled. Restoring `['items']` fails 7. |
| `tests/Feature/SALES/PosTerminalTest.php` | 5 | Dual-role partners offered, inactive excluded, numeric values, list matches the `customers()` scope. Restoring the `is_customer` query fails 3. |

The structural test also guards itself: it asserts the scan finds at least one endpoint, so a renamed prop or moved directory cannot make every assertion pass vacuously — the same trap as the off-day repro harness in P0-J.

---

## 3. Cross-cutting fixes

### Database portability
`HrmReportsService` was **SQLite-only and broken against the real schema**. It queried `employee_movements` (real table: `hrm_employee_movements`) and `resignation_date` (real column: `last_working_date`), used 6 raw `strftime()` calls, and embedded double-quoted SQL string literals (invalid under MySQL's default `ANSI_QUOTES`-off mode, and a column reference under PostgreSQL).

Created `modules/CORE/Support/SqlDialect.php` — `yearMonth()`, `day()`, `driver()`, and `assertSafeIdentifier()` (regex-validated, throws `RuntimeException` otherwise) — emitting `strftime` / `DATE_FORMAT` / `to_char` / `FORMAT` per driver and throwing on an unknown one.

`otCost()` was rewritten entirely. It previously joined on `approved_hours`, `duty_date` and `hourly_rate` — **none of which exist**. It now joins `roster_overtimes` → `payroll_attendance_duty_decisions` → `employee_salaries`, takes `COALESCE(approved_hours, ot.hours)`, and values hours at `gross_salary / 208` (26 days × 8 hours) via a named `OT_MONTHLY_HOURS` constant, because **the schema has no hourly-rate column**. That basis is now stated on the OT report page rather than hidden in SQL. The return contract was preserved exactly so `Reports/Index.vue` kept working.

### Other correctness fixes
- **`INVENTORY/CountController`** referenced a non-existent `InventoryCount` model (real: `StockCount`) and table `inv_warehouses` (real: `inventory_warehouses`) — 5 and 1 occurrences. Also chained `applyDataTable()` as if it returned a builder; it is **`void`**. Fixed to call it explicitly.
- **`SALES/DeliveryRepository` and `SalesReturnRepository`** didn't follow the `InvoiceRepository` shape at all. Rewritten with proper `leftJoin`, explicit `select('<table>.*')` to avoid column collisions, and correct searchable/sortable/filter lists.
- **Seeders leaked the super-admin password** to stdout. Removed from both `CoreSeeder` and `SuperAdminSeeder`; they now print the email only and point at `CORE_SUPER_ADMIN_PASSWORD`.

### Ledger and persistence primitives (new)

Two of the fixes below are not local edits but primitives that every money path now runs through, so
they are named here rather than only under their findings:

- **`modules/CORE/Support/Concerns/CoercesNullNumerics.php`** (P1.13) — an overridable
  `nullToZero(): array` plus a `creating` hook that writes `0` for the numeric columns present in the
  attributes but set to null. Used by all 19 line-item models. It deliberately ignores *omitted* keys
  so a database default still applies, and never coerces `id` or `*_id`.
- **`modules/ACCOUNTS/Support/ChartOfAccounts.php`** (P1.19) — `requireId()` and
  `firstExistingId()`, the single choke point through which all seven document services now resolve an
  account before marking anything posted. A missing code throws a `DomainException` naming the account
  and the document, instead of skipping the journal and stamping the document anyway.

Both are pinned by tests that fail if the primitive is bypassed rather than merely broken:
`LineItemCoercionTest` discovers its 19 users by reflection, and `ChartOfAccountsGuardTest` asserts as
a source contract that no document service calls the nullable lookup directly.

### Security headers (new)
Added `app/Http/Middleware/SecurityHeaders.php`, registered **globally** in `bootstrap/app.php` (not just the `web` group, so downloads and JSON responses are covered too): `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, a restrictive `Permissions-Policy`, `X-XSS-Protection: 0` (deliberate — the legacy auditor is itself a risk), HSTS on secure requests only, removal of `X-Powered-By`, and a full CSP.

Every value is driven by `config('core.security_headers')`, with a `report-only` switch so a deployment can observe violations before enforcing.

Two deliberate engineering decisions, both documented in code and pinned by tests:
- **`style-src` keeps `'unsafe-inline'`.** Every Vue page binds element styles (`:style="{ width: pct + '%' }"`) for progress bars and charts; CSP counts a style *attribute* as inline and would blank them all. `script-src` does **not** allow it. Removing the style exception needs a refactor to CSS custom properties — recorded as follow-up rather than shipped as a silent UI regression.
- **The Vite dev server is allowed while `public/hot` exists**, including the HMR websocket, and the allowance disappears the moment the hot file is gone. Without this, `npm run dev` renders a blank page under CSP.

#### The first version of this CSP broke the login page — and the tests said it was fine

Shipped as `script-src 'self'`, the policy blocked the two inline scripts the page depends on:

- **Ziggy's `@routes` directive** emits `<script>const Ziggy={…}</script>` into the `<head>`. Blocked, so `window.Ziggy` was `undefined` and every `route()` call threw. `AuthSimpleLayout.vue` resolves `route('home')` during render, so the app died at mount with `TypeError: Cannot read properties of undefined (reading 'home')` — a blank login page.
- **The Bunny and Inter font stylesheets** (`fonts.bunny.net`, loaded by `app.blade.php` on every page; `rsms.me`, loaded by `Welcome.vue` through `@inertiaHead`) were blocked by `style-src`, and the font files themselves by `font-src`.
- `Permissions-Policy: interest-cohort=()` produced a permanent *"Unrecognized feature"* console error. FLoC was withdrawn and Chrome no longer recognises the token, so it protected nothing.

**All 11 original tests passed throughout.** They asserted on the *header string* and never on the HTML served alongside it, which is exactly the blind spot that let a page-killing policy through. This is the same false-negative failure mode as the off-day repro harness in P0-J: the check was measuring its own assumption, not the outcome.

**Fix — per-request CSP nonces, not `'unsafe-inline'`.** Widening `script-src` to allow inline scripts would have removed the XSS protection the header exists to provide, so the nonce path was used instead. Both emitters support one natively:

- `SecurityHeaders` now mints the nonce **before** `$next($request)` — the body is rendered inside `$next()`, so a nonce applied only to the header afterwards would match nothing. `Vite::useCspNonce()` owns the value, making it the single source of truth for the header, the asset tags and Blade.
- `applyNonce()` appends `'nonce-…'` to `script-src` / `script-src-elem` only. Adding it to `style-src` would make CSP level 2+ browsers discard `'unsafe-inline'` there and blank every Vue `:style` binding.
- `app.blade.php` passes it through: `@routes(null, $cspNonce)`. Ziggy's `BladeRouteGenerator::generate()` and both its `Script` and `MergeScript` output classes accept a nonce. When headers are disabled the value is `null` and Ziggy omits the attribute, so nothing breaks.
- `style-src` / `font-src` now allow `https://fonts.bunny.net` and `https://rsms.me`.
- `interest-cohort=()` replaced with `browsing-topics=()`.

Verified against a real request through the HTTP kernel: `/login` returns **200**, the nonce in the header matches the nonce on every inline script, and **0 inline scripts are unnonced**.

**6 new tests (17 total)** compare the policy against the HTML actually served — every inline script must carry the nonce from *its own* response, the Ziggy config must be present in a permitted script, the nonce must differ between requests, and the font origins and Permissions-Policy are pinned.

**Sensitivity proven:** reverting `@routes(null, $cspNonce)` to `@routes` fails the two new tests while all 11 original ones still pass — demonstrating both that the guard works and that the old suite could not have caught this.

> **CI gap this exposed, now fixed:** the `php` job ran Pest without building assets, so `@vite` would have thrown *"Vite manifest not found"* on a clean runner. `npm ci && npm run build` now runs before Pest in that job.

> **Known Ziggy caveat:** `BladeRouteGenerator::$generated` is a **static**. The first render in a process emits `const Ziggy={…}`; every later render emits `Object.assign(Ziggy.routes,{…})`, which assumes the first one ran. Harmless under PHP-FPM (one process per request) but broken under Octane/FrankenPHP, where a worker would serve the merge script with no `Ziggy` to merge into. Relevant only if a long-running server is adopted.

**17 tests** assert the headers and the served HTML against real responses, including that the dev allowance is revoked.

### Front-end quality
- **Zero `v-html` remains in the entire front end.** Nine pages used `v-html="l.label"` on the Inertia `<Link>` **component** to render Laravel's HTML-escaped paginator labels (`&laquo; Previous`). Vue rejects `v-html` on a component root, so pagination labels were rendering blank on all nine; it was also an injection sink and an ESLint error.
  Rather than patch nine copies, created **`modules/CORE/Resources/js/Components/Pagination.vue`** — entity-decodes labels to plain text, renders disabled pages as inert `<span>` (the old code used `:href="l.url||'#'"`, so clicking "Next" on the last page navigated to `#`), adds `aria-current`/`aria-disabled`/`aria-label`, and accepts an optional record-count caption. Replaced **10** hand-copied paginators with it, including `Holidays/Index.vue` which used plain `<a>` tags and therefore forced a **full page reload on every page turn**.
- **Fixed a money-rendering bug.** `Reports/Index.vue` had `{{ …cost ?? 0 | 0 }}`. Vue 3 removed filters, so `|` is bitwise OR; precedence makes this `x ?? (0|0)` — a no-op that renders raw SQL float aggregates like `48213.770000000004` next to "BDT". Now formats through an `Intl.NumberFormat('en-BD')` helper.
- **Removed internal build notes shipped to end users.** `EmployeeDocuments/Index.vue` rendered a paragraph of developer commentary including a raw `whereDate('expiry_date','<',now())` snippet whose unescaped `<` broke out of the template (`vue/no-parsing-error`).
- **ESLint: 140 errors → 0.** 95 unused import specifiers, 24 needless `const props = defineProps()` assignments, plus a no-op `watch(grandTotal, …)` whose body was only a comment, a `const o = (typeof window !== 'undefined' ? null : null)` nonsense binding, a ternary used as a statement, and dead refs (`bankId`, `localSearch`, `showLegend`, `bdtFormatter`).
  Four "unused" symbols turned out to be **unwired features rather than dead code** — the checklist `toggle()`, the payroll `requestReopen()`, and the roster grid's `quickRange()`. Those were built out (see P0-A); `quickRange` is now Today/7d/30d presets in the date toolbar.
- **Bundle:** added `manualChunks` to `vite.config.ts`. Rollup had been hoisting radix-vue, `class-variance-authority` and the whole ui-kit into whichever component pulled them first — a chunk *named* `Button.vue` at **126.62 kB**. Named vendor chunks now make that code stable and cacheable across deploys: `app.js` **329.96 → 67.52 kB** (gzip **108.40 → 16.17**), `Button` chunk **126.62 → 1.55 kB**, `AdminLayout` 62.77 → 35.63 kB, `UiKit` 53.57 → 29.97 kB.
- **`HandleInertiaRequests` no longer ships the whole `User` model** to the browser on every request. `password` and `remember_token` were already in `$hidden`, so no credential leaked, but every other column and any loaded relation did. Now whitelisted to six fields. Also removed a duplicate `parent::share()` call.

### Regression guard (new)
Added `tests/Feature/RouteIntegrityTest.php` — four structural assertions over the whole application that catch entire *classes* of the defects above:
1. every route targets a controller class **and method** that exist
2. every route middleware class exists
3. every `Inertia::render()` target resolves to a real `.vue` file (mirroring `app.ts`'s `MODULE::Path/Page` resolution exactly)
4. every permission a route enforces is actually seeded

Assertion 4 needs `$this->seed(DatabaseSeeder::class)` because Pest runs on `sqlite :memory:` with `RefreshDatabase` and **seeds nothing** — without it, the test reports every permission in the application as phantom. That false positive cost real debugging time and is now encoded in the test itself.

### CI (new)
`.github/workflows/ci.yml`: PHP 8.3/8.4 × SQLite/MySQL matrix, `composer audit`, `php -l` over all sources, Pest, a **post-seed row-count assertion** (because `migrate:fresh --seed` exits 0 even when a seeder throws mid-way — the exact failure mode behind P0-B), plus a front-end job running ESLint with `--max-warnings=0`, Vitest, a production build, and `npm audit --audit-level=high`. A single `ci` aggregate job exists for branch protection.

**Pint is blocking** (item 28). It was advisory while 646 of 832 files diverged from the preset, because normalising then would have buried every substantive change in one diff; the tree has now been normalised, `pint.json` names the preset, and `continue-on-error` is gone. `CiWorkflowContractTest` fails if the step stops running `--test`, if `continue-on-error` returns to *any* step, if ESLint is given `--fix`, if the front-end build moves after the tests that render it, or if the aggregate job stops failing when a dependency fails.

### Migration/data separation (new) — item 38

**Migrations change schema; seeders create content.** P0-B was not caused by a bad seeder or a bad
migration. It was caused by a migration creating one row of content, which switched off the guard of
the seeder that owned that content. Item 38 removes the pattern rather than the instance, and pins the
rule with a test.

**The scan.** All **89 migrations** in both locations (`modules/*/Database/Migrations` and
`database/migrations`) were read as tokens with comments stripped, excluding `Schema::` and `Blueprint`
so that DDL cannot be counted as DML. **Four** write data. The first pass at this scan reported
"82 of 89 migrations contain DML" and was pure artefact: `[A-Z][A-Za-z]*::create\s*\(` matches
`Schema::create`, which is in 79 of them. The corrected scan also found one the first pass missed —
a `->where()` between `DB::table()` and `->update()` is invisible to a pattern that does not allow for
an intervening call — so the figure below is four, not the three the ad-hoc scan reported:

| Migration | `up()` | `down()` | Verdict |
|---|---|---|---|
| `PURCHASE/…/2026_11_03_000075_add_grni_to_receipts` | creates ACCOUNTS' chart-of-accounts row **2115** | drops the FK and column only | **Provisioning — extracted.** Also asymmetric: `down()` left the account behind |
| `HRM/…/2026_10_30_110000_add_buffer_and_fallback_to_shifts_and_policies` | inserts the **`FLEXI / Ad-Hoc`** fallback shift | deletes it | **Provisioning — extracted** |
| `HRM/…/2026_10_28_000002_add_biometric_id_to_employees` | backfills the `biometric_id` column **it adds in the same migration**, from `device_users` | DDL only | **Allowed** — a column backfill travels with its column |
| `HRM/…/2026_11_01_000001_add_off_day_status_to_attendance_dailies` | DDL only | converts `Off Day` rows back to `Absent` | **Allowed** — the enum cannot be narrowed while rows hold the removed value |

Both allowlists are by filename **with a reason**, and the test fails if an allowlisted migration stops
writing data — a stale allowlist is how a scanner goes blind without anyone noticing.

**What moved.** Account 2115 → `AccountsDemoSeeder`, which already listed it among the 34 children of
the five groups. The fallback shift → a new `Modules\HRM\Database\Seeders\RosterFallbackShiftSeeder`,
wired into `DatabaseSeeder` between the permission seeders and `BiometricDeviceSeeder`. Both migrations
keep every column they exist to add, and each now carries a comment naming the seeder that owns the row
and the defect that made the move necessary. `110000`'s `down()` still deletes the shift: it is dropping
the columns the row is defined by, which is the one thing `down()` is allowed to undo.

**The rows were not equivalent, and a naive extraction would have been a regression.** The migration
created 2115 with `is_system = 1`, `is_posting = 1` and a description; `AccountsDemoSeeder`'s entry set
none of the three. `is_system` defaults to **0** in the schema, so simply deleting the migration's write
would have left the account every GRNI accrual posts to **deletable from the chart-of-accounts screen**.
`is_posting` would have survived only by accident — `Account`'s `saving` hook derives it as
`! $m->is_group`. The seeder entry now carries `is_system` and a description that says what the account
does. *When a write moves from a migration to a seeder, diff the row, not the call.*

**Measured, fresh `migrate:fresh --seed --force`, before → after.** `acc_accounts` **35 → 35**;
`roster_shifts` **1 → 1**; the fallback shift's every value byte-identical (`FLEXI / Ad-Hoc`,
`00:00:00`–`23:59:00`, `is_fallback 1`, `max_duration_hours 24`, both buffers `0`); 2115 keeps
`is_system 1`, `is_posting 1`, `is_group 0`, `is_active 1`, `liability`/`credit`, `level 2` under
Liabilities. What changes is identity, not semantics: 2115's id **1 → 17**, its `parent_id` **3 → 2**
(the Liabilities group in both cases — the group's own id moved), its name `GRNI — Goods Received Not
Invoiced` → `Goods Received Not Invoiced (GRNI)`, its slug with it, and its description. Every other
account's id shifts by one, because 2115 no longer occupies id 1. Nothing in the codebase depends on
that: a grep for a literal account id (`'account_id' => <int>`, `accountId: <int>`) returns zero hits
outside migrations, and `ChartOfAccountsGuardTest`'s code→name map builds its own fixture accounts
rather than reading the seeded chart. An *external* integration that stored account ids would need
re-mapping, which is a deploy note rather than a code change.

**The failure mode is now loud, and the test proves the rollback with it.** With 2115 removed, posting a
goods receipt throws:

> Cannot post the GRNI accrual for GRN-00001: the chart of accounts has no account coded '2115'. Create
> that account, or point accounts.defaults at the code you use, and post again. Nothing was written and
> the document was left unposted.

The receipt stays unposted with `grni_journal_id` null, no journal row appears, **and the stock the
service had already received is rolled back with it** — `GoodsReceiptService::post()` restocks *before*
it resolves accounts, so that assertion is the one that proves the whole posting runs in a single
transaction. Restoring the account through the seeder, not a migration, then posts the same receipt
normally. Asserting on `journal_id` here would have been vacuous: `post()` only ever writes
`grni_journal_id`.

**Deployment.** `DEPLOY.md` step 2 already runs `migrate:fresh --seed --force`, so the documented path
provisions the chart and nothing is lost by the move; a migrations-only deploy now has no chart at all,
which is correct — it has no content of any kind — and every posting path refuses with the message above
instead of writing empty documents. `docs/PURCHASE_GRNI_HARDENING.md` already told the operator to run
`db:seed --class=AccountsDemoSeeder # ensures 1200/2115/1330/2100 exist`; its two descriptive lines
naming the migration as the account's source are corrected. Two comments in the code that described the
old world were also false after the move and are rewritten: `ChartOfAccounts`' explanation of P0-B, and
`AccountsDemoSeeder`'s claim that migrations may auto-create accounts.

**Test.** `tests/Feature/CORE/MigrationDataSeparationTest.php` — 9 tests, 62 assertions, ~57 s (every
test seeds the full `DatabaseSeeder`, ~6 s each). Written first: it failed 5 of 9 before the extraction,
naming both migrations and the missing seeder class. Its scanner is held to this report's standard for
scanners, so it carries a sensitivity probe — six provisioning snippets it must flag, five pure-DDL
snippets it must not, and a migration whose *comment* mentions an insert, which must not be counted.
Without that probe, a clean result and a blind scanner are indistinguishable.

**Gates after this round.** Pest **498 passed / 1 skipped / 3,401 assertions** in **564 s**, from 489 / 1 /
3,339 in 569 s: the new file contributes exactly **+9 tests and +62 assertions**, and the wall clock went
*down* 5 s even though the file adds ~57 s of it, so run-to-run variance on this sandbox is at least a minute
and neither figure is a performance claim. `pint --test` passes on all **872** PHP files and `php -l` is clean
on the same 872, both up from 870 — the fallback-shift seeder is a source and the separation test is a test,
giving **63** test files against **786** PHP sources under `app/` and `modules/`. No front-end file changed
this round, so ESLint (0 errors), Vitest (213 tests / 6 files), `vite build` (success) and `vue-tsc` (20 base /
562 whole-tree errors, still the one red gate, still not in CI) are unchanged from item 31.

**Finding 39 (P3) — 22 of 39 seeder classes are never called.** Measured: 39 seeder classes exist under
`modules/*/Database/Seeders` and `database/seeders`; `DatabaseSeeder` calls **17**; **22** are orphans,
and 21 of those are referenced by nothing anywhere in the codebase (the 22nd, `RealEmployeeImportSeeder`,
is called by `RealEmployeeBasicSeeder`). One of them, `RosterShiftSeeder`, contains a fallback-shift
block **byte-identical to the migration's**, so that row had two owners and neither was authoritative —
which is the same shape as P0-B one level up. It was deliberately *not* wired up as part of this item:
it also creates six real shifts, and real shifts change how every punch in every install is classified,
so wiring it is a content decision that needs its own measurement rather than a side effect of moving one
row. The recommendation stands on its own: wire the orphans or delete them, because a seeder nobody calls
is a claim about provisioning that the codebase does not keep. The full list is in the test's failure
output and in §4 P3.

**Finding 41 (P2) — the allowed backfill swallows its own failure.** `2026_10_28_000002` wraps its
backfill in `try { … } catch (Throwable $e) { Log::warning(…) }`. If it throws, every
`employees.biometric_id` stays null, attendance device-mapping silently degrades to manual, and the
migration still exits 0 — the tolerated-swallow family of P1.1, inside a migration, where the only trace
is a `Log::info` count nobody reads during a deploy. It is allowed by the policy above (a backfill
belongs with its column) and published rather than fixed here, because fixing it means deciding whether a
failed backfill should abort a deployment — which is a question about the 998 existing employee rows, not
about this item.

### POS day-end posting (new) — item 39

**Five sentences of user report, four of them defects, and two true for a different reason than the
one given.** The ask was: post the POS day-end to the ledger; allow credit sales at the till and
collect them; make the trial balance visible; give a day-end and collection report; and stop the
sidebar being disturbing. Each was investigated in the code before anything was edited, and the
investigation changed the shape of two fixes. "I can not see any trial balance" was half right — both
halves were real, and repairing either one alone would have left the complaint exactly as true as it
was. The sidebar complaint named a feeling; it had four distinct causes and one structural one.

#### What the till actually did

`PosService::sell()` created a `sales_invoices` row **and posted it** for every counter sale, then
`closeSession()` — the one event that counts the drawer — wrote nothing to the ledger at all. Three
consequences, all measured:

- **One journal per sale, not per day.** A 200-sale day wrote 200 invoices and 200 posted `SV`
  journals. The demo data proved it from the other end: after the rewrite, `sales_invoices` fell from
  **7 to 3** — four rows that were counter sales wearing invoice numbers are simply gone.
- **A phantom open receivable for every cash sale.** `JournalService::syncPayableReceivable()`
  (:269–293) books `amount = total_debit` as an open receivable for *any* `RV`/`SV` journal that
  names a customer partner. The POS passed the counter customer, so every instant-cash sale landed in
  AR aging as money owed, and nothing ever settled it: the POS never called `recordPayment()`. The
  customer was named "Counter Credit Customer" and the aging report was full of settled sales.
- **Nothing reconciled.** There was no posting to reconcile the counted drawer against.

`PosSaleItem::$fillable` also excluded `discount_amount` and `vat_amount`, so per-line discount and VAT
were computed and then silently dropped on insert (**finding 42**). And `PosController::void()` flashed
"Stock returned and the journal reversed" for a reversal that the code did not perform, while
`Terminal.vue` told the operator that VAT "posts to 2110 on invoice" — copy that described the ledger
as doing something it was not doing (**finding 44**).

#### The model now: one balanced journal per session

Schema-only migration `modules/SALES/Database/Migrations/2026_11_07_000001_pos_day_end_posting.php`
(**90 migrations**, up from 89; no row is written in it, per item 38's rule): **14 new columns** across
`pos_sessions` and `pos_sales`, a new `pos_collections` table, and a rebuild of `pos_sales.payment_method`'s
CHECK constraint to admit `'due'` — each change guarded by `Schema::hasColumn`/`hasTable` so the
migration is idempotent on a database that already has part of it.

The sale records the transaction and takes the stock off the shelf in real time. The accounting happens
once, when the session closes. `PosDayEndPostingService` posts a single `SV` journal with these legs:

| | Account | Amount | Rule |
|---|---|---|---|
| Dr | Cash in Hand `1001` | `cash_total` | omitted when zero |
| Dr | the bank's **own** GL account (`100201`/`100202`) | `bank_total` | omitted when zero; a bank with no mapped account falls back to cash |
| Dr | Accounts Receivable `1100` | `due_total` | omitted when zero |
| Cr | Sales `4001` | `net_sales_total` | gross **less** discount |
| Cr | VAT Payable `2110` | `vat_total` | |
| Cr | Accounts Receivable `1100` | `collected_total` | **gross**, never netted against the debit — see below |
| Dr | COGS `5001` | `cogs_total` | sum of the per-sale cost snapshots |
| Cr | Inventory `1200` | `cogs_total` | |

Zero legs are omitted rather than posted at 0.00, so a cash-only day produces four lines and not eight.
`accounts.defaults` gained **`cogs_code` = 5001** to make the COGS leg configurable like every other.

Two of these decisions are load-bearing and both are pinned by tests:

**`partnerId` must be null on the day-end journal.** It is an `SV` journal, and `syncPayableReceivable()`
would book its entire debit as one receivable against whatever partner it names — recreating, at session
scale, the exact phantom-debt defect this item removes. The receivables are raised per customer by the
sale path instead, where the terms and the customer belong.

**Dues collected in the same session post gross on both sides.** A 5,622.75 credit sale collected 2,811.38
at the counter before close produces Dr AR 5,622.75 and Cr AR 2,811.38 as separate legs, with the
receivable born **part-paid** (balance 2,811.37, status `partial`, due date from the customer's
`payment_terms_days`). Netting them would hide the gross credit exposure from the aging report and make
the day's collection figure unreconcilable to the drawer.

#### Credit at the till

`'due'` is now a payment method, with a `pos_collections` row per collection (`POS-COL-00001`), and two
rules that a naive implementation gets backwards:

- **A collection belongs to the drawer that took the money**, not to the sale it settles. A credit sale
  rung up by cashier A on Tuesday and collected by cashier B on Wednesday is Tuesday's credit and
  Wednesday's collection. The report and the day-end both attribute it that way.
- **Credit control runs before the stock moves.** `PosService::assertWithinCreditLimit()` fires only for
  `'due'`; `Partner::credit_limit <= 0` means unlimited; exposure is the sum of open/partial/overdue
  `Receivable::balance` for that customer plus this sale; exceeding it throws `DomainException` and the
  sale is not written. Checking after the stock move would leave the goods gone and the sale refused.

#### The day-end and collection report

`PosReportService::dayEnd(array $filters)` returns `['rows','byUser','totals','filters','range']` — one
row per drawer, a per-cashier breakdown ordered by takings, and totals. Filters: date range
(`whereDate('opened_at')`), cashier (`opened_by`), warehouse, status; the controller defaults to the
current month, the service to unbounded so a test can assert the whole history.

Three rules make its figures mean something:

- **`cash` and `bank` are sale tender only.** A closed session's stored `cash_total` *includes* counter
  collections, so reconciling counted cash against `cash` is wrong by exactly the collections taken.
  The row therefore carries **`drawer_cash`** (sale cash + collected cash), and `expected_cash` =
  opening + `drawer_cash`, which is the number the counted drawer is compared against.
- **Open drawers are derived from their children**, not from their stored counters, which are only
  written at close. An in-progress session shows live figures instead of zeros.
- **All money is decimal text** matching `^-?\d{2}$`, aggregated in SQL as `ROUND(SUM(CASE WHEN status
  <> 'void' THEN col ELSE 0 END) * 100)` grouped by session. A void counts as a void and drops out of
  takings; a short count is a **negative** variance, not an absolute one.

`sessionDetail(int $sessionId)` backs the close screen: the session, its sales and its collections.

#### "I can not see any trial balance" — two independent causes

The report exists, is routed (`accounts.reports.trial`) and *was* linked in the menu. It was invisible
for two reasons that had nothing to do with each other:

**Cause 1 — permission.** `accounts.reports.view` was granted to the Super Admin alone. Administrator,
Manager and Auditor all answered **no**, so the menu item was filtered out for them and the route 403'd.
Fixed in both permission seeders: the SALES seeder now grants `sales.{quotations,orders,invoices,deliveries,returns}.view`
and `pos.reports.view`; the ACCOUNTS seeder grants `accounts.{reports,coa,journals,vouchers,banks,payables,receivables}.view`.
Read permissions only — posting, voiding and chart-of-accounts management stay Super-Admin-only, and
`ReportAccessTest` asserts that an Auditor who can read the trial balance still gets **403** posting a
journal. Vocabulary **177 → 178** permissions (`pos.reports.view`).

**Cause 2 — the menu's third level was never drawn.** `menu.ts` nests `Accounts › Financial Reports`
and `Inventory › Inventory Reports`, but `TreeSidebar.vue` rendered exactly two levels. Each nested group
was drawn as a *leaf*: it has no `route` or `href`, so `hrefFor()` returned `'#'`, and its children were
never rendered at all. **Ten items were unreachable for every role including the Super Admin** — Trial
Balance, Profit & Loss, Balance Sheet, General Ledger, AP Aging, AR Aging, Bank Reconcile, FIFO Layers,
Reorder & ABC, Lots & Expiry. The filter could not find them either (it searched one level deep, so
"Trial" matched nothing), and `expandActiveBranches()` descended one level, so landing on the trial
balance opened nothing.

Both are fixed: the third level renders as a nested collapsible, and filtering, active-detection and
auto-expansion are now recursive. `treeSidebarPolish.test.ts` pins **reachability rather than markup** —
it opens every branch and asserts that *every leaf in the config appears in the DOM*, lists the ten by
name, asserts the branch opens when the current page is the trial balance, asserts the filter finds a
three-level item, and fails if the config ever grows a fourth level the renderer cannot draw. That last
assertion is the one that keeps the defect from returning: nothing in the codebase previously connected
"an item is in the menu" to "a user can click it".

Measured after `migrate:fresh --seed --force`, the grants are now:

| Role | Permissions | Users | `accounts.reports.view` | `pos.reports.view` |
|---|---|---|---|---|
| Super Admin | 164 | 1 | yes (holds `*`) | yes |
| Administrator | 108 | **0** | yes | yes |
| Manager | 69 | 1 | yes | yes |
| Auditor | 45 | 1 | yes | yes |
| Staff | 2 | 1 | **no** | **no** |

#### The sidebar

Four things made it disturbing, and one structural thing made it wrong. Every section was expanded on
first load (**~100 rows** before anything could be read); sections were ordered by module build order
rather than by work order, so `Design System` sat between Sales and Audit; the footer advertised (a
`PRO` badge, "Production ready", and a "Secure session · audit on" line with a pinging dot); and `⌘K`
was advertised twice in the placeholder and the footer with **no key handler anywhere**. A `mask-image`
fade across the bottom of the scroll area made the last item look permanently disabled, and five labels
carried emoji (`📁 🎒 📊 🌊 🧩`) that no other label did — four of which a targeted grep for emoji found
and the fifth did not, which is why the test now scans for `ord(c) > 126` rather than for instances.

Now: collapse-by-default (the stored state key was bumped `core.sidebar.tree.v1` → **`v2`** so an
all-open state saved by the old build does not survive the fix); work-order bands (**People**, **Supply
& Sales**, **Finance**, **Administration**) drawn as headers per group change; a real `Ctrl/⌘K` handler
on `window` that focuses the filter and is removed on unmount, with `Escape` clearing and blurring; a
footer that says who is signed in (initial avatar, name, email, null-safe) and that only permitted items
are listed; no marketing, no fade; per-section child counts; an empty state when a filter matches
nothing. `PAYROLL` became `Payroll`, all emoji are gone, and the 15 top-level entries sit in 4
contiguous bands.

#### Demo data

`DemoDataSeeder::posSales()` read a column named **`sale_price`**, which does not exist on
`inventory_products` (the real columns are `cost_price`, `selling_price`, `mrp`, `vat_rate`). On SQLite
a typo'd column in a `select` returns null rather than erroring, so every demo POS and invoice line was
priced at the `?: 900` fallback — four sites, all fixed to `selling_price`.

What follows is measured after `migrate:fresh --seed --force` and `erp:demo-data --force` (8.7 s), and
the **structure** is what is worth publishing, because the amounts are not reproducible — see finding
46. Two seeds of the same code gave a POS-DEMO-1 net of 16,020.00 and of 5,887.67.

- **POS-DEMO-1** closed and posted: 4 transactions (cash, card, cash, credit), opening cash 5,000.00,
  a counted drawer **50.00 short of expected**, so the variance reads **−50.00** and the close screen
  has something to explain; journal **SV-00004** with `partner_id` **NULL**, **Dr = Cr** (11,543.28 on
  the latest seed), and exactly **7 legs** — Dr 1001 cash, Dr 100201 the bank's own GL account, Dr 1100
  receivables, Dr 5001 COGS, Cr 4001 sales, Cr 2110 VAT, Cr 1200 inventory. No Cr 1100 leg, because
  nothing was collected in that drawer, and a zero leg is omitted rather than posted.
- **POS-DEMO-2** left **open** with 1 transaction and every stored total at zero, so the report has to
  derive it from its children — a live drawer next to a posted one.
- `pos_sales` **6 rows, every `invoice_id` NULL** (was 7 invoices before the rewrite), and one credit
  sale carrying a due with half of it collected.
- `pos_collections` **POS-COL-00001**: taken in session 2 against a sale of session 2, cash, pointing
  at `receivable_id` 6.
- `acc_receivables` id 6 — `Counter Credit Customer` / `POS-DEMO-1`: raised gross, half received,
  **balance = amount − received** to the cent, status **`partial`**, due date **15 days** out from the
  customer's own `payment_terms_days`, not from the 30-day config fallback.
- `sales_invoices` **3**, `acc_journals` **15**, `partners` **23**, `permissions` **178**. (Item 40 raises
  `acc_journals` to **28**: the demo now posts the 13 further payroll payment journals it had been deliberately
  skipping under finding 35. The figures above are the measurement at the end of item 39 and stay as recorded.)

#### Tests

Written first where the behaviour was not yet known: `PosDayEndPostingTest.php` (**14 tests**) was
written before `PosDayEndPostingService` existed, and `PosCollectionReportTest.php` (**8**) before
`PosReportService`. New this round: `tests/Feature/SALES/PosDayEndPostingTest.php`,
`tests/Feature/SALES/PosCollectionReportTest.php`, `tests/Feature/ACCOUNTS/ReportAccessTest.php`
(**6 tests / 7 cases**), `modules/CORE/Resources/js/Layouts/treeSidebarPolish.test.ts` (**10 tests**).
Rewritten: `PosVoidTest.php` (4 — voiding inside a closed, posted session is now refused with the
reason, because the reversal it used to promise no longer exists). Re-pinned: `MoneyColumnCastTest`
**156 models / 242 money columns** (was 230 — the migration's 12 new money columns are all `decimal:2`,
which is the pin doing its job), `OptionEndpointAuthorisationTest` **178 permissions**,
`NativeDialogTest` **46 calls in 33 files**.

**One pin in `SilentFailureInventoryTest` was re-pointed deliberately, and the reason matters.** It
asserted that `PosService.php` carries `'Could not reverse the invoice journal for POS'` — item 26's
guard against swallowing a failed reversal. A counter sale no longer *has* an invoice journal, so that
message exists nowhere in the codebase and the guard it described cannot exist. The pin now names the
guard that survived the rewrite, item 26's restock wrap (`'the stock could not be returned to the
shelf'`), with the reason recorded in the test. A pin that fails because the code got better is a pin
that needs updating, not silencing — but only after proving, by grep, that no guard was lost with it.

`PosCollectionReportTest`'s eight cases are the ones a report needs: one row per drawer including an
open one; a collection attributed to the *collecting* cashier; a closed drawer's figures equal to its
posted journal's legs; a short count as a negative variance; filtering by cashier, warehouse and date;
a void counted as a void and dropped from takings; all money as decimal text; and 403 without
`pos.reports.view` against 200 with it.

#### Front end

Two new pages — `Pos/DayEndReport.vue` (six KPI tiles, a filter bar, a by-cashier table and a drawers
table) and `Pos/SessionDayEnd.vue` (an open-drawer warning, what the day did, drawer-count
reconciliation, and the day-end journal's Dr/Cr legs with a balance check). `Terminal.vue`, `Show.vue`,
`Receipt.vue`, `History.vue` and `Sessions.vue` were updated to the new contract, including three POS
menu entries that had none at all (`sales.pos.history`, `sales.pos.sessions`, and the day-end report).
Option payloads now return `{value, label}`, matching what `SearchableSelect` consumes.

#### Gates after this round

**Gates after this round.** Pest **527 passed / 1 skipped / 3,641 assertions**, from 498 / 1 / 3,401:
**+29 tests**, which is exactly the three new PHP test files — `PosDayEndPostingTest` 14,
`PosCollectionReportTest` 8, `ReportAccessTest` 6 tests expanding to 7 cases through its dataset.

Neither the wall clock nor the assertion total is published as a claim, because this round measured
both twice. Two full runs of the same suite reported **3,642 assertions in 720 s** and **3,641 in
816 s**; the code differed between them only by the two fixes described below, and no test's outcome
did. The 96-second spread continues item 38's observation that run-to-run variance on this sandbox is
measured in minutes (564 s directly after a 569 s run of a *smaller* suite), and the one-assertion
spread has a named cause: the suite seeds a dataset whose product prices come from
`Product::factory()` (**finding 46**), so any assertion inside a loop over demo rows can move by one
between runs. Both figures are recorded rather than the more flattering pair.

The two runs bracket something worth stating plainly, because it is the reason there are two. **Partway
through this round the sandbox restored the working tree to an earlier snapshot.** It deleted
`PosReportService.php` outright and reverted six edits across five files — two guards in `PosService`
(item 26's restock wrap and the credit-limit check), the per-customer payment terms and the part-paid
receivable birth in `PosDayEndPostingService`, and three test pins — while leaving *later* writes
intact, so the damage was not chronological and could not be read off file timestamps. Nothing noticed
automatically: the suite had already passed, and `php` and `node_modules` went with it.

What made it recoverable is the convention this report keeps arguing for. The behaviour was pinned by
tests written before the code, so a marker sweep over every file the round had touched named each loss
exactly, and the deleted service could be rebuilt against `PosCollectionReportTest`'s eight cases —
which specify attribution, the open-drawer derivation, the tender/drawer-cash split, the signed
variance and decimal text — rather than from recollection. Rebuilding it that way found a real bug in
the reconstruction: `when($x !== null, fn ($q, $v) => $q->where('opened_by', $v))` passes the callback
the *boolean*, not the value, so every filter silently matched nothing and the report returned an empty
row set — which looks like a day with no drawers, not like a broken filter. The test caught it on the
first run. Three pins would have failed loudly on the next run and the missing service would have
fataled; the two reverted guards in `PosService` would have failed their tests too. The one thing that
would *not* have been caught is a reverted comment, and the sweep is therefore over content markers
rather than over file existence — a file that exists with yesterday's contents is the failure mode that
file-existence checks are blind to.

`pint --test` passes on all **879** PHP files and `php -l` is clean on the same 879, both up from 872 —
four new sources (the migration, `PosCollection`, `PosDayEndPostingService`, `PosReportService`) and
three new tests — giving **66** test files against **790** PHP sources under `app/` and `modules/`, and
**90** migrations on disk against the 89 item 38 scanned.

Front end: **ESLint 0 errors**, **Vitest 223 tests across 7 files** (from 213 / 6; `treeSidebarPolish.test.ts`
contributes 10), and `vite build` succeeds in 15.2 s emitting both new page chunks.

**`vue-tsc` — the one red gate, still not in CI — measures 562 errors, unchanged, and item 39's net
contribution to it is zero.** That last clause is a measurement, not an intention: the round's first
whole-tree run reported **568**, and six of the increase were in files written this item. All six were
fixed rather than published — a `usePage<T>()` generic given the *page* shape instead of the *props*
shape, which silently typed away every shared prop (`page.props.auth.user` reading from `{}`); `window`
used inside two templates; three positional `route('name', id)` calls where ziggy's types want a params
object; and a mocked slot typed `() => VNodeChild`, whose `null` is not a valid `createVNode` child. The
re-measure is **562 = 542 in `modules/` + 20 in the starter kit's `resources/js/`**. Eleven remain in the
five POS pages this item touched, all of four pre-existing families — untyped `DataTable` slot rows
surfacing as `unknown`, `window` in a template, positional `route()` arguments, `number | null` into an
input model — which between them account for most of the 542. Fixing them in POS while 200 other list
pages carry the same shape would be decoration, so they stay in the backlog where item 31 put them.

**Method correction, because the earlier figure was right and its provenance was not recorded.** Items 31
and 38 both publish "20 base / 562 whole-tree", and item 31 says the 562 came from "a scratch config".
Re-measured here with a config that **extends the project's own** — identical `paths`, `types`, `strict`
and `skipLibCheck`, `include` widened to `modules/*/Resources/js/**` — the figure is **562 across 433
files** (275 module, 158 starter kit), so the number stands and the method is now stated rather than
implied. What "20 base" means is narrower than it reads and worth saying plainly: the project's
`tsconfig.json` includes **only `resources/js/**`**, so the 275 module front-end files are type-checked
by nothing today. That, not the error count, is the gate gap — it is how 542 errors accumulated with no
tool reporting any of them, and it is why the scratch config was needed to see them at all.

#### Findings published, not fixed

**Finding 42 (P2) — per-line discount and VAT are computed and dropped.** `PosSaleItem::$fillable`
omits `discount_amount` and `vat_amount`, so a line-level discount entered at the till affects the
totals through the header but leaves no per-line record. Either the columns are added and filled, or the
inputs are removed from the till; the current state computes a number nobody can see or audit.

**Finding 43 (P3) — `DemoDataIntegrityTest`'s minimums are floors.** `DD_MINIMUMS` asserts `>=`, so a
regression that *deletes* demo rows down to the floor passes silently. The item-39 `sale_price` defect
was invisible to it for exactly this reason: rows existed, they were just all priced at 900. A shape
check (at least one posted session, at least one collection, at least one part-paid receivable) would
have caught it.

**Finding 44 (P2) — operator-facing copy claimed ledger effects the code did not perform.** Fixed here
for the POS (`void()`'s flash and `Terminal.vue`'s VAT claim), but the same class deserves a sweep:
any message asserting "journal posted", "stock returned" or "payment recorded" is a claim about state
that no test currently checks against the code path that emits it.

**Finding 46 (P3) — the seed is not reproducible, so no demo figure in this report can be.**
`DemoDataSeeder` contains no randomness at all, but it prices its documents from products that
`InventoryDemoSeeder` creates with `Product::factory()->count(15)` and stocks with `rand(50, 200)`
units at `rand(50, 300)` cost. Two runs of `migrate:fresh --seed` therefore produce different money
everywhere downstream: the POS demo day-end measured net 16,020.00 on one seed and 5,887.67 on the
next, with the same seven journal legs and the same −50.00 variance. The consequence is practical
rather than cosmetic — a UAT script, a screenshot or a bug report that quotes a demo amount will not
match a freshly seeded database, and a tester cannot tell a wrong figure from a different one.
`DemoDataIntegrityTest` survives this because it asserts invariants (balanced journals, a ledger that
mirrors them, arithmetic that agrees with itself) rather than amounts, which is the right shape for a
test over random data and the reason this finding is P3 and not P2. Fixing it means giving the fifteen
factory products fixed prices, or seeding the demo from a product list of its own.

**Finding 45 (P3) — nothing tested that a menu item was reachable.** Routes were tested, permissions
were tested, `menu.ts` was tested for three literal strings. No test connected the two, so ten features
were unreachable for every role while every gate was green. Now pinned by reachability, at any depth.

---

### Payroll payment posting (new) — item 40

Item 40 closes **finding 35**, the highest-value open defect in §4 and the one this report has cited
three times without being able to fix: only the first slip of a salary sheet ever reached the general
ledger, and two layers of `catch (\Throwable)` meant nobody could tell.

#### What the ledger was missing

`payroll_journal_posts` carried `unique(salary_sheet_id, posting_type)`, added with the comment
`// idempotent`. That key is correct for `salary_accrual` and `settlement`, which post one row per
sheet, and wrong for `salary_payment`, which `PayrollJournalService::postSalaryPayment()` writes once
**per slip**. The method guarded its own idempotence with
`where('meta->slip_id', $slip->id)` — a JSON path the unique index cannot see — so the guard passed,
the insert ran, and the second slip of any sheet violated `uniq_sheet_posting`. The insert happened
inside `DB::transaction`, so the journal it had just written rolled back with it.

Written first, as a failing test, the violation is exact:

```
SQLSTATE[23000]: Integrity constraint violation: 19 UNIQUE constraint failed:
payroll_journal_posts.salary_sheet_id, payroll_journal_posts.posting_type
(SQL: insert into "payroll_journal_posts" ("salary_sheet_id", "posting_type", "reference_no",
"journal_id", "total_debit", "total_credit", "status", "meta", …) values
(1, salary_payment, PAY-PV-00001-L308003, 2, 2000, 2000, posted, {"slip_id":2,…}))
```

Note what the row says: journal id **2** already existed, its debit and credit were both correct, and
the post that would have made it findable was refused. The money was right; the bookkeeping that
proves it was not.

The severity was always in the callers, and there were **two**, which is why this finding needed a
second reading before it could be designed against. `PayrollPaymentPostingService::postIfPaid()`
wrapped the whole posting in `catch (\Throwable $e) { Log::error(…) }`, and
`MarkPayrollSlipPaymentAction::execute()` wrapped *that* in `catch (\Throwable $e) { Log::warning(…) }`
under the comment `// ── Finance Bridge: auto-post payment JV (idempotent, never blocks HR) ──`. So
the officer's screen showed a paid slip, the PF ledger was written for every slip, the loan
installments were marked paid, and N−1 of N bank journals did not exist. In the demo dataset as it
stood before this item: **14 slips marked paid, 1 with a `payment_journal_id`, 2 rows in
`payroll_journal_posts`** — the accrual and the one slip that got through.

This is P1.1's silent-failure pattern in a place `SilentFailureInventoryTest` could not see, because
neither catch is empty. Both log. That is precisely what made them survivable for 40 items.

#### The fix is a schema decision, and the rejected shape matters

Two candidate fixes were written down before either was built:

- **Widen the unique index to include a nullable `payroll_slip_id`.** Rejected, and the reason is a
  portability fact rather than a preference: SQLite and MySQL both treat NULLs as *distinct* in a
  unique index, so every sheet-level row (accruals, settlements, which have no slip) would have become
  unconstrained. That trades a defect affecting payments for one affecting accruals — a worse trade,
  because an accrual that posts twice overstates the whole payroll expense rather than one bank line.
- **Post one aggregate payment per sheet.** Rejected because it contradicts the design already in the
  schema: `payroll_slips.payment_journal_id` exists per slip, the payroll screen links a slip to its
  own journal, and `reference_no` is `PAY-PV-<sheet>-<employee>`. An aggregate journal would have made
  those columns meaningless and removed the ability to trace one employee's payment to one bank line.

What was built instead is a **scope column**. `posting_scope` is `varchar(40) NOT NULL DEFAULT 'sheet'`:
`'sheet'` for sheet-level postings, `'slip:<id>'` for per-slip ones. Nothing is ever NULL, so the index
constrains every row, and the scope is readable in the row itself rather than only inside a JSON blob.
The index becomes `unique(salary_sheet_id, posting_type, posting_scope)` — `uniq_sheet_posting_scope` —
and `payroll_journal_posts.payroll_slip_id` is added alongside it as a real nullable foreign key,
because a JSON path cannot be indexed portably and the divergence query below needs a join.

Migration `2026_11_07_000002_payroll_payment_posting_scope.php` does four things: adds the two
columns, **backfills** the existing per-slip rows from their own `meta->slip_id`, swaps the index, and
adds `payroll_slips.payment_posting_error`. The backfill is listed in `MigrationDataSeparationTest`'s
`MDS_UP_ALLOWLIST` with its reason — it copies data into columns the same migration just created, on
rows that already exist in any deployed database, and it must run *before* the new index is built or
two slips of one sheet collide on `('sheet','salary_payment','sheet')` immediately. No seeder can do
it: seeders do not run on a deployed database, and the old rows are exactly the ones that need it. The
`down()` is listed in `MDS_DOWN_ALLOWLIST` for the same reason it is worth reading — rolling back
restores the narrow index, which cannot hold the per-slip rows, so they are deleted first and the
sheet-level accrual survives. Both entries are asserted by name, so neither can drift.

SQLite accepted the foreign key on an existing table by rebuilding it, which was verified against
`sqlite_master` rather than assumed: the rebuilt `payroll_journal_posts` carries
`foreign key("payroll_slip_id") references "payroll_slips"("id") on delete set null`, and
`uniq_sheet_posting` is gone from the index list. **91 migrations** now, up from 90.

#### "Never blocks HR" was right. "Never surfaces" was not.

The old comment on that catch was making a real argument, and this item keeps it: a chart of accounts
that cannot take the journal must not stop a payroll officer paying people. What it conflated was
*blocking* with *recording*. The two halves are now separated, and each is pinned on its own because
either one alone is a regression:

- `PayrollPaymentPostingService::postIfPaid()` **rethrows**, wrapping the cause in a `DomainException`
  that names the slip. It no longer decides the policy for every caller — which is what a swallow does.
  Its two advisory skips (no active company bank; bank with no ledger account) are *also* recorded on
  the slip now, because leaving those in the log only would have reproduced the same invisibility one
  layer down. They stay non-exceptional: there is nothing for the officer to retry.
- `MarkPayrollSlipPaymentAction::execute()` still catches, still marks the slip paid, still returns it —
  but writes the reason to `payroll_slips.payment_posting_error` and logs at **error**, not warning,
  because a divergence between payroll and the ledger is not an advisory skip. A successful posting
  clears the column, so the field means what it says.

That makes the divergence queryable, which is the whole point of the second half:

```php
PayrollSlip::paidButUnposted()->whereNotNull('payment_posting_error')->get();
```

`scopePaidButUnposted()` is new on the model — `payment_status = 'paid'` and `payment_journal_id IS
NULL` — and `PayrollJournalPost::slip()` is the relation the new foreign key makes possible. Before
this item the same question could only be asked by reading a log file, and the answer was "everybody,
except the first".

#### What the demo dataset shows now

The seeder's workaround is gone. It used to stop at the slip status with a comment explaining that
posting would crash, which was true and is no longer; the comment now says what happens instead. Measured
on a fresh `migrate:fresh --seed && erp:demo-data`:

- `payroll_journal_posts` **15** — 1 accrual at `posting_scope = 'sheet'`, **14** payments at
  `'slip:1'` … `'slip:14'`. Was **2**.
- `payroll_slips` **14 paid, 14 with a `payment_journal_id`, 0 with a `payment_posting_error`**. Was
  14 paid and 1 posted, which is the finding as a row count.
- The sum of the payment posts' credits is **160,547.83**, equal to the sheet's `net_total` to the
  cent. That equality is the invariant the finding said was broken: before, it was one slip's net.
- Account **2121 Salary Payable** now carries 15 ledger rows, debit **160,547.83** and credit
  **160,547.83**, so its balance is **0.00** — the accrual is fully discharged by the payments, which
  is what "everybody has been paid" is supposed to look like in a ledger.
- `acc_journals` **28** (was 15), `acc_journal_lines` and `acc_account_ledger` **75** each, and
  **0** unbalanced journals across the whole demo dataset.

`DemoDataIntegrityTest`'s floor for `payroll_journal_posts` moves from **1** to **11**, and the reason
is written into the constant rather than left to the diff: the floor follows the slips floor above it
(1 accrual + 10 slips), not the count a particular seed produces, because the sheet is run for real and
its size moves with the roster. The `DD_UNCOVERED` docblock entry that documented the per-slip journal
as impossible is rewritten to say it was impossible, why, and that item 40 closed it — the list is the
executable form of the gaps, so a closed gap that stays listed is a lie the test would enforce.

#### Tests

`tests/Feature/PAYROLL/SalaryPaymentPostingTest.php` — **9 tests**, written before any of the code
above existed. Six failed pre-fix and three passed, and the three that passed are the informative part:

| test | pre-fix | what it pins |
| --- | --- | --- |
| every slip of a sheet reaches the general ledger, not just the first | **fail** (`uniq_sheet_posting`) | the defect itself: 3 slips → 3 posts, 3 journals, Dr 2121 / Cr bank each = that employee's net, and each slip pointing at its own journal |
| the ledger's salary payments add up to the sheet's net pay | **fail** | Σ posted credits = Σ net pay over 4 slips — the figure the bank reconciliation would have caught |
| two sheets in different periods each post their own slips | pass | the key was never wrong *across* sheets; this is the control that localises the defect to one sheet's second slip |
| posting the same slip twice posts it once | pass | per-slip idempotence already worked through the JSON guard, and still does through the column — one post, one journal |
| a sheet-level posting is still one row per sheet | **fail** (column absent) | the half the rejected design would have broken: accrual idempotence survives the wider key, `posting_scope = 'sheet'` |
| per-slip posts carry the slip | **fail** (columns absent) | `posting_scope = 'slip:<id>'`, `payroll_slip_id` set, and the old `meta->slip_id` snapshot still written, since a screen reads it |
| the posting service rethrows a failure instead of logging it away | **fail** (swallowed) | swallow #1 is gone: `DomainException` reaches the caller, and no journal is left behind |
| a slip that could not be posted stays paid, and says why on the record | **fail** (no column) | swallow #2 is now a recorded state: paid, `payment_journal_id` null, reason on the slip naming the slip, and findable through `paidButUnposted()` |
| a slip that posts cleanly carries no error | pass | the happy path is unchanged and the error column is cleared, so the field stays meaningful |

The failure used in the last three is a **real** one, not a mock. `Bank::account_id` is repointed at
`1002 Cash at Bank`, which is a group header, and `JournalService` refuses to post to one ("post to a
child account"). Two reasons: `PayrollJournalService` and `PayrollPaymentPostingService` are both
`final`, so Mockery cannot stand in for them, and a mocked failure would only have proved that the
test's own stub throws. A mis-linked bank is also how this would actually happen in a deployment —
which is the shape of failure the recorded-reason path exists for.

One fixture trap is worth recording because it cost a run and its error message is actively misleading.
`payroll_slips.employee_id` is a foreign key to `employees.employee_id` — the business id, `RESTRICT` —
so slips built from invented ids fail with a bare `SQLSTATE[23000] … FOREIGN KEY constraint failed` on
the *slip* insert, which reads as "the sheet does not exist". It was diagnosed with
`PRAGMA foreign_key_list('payroll_slips')`, which names all four constraints including
`employee_id → employees.employee_id`, and the fixture now takes its ids from the seeded 998-strong
roster. The base seed already carries a real payroll population; a test that invents employees is
testing a database the application never has.

Two pins were added to `SilentFailureInventoryTest`'s "money paths that used to swallow their
failures no longer do" list, one per swallow half, with the reason both are needed in the comment:
reverting the service to a swallow, or the action to a bare `Log::warning`, fails the suite. And
P1.1's claim in §4 — "every financially consequential site has been fixed" — is now true of the
non-empty catches too, which is a stronger statement than the one item 39 could make.

#### Gates after this round

**Gates after this round.** Pest **536 passed / 1 skipped / 3,693 assertions** in 850 s, from
527 / 1 / 3,641: **+9 tests**, which is exactly the one new file, and **+52** assertions. That second
figure is not a round number per test and is worth stating rather than leaving to be inferred: the
ledger tests assert per slip inside loops, so their assertion count scales with the fixture, and the
three-slip case alone accounts for most of the movement.

`pint --test` passes on all **881** PHP files and `php -l` is clean on those 881 plus the four under
`bootstrap/` — two new sources over item 39's 879, giving **67** test files against **791** PHP sources
under `app/` and `modules/`, and **91** migrations on disk against the 90 item 39 counted.

The four front-end gates were re-run and are unchanged: **ESLint 0 errors**, **Vitest 223 tests across
7 files**, `vite build` succeeding in 18.0 s, and `vue-tsc` at **20** under the project's own `include`
and **562** whole-tree — **542** in `modules/` plus **20** in `resources/js/`, measured again with a
scratch config extending the project's own and deleted afterwards. "Unchanged" is a measurement here
rather than an assumption: item 40 touched **12 PHP files** and nothing of any other kind — this report is
the only non-PHP file it changed, and §6 counts that separately — established by diffing
the dirty tree against the item-39 backup manifest instead of by recollection, so no `.vue`, `.ts` or
`.js` file moved and those gates had nothing new to see. `vue-tsc` stays the one red gate and stays out
of CI, exactly as items 31 and 39 recorded it.

The suite ran **once** this round. Item 39 published two runs because a snapshot restore had made the
first run's provenance uncertain; nothing of the kind happened here, and one 850 s run against a tree
whose manifest was diffed before and after is the honest measurement. One test file was re-run
separately afterwards — `MigrationDataSeparationTest`, 9 passed — because the round ends by adding a
comment to the *historical* migration `2026_11_03_000073`, whose `// idempotent` sits next to the index
this item removes. Its DDL is byte-identical and only the comment changed, but a reader of that file
has no other way to learn the comment was wrong, and a scanner that reads migrations deserved a
re-run rather than an argument that comments cannot matter.

### Stock lots and the cache key (new) — item 41

Item 41 closes **P1.25**, the defect §4 has carried since item 24's safety net found it and deliberately
left it alone: the stock cache was keyed by lot on the way in and looked up lot-less on the way out, so
quantity received against a lot was unsellable. It also closes the second, smaller defect the report
coupled to it — a lot number typed on a delivery was validated, stored on the delivery item, and then
dropped before it reached the movement ledger — and publishes three more that were found while reading
the same method, each measured by a test rather than inferred from the source.

#### The two halves, and why they had to be fixed together

`StockService::move()` is the only writer of `inventory_stocks`, and it maintained two records of the
same fact in one transaction: an append-only `stock_moves` ledger and a denormalised cache row. It put
`lot_no` into the cache key for both directions. But the paths were not symmetric: `ReceiveStockAction`
forwarded `lotNo: $item['lot_no'] ?? null` and documented `lot_no` in its `@param` list, while
`IssueStockAction`, `InventoryStockProvider::deductStock()` and `::restock()` constructed their
`StockMoveData` **without a `lotNo` argument at all**, so it defaulted to null.

Receive 10 units against `LOT-RECEIVED`, then sell 4: the cache row was keyed
`(product, warehouse, lot_no = 'LOT-RECEIVED')`, the sale looked for `(product, warehouse, lot_no IS
NULL)`, found nothing, and threw. Written as a failing test before any code was touched, the exception
was exactly the one item 24 had recorded:

```
Insufficient stock for product #1 in warehouse #1. On-hand: 0, requested: 4
```

Ten of the thirteen new tests failed pre-fix, and the failure messages are worth reading together
because they show the defect from three angles at once: the cache row asserted to be lot-less came back
as `'LOT-RECEIVED'`; three receipts against two lots produced **3** cache rows instead of 1; and five
separate issues were refused with `On-hand: 0` against stock the ledger said was there.

The second half is why the report insisted these be fixed in one item. `DeliveryService::ship()` maps
its items into `'lot_no' => $it->lot_no, 'serial_no' => $it->serial_no`, `SalesReturnService` maps
`'lot_no' => $it->lot_no` into the array it hands `restock()`, and `StoreDeliveryRequest` validates
`items.*.lot_no` as `nullable|string|max:100`. So an operator could type a lot on a delivery, the
request accepted it, `delivery_items.lot_no` stored it — and both stock paths dropped it. Forwarding the
lot without also fixing the key would have converted a lost lot number into a refused sale, which is a
worse outage than a missing audit field.

#### What the cache is now

**An aggregate per product, variant, warehouse and location.** That is not a model imposed on the
module; it is the model the module already used everywhere except this one write. `reserve()` and
`release()` look the cache up on `(product_id, variant_id, warehouse_id)` alone, and so do
`ValuationService`, `PosLookupController`, `InventoryStockProvider::available()`, `StockRepository` and
`LandedCostService`. `move()` was the only writer that disagreed with all seven readers.

Lot identity stays where it already lived and was already maintained by the same method:
`inventory_lots` and `fifo_layers`. Test 3 pins that the change did not cost anything — two receipts
against two lots still produce two `Lot` rows and two layers with their own quantities and costs, which
is what the Lots & Expiry report reads and what a recall is answered from.

The `lot_no` **column stays** on the cache and reads NULL for every row. `StockController:31` already
sends it to the stock screen, and dropping a column a controller exposes is a UI change for no
behavioural gain. What the column now says is the truth: this table does not track lots.

One fact about the old key is worth recording, because it looks like a protection and is not.
`inv_stocks_unique` is `unique(product_id, variant_id, warehouse_id, location_id, lot_no)` — and three
of those five columns are nullable. SQLite and MySQL both treat NULLs as *distinct* in a unique index,
which is the same portability fact that ruled out a nullable slip id in item 40's payroll index, so the
constraint has never prevented two aggregate rows for one product and warehouse. What actually prevents
that is the transaction and the `lockForUpdate()` around the read-modify-write — and the note item 24
already published stands: SQLite ignores `lockForUpdate()`, so the suite cannot observe two writers
racing. Removing `lot_no` from the key does not weaken the index, because the index was not doing the
work.

#### A lot named on the way out is honoured, not just recorded

Recording the lot on the move would have made the audit trail honest and left the quantities behind it
dishonest: `Lot.qty_remaining` would still have been decremented oldest-first, so a delivery naming
`LOT-NEW` would have shipped `LOT-OLD` while the ledger said otherwise — and a recall reads both. So:

- a named lot's layers are consumed **before** any older layer, and FIFO order covers whatever the
  named lot cannot;
- the issue is **costed out of the layer that actually shipped**, so naming `LOT-NEW` at 7.00 against
  an older 5.00 layer costs the movement at 7.00 rather than at the oldest layer's price;
- naming a lot that holds nothing is **refused outright**, and deliberately regardless of the
  warehouse's `allow_negative_stock` setting: that setting permits overselling a *balance*, not putting
  a lot number into the movement ledger that no receipt ever created. The message names the lot, the
  product and the warehouse;
- a lot-less issue is **unchanged** — layers oldest-first, exactly as before. Two tests exist only for
  that: every caller in the tree today issues without a lot, so the new behaviour must not leak into
  the path they all use.

No controller change was needed for the new refusal. `DeliveryController::ship()` already wraps the
service call in `catch (\DomainException $e) { return back()->with('error', $e->getMessage()); }`, and
`SalesReturnController` does the same in three places, so a mistyped lot surfaces as a flash message
rather than a 500. That was checked rather than assumed, because item 39's finding 44 was exactly a
message claiming an effect the code did not perform.

**The residual, stated rather than left to be discovered.** When a named lot covers only part of the
request — 8 units asked for, 6 in `LOT-NEW` — the named lot empties, the oldest layer covers the
remainder, and the move records the lot the operator *named*. So `stock_moves.lot_no` is a declaration
and `fifo_layers` is the fact. A recall that needs quantities per lot must read the layers; a recall
that needs to know what the operator said they shipped reads the move. Test 8 pins the arithmetic
(`unit_cost` 6.50 = (6 × 7.00 + 2 × 5.00) / 8) so the fallback cannot silently become "ship the named
lot or nothing".

#### Existing lot-keyed rows are merged, not stranded

Changing a key underneath a deployment is the part that makes this a data decision rather than a patch,
and it is why item 24 declined to fix it. `2026_11_07_000003_merge_lot_keyed_stock_cache_rows.php`
folds every lot-keyed row into the aggregate row for the same product, variant, warehouse and location,
keeping the invariants `move()` itself maintains:

```
qty_on_hand   = sum of the rows it replaces      total_value = sum of the rows it replaces
qty_reserved  = sum of the rows it replaces      avg_cost    = total_value / qty_on_hand
qty_available = qty_on_hand − qty_reserved       last_movement_at = newest of the merged rows
```

`avg_cost` is one rule rather than two because it already means the same thing under both valuation
methods: for a weighted-average row `total_value` is `qty_on_hand × avg_cost`, so the division returns
the quantity-weighted average of the rows being merged, and for a FIFO row `avg_cost` is *defined* by
`move()` as `total_value / qty_on_hand`. Where no aggregate row exists, the first lot-keyed row becomes
it and the rest merge into it, so no unit is dropped and no row is invented.

The migration is **DML only, with no DDL**, on purpose. It is listed in `MigrationDataSeparationTest`'s
`MDS_UP_ALLOWLIST` with its reason — a merge of existing rows cannot be done by a seeder, because
seeders do not run on a deployed database and these are exactly the rows a deployed database has. And
being pure data, it can be run *again*: the test inserts three rows of the old shape (one lot-less, two
lot-keyed, with different costs, reservations and movement times), calls `up()` directly, asserts the
merged row to the cent, and calls `up()` a second time to prove the merge is idempotent. That is the
only way a merge of rows the code can no longer produce can be exercised at all. `down()` is empty and
says why: the per-lot split of a cache row is not recoverable from the cache, and never was — lot
identity lives in `stock_moves`, `inventory_lots` and `fifo_layers`, none of which this migration
touches, so rolling the application back loses no data.

Run against the development database rather than only against a test fixture: **18 cache rows before and
18 after**, `sum(qty_on_hand)` **2,165** and `sum(total_value)` **668,251.0304** identical to the digit on
both sides of it, and `inventory_lots` empty throughout. Nothing in the base or demo seed uses lots, so a
no-op is the correct result here — and it is the reason the test invents rows of the old shape instead of
trusting the migration's behaviour on a database that never had any.

#### The suite's only skipped test now runs

`StockMovementDomainTest` carried two things item 24 left behind, and both changed:

- a **characterisation test** named `an issue records no lot even when the caller supplies one`, which
  asserted `$move->lot_no` **was null** and said in its comment that it was pinning a defect rather than
  endorsing it. It is renamed to `an issue records the lot the caller supplies`, the assertion is
  inverted, and the comment records that it was a characterisation test and what made it safe to flip.
- a **skipped reproduction**, `a lot received on a goods receipt is still sellable without naming the
  lot`, whose skip reason was the finding text. The skip is removed and the test runs.

That was the only `->skip()` in the suite, so the published gate changes shape as well as size: the run
after this item reports **0 skipped** where every run since item 24 reported 1.

#### Findings published, not fixed

Three defects were found in and around the same method. Each is pinned by a **characterisation test** in
`StockLotCacheTest` — a test that asserts what the code *does*, says so in its name, and therefore fails
loudly the day somebody fixes it, at which point the assertion is inverted deliberately the way the lot
test above was. None is skipped, because a skipped test is a defect with the lights off, which is what
P1.25 itself was for 17 items.

**Finding 47 (P2) — a reservation against stock held in two locations lands on one arbitrary row.**
`move()` keys the cache on `location_id`; `reserve()` and `release()` key on `(product_id, variant_id,
warehouse_id)` only and take `->first()` with no `ORDER BY`. Measured: receive 10 into Aisle A and 10
into Aisle B, reserve 5, and exactly **one** row carries the reservation while the other still advertises
all 10 of its units — 20 on hand, 5 reserved, 15 available, with the split undefined by the query. Not
reachable today: no caller passes a location and no screen captures one, so every row has
`location_id = NULL`. Reported rather than fixed because it is a second data-model decision — whether a
reservation belongs to a bin or to the warehouse — and the honest fix changes
`InventoryStockProvider::reserveStock()` and its sales callers, none of which has a location to pass.

**Finding 48 (P2) — a batch-tracked product valued at weighted average never gives lot quantity back.**
`move()` consumes layers inside `if ($sign < 0 && $isFifo)`, so for a product that is
`is_batch_tracked` but *not* `valuation_method = fifo`, receipts create `Lot` and `FifoLayer` rows and
issues never decrement them. Measured: receive 5, issue 2, the cache correctly says 3 on hand while
`Lot.qty_remaining` and the layer's still say **5.0** — permanently. `Lot` and `FifoLayer` are what the
Lots & Expiry report reads and what a recall is answered from, so the units that shipped are missing
from both. Reported rather than fixed because closing it means deciding whether a lot-less issue should
consume lots oldest-first for products that are *not* valued by FIFO — which is the lot-consumption
engine this item deliberately did not build.

**Finding 49 (P2) — a lot number is a global identity, so a reused stamp is attributed to the wrong
product.** `inventory_lots.lot_no` is `unique()` on its own, not per product, and `move()` creates the
lot with `Lot::firstOrCreate(['lot_no' => $lotNo], ['product_id' => …])`. Measured: receive 6 of product
A against `LOT-SHARED`, then 4 of product B against the same stamp, and there is **one** lot row — owned
by product A, holding `qty_received` 10 at A's 5.00 unit cost, with B's layer pointing at it. Two
suppliers using the same lot stamp for different goods is ordinary practice, so this is reachable the
moment lots are used at all. The cache is unaffected, which is the one piece of good news: both
products' aggregates stay correct at 6 and 4, so the defect is confined to lot reporting rather than to
stock quantities. Fixing it is a schema decision — `unique(product_id, lot_no)` plus a migration over
any lot already shared — and it belongs with the lot-tracking item, not inside this one.

#### Tests

`tests/Feature/INVENTORY/StockLotCacheTest.php` — **16 tests**, written before any of the code above
existed: 13 for the fix and 3 characterising the findings. Pre-fix, **10 failed and 3 passed**, and the
three that passed are the informative part: the batch-tracked receipt already kept its generated lot out
of the cache key (a nuance of `move()` that the change had to preserve rather than discover), and the
two merge tests passed because the migration was written to the assertions rather than before them.
`StockMovementDomainTest` goes from 17 passed / 1 skipped to **18 passed / 0 skipped**, with the
characterisation test inverted and the reproduction running.

One fixture note, because it is the kind of thing that reads as a different bug than it is: the helpers
create their own warehouse and products rather than seeding the demo dataset, and `Product` needs
`valuation_method` set explicitly — a lot of these assertions only mean anything for a `fifo` product,
and a weighted one has no layers to consume.

#### Gates after this round

**Gates after this round.** Pest **553 passed / 0 skipped / 3,786 assertions** in 885 s, from
536 / 1 / 3,693. The **+17 tests** account for themselves exactly: 16 in the new `StockLotCacheTest`
plus the reproduction whose `->skip()` this item removed. **Nothing is skipped any more** — that one
test had been the suite's only skip since item 24 added it, and every gate published between then and
now carried a "1 skipped" that this round drops. Assertions moved by **+93**.

`pint --test` passes on all **883** PHP files and `php -l` is clean on the same 883 — two new sources
over item 40's 881, giving **68** test files against **792** PHP sources under `app/` and `modules/`,
and **92** migrations on disk.

The four front-end gates were re-run and are unchanged: **ESLint 0 errors**, **Vitest 223 tests across
7 files**, `vite build` succeeding in 18.8 s, and `vue-tsc` at **20** under the project's own `include`
and **562** whole-tree — **542** in `modules/` plus **20** in `resources/js/`. As at item 40, that is a
measurement rather than an assumption: item 41 touched **9 files, 8 of them PHP and the ninth being
this report**, established by diffing the dirty tree against the item-40 backup manifest, so no `.vue`,
`.ts` or `.js` file moved. `vue-tsc` remains the one red gate and remains out of CI. The lot input on
`Deliveries/Create.vue` needed no change, which is the point of the fix — the lot was already captured,
validated and stored; it was dropped between the delivery item and the stock ledger.

One gate this round is a migration run rather than a test run. `2026_11_07_000003` was applied to the
development database, which holds 18 cache rows from a full demo seed, and measured as a no-op: 18 rows
before and after, `sum(qty_on_hand)` 2,165 and `sum(total_value)` 668,251.0304 identical on both sides.
That is the correct result for a database with no lot-keyed rows, and it is published because "the
migration did nothing here" and "the migration worked here" are the same observation only when the sums
are compared.

The suite ran **once**, as at item 40: no snapshot restore, no ambiguity about provenance, and the
targeted run before it (INVENTORY, SALES, PURCHASE and the two contract files, **120 passed**) is
recorded in `pest_item41a.log` rather than folded into the total.

### Colour schemes and the design tokens (new) — item 42

Item 30 retracted P2.2 and left behind a corrected measurement: **144 page-level candidates**, an ink
rule for adjudicating them, and a shared-component set that was clean but *fixed*. Clean and fixed
turned out to be the same problem from two directions. `PageHeader.vue` painted its hero
`bg-[#0B0F1E]` — a navy chosen once, in one component, that no setting could reach. `StatusBadge.vue`
carried six tones as six raw palettes (`zinc`, `sky`, `emerald`, `amber`, `rose`, `indigo`), each with
a `dark:text-*-400` counterpart, so the badge knew which theme it was in and knew what "success"
looked like independently of everything else. And the screen that offered an appearance setting was
the starter kit's, reachable from no ERP navigation, rendered in `AppLayout` rather than the admin
shell, writing a `localStorage` key that `useAppearance.ts` persisted and nothing at the token layer
read.

Measured before the round, so the figures are not inferred: **0** of the application's colour was
switchable; **1** literal hero colour; **6** status tones as raw palettes with **6** `dark:`
counterparts; **15** semantic tokens mapped into `@theme inline` (the shadcn set); **0** consumers of
`--chart-1..5` because those tokens did not exist; **1** appearance screen, orphaned.

**What was built.** `resources/css/themes.css` (485 lines, 378 token declarations) defines eight
palettes × twenty tokens × two modes, plus the semantic status colours per mode. `resources/css/app.css`
grew sixteen `@theme inline` mappings so each token has a utility an author can write
(`from-hero-from`, `text-hero-ink`, `bg-success/10`, `text-brand-tint-ink`). `Modules\CORE\Support\UiTheme`
holds the catalogue and the resolver; `CoreSetting` plus migration `2026_11_07_000004_core_ui_settings`
(pure DDL: `core_settings`, and nullable `users.ui_scheme` / `users.ui_mode`) hold the two levels of
preference; `UiSettingsController` exposes them; the resolved theme is shared on every Inertia response
and rendered onto `<html>` by `resources/views/app.blade.php` before the first frame, with a nonce'd
inline script settling `system` mode and any local override — nonce'd because the CSP this application
ships has no `'unsafe-inline'` for scripts, so an un-nonced bootstrap is a bootstrap that never runs.
`useUiTheme.ts` (268 lines) owns the browser half under `localStorage['core.ui.v1']` and adopts the
starter kit's legacy `appearance` key, so an upgrade does not discard a dark-mode choice somebody
already made. `SchemePicker.vue`, `Pages/Settings/Appearance.vue` (320 lines) and a quick switcher in
the shell header are the three surfaces; `menu.ts` gained `ui-settings` under Administration.

The eight schemes, each a complete light and dark set: `indigo` Indigo Executive (#4f46e5, the house
default) · `royal` Royal Blue (#2563eb) · `emerald` Emerald Enterprise (#059669) · `teal` Teal Ledger
(#0d9488) · `violet` Violet Regent (#7c3aed) · `rose` Rose Crimson (#e11d48) · `amber` Amber Copper
(#d97706) · `graphite` Graphite Neutral (#18181b). The twenty tokens per palette are `--primary`
(+foreground), `--ring`, `--hero-from/-via/-to/-ink/-glow`, `--brand-tint` (+ink), `--sidebar-primary`
(+foreground), `--sidebar-ring`, `--sidebar-accent` (+foreground) and `--chart-1..5`.

**One decision worth stating separately:** the status colours — `success`, `warning`, `danger`, `info`,
`neutral-ink` and their tints — are defined **per mode and never per palette**. Under Amber Copper a
success badge is still green. The alternative, letting each palette redefine them, is what makes a
redecorating administrator accidentally change what a colour *means* on a payroll exception report, and
`ThemePaletteTest` now fails if any palette block redefines one.

**Precedence** is user → company → constant, and `null` is a value with meaning: it is "no opinion",
which is what makes *Follow company default* a real button rather than a restatement of the default id.
Choosing for yourself needs only `auth`; setting the company default needs
`settings.appearance.manage`, enforced in route middleware so the 403 precedes validation. The
permission vocabulary moves **178 → 179** and `OptionEndpointAuthorisationTest` is re-pinned with that
reason, along with `MoneyColumnCastTest`'s model census **156 → 157** for `CoreSetting` — a model with
no decimal columns, which is why the census and the money-column count are separate assertions.

**What was tokenised.** `PageHeader` now paints the palette's ramp (`from-hero-from via-hero-via
to-hero-to`) with `hero-ink` for every letter, its grid overlay drawn in `hero-ink` rather than
`--foreground` so it is visible in both modes, and its two glow orbs in `hero-glow`; the minimal header
gained a one-pixel ramp hairline and a gradient icon plate. `StatusBadge`'s six tones are the six
semantic tokens and all six `dark:` counterparts are deleted — the only `dark:` string left in the four
components is the sentence recording their removal. `EnterpriseStat`'s tones are semantic and every
tile now carries a two-pixel accent bar, the `default` tone taking the hero ramp, so a wall of numbers
is brand-coloured without an author choosing a colour. `EnterpriseCard`'s header washes in
`from-brand-tint` with the icon on a `bg-primary/10` plate. `DataTable`'s four grays stay exactly as
they were and stay allowlisted: they are inside `hidden print:block`, and tokenising them would make
the printed sheet header follow the screen theme.

**The pass reached one layout, and it is worth being exact about what that did and did not move.**
`TreeSidebar.vue`'s logo plate was `bg-primary` with a `ring-black/5 dark:ring-white/10` hairline; it
now paints the same hero ramp as the page header with a `ring-hero-ink/25` edge, which leaves the file
with **no `dark:` utility and no raw palette class at all** — measured, both zero, at 342 lines
unchanged. The ratchet above did not move when it did, and the reason is a property of the scanner that
belongs in the record: `SharedComponentThemeTest` skips a light class whose `dark:` counterpart sits on
the same line, on the argument that a paired override is already themed, so that hairline was never a
candidate. The scanner's family is also the neutral one — `white|black|slate|gray|zinc|neutral|stone` —
which means the chromatic hues a page hardcodes are outside its view entirely. `Dashboard.vue` is the
case in point: **nine lines** of handpicked colour survive this round (four stat tiles tinted
`from-indigo-500 to-violet-500`, `from-sky-500 to-cyan-500`, `from-amber-500 to-orange-500` and
`from-emerald-500 to-teal-500`; a six-chip `rolePalette` of `bg-indigo-500` through `bg-violet-500`;
two progress bars; one decorative orb; one `text-white`), and only the last of those is a candidate at
all. Both facts are bounds on what **143** means rather than defects in it, and the second one names
finding 55's first honest consumer: those tiles are categorical, `--chart-1..5` is exactly a
categorical set, and repointing them is a one-file change deliberately left for the round that owns
pages rather than shared chrome.

**The orphan was closed rather than duplicated.** `settings/appearance` is now a redirect to
`core/settings/appearance`; the starter's `pages/settings/Appearance.vue` and `AppearanceTabs.vue` are
deleted; the account menu's single "Settings" entry became "Profile" plus "UI Settings", so the shell
no longer drops the user into a different layout to change a colour. `.vue` census **394 → 394**: one
page added, two deleted, one component added.

**What this round did not do**, measured rather than hoped: the page-level raw-palette backlog is
**143** candidates (one fewer, and it fell because a scanner that now strips comments stopped counting
a colour that was only written about); **157** `dark:` variants remain across the pages, led by
`Welcome.vue` (22), `Pos/Terminal.vue` (11) and `Journals/Edit.vue` (11); `--chart-1..5` has **2**
consumers and both are demos — the design-system page and the settings preview — because no chart
component reads them yet; there is no custom-palette creator and no logo or letterhead upload, both
declined as out of scope; the starter kit's Profile and Password pages still render in `AppLayout`
(finding 54).

**The type gate moved in this audit's favour for the first time.** `vue-tsc` reports **19** errors
against the project's own `include`, was 20 at item 41's close, and **550** against the whole front
end, was 562: twelve went away and none arrived. The twelve are nine `Property 'window' does not
exist` errors — one per template that reached for `window.print()`, which is item 43's migration
counted by the type system — plus two in `AdminLayout.vue` that the corrected `usePage` generic
resolved, plus `UserInfo.vue`'s avatar `src`, which had been `string | undefined` against a required
`string` since before this audit began. Nothing this round wrote is among the 550, and the one error
in a file this round touched is `app.ts`'s pre-existing `vite/client.d.ts is not a module`, which was
in the baseline at a different line number and is a triple-slash reference the project's `tsconfig`
does not resolve. The gate is still not wired into CI, which section 4 carries as open, and finding 56
is the argument for wiring it: the round's only shell-wide regression was caught by the one gate
nobody runs, after pint, ESLint, Vitest, the production build and 589 Pest tests had all passed. `UiKit.vue`'s hero claimed "Components: 24" as a literal, which was already wrong the day
`SchemePicker` arrived; the controller now counts the directory (finding 52).

**Contract tests added:** `ThemePaletteTest` (**12 tests, 533 assertions**) holds the three languages
together — every palette in CSS has a PHP id and vice versa, every palette block defines all twenty
tokens in both modes, every token is mapped to a utility, status colours belong to the mode, the
resolver's precedence and its refusal of an unknown value, the shell's pre-paint rendering, and the
storage key read identically by Blade and by TypeScript. `UiSettingsTest` (**12 tests, 86 assertions**)
covers both endpoints, the permission gate, `null` as a reset, and reachability from the menu.
`SharedComponentThemeTest` is re-pinned: its allowlist is down to DataTable's four print grays, the two
assertions that *demanded* the hardcoded hero and the six `dark:` counterparts are inverted into
assertions that demand the tokens, and its scanner now strips comments. On the front end,
`useUiTheme.test.ts` (**9 tests**) covers the two attributes on `<html>`, `system` mode against a
stubbed `matchMedia`, the exact storage shape Blade reads, and legacy-key adoption. `ShellBindingTest`
(**3 tests, 20 assertions**) was added after the round broke the shell in a way nothing else could see
— finding 56 — and scans every CORE layout for a bare template name with no binding behind it, in both
directions, with fixtures that fail if the scanner goes blind or starts over-reporting.

A twelfth test in `ThemePaletteTest` exists because of a defect introduced and caught inside this round:
`UiTheme::share()` was written against `Modules\CORE\Models\User`, and the starter kit's own auth tests
sign in as `App\Models\User` — the parent class. Every Inertia response for those requests became a 500
`TypeError`, and the visible symptom was `users can logout` failing on an `assertGuest()` that never
ran, because the request died before the redirect. The hint is now the framework's base authenticatable,
which is also the honest one: `can()` and attribute access both come from the base class, so nothing is
lost by widening it (finding 53).

### Printing (new) — item 43

P2.4 recorded "no print/PDF styles" as an absence. It was worse than an absence: printing was
*implemented* and produced blank paper, which is the failure nobody reports because the button looks
like it worked.

Measured, in the state item 43 started from: **5** files carried `@media print`; the shell's rule hid
`body *` and revealed only `[data-core-print]`, an attribute that **exactly one component in the
application set** (`DataTable.vue`); **15** lines mentioned `window.print`, of which the templates and
scripts held the live calls; **2** pages — `PaySlips/Show.vue` and `BarcodePrint.vue` — carried their
own **unscoped** `<style>` print blocks, and an unscoped style in a Vue SFC is injected once and never
removed, so visiting either page blanked printing for the rest of that tab's life, including pages
printed before it; and `Pos/Receipt.vue` called `window.print()` **from a template**, which compiles to
`_ctx.window.print()` and throws — that button had never once printed anything.

The type checker had already found this, from a direction nobody asked it to, and nobody was reading
it: at item 41's close `vue-tsc` reported **nine** `Property 'window' does not exist` errors across the
whole tree — one for each template that reached for `window.print()` — and after the migration it
reports **zero**. Nine of the 562 errors in that backlog were not noise, they were this bug, counted
per file, in a gate the repository does not run. That is the strongest single argument in this report
for wiring `vue-tsc` into CI, and it is recorded here rather than in the recommendation because the
evidence belongs next to the defect it found.

So the blank sheet had two independent causes, and fixing either alone would have looked like a fix:
the shell hid everything and revealed one region, and two pages had responded by hiding everything
themselves, globally, forever.

**What replaced it.** One stylesheet owns paper — `resources/css/print.css` (297 lines) — and no
component owns any: there are now **0** unscoped `<style>` blocks in **394** `.vue` files, and the
seven that remain are all `scoped`. Region isolation is opt-in, keyed on `html.is-printing-region`,
which the engine sets only when a `[data-core-print]` element exists; a page that marks nothing gets a
structural fallback (`.core-shell > *:not(main)`) instead of a hidden body, so it prints its content
with the sidebar removed. `usePrint.ts` (164 lines) is the only caller of `window.print()`: it waits
for web fonts, stamps the sheet furniture at print time rather than at mount time (a tab left open
overnight used to print yesterday's date on the letterhead), installs `beforeprint`/`afterprint` once
from `app.ts` so that even an unmigrated call behaves, and falls back to a one-second timer for engines
that never fire `afterprint`. The token set is reset to light-on-white under `:root, :root.dark,
:root[data-theme]` and left unlayered, because `.dark` at (0,1,0) beats `html` at (0,0,1) and Tailwind
v4's `@layer base` loses to an unlayered rule — dark mode on screen must not become grey-on-grey on
paper. Table headings repeat via `display: table-header-group`; rows and cards keep
`break-inside: avoid`; date, month and search inputs, `select` elements and `.print-hide-filters` are
hidden by element type so a filtered report does not print its filter bar. Four documents ask for the
named page `portrait-sheet` (payslip, barcode sheet, leave application, POS receipt) while the default
`@page` stays A4 landscape for ledgers, and `print-keep-color` requests exact colour on the two pages
whose colour *is* the content — a dithered barcode is a rectangle of nothing.

**16 files** now print through `printDocument()`: the eight accounting reports, the shared `DataTable`
(so every list it renders prints correctly), the payslip, the leave application, the barcode sheet, the
POS receipt, the two day-end screens and the design-system demo. `DataTable` was migrated last and is
worth naming: its bare `window.print()` *worked*, because the global hook exists, which is exactly why
it survived the first pass — it just printed before the fonts settled and without stamping the
furniture. Three bespoke `<style>` print blocks were deleted.

**What CSS cannot do here**, published so nobody rebuilds it: Chrome and Firefox do not implement
`@page` margin boxes, so `counter(page)` page numbers are impossible from a stylesheet, and the sheet
colophon says to use the browser's headers-and-footers option instead. Named pages are honoured by
Chrome and Edge and ignored by Firefox, which degrades to the default sheet rather than misprinting.

**Out of scope by decision**, not by oversight: transactional-document stationery (invoice, quotation,
delivery challan, purchase order, GRN, journal voucher and POS-receipt layouts) and server-side PDF
export. `dompdf` is installed and is used by none of this. Those documents print today through the
generic path — correctly, on white paper, with the letterhead — but they do not yet have a document
layout of their own.

**Contract tests added:** `PrintContractTest` (**11 tests**) pins the architecture rather than the
output: no component hides the body under `@media print`; every `visibility: hidden` rule in the print
stylesheet is keyed on the opt-in class; the hook is installed exactly once and cleans up; no template
calls `window.print()` and, with `DataTable` migrated, no `.vue` file calls it at all; the shell owns
the letterhead and no print CSS; paper is light-on-white in both modes with repeating table headings;
exact colour is requested where colour is content; the portrait pages ask for portrait; and no page
marks more than one region, because isolation reveals one and two means one prints blank.
`usePrint.test.ts` (**7 tests**) drives the real composable in jsdom, and its first test is the
regression: a page that marked nothing must **not** enter region isolation, because that class is what
blanked the sheet.

Two false readings from the scanner are recorded because they shaped it. A first version stripped
comments with three regexes and swallowed 1.4 kB of `DataTable.vue`'s code, because the file sends
`Accept: 'application/octet-stream, application/pdf, */*'` and the comment opener inside that string
ran to the next closer. A second version walked the file tracking strings properly and stopped
stripping anything after line 294, because the same file matches `/filename="?([^";]+)"?/i` and a regex
literal is indistinguishable from a string without a JavaScript parser. The rule that survives is
narrower and true: **whole-line comments only** — markup comments anywhere, `//` and block comments
that each start their own line — with sensitivity fixtures for both directions, since a scanner that
over-strips reports a clean tree and one that under-strips reports prose as a defect.

### Chart-of-accounts modelling (new) — item 44

P1.20 recorded two defects it declined to fix, because each looked like a decision about somebody's
chart rather than a repair. Item 44 takes them in the order the report gave them and separates the two
things that were tangled together in both: **what the code does wrong**, which is fixable here, and
**what the chart is allowed to say**, which is not this project's to decide.

**The zero-net kit journal.** `KitService::complete()` consumed the components, produced the finished
kit, and then posted a JV whose two legs both resolved to
`config('accounts.defaults.inventory_code', '1200')` — Dr Inventory 20.00, Cr Inventory 20.00 for a
two-unit build of a kit whose components cost 10.00 each. The entry was balanced, which is why nothing
ever complained about it, and it netted to zero, which is why it recorded no value transfer. There was
no value transfer to record: component stock and finished-kit stock sit in the one account on this
chart. What the entry did do was **gross up both sides of the inventory ledger** by the component cost
on every build, so account 1200 gained a debit and a credit that no transaction explains and its
running balance walked through numbers a reader would have to reconstruct by hand to dismiss. That is
the same shape of harm as P0-B and P1.19, produced by a service that was following both of their
rules. The kit gained nothing from the entry either — `inventory_kits` has no `journal_id` column,
checked with `PRAGMA table_info` rather than by reading the model — so it was not even a link back to
the build. The code carried an inline NOTE pointing at this item; the note is gone, and so is the
entry.

**What was deliberately not done.** The report's own reasoning stands, and the fix honours it rather
than routing around it: correcting that journal by posting it somewhere real "needs a WIP or kit-output
account that the chart does not currently define, and inventing one would be a change to somebody's
accounting policy rather than a bug fix". So no account was invented. `accounts.defaults` gains
**`kit_output_code`** (env `ACCOUNTS_KIT_OUTPUT_CODE`), which **ships empty**, and `complete()`
branches on it:

- empty, equal to `inventory_code`, or an empty string arriving from an env key set to nothing — the
  build posts **no journal**, because the chart cannot represent a transfer into itself;
- naming a real account — the conversion posts for real, **Dr kit output / Cr inventory** for the
  component cost, through the same `requireId()` guard as every other leg, so a code the chart lacks
  refuses the build and rolls the whole transaction back. That matters more here than elsewhere: the
  components are already consumed by the time the journal is written, and a rollback that left a
  completed kit with no entry behind it would be worse than the defect being fixed.

`filled()` rather than a null check is what makes the first case safe. A key that is present but empty
in a deployment's `.env` arrives as `''`, and handing `''` to `requireId()` would refuse every kit
build over a typo in an env file.

**What skipping the journal does not lose.** The conversion stays fully traceable in the ledger that
already described it, and the tests pin that rather than assert it: `inventory_stock_moves` carries
both legs against the kit number — `reference_type` `kit_component` with direction `out`, and
`kit_production` with direction `in`, both with the kit number in `reference_no`. One correction is
worth publishing because it is easy to read wrong from the call site:
`InventoryStockProvider::deductStock($items, $referenceNo, $referenceType)` takes the *type* third, so
`KitService`'s `'kit_component'` lands in `reference_type` and the `reason` column stays null. A reader
who goes looking for the kit's legs in `reason` — as this report's own P1.20 entry did — finds nothing
and concludes the trail is missing when it is one column over.

**The three hardcoded account codes.** The census was exact before the change and is zero after:
`modules/PURCHASE/Services/LandedCostService.php:158` resolved every landed-cost line that carried no
`account_id` of its own to `requireId($posting, '6000', $purpose)`, and
`modules/SALES/Services/InvoiceService.php:146` and
`modules/SALES/Services/SalesReturnService.php:105` each wrote `$cogsCode = '5001';` on a line
surrounded by five `config('accounts.defaults.*')` lookups. All three now read config —
`landed_cost_code` and `cogs_code` — and the defaults are the numbers the literals were, so no chart
and no posted amount changes as a result. Two consequences are worth recording. First, this report's
P1.20 entry claimed `accounts.defaults` had *neither* key, but item 39 had already added `cogs_code`
for the POS day-end posting: the claim was half stale, and the real defect was narrower and stranger —
two services hardcoding a number that a config key already owned and already meant. Second, the seeded
chart has **no account 6000**, so a landed-cost allocation whose lines carried no `account_id` threw a
`DomainException` on an otherwise complete chart. That is still true when the new key points nowhere,
and it is the behaviour P1.19 established as correct: refuse, name the code, say what to configure, and
say that nothing was written.

**Tests added.** `tests/Feature/INVENTORY/KitConversionPostingTest.php` (**9 tests, 62 assertions, 474
lines**) builds its own two-account chart and fiscal year rather than seeding the demo data whole, so a
kit build is tested against exactly the accounts the test says it should use. It pins: no journal by
default *and* no line and no running-balance row on inventory, which is the gross-up's measurable
signature; the stock legs still moving when no journal posts; the balanced Dr/Cr to a configured output
account, with neither leg appearing on the other side of the same entry; the degenerate configuration
(output = inventory) and the empty string both staying silent; the refusal-and-rollback path; the
census at zero; the scanner's own two-directional proof; and the published default of every key the
posting services read.

**Sensitivity, measured rather than claimed.** Three probes, each reverted inside the same run:
restoring the old block verbatim — both legs on inventory, no guard — fails **5 of the 9**, including
the ledger assertions and the rollback test; putting `$cogsCode = '5001';` back into `InvoiceService`
fails exactly **1**, the census, naming `InvoiceService.php:146 5001 (assignment)`; and blinding the
scanner's resolver regex leaves the census **green at zero** and fails only the fixture test. That last
result is the argument for having a fixture at all: a scan that cannot see violations and a scan that
found none produce identical output, and only the second probe tells them apart.

**What was run.** Every test that touches the four changed services, and the four module suites around
them: `tests/Feature/{INVENTORY,ACCOUNTS,SALES,PURCHASE}` came to **162 passed / 935 assertions**, and
`PurchaseAndSalesE2ETest`, `LineItemCoercionTest`, `SalaryPaymentPostingTest` and
`MigrationDataSeparationTest` to **69 passed / 655 assertions** — 231 tests with no failures, and no
existing assertion needed editing, because nothing tested kit posting before.

**Gates after this round.** Pest **601 passed / 0 skipped / 8,519 assertions** in 680 s, from item
43's 592 / 8,457. The totals moved by exactly the nine tests and 62 assertions this file adds, which
is the arithmetic check that the three new config lookups changed no other test's behaviour — a lookup
whose default differs from the literal it replaced would have moved an assertion count somewhere in
the 231 above. `pint --test` is **892 files PASS** against item 43's 891, the one being the new test
file, whose single style fix was an inline fully-qualified class name in a docblock. No front-end file
was touched, so the figures published at item 43's close stand unchanged and were not re-measured:
Vitest 239 tests / 9 files, ESLint 0 problems, the production build ✓ in 11.93 s, and `vue-tsc` at 19
base / 550 whole-tree errors.

### Rate limiting the expensive HRM endpoints (new) — item 45

P1.6 was one line long — "no rate limiting on device sync or bulk roster operations" — and the first
thing item 45 established is that the line was right about the endpoints and wrong about the shape of
the gap. Login was already throttled: `LoginRequest::ensureIsNotRateLimited()` allows five attempts
before it makes a caller wait, clears the counter on success, and `routes/auth.php` puts
`throttle:6,1` on the password-reset link. What had nothing on it was HRM's sync and bulk surface, and
the reason it matters is cost rather than secrecy: **`POST hrm/attendance/sync-all` walks every
configured terminal's attendance log over the network**, `sync/{device}` does it to one,
`devices/{device}/sync-users` pulls a terminal's user list, `overtimes/sync-attendance` derives
overtime from attendance, `process-pending` works the queued punches, and `duties/bulk` expands one
request into a duty row per employee per day with `duties/bulk-week` rendering the week behind it.

**Why this was P1 and not P0.** All seven routes were already behind `PermissionMiddleware` with a real
permission (`hrm.attendance.sync`, `hrm.devices.manage`, `hrm.duties.manage`, `hrm.duties.view`), so the
caller had to be an authenticated HRM user. Authorisation is not a budget, though. Nothing in the stack
limited how *often* an authorised user could ask, so one operator with a stuck button, a retry loop in
a script, or a browser tab left refreshing could hold every device sync open at once — and each of
those requests would look entirely legitimate in the log, because each of them was.

**What was added.** Two named limiters in `AppServiceProvider::boot()`, which had been an empty stub
since the starter kit:

- **`hrm-device-sync`** — `Limit::perMinute(6)`, keyed `hrm-sync|{user}|{device}`, where `{device}` is
  the route parameter and falls back to `all` for the routes that act on every terminal. Per device
  because one terminal's backlog is not another's: an operator legitimately syncing five devices should
  not spend a single shared bucket doing it, and a device that keeps failing should not lock out the
  rest of the site.
- **`hrm-bulk-write`** — `Limit::perMinute(12)`, keyed `hrm-bulk|{user}`, for the two roster endpoints
  that generate or render a week of rows per employee.

Named limiters rather than an inline `throttle:6,1` on each route, so the budget and the key exist in
one place and a route only says what it is; and the key is the **user id, not the IP address**. That
choice is the one part of this item that is a judgement rather than a convention, and it is worth
stating plainly: an office behind one NAT address shares a source IP, so an IP-keyed bucket means the
twelfth person to click Sync is told to wait for the first. The IP is kept as the *fallback* for a
request with no authenticated user — which none of these routes can currently produce — so that if one
is ever exposed the key degrades to per-address instead of to nothing.

**Deliberately not throttled:** the `GET hrm/attendance/sync` screen and `PUT
hrm/attendance/sync/{device}/settings`. One is a page and the other writes a single row; limiting them
would only annoy the person using the module, and the contract test is written to leave them alone
rather than to demand a limiter on everything whose URI contains the word "sync".

**Tests added.** `tests/Feature/HRM/SyncRateLimitTest.php` (**5 tests, 34 assertions**) covers the
wiring and the behaviour. Two tests pin the seven routes as they stand, and one of those two is the
forward-looking half: it walks every registered HRM POST route whose URI says `sync`, `bulk` or
`process-pending` and demands a `throttle:` limiter on it, so the eighth endpoint somebody adds next
quarter arrives throttled or fails the suite. The other three pin the shape and the effect — both
limiters registered at six and twelve with keys that differ between two users on the same IP, the
seventh request inside a minute refused with a 429 and a `Retry-After`, and a second operator getting
straight through after the first has exhausted their bucket. That last test is the one an IP-keyed
limiter fails, which is the point of writing it.

**Sensitivity, measured.** Three probes, each reverted in the same run: removing the limiter from
`sync-all` alone fails **2 of the 5** — the route list and the walk-every-route contract; raising
`perMinute(6)` to `perMinute(600)` fails **3**, the budget assertion and both behavioural tests,
because a limit that never bites is not a limit; and re-keying the limiter on `$request->ip()` fails
exactly the **2** that exist to catch it, the per-person key assertion and the second-operator test,
while every other test stays green — which is what makes those two worth having separately.

**Gates after this round.** `tests/Feature/CORE` and `tests/Feature/INVENTORY` came to **180 passed /
5,887 assertions**, and `tests/Feature/HRM` to **131 passed / 751 assertions** — 311 tests, no
failures. The HRM run is the one that matters here, because it is the suite that exercises the seven
routes the new middleware now sits on: nothing in it calls a sync often enough to meet a limit, and
nothing that did was expecting a 429 it did not get. `pint --test` is **893 files PASS**, one more than
item 44's 892 for the new test file, whose single style fix was an operator spacing. The full suite was
last measured at item 44's close — **601 passed / 8,519 assertions in 680 s** — and was not re-run for
this item; the difference is exactly the five tests and 34 assertions above, and the three suites run
here cover every module whose code changed. No front-end file was touched, so the Vitest, ESLint, build
and `vue-tsc` figures published at item 43's close stand unchanged. The production build *was* re-run,
but only because the rollback described below deleted `public/build` along with everything else it was
not told to keep: ✓ built in 13.14 s against item 43's 11.93 s, from source that is byte-identical to
the tree that produced that figure.

**A mid-turn container rollback, and what it cost.** Part way through this item the sandbox reverted
files created in earlier turns: five were deleted outright (`ShellBindingTest`, `ThemePaletteTest`,
`PrintContractTest`, `UiSettingsTest` and `SchemePicker.vue` — finding 56's contract among them),
eleven more were silently rolled back to older revisions, and `vendor/composer` was damaged far enough
that nothing would boot. Two of the eleven were **this report's own item-44 work**: `InvoiceService` and
`SalesReturnService` were back to their hardcoded `$cogsCode = '5001'`. The repair was the discipline
the tarball exists for — rebuild the toolchain, extract `backup/tree-item44-final.tgz`, sha256-sweep
all 985 of its paths against the working tree, restore the sixteen that differed, and hold back the one
path edited *after* the tarball was taken (`modules/HRM/Routes/web.php`, whose only differences from
the archive were proved to be this item's seven throttle insertions before it was kept). The re-sweep
came back with nothing differing and nothing missing, item 44's config lookups verified present again
by grep, and its nine tests green again. The cost was about ten minutes and no lost work; the reason it
was not more is that the previous round's tree had been archived at all.

One casualty no archive could return: `public/build` is gitignored, so it was in no manifest and no
tarball, and its absence presents as an HTTP 500 on *every* page reading "Vite manifest not found" —
which looks exactly like an application bug in the code that was just changed. `npm run build` put it
back. The general rule this round is the one the tarball already encodes, extended: an archive of the
dirty set protects the work, and only the work, so anything gitignored that the running application
needs — build output, the vendor tree, `node_modules` — has to be rebuildable from a script rather
than recoverable from a backup, which is what `rebuild_toolchain.sh` is for.

### The demo device that an IP address vouched for (new) — item 46

§4 item 8 filed the seeders' device addresses as "real-looking data" — a hygiene complaint. Reading
them turned out to be worth more than that, because those addresses were load-bearing in production
code. `AttendanceSyncService` answered the question *"is this a demo device?"* by comparing the
device's IP address against a hardcoded copy of the seeder's own list, in three places:

- line 373, where a missing ZKTeCo package falls through to `generateDemoAttendance()` if the address
  is `192.168.10.93`;
- `shouldUseDemoFallback()`, where `in_array($device->ip_address, ['192.168.10.93', '192.168.10.94',
  '192.168.10.95'])` stands in for the environment check;
- line 633, where the same list lets a device past a failed `fsockopen` and into a UDP attempt.

An IP address says where a device lives, not what a device is. On a production host the consequence
was concrete: a customer whose LAN happened to use `192.168.10.9x` — one of the most common private
ranges there is — and who had a device record at one of those three addresses got **fabricated punch
rows written into the attendance table that payroll reads**, with no environment check and no opt-in.
The seeder hygiene framing understated it; this was invented data entering a salary pipeline.

**The fix removes the address from the decision entirely.** One private `simulationAllowed()` now
answers the question as `app()->environment('local', 'testing', 'development') || (bool)
config('hrm.sync.allow_simulation', false)`, backed by `HRM_ALLOW_ATTENDANCE_SIMULATION` and shipped
`false`. All three call sites use it, and no IPv4 literal remains anywhere in the service. A
deployment that genuinely wants a simulated device on a live host now has to say so, in writing, in
its environment — instead of inheriting it from an address a seeder happened to pick.

**The seeders moved to RFC 5737 TEST-NET-1** (`192.0.2.91/.93/.94`), the block reserved for
documentation and guaranteed never to be routed to anybody's device: 11 literals across
`BiometricDeviceSeeder.php` and `AMZDemoSeeder.php`. A scan of every seeder in `database/seeders` and
`modules/*/Database/Seeders` confirms those eleven are the only IPv4 literals any seeder contains.

Under the test environment behaviour is unchanged, which is why this was safe to make: `testing` was
already inside the environment branch, so the address comparison only ever mattered in production —
exactly where it should not have. The targeted regression across every suite that touches the sync
service (`tests/Unit/HRM/Services`, `BiometricDeviceTest`, `AttendanceEngineTest`,
`SyncRateLimitTest`, `SilentFailureInventoryTest`) ran **57 passed / 215 assertions / 252.31 s**, no
failures.

**`SeederNetworkHygieneTest.php` — 191 lines, 5 tests / 34 assertions, 0.53 s.** It classifies
RFC 1918, loopback, link-local and CGNAT space in both directions, scans every seeder with comments
stripped so prose about the old addresses is not mistaken for an address, demands the demo devices
stay on TEST-NET-1, demands no IPv4 literal or address-list comparison survive in the sync service,
and drives `simulationAllowed()` through `testing`, `production` and an explicit opt-in via
`ReflectionClass::newInstanceWithoutConstructor()` so the test does not couple to three repository
bindings. Proven sensitive three ways: restoring the hardcoded list in the service → 1 failure;
moving a seeded device back to `192.168.10.94` → 2 failures; shipping `allow_simulation` as `true` →
1 failure. Each mutation was reverted and the file re-run green.

**One of those probes was blind at first, and is worth recording.** Probe C passed on a green suite:
the assertion meant to pin the shipped default read `config('hrm.sync.allow_simulation')`, but the
test had been overriding that key throughout, so it could only ever report the override and would
have stayed green against any default at all. It now re-`require`s `modules/HRM/Config/hrm.php` and
asserts on the array that file returns. A check that passes for a reason unrelated to the thing it
claims to pin is worse than no check, and the only way to find it is to break the source and watch.

**Item 8 is only partly closed.** Its other half — real-employee seeders wired into the default
`DatabaseSeeder` — stays open deliberately: `SalaryPaymentPostingTest` depends on the base seed
carrying the full 998-strong roster, so thinning it would need that contract re-cut first. The
address half is fixed and pinned.

## 4. Still open — prioritised

### P1 · Should fix before production

**1. Systemic silent failure — the highest remaining risk.**
**11 empty catch blocks in production code** — and this is the first time the figure has been counted correctly; every earlier number in this report (27, then 25, then 20) was wrong, for the reason given in P1.18. All 11 are in HRM attendance and all are tolerated with a stated reason, *enforced by a test* rather than merely listed: 8 in `AttendanceSyncService` (best-effort device housekeeping) and 3 in `AttendanceDailyRepository` (punch-time parse fallbacks). Every financially consequential site has been fixed — P1.14, P1.17, P1.18. The **24 `accountIdByCode()` sites** that shared the same silent-skip shape are closed too, under P1.19. Item 40 extends the claim past *empty* catches: finding 35's two payroll swallows both logged, which is exactly why the tokenizer-based inventory could not see them, and both halves are now pinned by name in the same test.
*Recommendation:* a fail-loud posting guard plus an `accounts.posting.require_accounts` config flag, then delete the empty catches. This is the same class of bug as P0-B and is not fully closed until the pattern is removed, not just the one instance.

**2. Money handled as float — PARTLY FIXED (item 26).** The model layer is now correct and enforced:
all **242 money columns** in the schema are declared `decimal:N`, the last 43 of them fixed under item
26, and `PosRepository`'s float-in-raw-SQL aggregate is gone — item 39 adds twelve more with the day-end
and collection columns, all `decimal:2`, and re-pins `MoneyColumnCastTest` at **156 models / 242 columns**.
What remains is the PHP layer's
*arithmetic* — 32 `(float)` sites in ACCOUNTS (`JournalService::balanceDelta/currentBalance`,
`LedgerService`, `JournalLine`, `Payable::balance()`, the DTOs) still compute in binary float.

Two claims this entry originally made are corrected in item 26 rather than edited away here: the
`increment()` replacement is neither "safer" nor newly atomic (the original was one UPDATE statement
already, and `increment()` interpolates its amount too), and because Laravel's `decimal:N` cast formats
on GET only, **no cent-level error in the stored ledger has been demonstrated**. One cent-level error
*was* demonstrated and fixed, but it was not in the ledger: the POS session aggregate dropped five
cents on a total at the top of `decimal(15,2)`'s range, and item 26 quotes the SQL. The remaining
conversion is therefore hygiene and future-proofing rather than the repair of a reproduced defect, and
it is deliberately deferred instead of being done under a banner this audit could not earn.

**3. Auth-only JSON endpoints — FIXED.**

`api/options/{type}` exists in seven modules and `inventory/api/pos-product/{key}` in one. At
`f3e014a`, **six of the seven controllers contained no authorisation construct at all** — no `can()`,
no `abort`, no middleware, no permission map — and only CORE gated per type. Every one of those
routes carried `['web', 'auth']` and nothing else, so any authenticated user could enumerate:

- every **bank account number** — `BankRepository::options()` puts `account_no` in the option label;
- the whole posting chart of accounts;
- the entire customer and vendor list **with phone numbers** — `PartnerRepository::options()` selects
  `legal_name` and `phone`, and emits the phone as the option description;
- every product **with its selling price** — `ProductRepository::options()` emits `meta`/`price` and
  selects `cost_price` as well;
- live stock levels and prices one barcode at a time through `inventory/api/pos-product/{key}`, which
  `Pos/Terminal.vue` calls with a bare `fetch()` and no rate limit.

The exposure was invisible from the route list, which is where an audit normally looks. Reading only
the route files showed HRM protected and six modules open; reading only the controllers showed CORE
protected and HRM open. Both layers had to be read — and the earlier revision of this finding read
one of them, which is why it credited HRM with a gate this engagement had added and missed that CORE
already had one.

**HRM had the mirror-image defect.** Its route carried a single coarse `hrm.view`, while every HRM
*page* is gated on a specific permission — `leaves/create` needs `hrm.leaves.view`, not `hrm.view`.
The two seeded operational roles (Manager, Auditor) happen to hold `hrm.view`, so nothing broke in the
demo. A custom role granted only `hrm.leaves.view` could open `LeaveRequests/Create.vue` and get a 403
from its own employee picker: the form was unusable, and the failure presented as a dropdown that
would not fill rather than as a permission error. The coarse route gate is now gone.

**Fix.** `modules/CORE/Support/OptionAccess.php` — one gate, `gate($request, $type, $permissions)`,
returning a `JsonResponse` to short-circuit or `null` to proceed. All eight controllers declare a
`private const PERMISSIONS` map and hand it over. Two properties were deliberate:

- **Deny by default.** A type not in the map 404s instead of falling through to the `match` default
  and returning an empty list, so adding an arm without a permission breaks loudly. The
  `default => collect()` arms were removed for the same reason: they made a typo in an endpoint
  indistinguishable from "no matches", which is exactly how `purchase/api/options` and the two HRM
  types shipped broken in the first place (P0-K).
- **Any-of, and the list follows the consumer rather than the owner.** These types are cross-module
  reference data. `/inventory/api/options/products` is consumed by 7 PURCHASE pages, 5 SALES pages
  including the POS terminal, and 5 INVENTORY pages; `/hrm/api/options/employees` by 15 HRM pages
  **and two PAYROLL components** that resolve `route('hrm.api.options', …)`. Gating products on
  `inventory.products.view` alone would 403 for every purchase or sales role not also granted
  inventory. So each type lists every permission that implies needing that data, and holding any one
  suffices. The rule applied was *may use a document that carries this field*, not *may administer
  this table*.

The gate returns JSON rather than calling `abort()`: `abort(403)` renders an HTML error page unless
the request sent `Accept: application/json`, and these endpoints are called with a bare `fetch()`. The
403 body carries `requires_any_of`, so an operator can see which grant is missing.

**Tests.** `tests/Feature/CORE/OptionEndpointAuthorisationTest.php` — 11 tests, 154 assertions, over a
38-type matrix read out of the controllers by reflection. Every type must answer the super admin with
a readable envelope, refuse a user holding no grants, and accept a user holding **only the first**
permission in its list. Every permission named anywhere must exist in the seeded vocabulary: a typo is
invisible to the super admin, who holds `*`, and ships as an empty dropdown for every real role. A
structural test walks the whole route table and asserts that any route serving `api/options/` or
`api/pos-product` resolves to a controller declaring a map — that is the check which catches the
*next* module rather than these eight. Two behavioural tests pin the cross-module shape in both
directions: a `purchase.bills.view`-only role can fill the bill form's product, warehouse and vendor
pickers but is refused bank accounts, customers and the POS lookup; an `hrm.leaves.view`-only role can
fill the employee picker while a `sales.invoices.view`-only role cannot.

**Sensitivity proven:** making `gate()` return null unconditionally fails exactly the five refusal
tests and none of the other six.

**4. Default credentials and deployment config — FIXED, both halves (items 22 and 27).** `core.super_admin` defaults to `Super Admin / admin@example.com / password`; those env keys were **absent from `.env.example`** (now added). `CoreSeeder` seeded users with `Hash::make('password')` — three named demo accounts, plus up to 200 more through `User::factory()`, whose default is the same string. There was **no production guard**, so a deployment that forgot to set the variable handed its `*`-wildcard administrator to anyone who has read this repository; that is now refused at both the writing and the using end. The container half is closed by item 27: `docker-compose.yml` no longer states `MYSQL_ROOT_PASSWORD=secret`, no longer publishes 3306 beyond the compose network, and now actually connects the application to the database it starts; `.env.example` keeps `APP_DEBUG=true` because it is the *local* template, and `ProductionDebugGuard` makes the value incapable of taking effect under `APP_ENV=production`. One thing in this area is reported rather than fixed: the compose file's `build: .` has no `Dockerfile` in the repository, so `docker compose up` cannot run as shipped. A hardened **`.env.production.example`** was added earlier and now documents both guards' switches.

**5. Cross-database date divergence — the fiscal gate FIXED (item 23); the report queries still open.** `FiscalPeriod`/`FiscalYear` use `date` casts, which serialise as `Y-m-d H:i:s` on SQLite but `Y-m-d` on MySQL. `assertFiscalOpen()` compared those strings directly, so **period-open checks behaved differently per database** — and this is no longer a theory: `JournalPostingDomainTest` reproduces it, and on SQLite a journal dated exactly on the fiscal year's *first* day was refused with "No fiscal year covers 2026-07-01" while one dated on the last day was accepted. All four fiscal lookups in `JournalService` now compare with `whereDate()`, which is driver-independent, and the boundary is pinned by a test. What remains open is the same class of comparison in the HRM report and roster queries, which still use `whereBetween()` against `date`-cast columns; the CI matrix runs MySQL, which is what will catch those. **Item 33 adds an eighteenth site in a different shape**, and it is reproduced rather than predicted: `LeaveRequestService::syncApprovedLeaveToAttendance()` passes a `Y-m-d` string to `AttendanceDaily::firstOrNew()`, the `date` cast serialises it as `2026-10-12 00:00:00`, the lookup misses on SQLite, and the `save()` that follows becomes an INSERT against `unique(employee_id, duty_date)`. Approving leave over an already-processed attendance day therefore throws — on the database this project actually runs, and not on the one CI tests.

**6. No rate limiting on device sync or bulk roster operations — FIXED (item 45).**

Login was never the gap: `LoginRequest::ensureIsNotRateLimited()` has throttled the login form at five
attempts since the starter kit, and `routes/auth.php` throttles the password-reset link at six an hour.
What had nothing on it were the seven HRM endpoints whose cost is out of proportion to a click — a
device sync walks a terminal's attendance log over the network, `sync-all` does that to every terminal
at once, and a bulk roster write expands into one duty row per employee per day. All seven were already
behind `PermissionMiddleware`, which is why this was P1 rather than P0: the caller had to be an
authenticated HRM user. Permission is not a budget, though. One user holding `hrm.attendance.sync`
could keep `sync-all` open in a loop and starve every other device, and nothing in the stack would
notice, because every one of those requests was authorised. Two named limiters cover them now —
`hrm-device-sync` at six a minute keyed on the user *and* the device where the route names one, and
`hrm-bulk-write` at twelve a minute keyed on the user — registered once in `AppServiceProvider` so the
budget and the key are stated in one place and the routes only name what they are. Neither is keyed on
the IP address alone, because an office behind one NAT address shares a source IP and the twelfth
person to click Sync would be told to wait for the first: that limit would punish the org chart rather
than the caller. Detail, the five tests and the three sensitivity probes are in §3 under item 45.


**7. 21 HRM FormRequests return `true` from `authorize()`.** More defensible now that routes are permission-gated, but per-record rules (e.g. an employee may only see their own leave) still need policies.

**8. Seeders contain real-looking data** — PARTLY FIXED (item 46). The device addresses are gone
from both the seeders (now RFC 5737 TEST-NET-1) and, more importantly, from the three production
sites in `AttendanceSyncService` that used them to decide whether a device was a demo and therefore
whether to invent attendance; simulation is now environment-gated behind
`hrm.sync.allow_simulation`, shipped false. Still open: real-employee seeders wired into the
default `DatabaseSeeder`, which `SalaryPaymentPostingTest` currently depends on.

**9. Every journal create and every draft edit returned a 500 — FIXED.**
`acc_journal_lines.debit`/`credit` are `numeric NOT NULL DEFAULT '0'`. A column default only
applies when the key is *omitted* from the INSERT, and `JournalRepository::create()`/`update()`
forwarded each request line verbatim. A journal line uses one side or the other, and both the
journal UI (typing a debit clears the credit to `null`) and `JournalService::createFromDto()`
legitimately submit `null` for the unused side — `StoreJournalRequest` even declares those
fields `nullable`. The result was `NOT NULL constraint failed: acc_journal_lines.credit`, and
because `store()` catches only `DomainException` it surfaced as a **500**, not a flashed error.
It also affected the internal posting path used by SALES, PURCHASE and PAYROLL through
`AccountsPostingProvider`.

Nothing caught it because `acc_journals` ships **empty** in the demo seed and the module had
**zero** tests.

*Fix:* a private `JournalRepository::normalizeLines()` coerces both sides to float
(`null → 0.00`) and is wired into `create()` and `update()`, covering the web form and the DTO
path at the single choke point. The fiscal-period check is untouched.
*Tests:* `tests/Feature/ACCOUNTS/JournalDraftGuardTest.php` — 7 tests / 39 assertions, covering
create-with-null-sides, draft re-save, and a closed fiscal year still refusing. Unwiring
`normalizeLines()` fails 3 of them.

**10. `update()` did not re-check the fiscal period — FIXED.**
`create()` and `post()` both call `assertFiscalOpen()`, but `update()` does not. A draft
journal can therefore be edited *into* a closed or locked fiscal year and then posted, since
`post()` only checks the year covering the (already changed) date — which would by then be the
closed one, so posting is blocked, but the draft itself silently sits in a closed period.
*Fix:* `JournalService::update()` now calls `assertFiscalOpen()` with the incoming date (falling
back to the stored one) before delegating. `JournalController@update` is the only caller, so no
internal flow is affected. *Test:* `a draft cannot be re-dated into a closed fiscal year` —
removing the call fails exactly that test and no other.

**11. Correction to an earlier claim in this report.** A previous revision of this section
asserted that posted journals were editable in place because `JournalService::update()` never
called `isDraft()`. **That was wrong.** The guard exists one layer down, in
`JournalRepository::update()`: `if (! $journal->isDraft()) throw new \DomainException('Only
draft journals can be edited. Void and recreate if needed.')`. I read only the service method
and missed the delegation. The rule is now covered by tests (previously it was untested, which
is why it was easy to misread), and the redesigned `Journals/Edit.vue` surfaces it in the UI
instead of letting a user edit a posted entry and only learn on submit.

**12. The product form could not save a simple product — FIXED.**
Reading `StoreProductRequest`, `UpdateProductRequest`, `ProductController` and
`ProductRepository` to redesign the product form produced four independent defects, each
invisible to the others:

1. **`required_with` vs. a seeded blank row.** `variants.*.name` and `suppliers.*.partner_id` are
   `required_with:variants` / `required_with:suppliers`. The page always seeds one blank row per
   repeater, so the array key is always present and validation rejected a plain product with no
   variants and no supplier links — on fields the user never touched. `ProductRepository::
   syncRelations()` *already* skipped empty rows; the validation simply ran first.
2. **`inventory_products.mrp` is `numeric NOT NULL DEFAULT '0'`,** and the form initialises `mrp`
   to `null`. Same omitted-vs-null distinction as P1.9: `store()` caught only `DomainException`,
   so this was a **500**. `reorder_level`, `reorder_qty`, `vat_rate`, `cost_price` and
   `selling_price` are exposed the same way once a field is cleared, because
   `ConvertEmptyStringsToNull` turns it into `null`.
3. **`inventory_product_variants.variant_code` is NOT NULL, UNIQUE, and has no default** — and no
   form collects one, so saving any real variant was a 500 too. Its `cost_price`/`selling_price`
   are NOT NULL as well.
4. **`UpdateProductRequest` had no `weight`, `currency` or `variants.*` rules, and `edit()` did
   not send `weight`.** So the Edit page rendered a Weight field that was always blank and could
   not be saved, could not change currency, and showed an editable Variants tab whose edits were
   silently discarded — `validated()` stripped the key, so `syncRelations()` never ran.

*Fixes:* a new `Modules\INVENTORY\Requests\Concerns\PrunesEmptyRepeaterRows` trait (shared by
both requests, since duplicated request classes are what drifted in the first place) drops rows
whose non-flag values are all `null`/`''`/`[]`; `ProductRepository::extractProductData()` coerces
`null → 0` on the NOT NULL numeric columns, deliberately leaving genuinely nullable `weight` and
`shelf_life_days` alone; a new `variantRows()` derives a unique `variant_code` from the SKU plus
row position and coerces the variant prices; `UpdateProductRequest` gained the missing rules; and
`edit()` now sends `weight` and `currency`.
*Tests:* `tests/Feature/INVENTORY/ProductFormContractTest.php` — 9 tests / 43 assertions. **5 of
the first 6 failed against the unchanged code**, which is the evidence for all four findings.

*A testing note worth keeping:* `assertSessionHasNoErrors()` **passes on a 500**, because a
server error carries no session errors. Three of these tests looked like they were passing
validation while actually throwing. They now assert the redirect target as well.

**13. Every line-item write path forwarded `null` into NOT NULL columns — FIXED.**
`enrichTotals()` exists in **7** services — `VendorBillService`, `PurchaseOrderService`,
`PurchaseReturnService`, `GoodsReceiptService`, `InvoiceService`, `QuotationService`,
`OrderService`. All seven read the line numerics defensively for the *arithmetic*:

```php
$discPct = (float) ($it['discount_pct'] ?? 0);
```

…and then persisted the **raw** row: `$doc->items()->create($it)`. Every line-item table declares
`qty`, `unit_price`, `discount_pct` and `vat_rate` **NOT NULL**. Clearing a Discount or VAT field
in the UI sends `''`, `ConvertEmptyStringsToNull` turns it into `null`, and the insert fails — a
**500**, not a flashed error, because `store()` catches only `DomainException` (or nothing at all:
`VendorBillController::store()` has no try/catch).

This is the same *omitted vs. explicitly null* distinction as P1.9 and P1.12: a column's
`DEFAULT '0'` never applies to an explicit `null`. It was easy to miss because the defensive read
sits three lines above the `create()` in the same loop, so the code *reads* as if it handles
nulls — it handles them for the maths only.

*The scope is wider than those 7 services.* Counting every `items()->create()` site gives **26**,
including repositories and services that no document form contract passes through: POS, sales
returns, deliveries, stock transfers, counts, adjustments, kits and partner price lists. A first
fix inserted the same coercion into all 7 `enrichTotals()` loops and was **superseded** once the
wider count was known — 7 of 26 is not a fix, it is a patch on whichever paths were read first.

*Fix (final):* `Modules\CORE\Support\Concerns\CoercesNullNumerics`, applied to all **19**
line-item models. A `creating` hook coerces a **present-but-null** value to `0` for the columns
each model lists in `nullToZero()`, leaving *omitted* keys alone so genuine column defaults still
apply. `id` and every `*_id` are excluded deliberately: coercing a null `product_id` to `0` would
insert a line pointing at nothing, which is worse than the crash being prevented. The per-model
column lists were generated from `PRAGMA table_info` rather than typed by hand.

*A trap worth recording:* the trait first declared `protected array $nullToZero = []` and the
models overrode it with their own lists. PHP rejects that — *"define the same property … however,
the definition differs and is considered incompatible"* — as a **fatal error at class-composition
time**. `php -l` passes on all 19 files, because it is a composition error rather than a syntax
error; it only surfaces when the class is actually loaded. It is now an overridable **method**.
Any trait a using class has to configure should expose a method, not a property with a default.

*Out of scope by design:* NOT NULL **string** columns such as `product_name` and `sku`, which
`enrichTotals()` back-fills from the product. The trait is numeric-only.

*Tests:* `tests/Feature/LineItemCoercionTest.php` — **47 tests / 507 assertions**. It discovers
the 19 models by reflection instead of listing them; asserts every declared column really is NOT
NULL and is never a key; asserts every NOT NULL numerically-*cast* column is covered, so a future
migration adding one cannot silently reopen the hole; and drives the mechanism on a probe table
across present-but-null, omitted, real-value and zero cases. Neutering the trait fails exactly the
4 behaviour tests with `NOT NULL constraint failed`, and the end-to-end
`PURCHASE/VendorBillContractTest` and `SALES/InvoiceContractTest` fail on
`purchase_bill_items.discount_pct` / `sales_invoice_items.discount_pct`.

**14. Voiding a document survived a failed journal reversal — FIXED.**
`VendorBillService::void()` and `GoodsReceiptService::void()` wrapped the ledger reversal in
`try { … } catch (\Throwable $e) {}` and then carried on to mark the document cancelled. Two
failures compound. The AP/inventory journal — or the GRNI journal — stays **posted** against a
document the system now calls cancelled, so ledger and purchase record disagree permanently. And
both calls sit inside `DB::transaction()`, where swallowing the exception is precisely what
prevents the rollback: the one place the damage could have been undone for free discarded the
information needed to undo it. Both now rethrow as `DomainException`, which the controllers
already catch and flash, so the document stays posted and the user is told why.

These were the two sites P1.1 flagged as most financially critical (`VendorBillService:79`,
`GoodsReceiptService:87`). `OrderService:47`, `PosService:99`, `PayrollBankAdviceService:111/130`
and `MovementService:109` were closed in P1.17 and P1.18; the surviving inventory, and the reason
the count quoted here at the time was wrong, is in P1.18.

*Tests:* `tests/Feature/PURCHASE/VendorBillVoidTest.php` — 3 tests / 18 assertions, mocking
`AccountsPostingProviderInterface` to throw. Restoring the empty catch fails exactly the first
test: the bill comes back `cancelled` with a **success** flash while its journal is still posted.


**15. Editing a sent quotation was a 500 — FIXED.**
`QuotationRepository::update()` throws `\DomainException('Only draft quotations can be edited.')`
for precisely the case a user hits when reopening a quotation that has already gone out.
`QuotationController::update()` had **no try/catch**, so the exception escaped as a 500 with a
stack trace instead of the flashed message the repository wrote for that purpose. `send()` and
`accept()` had the same shape. The omission was local rather than a house style: `destroy()` in
the very same controller already caught it, and so does `OrderController::update()`. All three now
catch `DomainException` and flash it.
*Tests:* `tests/Feature/SALES/QuotationContractTest.php` — 5 tests / 28 assertions. The three
`sent`/`accepted`/`converted` cases failed against the unchanged code with *"Expected response
status code [201, 301, 302, 303, 307, 308] but received 500."*

**16. Saving a sales order silently reset every line's discount and VAT — FIXED.**
`Orders/Edit.vue` was a 14-line stub whose line mapping was `{ product_id, qty, unit_price }`.
`OrderRepository::update()` replaces the lines wholesale (`items()->delete()`, then re-create) and
`OrderService::update()` recomputes the header totals from whatever arrived — so each save through
that page zeroed `discount_pct` and `vat_rate` on every line and re-totalled the order: **189.00
came back as 200.00**, behind an "Order updated." success flash. No error, no 500 — just quieter
money. The same page rendered no delivery date, no discount or VAT inputs and no way to add or
remove a line, and threw on `order_date.slice(0, 10)` when the date was null.

This is a different failure mode from P1.13 and needs a different guard: the backend behaved
*correctly*, persisting exactly what it was sent, so no backend test can catch it.
*Fix:* `modules/SALES/Resources/js/Components/SalesOrderForm.vue`, shared by both pages (§8.10).
*Tests:* `tests/Feature/SALES/SalesOrderContractTest.php` — 5 tests. One pins the backend's
full-replacement semantics and the 189 → 200 consequence; a **source-contract** test scans every
`Create.vue`/`Edit.vue`/`*Form.vue` under `modules/*/Resources/js` and fails any that collects
`unit_price` and `qty` without also collecting `vat_rate`, with a documented two-entry allowlist
(`PARTNER::PriceLists`, whose table has no `vat_rate` column; `SALES::Returns/Create`, whose
service derives VAT from the source invoice item). Against the unchanged code it named exactly one
offender: `SALES/Resources/js/Pages/Orders/Edit.vue`.

*A trap worth recording:* the first version of that scan derived its root with
`dirname(__DIR__, 2)` from a file three levels deep, so `glob()` matched nothing and the test
**passed without examining a single file**. It now asserts it found more than 150 Vue files and
matched at least 10 line-item editors before asserting anything about them. A discovery-based test
that can discover nothing is not a test.

*Two smaller corrections found on the way:*
- `OrderItem` casts `qty`, `qty_invoiced` and `line_total` to `decimal:2` but **not**
  `unit_price`, `discount_pct`, `vat_rate`, `discount_amount` or `vat_amount`, while
  `VendorBillItem` casts all of them. The same money field therefore arrives as `10` from an order
  and `"10.00"` from a bill — an instance of P1.2 (money as float) that also makes the API
  inconsistent between documents. Not changed here; the render test asserts numerically so it
  holds either way.
- `InvoiceForm.vue`, written in §8.8, prefilled the due date from `order.expected_delivery_date`.
  **No such column exists anywhere in the schema** — checked across all 168 tables; the real one
  is `sales_orders.delivery_date`. The expression evaluated to `undefined` and fell through to
  `''`, so that prefill silently did nothing. Corrected.

**17. Four more swallow-then-continue sites closed.**
Following P1.14 the same shape turned up in four more places, and it is **two** different bugs,
which is why they are not all fixed the same way.

*Money side-effects, where the next line mutates state — rethrown:*
- `PosService::void()` swallowed a failed `voidJournal()` on the sale's invoice and then marked the
  sale `void`. The journal stayed posted against a voided sale, inside a `DB::transaction()` that
  could no longer roll back. Note the asymmetry inside one method: the `restock()` call
  immediately above had no try/catch at all, so stock failures already propagated — only the
  ledger call was silenced.
- `OrderService::cancel()` swallowed a failed `releaseReservation()` and cancelled the order
  anyway. The stock stayed reserved against an order that no longer exists, permanently
  understating what is available to sell. This one is *not* inside a transaction, so there was no
  rollback being defeated; the reserved quantity was simply stranded, with nothing logged.
- `PayrollBankAdviceService::markPaid()` swallowed a failure to mark each employee's slip paid
  **after** setting the advice itself to paid — so the bank file said paid while the slip still
  said unpaid, inside a `DB::transaction()` holding `lockForUpdate()`.

All three now rethrow `DomainException`, which their controllers already catch and flash
(`PosController::void`, `OrderController::cancel`, and `PayrollFinanceController::markAdvicePaid`,
which catches `\Throwable`).

*Best-effort steps, where aborting the whole operation would be worse — logged instead:*
- `PayrollBankAdviceService::resolveEmployeeBank()` falls back to null bank details when a lookup
  throws. Rethrowing would abort generation of the entire bank file because one employee's details
  could not be resolved. It now logs a warning carrying the slip and employee id, because a null
  account number reaching a payment file has to be findable afterwards.
- `HRM\MovementService` swallowed a failure to auto-assign the onboarding checklist on a transfer.
  Failing an already-approved transfer over a convenience step would be worse, but HR would never
  learn the checklist was missing. Now logged.

`Log::` appears 139 times elsewhere under `modules/`, so logging is the house convention here
rather than a new one. The distinction that decides each case is not "is it important" but **"does
the next line change state on the strength of this one succeeding"**.

*Tests:* `tests/Feature/SALES/PosVoidTest.php` (3 tests / 18 assertions) plus two added to
`SalesOrderContractTest.php` (now 7 tests / 40 assertions). Reverting both service fixes fails
exactly the two relevant tests and no others.
*Not tested — the gap is deliberate to call out:* the payroll `markPaid()` rethrow. Exercising it
needs a salary sheet, a confirmed advice, advice items and slips carrying `breakdown_json`; that
fixture was not worth the cost this pass, so the change rests on the identical and tested shape in
the other two. The two logged cases are untested for the same reason.

**18. The empty-catch count in this report was wrong, and is now enforced instead of reported.**
Every figure quoted above for P1.1 — 27, then 25, then 20 — came from grepping a single textual
spelling, `catch (\Throwable $e) {}`. That pattern misses `catch(\Throwable $e){}` with no space,
which is how three of `AttendanceDailyRepository`'s five sites are written; and it matches the
spelling when it appears in a *comment*, which is how two of my own explanatory comments and two
docblocks in this suite's test files were counted as live defects.

Counted properly — PHP's tokenizer over **624 files** in `modules/` and `app/`, excluding
migrations, seeders and factories, whose empty catches guard DDL that may or may not already exist
— the real figures are **171 catch blocks, 138 with real statements, 11 empty**. Not 20.

The 11 are all in HRM attendance and all defensible: 8 in `AttendanceSyncService` are best-effort
housekeeping on a physical ZKTeco device (`$zk->enableDevice()` / `disableDevice()`), several of
them inside another catch so the device is left usable before the real error propagates — throwing
from that cleanup path would mask the original failure, and the variable is named `$_`, which is
this codebase's own signal for "deliberately ignored". The other 3 are `Carbon::parse($punch)`
fallbacks in `AttendanceDailyRepository` that revert to the shift's expected time.

*Five more sites fixed this pass*, each one a case where the next line acted on the strength of the
swallowed call:
- `ProcessFinalSettlementAction` — unreturned-asset recovery. On failure `$assetRecovery` stayed 0,
  so a final settlement paid the employee **without recovering company assets**, while the
  breakdown it wrote reported `asset_recovery.total = 0` as though that were the truth. Logged, not
  rethrown: aborting a final settlement because an HRM table could not be read would be worse.
- `ProcessFinalSettlementAction` — encashable days. Audit-only and cannot change what is paid, but
  a settlement record quietly claiming 0 days misleads whoever reads it later. Logged.
- `MovementService` — mirroring an approved movement into payroll. The movement stays approved, but
  payroll would never learn about the salary change. Logged.
- `AttendanceDailyRepository` — the late and early-out decisions. A swallowed parse failure left
  `$isLate` / `$isEarly` **false**, so an unparseable punch read as "on time" and no deduction
  followed: failing in the employee's favour, silently, in a system that deducts pay. Both now log
  with the daily id and the raw punch values. These are the two sites in that file that were *not*
  tolerated; the three that were are pure time-format fallbacks with no deduction behind them.

*Tests:* `tests/Feature/SilentFailureInventoryTest.php` — 3 tests. The count is now **asserted, not
reported**: planting an empty catch in a file that is not on the allowlist fails with the file, the
count and the three ways to resolve it; changing an allowlist entry without changing the code fails
as drift, so the list cannot quietly diverge from reality. A third test asserts the replacement text
is present in all eight previously-swallowing money paths, so reverting any one of them is caught
here as well as in its own contract test. Both directions were sensitivity-checked by planting a
catch in `QuotationService` and by making one allowlist entry stale.

*Worth keeping:* the scanner skips comment tokens, which is why `PosService`'s explanatory comment
quoting the old `catch (\Throwable $e) {}` is not counted. A grep cannot make that distinction — an
earlier check in `verify_artifacts.sh` had to be written around exactly this.

**19. A missing account meant "post nothing and carry on" — FIXED.**

P0-B put the chart of accounts back. This closes the code path that let the ledger be skipped in the
first place, so that a chart which diverges from `accounts.defaults` — a renamed COGS account, a
deployment that never ran the demo seeder, an account deactivated by an administrator — refuses the
posting instead of quietly writing nothing.

All 24 call sites were the same three lines, in seven services:

```php
$apId = $posting->accountIdByCode($apCode);      // declared : ?int — a miss is a legal answer
if ($apId) { /* build the lines, post the journal */ }
$bill->update(['status' => 'posted', 'journal_id' => $journalId]);   // ← outside the guard
```

Because the status update sat *outside* the `if`, a null meant "write no entries, mark it done
anyway". Four distinct consequences, in rising order of how hard they are to notice:

1. **The whole journal is skipped.** `VendorBillService::post()`, `InvoiceService::post()`,
   `PurchaseReturnService::post()`, `SalesReturnService::approve()`, `KitService::complete()`,
   `LandedCostService::allocate()` — document marked posted, `journal_id` null, no entries.
2. **Money moves without cash moving.** Both `recordPayment()` methods gated the journal on
   `if ($cashId && $apId)` while the `paid_amount` increment and the `payment_status = 'paid'` update
   sat below, ungated. A bill could be reported as settled for cash the ledger had never received.
   For a purchase return it was worse: the stock had *already left the warehouse* above the guard.
3. **A leg is dropped from a journal that still balances.** `InvoiceService` gated COGS on
   `if ($totalCost > 0 && $cogsId && $invId)`. With no `5001` in the chart, AR/Sales/VAT balance
   perfectly, so `JournalService::assertBalanced()` passes, the journal posts, and the cost of the
   sale is never recognised. **No downstream check can object to this one** — profit is overstated
   and every validation is satisfied. `SalesReturnService` had the same shape on the reversal.
4. **A fallback that silently reclassifies.** `LandedCostService` resolved each cost line with
   `$cl->account_id ?? $posting->accountIdByCode('6000') ?? $invId`. `6000` is not in the demo chart,
   so the fallback always fired: the journal credited Inventory for the cost it had just debited,
   netted to zero, capitalised nothing — while `$stock->total_value` had been incremented in the same
   transaction. The stock subledger and the general ledger were left in permanent disagreement.

Two related shapes were found while closing these:

- `VendorBillService::post()` gated the GRN branch on `if ($hasGrn && $grniId)` and fell through to
  the no-receipt branch on a miss, where `$useInventory = $hasGrn && $invId` was still true — so it
  **debited Inventory a second time** for stock the goods receipt had already booked.
- `GoodsReceiptService::post()` wrapped the entire GRNI accrual in `catch (\Throwable) { Log::warning(…) }`.
  A missing account *or a closed fiscal year* left the receipt marked posted with no accrual, which
  then triggered the double-count above on the bill raised against it. The accrual is now resolved
  before the `try`, and the `try` covers only `postJournal` and rethrows — matching `void()`, which
  P1.14 already fixed in the opposite direction.

**Fix.** `modules/ACCOUNTS/Support/ChartOfAccounts.php`, a final class with two static methods:
`requireId($posting, $code, $purpose)` and `firstExistingId($posting, $purpose, ...$codes)` for the
one leg where either of two accounts is acceptable (a purchase with no receipt is expensed, or
capitalised into Inventory when the chart has no purchase account). Both throw a `DomainException`
naming the code and the document. All seven services post inside `DB::transaction()`, so the throw
rolls the document back to draft, and the controllers already catch `DomainException` and flash it —
the operator is told which account to create, and nothing is half-written.

It is a static choke point rather than a new interface method deliberately. Adding
`requireAccountId()` to `AccountsPostingProviderInterface` would make every test double answer it,
and a mock returning null from a `: int` method fails for the wrong reason; a static call cannot be
mocked away, which is the point of a guard.

After the change **no document service in the codebase calls the nullable lookup at all** — it stays
on the provider for genuinely optional callers. `ChartOfAccountsGuardTest` asserts that as a source
contract (comments stripped with `token_get_all`, so an explanatory comment can neither satisfy nor
break it), with a per-file minimum on guarded resolutions so a service cannot be reverted to one.

**Why it survived.** Only `PurchaseAndSalesE2ETest` seeds a chart of accounts. Every other test that
posts a document was exercising the null branch, so the suite was green *because* of the defect. The
guard test therefore clears `acc_accounts` and builds an explicit chart — `forceDelete()`, because
`Account` soft-deletes and a soft-deleted `2115` still collides with the UNIQUE index on `code`. That
migration-provisioned `2115` is also why a stock install has a one-account chart (P0-B).

Ten tests, 80 assertions: **8 failed against the unchanged code**; the 2 that passed are controls —
a complete chart still posts a balanced journal, and a zero-cost invoice still posts when no COGS
account exists, so the guard demonstrably does not over-fire on the legitimate case. Neutering
`requireId` to return any account instead of throwing fails exactly those 8 and no others.

**20. Two chart-of-accounts modelling defects — FIXED (item 44).**

Both surfaced while writing P1.19, and both were recorded rather than fixed because each looked like a
decision about somebody's chart rather than a repair. Item 44 closes them by separating the two things
that were tangled together: what the code does wrong, and what the chart is allowed to say. Detail,
measurements and the sensitivity probes are in §3 under item 44.

- **`KitService::complete()` posted a journal that debited and credited the same account — fixed by
  not posting it.** "Dr Inventory Kit / Cr Inventory Components" resolved both legs to
  `accounts.defaults.inventory_code`, so the entry balanced (which is why nothing complained), netted
  to zero, and recorded no value transfer — there was none to record, both stocks sitting in one
  account. What it did do was gross up both sides of the inventory ledger by the component cost on
  every build. No WIP account was invented: `accounts.defaults.kit_output_code` ships **empty**, and
  `complete()` posts nothing when it is empty, equal to the inventory code, or an empty string from an
  env key set to nothing — and posts the real Dr kit output / Cr inventory transfer the moment a chart
  names one. Skipping loses no linkage: `inventory_kits` has no `journal_id` column, and
  `inventory_stock_moves` already carries both legs against the kit number under `reference_type`
  (`kit_component` out, `kit_production` in). A configured code the chart does not have refuses the
  build and rolls it back whole, the components being already consumed by then.
- **`5001` (COGS) and `6000` (landed cost) were hardcoded literals — fixed by three config lookups.**
  `InvoiceService` and `SalesReturnService` now read `accounts.defaults.cogs_code`, and
  `LandedCostService` reads a new `accounts.defaults.landed_cost_code`. One correction to this entry
  as originally written: it claimed `accounts.defaults` had *neither* key, but item 39 added
  `cogs_code` for the POS day-end posting — so the two invoice-path services were writing a literal
  beside a config key that already existed and already meant the same thing. `landed_cost_code` is
  genuinely new, added because the literal it replaces carried a comment saying no key existed. The
  defaults are the same numbers the literals were, so no chart and no posted amount changes; what
  changes is that a deployment numbering its freight or cost of sales differently can configure its
  way out instead of editing PHP. Worth naming alongside it: the seeded chart has **no account 6000**,
  so a landed-cost allocation whose lines carried no `account_id` used to throw a `DomainException` on
  an otherwise complete chart — and still throws if the key points nowhere, which is the correct
  behaviour and the one P1.19 established.

  Pinned by `KitConversionPostingTest` (**9 tests, 62 assertions**), whose seventh test scans every
  service for the literal *form* rather than for these three files, because a scanner that only knows
  the sites already found cannot catch the fourth one — and whose eighth test proves that scanner both
  ways, since a census reporting zero is indistinguishable from one whose regex has gone blind.

Restated from P0-B's residual debt, because it is the root of both this item and P1.19:
`2026_11_03_000075_add_grni_to_receipts.php` writes seed data from inside `up()`, and crosses a
module boundary doing it — a PURCHASE migration creating an ACCOUNTS row. A migration that seeds will
keep defeating guards like `AccountsDemoSeeder`'s.

**21. Two search endpoints treated LIKE wildcards as literals — FIXED.**

`BankRepository::options()` and `SALES/OptionsController` interpolated the search term straight into
the pattern (`"%{$search}%"`), while `ProductRepository`, `WarehouseRepository`, `PartnerRepository`,
`PriceListRepository`, `AccountRepository`, the PURCHASE controller and HRM's `assets` and
`checklist-templates` arms all escaped with `addcslashes($term, '%_\\')`. The two that did not were
inconsistent with their own neighbours rather than broken in isolation, and the bank one is the worse
of the two: account numbers are digits, so a search for a single `_` matched any character and
returned the entire bank book. Both now escape, and `OptionEndpointAuthorisationTest` pins it — `%`
and `_` return zero rows while a real account-number prefix returns exactly one.

Also observed while reading these routes, and deliberately **not changed**:
`modules/ACCOUNTS/Routes/api.php` and `modules/SALES/Routes/api.php` each register a second
`…/api/options/{type}` route with the same `['web', 'auth']` middleware and the same controller as
their own module's `web.php`. Two definitions share one URI, so which answers depends on registration
order. It is harmless today — and it is one more reason the gate belongs in the controller rather than
on the route — but the duplicates should be deleted rather than left to be discovered later.

**22. The credentials this repository publishes are refused in production — FIXED.**

Until this item, the only thing between a public source repository and an administrative session on a
live deployment was an operator remembering to copy `.env.production.example`. Four places publish the
same secret: `config('core.super_admin')` defaults to `admin@example.com` / `password`; `.env.example`
sets `CORE_SUPER_ADMIN_PASSWORD=password`; `SuperAdminSeeder` writes that pair and grants it `*`, which
`enable_wildcard_permission` makes omnipotent; and `CoreSeeder` wrote `Hash::make('password')` for three
named demo users holding Manager, Auditor and Staff, then topped the table up to 200 accounts through
`User::factory()` — whose default is that string again. `CoreSeeder` tops the user table up *to* 200
rows rather than *by* 200, so a default production install had exactly **200 seeded accounts that all
answered to `password`**: the super admin, the three named demo users, and 196 from the factory. The
super admin among them could reach every module.

`Modules\CORE\Support\PublishedCredentialGuard` closes both ends:

| | Behaviour under `APP_ENV=production` |
|---|---|
| **Writing** | `SuperAdminSeeder` throws before creating anything unless `CORE_SUPER_ADMIN_PASSWORD` is a real secret. `CoreSeeder` gives its demo users and its 200-account factory loop `Str::password(24)` — unknowable, so the rows still exist for anything that references them, but nobody can sign in until an administrator resets one. |
| **Using** | `LoginRequest::authenticate()` verifies the credentials first and *then* refuses a published password: logs the session out, writes `Log::critical` with the email and the remedy (never the password), and flashes an actionable message. |

Three decisions worth recording, because each is the kind of thing that gets "fixed" back later:

- **The refusal message is deliberately not `auth.failed`.** It names `APP_ENV=production` and
  `CORE_SUPER_ADMIN_PASSWORD`. That is not an information leak — the credential is in the repository, so
  there is no secret to protect — whereas an operator locked out by a guard they cannot distinguish from
  a mistyped password will go and debug the password reset.
- **The guard blocks on the password, not the email.** Keeping `admin@example.com` with a real password
  still works; an unrelated account that happens to hold `password` does not. Both directions are tested.
- **The environment is read from `config('app.env')`, not `app()->environment()`.** Same value in a
  deployment, but the container's `env` binding is what `Application::runningUnitTests()` inspects, and
  `VerifyCsrfToken` uses that to exempt test requests — rebinding it to exercise this guard makes every
  login post in the suite die with a 419 and no flashed errors. That cost a full round of red tests to
  find, and it is written down here so it is not repeated.

Escape hatch: `CORE_BLOCK_PUBLISHED_CREDENTIALS=false`, documented in both env examples as discouraged
and covered by a test, so it cannot rot into a no-op.

**`PublishedCredentialGuardTest` — 14 tests, 250 assertions:** the refusal itself; the message content;
the critical log carrying the email but not the password; a real password on the published email; the
published pair under `local`, `testing` and `staging`; the escape hatch; a non-admin account holding the
published password; both seeder refusals; production `CoreSeeder` asserting that **every** one of the
≥200 seeded accounts rejects `password`; the same seeding outside production keeping the documented
developer credentials; and a source scan that fails if any seeder writes the literal again.

**Sensitivity proven, in two disjoint halves:** `shouldRefuse()` → `return false` fails exactly the four
login tests and none of the other ten; `seedersMayWritePublishedPassword()` → `return true` fails
exactly the two seeder tests and none of the other twelve.

Left alone deliberately: `database/factories/UserFactory.php` still defaults to the published password
(a test and local-development convenience — no seeder may write it any more, which the scan test
enforces), and `RegisteredUserController::store()` still calls `Auth::login($user)` with a
submitter-chosen password, unreachable while `CORE_ALLOW_REGISTRATION=false` (P0-H).

**23. The ACCOUNTS posting safety net — ADDED, and the five defects it found are FIXED.**

§7 item 2 put "add the missing domain tests" ahead of the float→decimal migration, because money types
cannot be changed safely while nothing pins what the money is supposed to do. `tests/Feature/ACCOUNTS/
JournalPostingDomainTest.php` is that net for ACCOUNTS: **25 tests / 94 assertions**, driving
`JournalService` directly rather than through HTTP, because the service is the boundary other modules
post through (`AccountsPostingProvider`) and `JournalController` adds its own recomputation on top
which would mask exactly the gaps being probed.

Written before any change and run against the code as it stood, **five tests failed** — five live
defects, none of them previously reported:

| # | Defect | Effect | Fix |
|---|---|---|---|
| 1 | Three separate definitions of "balanced": `assertBalanced()` and `StoreJournalRequest` both tolerated a difference of **0.01**, while `post()` gates on `Journal::isBalanced()`, an **exact** `bccomp` at two decimal places. | A journal whose sides differ by less than a cent but round to different cents is accepted as a draft and then **refused forever at posting time** — "Journal not balanced" on a journal the system had already declared balanced. Sub-cent line amounts are not a typing mistake: they are what an unrounded unit price times a quantity, a VAT split or a discount allocation produces. | New `modules/ACCOUNTS/Support/JournalBalance.php` — one rule, **compare the two sides after rounding to cents**. Rejects anything that would store as a different number of cents; still accepts binary-float noise (`33.33 + 33.33 + 33.34`), which the old tolerance also accepted, so no legitimate journal starts failing. Service and request both delegate to it. |
| 2 | `JournalService::update()` replaced a draft's lines without recomputing `total_debit`/`total_credit`, and `JournalRepository::update()` does not either. | A draft whose header said 100.00 while its lines summed to 300.00. `JournalController@update` recomputes inline, so the web form hid it; **every other caller** — including the posting provider other modules use — got the stale header. | `create()` and `update()` now derive both header totals from the lines through `JournalBalance::totals()`, so the invariant lives at the domain boundary instead of being each caller's problem. |
| 3 | `post()` validated the **stored header** and then wrote ledger rows from the **lines**. | A header that balances over lines that do not posts successfully and moves every account it touches by an amount the journal never claimed. | `post()` now calls `JournalBalance::assertBalanced($journal->lines)` inside the transaction, after loading them, so a refusal rolls back cleanly and leaves the draft untouched. |
| 4 | `void()` never consulted the fiscal calendar — the only writing path in the service that did not. | A void writes a brand-new **posted** journal dated `now()`, so closing a year did not stop reversal entries landing in it: a closed year could be quietly reopened by voiding. | `void()` calls `assertFiscalOpen(now())` before opening its transaction. |
| 5 | `assertFiscalOpen()`, `resolveFiscalYear()` and `resolvePeriod()` compared a `Y-m-d` string against `date`-cast columns with `where()`. | **P1.5, reproduced rather than theorised:** on SQLite a journal dated exactly on the fiscal year's *first* day was refused with "No fiscal year covers 2026-07-01", because the column holds `2026-07-01 00:00:00` and that string is greater than `2026-07-01`. The last day passed, so the failure was one-sided and easy to miss. | All four lookups use `whereDate()`, which compiles to a driver-appropriate date comparison. `assertFiscalOpen()` is the only implementation in the codebase, so this closes the fiscal gate everywhere. |

Beyond the five, the file pins behaviour that was correct but untested and is now hard to break by
accident: the running balance continues across two postings against the same account (the invariant
`Account::lockForUpdate()` exists to protect); voiding unwinds the ledger to exactly zero and writes
its reversal as a separate posted journal with the sides swapped; only a posted journal can be voided;
a group header and an inactive account are both refused; a closed year, a locked year and a closed
period each block posting; a date no year covers is refused; `accounts.posting.require_fiscal_open =
false` bypasses the gate; the journal number is generated per type and increments; and the AP/AR side
effects open a payable for `BV` + vendor and a receivable for `RV` + customer, with a 30-day due date,
while a journal voucher with no partner and a bill voucher naming a *customer* open neither.

**Sensitivity proven, one fix at a time.** Reverting each fix in isolation fails exactly the tests that
pin it and nothing else: the old 0.01 tolerance → 1 failure; totals no longer derived from lines → 1;
`post()` no longer validating the lines → 1; `void()` losing its fiscal gate → 1; `whereDate` back to
`where` → 1. Restored, 25/94 again.

**Full-suite check:** 413 passed / 0 failed after these changes, so tightening the balance rule and
adding the `post()` and `void()` gates broke none of the seven document services that post through
`AccountsPostingProvider`.

**24. The INVENTORY stock safety net — ADDED.**

`tests/Feature/INVENTORY/StockMovementDomainTest.php`: **17 tests / 85 assertions, plus one skipped
test that is a written specification of item 25** — the suite's only skip, and a written specification
in the literal sense: it stated the behaviour P1.25 should have had and was switched off until somebody
built it. Item 41 did, removed the skip and inverted the sibling characterisation test, so the file now
runs **18**. `StockService::move()` is the only writer of
`inventory_stocks` and maintains two records of the same fact in one transaction — an append-only
`StockMove` ledger and a denormalised `Stock` cache row. The cache is rewritten from itself
(`qty_on_hand + delta`) rather than recomputed from the ledger, so a single wrong write is permanent
and every valuation report downstream inherits it. That is why this file's centre of gravity is the
reconciliation: after any sequence of movements, `sum(in) − sum(out)` over the ledger must equal the
cache's `qty_on_hand`, and the ledger must still have one row per movement.

Also pinned: weighted-average cost moves on a receipt and deliberately does **not** move on an issue;
a receipt at a different price re-weights (`10 @ 5.00` then `10 @ 7.00` → `avg 6.00`, `value 120.00`);
an issue between two receipts re-weights correctly (`4 @ 5.00` plus `6 @ 8.00` → `avg 6.80`); FIFO
consumes the oldest layer first and costs the issue out of the layers it consumed (`10 @ 5.00` and
`10 @ 8.00`, issue 12 → `total_cost 66.00`, `unit_cost 5.50`, oldest layer at zero); a FIFO issue
larger than the remaining layers is refused; overselling is refused **and leaves no trace**, which
matters because `move()` creates the `StockMove` row *before* it discovers the shortfall, so the
transaction rollback is the only thing keeping a refused sale out of the ledger; both negative-stock
escape hatches work (the warehouse flag and the config switch); reservations move availability without
moving quantity on hand, refuse to exceed it, and cannot be driven negative by an over-release; and
the valuation snapshot agrees with the cache rows, using layer sums rather than the cache average for
FIFO.

One subtlety worth recording because it is invisible until it breaks: the cache lookup keys on
`variant_id`, `location_id` and `lot_no`, which are normally `null`. Laravel's array form of `where()`
routes a `null` value to `whereNull`, so the second movement finds the first movement's row. Had that
not been true, every movement would have created a *new* cache row instead of updating the existing
one. The reconciliation test asserts `Stock::count() === 1` after four movements specifically to hold
that behaviour.

**Sensitivity proven, three probes:** the weighted-average assignment removed → 3 failures; the
oversell guard removed → 1 failure (the "never held the product" case has its *own* guard in the
create branch, so the two tests each cover one — worth knowing before either is refactored); FIFO
ordering reversed to newest-first → 2 failures. Restored, 17/85 plus the skip.

**Stated limitation, not a hidden one:** `move()`, `reserve()` and `release()` all take
`lockForUpdate()`, and SQLite silently ignores it, so parallel movement cannot be exercised on the
test database. What is asserted is the invariant the lock protects — that movements against one row
accumulate rather than overwrite — which is what the lock produces on MySQL, where the CI matrix runs.
Claiming concurrency coverage would be claiming something the suite cannot observe.

**25. The stock cache was keyed by lot on the way in and looked up lot-less on the way out — FIXED, item 41.**

Found by the safety net in item 24 and deliberately left alone, because the correct fix is a decision
about the data model rather than a patch.

`StockService::move()` includes `lot_no` in the cache key for both directions. But the paths are not
symmetric:

- `ReceiveStockAction` **does** forward `lotNo: $item['lot_no'] ?? null` — and documents `lot_no` in
  its `@param` list;
- `IssueStockAction`, `InventoryStockProvider::deductStock()` and `::restock()` construct their
  `StockMoveData` **without** a `lotNo` argument at all, so it defaults to `null`.

Receive 10 units against `LOT-RECEIVED`, then sell 4: the cache row is keyed
`(product, warehouse, lot_no = 'LOT-RECEIVED')`, the sale looks for
`(product, warehouse, lot_no IS NULL)`, finds nothing, and throws `Insufficient stock … On-hand: 0`.
**Quantity received against a lot is unsellable.** This is measured, not inferred — the skipped test in
item 24's file was run with the skip removed and produced exactly that exception.

Two things keep it out of the P0 list, and both are stated so they can be re-checked rather than
trusted:

- **It is not reachable through the application today.** `ReceiveStockAction` has exactly one caller,
  `InventoryDemoSeeder:65`, and its opening-stock batches carry no `lot_no`. `GoodsReceiptService`
  receives through `InventoryStockProvider`, which never forwards one either.
- **The trap is armed for whoever adds lot tracking next.** The columns, the `Lot` and `FifoLayer`
  models, `StoreDeliveryRequest`'s `items.*.lot_no` rule and the lot input on `Deliveries/Create.vue`
  all exist, so the natural next step — forwarding the lot the user already typed — walks straight
  into it.

That natural next step is itself a second, smaller defect: `DeliveryService:39` and
`SalesReturnService:60` both put `lot_no` into the item arrays they hand to the issue and restock
paths, and both paths drop it. So a lot number typed on a delivery is validated, stored on the
delivery item, and then silently disappears before it reaches the stock ledger. **These two must be
fixed together:** forwarding the lot without also fixing the cache key converts a lost lot number into
a refused sale. `StockMovementDomainTest` pinned the drop as a *characterisation* test — it asserted
the behaviour of the day and said so in the comment — so that whoever fixed it would see the coupling
instead of discovering it in production. That is what happened: item 41 inverted the assertion, renamed
the test, and kept the comment explaining why flipping it was safe.

Options were written down before any of them was built, and none was free: drop `lot_no` from the cache
key and treat `inventory_stocks` as an aggregate per product/variant/warehouse/location, with lot
identity living in `FifoLayer` and `Lot` where it already is (cleanest, but strands any lot-keyed row a
deployment already has); or make a lot-less issue consume lot-keyed rows oldest-first, which is a
lot-consumption engine and a real piece of work; or require an issue to name a lot for lot-tracked
products and refuse clearly when it does not, which is honest but pushes the work onto every caller.

**Fixed at item 41, taking the first option and paying the stranding cost it named.** The deciding
evidence was that the aggregate is not a new idea imposed on the module: `reserve()`, `release()`,
`ValuationService`, `PosLookupController`, `InventoryStockProvider::available()`, `StockRepository` and
`LandedCostService` all already read the cache without a lot, so `move()` was one writer disagreeing
with seven readers. The stranding is answered by a merge migration rather than by hoping no deployment
has such rows — pure DML, re-runnable, allowlisted by name in `MigrationDataSeparationTest`, and tested
by inserting rows of the old shape and calling `up()` twice. The second half of the finding is fixed
with it: `IssueStockAction`, `deductStock()` and `restock()` forward `lot_no` and `serial_no`, a lot
named on an issue is consumed ahead of older layers and costs the movement at the layer that actually
shipped, and a lot that holds nothing is refused outright — even in a warehouse that allows negative
stock, because that setting permits overselling a balance, not writing a lot number into the ledger that
no receipt created. Measured: 16 tests in `StockLotCacheTest` (10 of which failed pre-fix with
`On-hand: 0` against stock the ledger said was there), the suite's **only skipped test now runs**, and
the inverted characterisation test asserts the lot arrives. Three defects found in the same method while
reading it are published below as findings **47**, **48** and **49**, each pinned by a characterisation
test rather than a skip. Full detail in §3, "Stock lots and the cache key (new) — item 41".

**26. Every money column is now declared decimal at the model layer — P1.2 phase 1, with two corrections to what this report previously claimed.**

`tests/Feature/CORE/MoneyColumnCastTest.php` (7 tests / 263 assertions) holds the contract: a column
that stores money must be handed back as a decimal string, never a float. It was written first, run,
and failed against **44 columns across 16 models**. 43 are now cast; the 44th was a flaw in the scan
rather than in the code, and is described below because it changed the number twice.

**What the failure looked like.** Two money fields of one document came back in two different shapes:

```php
$invoice->subtotal      // float(1234.5)
$invoice->total_amount  // string(7) "1234.50"
```

Both are `decimal(15,2)` in MySQL. Both travel in the same Inertia payload to the same Vue page. One is
a float the front end must re-round; the other is exact decimal text. Which of the two a caller got
depended purely on whether whoever wrote the model remembered the cast — `Invoice` had four of its eight
money columns cast and four not. A page that formats `total_amount` and adds `subtotal` was doing
`"1234.50" + 1234.5` in two different type systems.

**The fix: 43 `decimal:N` casts across 16 models**, each at the scale its own migration declares —
42 columns at `decimal:2`, and `inventory_lots.unit_cost` at `decimal:4`, because INVENTORY declares
unit costs `(15,4)` consistently across stock moves, transfers, adjustments and FIFO layers and `Lot`
already cast its two quantity columns at that scale.

| Model | columns newly cast |
| --- | --- |
| `SALES/PosSale` | subtotal, discount_amount, vat_amount, change_amount, cash_amount, bank_amount |
| `SALES/InvoiceItem`, `SALES/OrderItem` | unit_price, discount_pct, discount_amount, vat_rate, vat_amount |
| `SALES/Invoice` | subtotal, discount_amount, vat_amount, balance |
| `SALES/Order` | subtotal, discount_amount, vat_amount, invoiced_amount |
| `SALES/QuotationItem` | discount_pct, discount_amount, vat_rate, vat_amount |
| `SALES/PosSaleItem` | unit_price, discount_pct, cost_price |
| `SALES/Quotation` | discount_amount, vat_amount |
| `PAYROLL/PayrollSetting` | missing_punch_penalty_amount, unpaid_leave_day_divisor |
| `PAYROLL/PayrollSettlement` | asset_recovery_total, encashable_days |
| `PAYROLL/PayrollLoan` | interest_rate |
| `ACCOUNTS/Payable`, `ACCOUNTS/Receivable` | balance |
| `HRM/Employee/EmployeeSalary` | net_salary |
| `HRM/Employee/Employee` | joining_salary |
| `INVENTORY/Lot` | unit_cost |

SALES alone accounted for 33 of the 43 — the module that touches money most often was the one that had
relied most on the database to make it exact.

**Front-end risk, checked before the casts went in.** A `decimal:N` attribute is now a string, and in
JavaScript `"1234.50" + 10` concatenates. All 162 JS references to the 43 column names were read: every
arithmetic site already wraps in `Number(...)` or in `Math.abs(...)`, and the only bare identifiers
(`InvoiceForm.vue:120`, `VendorBillForm.vue:121`, `SalesOrderForm`, `QuotationForm`, the six PURCHASE
document forms) are local `computed()` reductions over form state, not model attributes.
`Products/Index.vue:14` already typed `cost_price: number|string`, which is the shape a cast column has
always produced. Nothing on the front end needed changing. On the PHP side, `castAttribute()` returns
null untouched for primitive cast types, so a nullable money column is still `null` and not `"0.00"` —
verified in tinker — and no strict `===` comparison and no `sortBy()` over any of the 43 attributes
exists in `modules/` or `app/`.

**Correction 1 — the atomicity claim in P1.2 was wrong, and so was the "safer" part.** P1.2 said
`PosRepository:59` built "string-concatenated SQL where `increment()` is both safer and atomic". It was
already atomic: the `DB::raw()` sat inside a single `->update()`, which is one UPDATE statement, and the
`(float)` cast made injection impossible. Reading `Query\Builder::incrementEach()` shows `increment()`
does not bind the amount either — it interpolates it into `DB::raw("col + $amount")` after an
`is_numeric()` check. So the replacement is not safer by construction.

What it does buy is exactness — and that turned out to be a **reproducible defect, not a theoretical
one**. `total_amount` is `decimal(15,2)`, which holds thirteen integer digits. At PHP's default
`precision=14`, the value `php.ini-production` ships, the float round-trip cannot carry that many
significant digits:

```php
(string) (float) '1234567890123.45';   // '1234567890123.4'  — five cents dropped
(string) (float) '9999999999999.99';   // '10000000000000'    — 99 cents dropped, and the integer
                                       // part no longer fits the column it came from
```

Both are values the column legally holds. `PosSessionAggregateTest` drives the first one through the
repository and captures the statement that was actually issued, with the old code in place:

```sql
update "pos_sessions" set "total_sales" = total_sales + 1234567890123.4, ...
```

The till's running total loses five cents, silently, with no exception and no log line anywhere. With
the fix, the same sale issues `total_sales + 1234567890123.45`: one statement, `updated_at` still
touched, and a non-numeric amount refused outright by `increment()`'s own guard.

The trigger range matters for honesty's sake. Below roughly 10^12 the float rendering and the decimal
text agree, so an ordinary till would never have hit this in a decade of trading. It is a real defect
with a narrow trigger — not the systemic money bug P1.2's wording implied, and not the safety or
atomicity problem it named. That is why the claim is corrected here rather than quietly edited at
line 357.

**Correction 2 — the `decimal:N` cast formats on GET only, so the ledger arithmetic has no
demonstrable bug, and P1.2's headline is overstated.** `asDecimal()` runs `BigDecimal::of((string)
$value)->toScale($n, HALF_UP)` when an attribute is *read*. `getCastType()` has no decimal branch, and
`setAttribute()` never re-formats on the way in. The consequence is uncomfortable and worth stating
plainly: a float computed in PHP is stored to the database as a float, and the cast only makes it *look*
exact on the way back out. A raw SQL aggregate over that column sees what was written, not what the
model reports.

It also means the reverse of what P1.2 implied for the ledger. `JournalService::balanceDelta()`,
`currentBalance()`, `LedgerService` and the `JournalLine`/`JournalLineData` DTOs still compute in float
(32 `(float)` sites remain in ACCOUNTS), but every column they read already came back as a decimal
string and every column they write is decimal, so **no cent-level error in the stored ledger has been
demonstrated** — only the possibility of one, in the intermediate float arithmetic. Converting that
arithmetic to `BigDecimal` is therefore hygiene and future-proofing, not a defect repair, and it is
deliberately deferred to a later phase rather than done under the banner of a bug that this audit could
not reproduce. Item 23's `JournalBalance` already compares after `round(...,2)` with an epsilon, which
is the part that mattered.

**The 44th column, and why the number moved twice.** Three methodology bugs were found in the audit
itself, all now fixed in the test:

- a flat `glob('modules/*/Models/*.php')` finds 136 models; the real figure is **155**, because HRM
  nests models in `Models/Employee/`, `Models/Leave/` and `Models/Attendance/`. The scan is now a
  `RecursiveIteratorIterator` and the test asserts the exact count, so a future reorganisation that
  hides models cannot silently shrink the audit to nothing;
- `PRAGMA table_info` **hides stored generated columns** (`hidden=3`). `acc_payables.balance`,
  `acc_receivables.balance` and `sales_invoices.balance` are `decimal(15,2)->storedAs(...)` and were
  invisible to a standalone script built on the pragma. `Schema::getColumnListing()` reveals them, and
  the test uses it — this is why the test says 44 and the script said 41;
- **HRM keeps five one-line alias models** (`Modules\HRM\Models\RosterOvertime extends
  Models\Roster\RosterOvertime {}`, plus `InformedRequest`, `LeaveRequest`, `LeaveType`,
  `RosterShiftSwap`). An alias declares no casts of its own, and letting it overwrite the real model in
  the table→model map reported `roster_overtimes.hours` as uncasted when the model that owns the table
  casts it `decimal:2`. The scan now concatenates every `$casts` block for a table, so an alias
  contributes nothing and the owning model decides. That is the 44th column, and it was a false positive.

**Reported, not fixed: `Payable::balance()` duplicates the generated column in float.** `acc_payables`
has a stored generated column `balance` — `numeric as (amount - paid_amount) stored` — which the cast
now returns as a decimal string. `Payable` also declares a `balance()` *method* that computes
`(float) $this->amount - (float) $this->paid_amount`. `$payable->balance` returns the column; the method
only runs when called explicitly, and the two can disagree by a cent. Removing the method is a
one-line change but needs a caller audit first, so it is recorded here instead of being done inside a
phase that was scoped to casts.

**The test guards its own guard.** The raw-SQL check strips comments with `token_get_all()` before
matching, so the comment explaining why the pattern is banned can never be the thing that trips it —
that trap fired once during this fix. A seventh test then feeds the stripper a sample containing the
pattern in both prose and code and asserts it still sees the code, so a stripper that stripped too much
could not pass forever.

**The branch had never been executed by any test.** Writing the behavioural test for it exposed a
second finding. Nothing in the suite reached `if ($sale->session_id)` at all: `PosTerminalTest` only
renders the terminal's picker data, and `PosVoidTest` builds its rows with `PosSale::create()`, so
`session_id` was always null and the guard always fell through. The line changed under this item had
therefore never been run — the static scanner in `MoneyColumnCastTest` proves the *pattern* is gone,
not that the aggregate still works.

`tests/Feature/SALES/PosSessionAggregateTest.php` (4 tests / 11 assertions) closes that: two sales fold
into a session total of exactly `1234.63`; a total at the top of the column's range reaches the SQL as
exact decimal text; a sale naming no session still sells and issues no aggregate statement; and
`updated_at` is still stamped, which pins `Eloquent\Builder::increment()`'s behaviour rather than
assuming it — the base query builder would not stamp the row.

The branch is live production code, not a leftover: `Pos/Terminal.vue:177` posts `session_id`,
`PosSaleRequest:20` validates it against `pos_sessions`, `PosController:93` puts it into `PosSaleData`
and `PosService::sell():57` forwards it to the repository.

**Sensitivity, all four proved.** Dropping `Invoice.subtotal`'s cast fails the document-shape test and
names `sales_invoices.subtotal`; restoring the original float-into-`DB::raw` block fails the repository
scanner *and* the aggregate test, which prints the five-cent loss quoted above; making `mccCodeOnly()`
return its input unchanged fails the scanner-guard test. Each file was restored byte-identical
(`diff -q` against the backup).

**One defect in the verification script itself.** `verify_artifacts.sh` ends with `exit $MISSING`, and
the 27 checks written for this item were first appended *after* that line, where they were dead code:
the script reported exactly the tally it had reported before, which is what gave it away. The summary is
now the last statement in the file and the block runs — **375 OK / 0 GONE**, up from 348. Two of the new
checks then failed for reasons worth recording, both in the checks rather than in the code: a
`grep -qF '->increment('` pattern whose leading dash grep parsed as an option (fixed with `-e`), and a
literal expecting `toHaveCount(230)` where the test says `toHaveCount(230, 'reason')`. A verification
script that cannot fail is worse than none, so both were diagnosed rather than loosened.

**27. Deployment hygiene — the container half of P1.4: FIXED, except for one thing this audit will not guess at.**

`tests/Feature/CORE/DeploymentHygieneTest.php` (17 tests / 64 assertions) was written first and failed
12 of its first 14. Four things were wrong: three in `docker-compose.yml`, one in the environment
templates.

**1. A published database password.** `environment: { MYSQL_DATABASE: erp_pilot, MYSQL_ROOT_PASSWORD:
secret }` — a root credential committed to a public repository. That is the same exposure
`PublishedCredentialGuard` (item 22) closes for the seeded logins, in a file nobody thought of as
configuration. Both passwords are now required from the environment in the fail-closed form:

```yaml
MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:?set MYSQL_ROOT_PASSWORD in .env — docker-compose.yml refuses to start without it}
```

`:?` and not `:-`, because `${VAR:-secret}` is the published password with extra steps. Compose now
refuses to start until an operator supplies one, and the refusal names the file to edit.

**2. The database was published on every interface.** `ports: ["3306:3306"]` binds 0.0.0.0, so
together with (1) anybody who could reach the host met MySQL as root. The app container reaches `db`
by service name over the compose network and needs no published port, so the mapping is gone; the
file's header records the loopback-bound form for anyone who genuinely wants to inspect the database
from the host. The *application* port is still published on all interfaces, deliberately: a
development stack is normally reached from another machine, and a deployment facing the internet
should sit behind a TLS-terminating reverse proxy rather than behind this file.

**3. The stack started a database nothing connected to.** Found while fixing the two above.
`env_file: .env` handed the app container the SQLite settings from `.env.example`, so
`docker compose up` produced an application on `database/database.sqlite` beside an idle MySQL
container — the compose file was not a deployment of this ERP at all, it was a MySQL container with a
bystander. The app service now sets `DB_CONNECTION`, `DB_HOST=db`, `DB_DATABASE`, `DB_USERNAME` and
`DB_PASSWORD` explicitly, overriding the file, and connects as the non-root user the db service
provisions (`MYSQL_USER`, defaulting to `erp_app`) — which is what `.env.production.example` has been
telling operators to do ("never root") while the compose file provisioned nothing else. `depends_on`
gained `condition: service_healthy` with a `mysqladmin ping` healthcheck, so the first migration run
no longer races MySQL's initialisation.

**4. `APP_DEBUG=true` had no defence but a template nobody was forced to copy.** The report's earlier
recommendation was to "stop `.env.example` shipping `APP_DEBUG=true`". That is the wrong fix and this
item supersedes it: `.env.example` is the *local* template, `APP_ENV=local` sits two lines above the
value, and Laravel's own template ships the same pair. Setting it false would break the development
experience the file exists for and would not stop anybody — an operator who copies the wrong file
copies whatever it says. So the value stays and the mistake is made incapable of taking effect.

`modules/CORE/Support/ProductionDebugGuard.php`, called from `COREServiceProvider::boot()` before any
route, view or request is handled, forces `config(['app.debug' => false])` when `config('app.env')` is
`production` and debug was asked for, and writes a `Log::critical` naming both the mistake and the
file that should have been copied. `config('app.debug')` is the exact value
`Illuminate\Foundation\Exceptions\Handler` reads when deciding whether to render a stack trace, and
two tests drive that path end to end: a probe route throwing a recognisable secret renders *without*
it under a guarded production environment and *with* it under `local`. The second test exists so the
first cannot pass by breaking error pages altogether.

**Why it corrects the value instead of refusing to boot.** A hard failure would guarantee the operator
notices, and would also turn a one-line misconfiguration into an outage of payroll, invoicing and
stock. The security-relevant behaviour is that debug output cannot be rendered, and correcting the
value achieves that immediately; the log line does the telling. The guard is switchable with
`CORE_FORCE_DEBUG_OFF_IN_PRODUCTION=false`, the same convention as the credential guard, for a
deployment that wants debug in production and is willing to say so in its configuration.

`.env.example` now qualifies its own `APP_DEBUG=true` in a comment that points at
`.env.production.example` and names the guard, and documents the four variables compose requires. A
test holds the coupling: `APP_DEBUG=true` may appear only in a template that declares `APP_ENV=local`,
and that template must name the hardened one.

**Reported, not fixed: the compose file builds an image whose Dockerfile does not exist.**
`services.app` says `build: .`, and there is no `Dockerfile` — and no `.dockerignore` — anywhere in the
repository, so `docker compose up` fails before it starts anything. This is pinned as a
*characterisation* test, the pattern item 25 established: it passes today by asserting the gap, and
will fail the day somebody adds the file, telling them to delete the test rather than leaving a stale
note behind. Writing a Dockerfile here would be a guess dressed as a fix. There is no docker binary in
this audit environment, so nothing could build it, run it, or check that it serves the application.

The same limitation covers everything else in this item and is stated rather than hidden behind a
green tick: **the compose assertions parse the file with `symfony/yaml` and judge its contents. No
container was ever started.** They prove the file says what it should say, not that the stack boots.

**A weakness in the test, found by its own sensitivity sweep.** The first sweep commented out
`ProductionDebugGuard::enforce()` in the provider and all 14 tests still passed: the wiring check
matched raw source text, and a commented-out call contains the very string it was looking for. This is
the same trap that produced three false GONEs in `verify_artifacts.sh` during item 2. The check now
strips comments with `token_get_all()` before matching, a further test proves the stripper still sees
real code, and the sweep was re-run — commenting the call out and deleting it outright each fail the
wiring test.

**Sensitivity, seven probes, each restored byte-identical (`diff -q` against the backup).**
Republishing the root password; republishing `3306:3306`; commenting out the guard call; deleting the
guard call; neutering the `config(['app.debug' => false])` correction behind an environment variable;
commenting the four `MYSQL_*` keys out of `.env.example`; and replacing the guard's name in that
template's warning. Each fails exactly the test that owns it, and nothing else.

**28. Formatting normalised across the whole tree and made blocking in CI — §7 item 5, done, with the footprint now reported as two numbers.**

`vendor/bin/pint --test` passes on all **865 PHP files**. Before this item it failed on **662** of them
(the CI comment said 646 of 832; both figures were accurate when written and the tree has grown since).

**What was done, in order.**

1. `pint.json` created, naming the preset explicitly — `preset: laravel`, excluding `public`,
   `storage`, `bootstrap/cache` and `node_modules`. Without it the enforced style is whatever the
   installed Pint version defaults to, so a `composer update` could redefine what "formatted" means and
   fail the build for reasons nobody wrote.
2. The whole tree normalised in one pass, **with the working tree backed up first**: nothing in this
   repository is committed, so the 69 untracked files had no recovery path through git.
3. `.github/workflows/ci.yml`: `continue-on-error: true` deleted from the Pint step, and its comment
   rewritten to record why it had been advisory and why it no longer is.
4. `tests/Feature/CORE/CiWorkflowContractTest.php` (8 tests / 46 assertions) written **before** the
   workflow was touched, and failing on exactly the three properties the change introduces.

**Why a test reads the CI workflow.** The workflow is where most of this project's guarantees are
actually enforced, and until now nothing checked it. A check that is present but advisory, a linter
invoked with `--fix`, a build step ordered after the tests that consume its output, or an aggregate job
that waits for its dependencies and then never looks at their results — each turns a green suite into a
decorative one. All four are now pinned, along with both dependency audits and the post-seed row counts
(`migrate:fresh --seed` exits 0 even when a seeder throws mid-way, which is the failure mode behind
P0-B). Written first, the file failed 3 of 8; the other five were already true and are regression nets.

**The consequence for §6, stated plainly.** Item 5's own advice was to run pint *as a standalone
commit*, precisely because normalising rewrites most of a codebase and buries substantive work in the
diff. Nothing in this engagement is ever committed — HEAD is still `f3e014a` — so the separation a
standalone commit would have provided is reported as two figures instead:

| | files | lines |
| --- | --- | --- |
| **Substantive** — this audit's edits, items 1–28 | 284 modified + 69 new | +9,855 / −3,802 in the modified files, and 15,618 lines of new files |
| **Formatting only** — pint normalised files the audit never edited | 558 | +13,024 / −4,732 |
| **Total touched** | **911** | +22,879 / −8,534 across tracked files |

The 284 are not purely substantive: pint normalised them as well, and the two cannot be separated
*inside* a file without a diff-of-diffs that would be less trustworthy than this sentence. The 558 are
files the audit had no other reason to touch.

**What pint changed, and the one rule worth naming.** The violations were overwhelmingly layout:
`class_attributes_separation` (172 files), `braces_position` (70), `statement_indentation` (31),
`whitespace_after_comma_in_array` (26), `blank_line_after_opening_tag` (26), `single_line_after_imports`
(23). One rule moves code rather than whitespace — `fully_qualified_strict_types` (23 files) rewrites
inline `\Modules\X\Y::class` references into imports — which is why the entire suite was re-run after
the pass instead of trusting `php -l`. **No Blade file was touched**, which matters because the CSP
tests assert on the inline scripts `resources/views/app.blade.php` emits.

**Two traps in the new test, both found by running it.** `expect($x)->toHaveKey($key, $message)` does
not take a message: its second parameter is the expected *value*. So `toHaveKey('ci', 'the workflow has
no aggregate job…')` failed on a type mismatch, and the negative form
`not->toHaveKey('continue-on-error', 'still advisory')` **passed while the key was present**, because
`true !== 'still advisory'`. Both moved to `assertArrayHasKey` / `assertArrayNotHasKey`, which do take
messages. This is the third time in this engagement that a Pest expectation has silently reinterpreted
a message argument as data.

**Limitation, and it is a wide one.** No GitHub runner has ever executed this workflow. The assertions
parse the YAML with `symfony/yaml` and judge its structure; whether Actions accepts it is a different
question. The workflow was written during the CSP round and has only ever been checked by reading it —
the same class of limitation as item 27's compose file, and stated for the same reason.

**Sensitivity, five probes, each restored byte-identical (`diff -q`).** Restoring
`continue-on-error: true` fails both "code style is enforced, not advised" and "no step anywhere in the
workflow is allowed to fail"; deleting `pint.json` fails "the enforced style is the one this repository
chose"; adding `--fix` to the ESLint step fails the linter test; moving the Pest step above the
front-end build fails the ordering test, which names the consequence in its message; and replacing the
aggregate job's `exit 1` with an `echo` fails the last one. The fourth probe was written wrongly the
first time — it inserted a *second* build step instead of moving the original, and all eight tests
passed. A probe has to be checked against the thing it claims to break, or it proves nothing.

**29. Native dialogs replaced with a shared confirm/prompt store — §7 item 6, first half, done for a first batch.**

P2.1 reported 76 native calls. Re-measured with a scanner that strips comments and excludes
declarations: **77 live calls across 42 files** — 18 `alert()`, 44 `confirm()` and 15 `prompt()`. A
naive grep says 80; two of the difference are prose inside `PAYROLL/Pages/Reopens/Index.vue`, a page
already migrated whose comments describe the dialogs it used to have, and one more is a declaration
counted as a call.

**A functional defect fell out of the re-measure.** `HRM/Pages/Probations/Index.vue:16` declared
`function confirm(id)` *and* called `confirm('Confirm probation? …')` inside its own body. A function
declaration shadows the global for the whole module, so the inner call resolved to the function
itself:

```
$ node -e "function confirm(id){ if(confirm('x')) return 1 } try{confirm(7)}catch(e){console.log(e.constructor.name+': '+e.message)}"
RangeError: Maximum call stack size exceeded
```

Line 57 wired `@click="confirm(p.id)"` to it, so **the ✓ Confirm button on the probation page threw a
stack overflow and never confirmed anyone** — no dialog, no request, nothing visible to the user.
`SALES/Pages/Orders/Show.vue:8` carried the same shadowing without the recursion, because its
`confirm()` posts straight through with no guard at all.

**What was built**, following the pattern `useToast`/`ToastStack` already set — a module-scoped `ref`
store plus one host mounted in the admin shell:

- `modules/CORE/Resources/js/Composables/useConfirm.ts` — `confirmDialog(): Promise<boolean>` and
  `promptDialog(): Promise<string | null>`. The prompt keeps `window.prompt()`'s contract (`null` on
  cancel, the trimmed answer otherwise) so migrations stay mechanical. A second request while one is
  open settles the first as dismissed, and so does the host unmounting, so a navigation can never
  strand an `await`.
- `ConfirmDialog.vue` gained an optional field (`input`, `inputLabel`, `inputPlaceholder`,
  `inputType`, `inputRequired`, `initialInput`) and now emits `confirm` with the trimmed value. It is
  off by default, so the 35 pages using the dialog as a yes/no question are untouched. A required
  prompt cannot be submitted empty — the rule `window.prompt()` could not express, and the one the
  audit-trail rejection reasons depend on. The field re-arms on every open, so one row's reason cannot
  leak into the next.
- `ConfirmDialogHost.vue`, mounted beside `<ToastStack />` in `AdminLayout.vue`, renders whichever
  dialog is pending and keeps a closed one mounted 200 ms so radix's exit animation still plays.

**Migrated: 30 calls across 8 files** — HRM Overtimes, ExtraDuty, Probations and Attendance/Fix;
PAYROLL Loans, SheetFinance and ProvidentFund; SALES Orders/Show (rename only). `alert()` became
`toast.warning()` for validation and `toast.error()` for server errors; `confirm()` became
`confirmDialog()` with a real title and a named confirm button; the five `prompt()` sites became
labelled, required textareas. Two behaviour improvements came free: a too-short rejection reason now
says so instead of silently doing nothing, and ProvidentFund validates before asking instead of asking
and then rejecting its own answer.

**Tests.** `NativeDialogTest.php` (**7 tests / 19 assertions**) counts the calls and asserts a
**ratchet lowered from 77 to 47**; that the migrated pages stay migrated; that each still imports a
composable, so a falling count cannot mean a deleted guard; that no file declares `confirm`, `alert`
or `prompt`; and that the host exists and is mounted. `useConfirm.test.ts` (**9 tests**) mounts the
real host and clicks the real buttons, because the defect worth guarding is a promise that never
settles — a dead click on a destructive action. **Four probes, each restored byte-identical:**
removing the unmount settle, making `canSubmit` always true, removing the settle-the-previous guard,
and removing the field re-arm; each turned the suite red.

**Not done, and the limits of what was done.** **47 calls remain in 34 files** — 46 in 33 since item 39 migrated the POS void confirmation; the ratchet records
them and the highest-value next batch is the ACCOUNTS journal void plus the SALES and INVENTORY
document approvals. No browser was driven — the Vitest run is jsdom, so radix's real focus trap, Esc
handling and overlay dismissal are exercised only through the component's own API, and a Pest test
asserts the host's *presence* in the layout, not its rendered behaviour. `SheetFinance.vue`'s
`confirmAdvice()` posts a bank-advice confirmation with **no guard at all**; that is pre-existing and
was left alone deliberately, because adding a confirmation to a financial action is a behaviour change
that belongs to whoever owns payroll, not to a dialog migration.

**30. P2.2 retracted: the shared components have no dark-mode defect — §7 item 6, second half, corrected and pinned.**

P2.2 claimed "75 hardcoded light-only classes … highest blast radius: `DataTable.vue` (4) and
`PageHeader.vue` (3) — shared components used everywhere", and §7 item 6 ordered the work accordingly.
**That was a regex counting hits, not defects.** Read in context, the shared components contain
**eight raw neutral palette classes across three files and zero dark-mode defects**:

| Site | Classes | Why it is not a defect |
|---|---|---|
| `DataTable.vue:413-420` | `border-gray-900`, `text-gray-500`, `text-gray-600`, `text-gray-900` | Inside `<div class="mb-5 hidden print:block">`, commented "Print-only sheet header (hidden on screen)". Printed paper is white in both themes, so tokenising these would make the print header follow the *screen* theme and print near-white text on white paper. |
| `PageHeader.vue:11,19,35` | `text-white` ×3 | White ink on a `bg-[#0B0F1E]` glass hero that is deliberately dark in both themes. |
| `StatusBadge.vue:31` | `bg-zinc-500` | The neutral dot in a six-colour semantic status set (sky/emerald/amber/rose/indigo) — a status colour, not a theme surface. Its `text-zinc-600` is paired with `dark:text-zinc-400` on the same line, as all six tones are. |

A fourth apparent `PageHeader` hit, `bg-white`, is `bg-white/[0.07]` — a bracket-opacity glass fill
that the original regex mis-read as a solid colour. That is the same measurement error twice inside one
finding, which is why the correction is published with the sites and not just the total.

**The rule that separates a defect from a design choice:** a hardcoded surface is a defect **only when
its ink comes from a token, or is absent**, so the pair can invert. `bg-slate-800 text-white` is
self-consistent in both themes; `bg-white` with inherited `text-foreground` goes white-on-white the
moment `.dark` applies. Applying the rule to `HRM/Pages/LeaveYearEnd/Index.vue:71`
(`bg-slate-800 text-white` on a "closed" chip), which the earlier survey had flagged as inverted in
light mode: it is **not** a defect, and it was left alone.

**Five real defects fixed**, all in `PAYROLL/Pages/Finance/SheetFinance.vue` and all of that class:
`bg-white` ×3 — the "View in Accounts" chip, the cost-centre breakdown cards and the bank-advice card
fallback, each containing tokenised `text-muted-foreground` ink — became `bg-card`; and `bg-slate-100`
plus `bg-slate-200`, two neutral status pills specifying no ink at all, became `bg-muted`.

**What the corrected measurement shows.** Scanning every `.vue` outside `components/ui/` for solid
neutral palette utilities, then dropping opacity variants, `dark:`-paired utilities and shadow tints:
**260 raw hits → 149 candidates in 47 page files → 144** after the five fixes. That is a *candidate
backlog*, not a defect count: the eight `from-slate-900 via-indigo-950 to-slate-900` hero headers
contribute three hits each and are all legitimate, and every remaining site needs the ink rule applied
by reading it. A second class is **not measured at all** by a neutral-only scanner — saturated light
tints such as `bg-emerald-50`, `bg-amber-50/50` and `bg-amber-100` are equally light-only surfaces,
and `SheetFinance.vue` alone carries several of them.

**Tests.** `SharedComponentThemeTest.php` (**7 tests / 22 assertions**) pins the corrected finding
instead of restating it: every raw palette class in a shared component must carry a documented reason;
no reason may go stale; and each reason must stay true of the code, so DataTable still has to mark its
header print-only, PageHeader still has to paint `bg-[#0B0F1E]`, and StatusBadge still has to give all
six tones a `dark:` counterpart. It also asserts the positive side — **236 semantic-token utilities
across 19 of the 21 shared components** — and that `.dark` in `app.css` still redefines
`--background`, `--foreground`, `--card`, `--muted`, `--border` and `--primary`, brace-matched,
because tokenising a component means nothing if the overrides go. The 144-candidate page backlog is a
second ratchet. **Five probes, each restored byte-identical:** an unexplained `bg-white` in
PageHeader; DataTable losing `hidden print:block`; `--card:` deleted from `.dark`; `.dark` renamed;
and a page growing `bg-gray-100`. The third passed at first — the assertion was a bare substring
match, which `--card-foreground` satisfies — and only failed once the rule was brace-matched and the
needle gained its colon.

**31. The front-end type-checker was dead, and P2.5's "~25 type errors" was stale — found while verifying item 29.**

`tsconfig.json` declared `"types": ["vite/client", "vue/tsx", "./resources/js/types"]`. `types` takes
**package names**, not paths; vue 3.5.44 ships `jsx.d.ts` with exports `./jsx`, `./jsx-runtime` and
`./jsx-dev-runtime`, and there is no `./tsx`; and `./resources/js/types` is a path whose `index.ts` is
already picked up by `include`. The consequence is worse than two noisy config errors: **with an
unresolvable `types` entry, `vue-tsc --noEmit` reports those errors and no source diagnostics at all.**

Proved rather than assumed. Appending `const bogus: number = "not a number";` to
`resources/js/composables/useAppearance.ts` — a file inside the covered tree — produced **zero**
errors. The same program with `types: []` reported **28**. The fix is three entries,
`["vite/client", "vue/jsx", "node"]` (`node` because `componentResolution.test.ts` imports `node:fs`
and `@types/node` was never listed). The probe then reports exactly one error, in that file, and the
file was restored byte-identical.

That is also why P2.5's "settings pages with ~25 type errors" is stale rather than wrong: the figure
is consistent with a checker that worked before the vue upgrade in item P0-I and has silently reported
nothing since. Nothing caught it because **CI runs ESLint, Vitest and `vite build`, and esbuild strips
types without checking them — there is no type-check step anywhere in the workflow.**

**What the live checker reports.** Over the tree `include` covers (`resources/js/**`, 158 files):
**20 errors**, nine of them a single root cause — the Inertia page-props augmentation — so `SharedData`
does not satisfy `PageProps` (3×), `auth` is `unknown` in `AppHeader.vue` (4×) and `$page` does not
exist in `Welcome.vue` (2×). The rest is starter-kit strictness: `breadcrumbs` possibly undefined (3),
`string | undefined` where `Method` or `string` is required (2), `flash` read off `{}` (2),
`string`→`number` in Login and Register (2), a `vite/client` module augmentation (1) and two
conflicting `NavItem` types (1).

**The larger gap: `include` never covered the modules.** All **272** `.vue` and `.ts` files under
`modules/*/Resources/js/` — every ERP page, including the eight migrated in item 29 — sit outside the
program. Adding them to a scratch config reports **562 errors**, of which **113 are one systemic
class**: `route('name', number)` against ziggy's `RouteParams<string>` signature, across **79 files**.
The worst files are the shared forms — `PartnerForm.vue` 28, `SalesOrderForm.vue` 18,
`QuotationForm.vue` 18, `ProductForm.vue` 17, `VendorBillForm.vue` 16. A whole-tree run also
**exhausts the default Node heap** (OOM, exit 134, at roughly 1 GB) and needs
`NODE_OPTIONS=--max-old-space-size=4096`, which any CI step would have to carry.

**Item 29's own work is type-clean.** The four new files report **zero** errors. Eight errors do fall
on lines the migration touched, and every one is the pre-existing `route(name, number)` complaint: each
appears on both the `-` and the `+` side of `git diff`, which is the same call re-wrapped rather than
a new one.

**Not fixed, deliberately.** The 20 and the 562 are published as measured backlog rather than repaired.
Clearing nine of the 20 by rewriting the Inertia augmentation is a design decision about how page props
are typed across the whole app, and 562 errors over 272 files is a project of its own; a CI type-check
gate added today would fail on arrival. The honest state is that `vue-tsc --noEmit` is now truthful and
red, which beats silently green — and that the two figures above are the cost of making it stay
truthful.

**Gates after this round.** Pest **480 passed / 1 skipped / 3,296 assertions** (from 466 / 3,255 — the
two new test files add 14 tests and 41 assertions). `pint --test` passes on all **867** PHP files, two
more than item 28's 865 because both new files are tests, and `php -l` is clean on the same 867.
ESLint reports **0 errors** tree-wide, Vitest **213 tests across 6 files** (the new `useConfirm.test.ts`
contributes 9), and `vite build` succeeds. `vue-tsc --noEmit` is the one red gate, for the reasons
above, and is not wired into CI.

**32. The demo dataset: 129 of 167 tables were empty, and every table a demo needs was among them.**

`php artisan migrate:fresh --seed --force` exits 0 and reports all sixteen seeders `DONE`, and the database
it leaves behind has **167 tables, of which 38 are populated and 129 are empty**. Both figures are measured
from `sqlite_master` and `COUNT(*)` per table after a fresh run, and both correct numbers published earlier
in this report: the P3 bullet said **168 tables, 132 empty**. There is no 168th table — that claim rested on
an `hrm_employees` table which does not exist, because HRM's employee rows live in `employees` and its models
are nested (`Modules\HRM\Models\Employee\Employee`) — and the empty count fell from 132 to 129 when item 1
rewrote `AccountsDemoSeeder`, which until then had never run at all.

What was empty is not trivia. Zero rows in `acc_journals`, `acc_journal_lines` and `acc_account_ledger`. Zero
invoices, orders, deliveries, bills, receipts and payments on both sides of the trading cycle. **Zero rows in
every `payroll_*` table.** Zero attendance of any kind, against **998 employees**. One quotation. One POS sale
with **zero items**. One roster shift — the 00:00–23:59 fallback. Every screen a UAT walk-through or a
screenshot needs was rendering its empty state, and the two "demo" seeders shipped to prevent that finish in
9 ms and 7 ms.

**Why the shipped demo seeders do not help.** `SalesDemoSeeder` and `PurchaseDemoSeeder` create one quotation
and one purchase order between them. Both hardcode their document numbers (`QT-00001`, `POS-00001`) instead of
asking the repositories that own the numbering, and both open with `if (Model::exists()) return;` — the same
guard shape that caused P0-B. They are left in place deliberately: existing screens and tests expect at least
one quotation and one POS sale after a plain seed, and withdrawing that is a change of contract this item had
no mandate to make. What follows is additive, and it is why the counts below show two quotations and five POS
sales rather than one and four.

**What was built.** `database/seeders/DemoDataSeeder.php` (1,025 lines), `app/Console/Commands/SeedDemoData.php`
(`erp:demo-data`, 47 lines) and `tests/Feature/CORE/DemoDataIntegrityTest.php` (468 lines, **9 tests / 43
assertions**, all passing). Measured result on a fresh database: **98 populated, 69 empty — 60 tables newly
filled — in 10.8 s**.

The dataset is one coherent month, October 2026 inside FY 2026-27, for one department's 14 employees: 280
roster duties against a real 09:00–18:00 shift, 640 punches processed into 320 pairs and 323 daily rows
(Present 160, Late 68, Early Out 40, Late & Early Out 52, On Leave 3); five leave types, three holidays, four
leave requests of which two are approved; two purchase chains (PO → GRN → bill → 60 % payment); a transfer
shipped and received, an adjustment approved and posted, a kit assembled; a quotation sent and accepted, two
orders confirmed, one delivery shipped, three invoices posted (one paid, one part-paid, one outstanding) and
four POS sales inside a session; two bank accounts, a posted rent journal and its bank-side statement lines;
and the payroll run itself — a PF policy with 14 accounts, an interest-free loan through both approval stages
with 12 instalments, a sheet dispatched with 984 documented exclusions, processed, released, accrued into the
ledger, all 14 slips paid (which is what writes the 28 PF ledger entries) and a bank advice generated. The
accounting result is **18 journals, all posted, across four voucher types (JV 3, PV 6, RV 2, SV 7), 62 lines
and 62 ledger rows**, plus 47 stock movements.

**Four design rules, each of which cost something to get right.**

*Services, not inserts.* Every document goes through the service that owns it, so document numbers come from
the repositories and the accounting effects are the real ones — the demo cannot reach a state the application
could not. The exceptions are genuine reference and config rows with no owning service (`roster_shifts`,
`hrm_holidays`, `hrm_leave_balances`, `payroll_settings`, `tax_configurations`/`tax_slabs`,
`acc_bank_statements`/`acc_bank_transactions`), and they are listed in a public `REFERENCE_INSERTS` constant on
the seeder so the compromise is visible rather than buried.

*Opt-in.* `DatabaseSeeder` does **not** call it. CI pays for seeding on every test, and this file alone adds
157 s to the suite; wiring it into the default seed would have imposed that on every build for no benefit to
one.

*Idempotent by marker, not by existence.* The guard is `sales_orders.notes LIKE 'Demo dataset
(erp:demo-data)%'` — not `if (Model::exists()) return;`. A second run is a no-op, pinned by a test that
compares all 66 promised row counts before and after, and nothing is ever deleted.

*Guarded mass assignment.* `SeedCommand` wraps every seeder in `Model::unguarded()` (finding 36), so a seeder
that drives services does not behave like the application. This one calls `Model::reguard()` for its whole
duration and restores the flag afterwards.

**The test asserts invariants, not only counts**, because a seeder that inserted plausible-looking rows would
satisfy any minimum. Every journal balances, its header totals equal the sum of its lines, and it has at least
two lines; the ledger balances as a whole and mirrors every posted line; the stock cache equals the movement
history aggregated per product and warehouse (aggregated deliberately — item 25 records the lot-keyed
asymmetry, so a lot-level comparison would fail for a reason already reported); each slip's net equals gross +
benefits − deductions, the sheet's totals equal the sum of its slips and its `employee_count` equals the slip
count; the accrual journal exists and is posted; every PF account has at least one ledger entry; every posted
invoice carries a live `journal_id` and stock movements reference it; the seeder is idempotent; and
`erp:demo-data` refuses in production without `--force`, writing nothing.

Its twin is what keeps the counts honest: one test observes the **base seed only** and asserts that all 37 of
the tables the demo promises are empty there — `inventory_stock_moves` excepted, at exactly 16, because
`InventoryDemoSeeder` writes those. An earlier version of that twin deleted six tables and re-ran the seeder
inside a nested transaction. That cannot work, and it failed when run: the idempotence marker lives in
`sales_orders.notes`, and `roster_duties` has a unique `(employee_id, roster_date)` key, so a partial delete
either makes `run()` a no-op or violates the constraint. Observing the untouched base is simpler and stronger.

**What it does not cover, published as an executable list.** Seventeen tables the design intended remain empty,
and a `DD_UNCOVERED` constant asserts each one is *still* empty, so the gap cannot drift silently and promoting
a table into the promised set is a deliberate act: the HRM lifecycle documents (movements, probations,
documents, assets, checklists, informed requests), `roster_overtimes`, festival bonus and gratuity policies,
payroll settlements, stock counts, locations, product suppliers, and price lists. `roster_overtimes` was probed
rather than assumed: it is derived by `RosterOvertimeService::syncFromAttendance('Overtime')`, which only picks
up dailies with `total_overtime_hours > 0.5`, and even after two employees were made to punch out at 19:45 the
reconciler recorded no overtime hours against the rostered shift, so there was nothing to derive — twice
measured at zero. Settlements are a leaver process and nobody in the cohort leaves. The per-slip salary payment
journal cannot be written at all: finding 35.

**Not done, stated plainly.** The two stub seeders keep their hardcoded document numbers and existence guards.
The payroll run is scoped to one department through the domain's own exclusion mechanism rather than running all
998 employees, which is what keeps it at 10.8 s; a full-company run is a flag away but was not built. And the
five findings below were found by this work, not fixed by it — each says why.

**33. Approving leave over an already-processed attendance day throws on SQLite.**

`LeaveRequestService::syncApprovedLeaveToAttendance()` looks the day up with
`AttendanceDaily::firstOrNew(['employee_id' => …, 'duty_date' => $date->toDateString()])`. The model casts
`duty_date` to `date`, so the value is serialised into the WHERE clause as `2026-10-12 00:00:00` while the
column stores `2026-10-12`. On SQLite those do not compare equal, `firstOrNew` therefore builds a **new**
model, and the `save()` that follows becomes an INSERT which violates `unique(employee_id, duty_date)`.
Reproduced, not inferred: the demo seeder died on exactly this the first time it approved a leave request dated
inside the attendance month, with the mismatch visible in the failing SQL. MySQL coerces a DATE against a
DATETIME-formatted string and matches, so this is SQLite-only — but SQLite is what this project's `.env`
specifies and what the entire test suite runs on, which is precisely why no test caught it. It is the same
family as P1.5's 17 `whereBetween`-on-`date` sites. **Not fixed.** The seeder dates its leave in November,
after the attendance window, and the call site carries a comment saying so and pointing here.

**34. `acc_journals.type` lists `'PV'` twice.**

The enum is `['JV','PV','RV','CV','BV','SV','PV']`, commented "Journal/Payment/Receipt/Cash/Bank/Sales/Purchase
Voucher" — seven labels for six distinct values, with Payment Voucher and Purchase Voucher both spelled `PV`.
Any query, filter or reconciliation that distinguishes a payment from a purchase by `type` cannot, and the
comment actively misleads the reader into thinking it can. **Not fixed:** changing an enum's values is a data
migration over existing rows, and the demo's journals are not the only ones that would need it — a count that has
now moved three times and is worth recording so the drift is not mistaken for an error: **18** when this was
first measured, **15** after item 39 stopped creating an invoice per counter sale, **28** after item 40 posted
the payroll payments the demo had been skipping.

**35. Only the first slip of a salary sheet was ever posted to the ledger, and the failure was swallowed — FIXED, item 40.**

`payroll_journal_posts` carried `unique(salary_sheet_id, posting_type)`, added with the comment `// idempotent`.
`PayrollJournalService::postSalaryPayment()` writes **one post per slip** and looked up its own idempotence key on
`meta->slip_id`, which that unique index does not include. The second slip of any sheet therefore violated
`uniq_sheet_posting`, inside the `DB::transaction` that had just written the journal, so the journal rolled back
with it. Reproduced twice: the seeder died on slip 2 of 14 with the constraint name in the error, and item 40's
test — written before the fix — failed with `UNIQUE constraint failed: payroll_journal_posts.salary_sheet_id,
payroll_journal_posts.posting_type` on an insert whose debit and credit were both correct.

The severity came from the callers, and there were **two**, which is why the finding needed re-reading before it
could be designed against. `PayrollPaymentPostingService` wrapped the posting in
`catch (\Throwable $e) { Log::error('Payroll payment posting failed', …) }`, and
`MarkPayrollSlipPaymentAction` wrapped *that* in `catch (\Throwable $e) { Log::warning(…) }` under the comment
`// auto-post payment JV (idempotent, never blocks HR)`. So in production nothing surfaced: **every slip was
marked paid, the PF ledger was written for each one, the loan installments closed, and N−1 of N salary payments
never reached the general ledger.** For a 998-employee sheet that is a bank account overstated by nearly a full
payroll, with the only evidence in a log file. Measured in the demo dataset as it stood: **14 slips paid, 1 with
a `payment_journal_id`, 2 rows in `payroll_journal_posts`** — the accrual and the one slip that got through. No
test called `postSalaryPayment`, and the only caller in the tree was that service, so this was P1.1's
silent-failure pattern in a place `SilentFailureInventoryTest` could not look, because neither catch is empty.

**Fixed at item 40**, as a schema decision plus a visibility decision. The unique key is now
`(salary_sheet_id, posting_type, posting_scope)` over a NOT NULL `posting_scope` — `'sheet'` for accruals and
settlements, `'slip:<id>'` for payments — with `payroll_slip_id` added as a real foreign key and the existing
per-slip rows backfilled from their own `meta->slip_id`. The narrower candidate (a nullable slip id inside the
index) was rejected on a portability fact: SQLite and MySQL treat NULLs as *distinct* in a unique index, so the
sheet-level rows would have lost their idempotence entirely — a worse trade than the defect, since a duplicated
accrual overstates the whole payroll expense. The aggregate-payment candidate was rejected because
`payroll_slips.payment_journal_id` and the `PAY-PV-<sheet>-<employee>` reference already make the design
per-slip. On visibility: the service rethrows, and the action keeps "never blocks HR" — that part of the old
comment was right — but records the reason in `payroll_slips.payment_posting_error` and logs at error, so a
paid-but-unposted slip is a queryable row (`PayrollSlip::paidButUnposted()`) rather than a log line. Measured
after: **15 posts, 14 of them per-slip; 14 paid slips all carrying a journal and none an error; posted credits
160,547.83 = the sheet's `net_total` to the cent; account 2121 Salary Payable at 0.00.** Nine tests in
`SalaryPaymentPostingTest`, both swallows pinned by name in `SilentFailureInventoryTest`, and the seeder's
workaround removed. Full detail in §3, "Payroll payment posting (new) — item 40".

**36. Seeders run with mass-assignment protection off, so a service-driven seeder is not the application.**

`SeedCommand:70` wraps the entire seed in `Model::unguarded()`. Under a seeder a key that the model's
`$fillable` excludes is **inserted**; over HTTP the same key is **silently discarded**. Two consequences, both
measured here rather than reasoned about.

First, `PosService::sell()` builds item rows carrying `discount_amount`, `vat_rate` and `vat_amount`, and
`pos_sale_items` has none of those columns — so POS selling crashes under any seeder that drives it, which is
what the demo seeder did. The production half is worse than the crash: the three values are computed per line,
passed to the repository, and dropped by `$fillable`, so **per-line VAT and discount are not persisted for POS
sales at all**. Only the header totals survive, and `PosSaleItem::$fillable` makes the drop invisible at the
call site. `PosSessionAggregateTest` did not catch this because it drives the repository with rows that only
contain real columns, which is a coverage gap this report is recording rather than excusing.

Second, a seeder that wants to reproduce the application has to undo what the framework did to it: hence
`Model::reguard()` in `DemoDataSeeder`. **The POS columns are not fixed** — adding them means a migration,
`decimal` casts (item 26's contract test enforces those), and a receipt screen that reads them.

**37. Repositories hand back models whose database defaults were never read.**

`TransferRepository::create()`, `AdjustmentRepository::create()`, `JournalRepository::create()` and
`QuotationRepository::create()` each `Model::create($data)` and return the instance without refreshing it. A
column whose value comes from a database default — `status`, on all four — is therefore `null` on the returned
model, and the state guards that follow compare strictly. `Transfer::canShip()` is
`in_array($this->status, ['draft','pending'], true)`, so `create()` immediately followed by `ship()` throws
`Transfer cannot be shipped in current status: ` with an empty status where the sentence should name one;
`JournalService::post()` likewise refuses a journal it has just created, with "Only draft journals can be
posted." Reproduced four times while building the seeder, on four different repositories.

The UI never hits this, because it redirects and re-fetches before the next transition. **Every programmatic
caller does** — a seeder, a queued job, an import, an API integration, a test. That is the population this
project's own `erp:demo-data` now belongs to. **Not fixed:** a `refresh()` per site is a one-line change, but it
alters what every caller receives, and this item's mandate was the demo data. The seeder refreshes at each call
site and says so in a comment.

**Gates after this round.** Pest **489 passed / 1 skipped / 3,339 assertions** in **569 s**, from 480 / 1 /
3,296 in 343 s: the new file contributes exactly +9 tests and +43 assertions, and 157 s of the added runtime,
because each of its tests seeds the base dataset and then the demo. `pint --test` passes on all **870** PHP
files and `php -l` is clean on the same 870, both up from 867 — the seeder and the command are sources, the
integrity test is a test, giving **62** test files against **785** PHP sources under `app/` and `modules/`. No
front-end file changed this round, so ESLint (0 errors), Vitest (213 tests / 6 files), `vite build` (success)
and `vue-tsc` (20 base / 562 whole-tree errors, still the one red gate, still not in CI) are unchanged from
item 31.

### P2 · Quality and UX

**1. 76 native `confirm()`/`alert()`/`prompt()` calls** despite `ConfirmDialog.vue` and `ToastStack.vue` existing (35 files already use the dialog). They guard destructive and financial actions: movement approval, attendance rebuild-all, leave approval/rejection, overtime rejection (using `prompt` for the reason). Two of the worst are now gone (payroll reopen); the rest remain. **Partly closed by item 29**, which re-measures the count at 77 live calls, migrates 30 of them onto a new `useConfirm` store, and leaves 47 under a ratchet that can only fall — **46 in 33 files** as of item 39.

**2. Dark mode: 75 hardcoded light-only classes** against 31 `dark:` variants — `bg-white` ×32, `bg-slate-50` ×6. The architecture is sound (1,329 semantic token usages; `useAppearance.ts` correctly defaults to `'system'`). Worst offenders: `SheetFinance.vue` (10), `Reports/Index.vue` (10), `RosterDuties/Excel.vue` (6). Highest blast radius: **`DataTable.vue` (4) and `PageHeader.vue` (3)** — shared components used everywhere. **Retracted in item 30.** Those were regex hits, not defects: the shared components hold eight raw palette classes and no defect at all (DataTable's are print-only, PageHeader's are white ink on a fixed-dark hero, StatusBadge's is a status dot paired with `dark:`). The corrected measurement is 144 page-level *candidates*, five genuine defects fixed in `SheetFinance.vue`, and an ink rule for adjudicating the rest. **Item 42 changes what "clean" meant here.** The shared components were clean *and fixed*: a hero painted `bg-[#0B0F1E]` and six status tones as six raw palettes are not defects by the ink rule, and they are still colours no setting could reach. All four are tokenised now, the allowlist is down to DataTable's four print-only grays, the two assertions that demanded the hardcoded hero and the six `dark:` counterparts are inverted into assertions that demand tokens, and the candidate backlog is **143** — one lower, because the scanner now strips comments and stopped counting a colour that was only written about.

**3. Thin design system outside CORE.** HRM (63 pages), Inventory, Sales and Purchase have **zero shared components**. Only 3 of 372 `.vue` files use skeleton loaders (16 are available), 73 `aria-*` / 35 `role=` attributes total, only 5 files use toasts, 12 empty states, 22 `v-for` without `:key`, 136 `any` types. The new `Pagination.vue` is the first cross-module component adopted outside CORE. **Item 42 adds a layer underneath the components rather than more of them:** eight palettes × twenty tokens × two modes in `resources/css/themes.css`, sixteen of them mapped to utilities, so the four shared components every module renders through now follow a scheme chosen at runtime. The gap this entry describes is unchanged — HRM, Inventory, Sales and Purchase still have zero shared components of their own, and 143 page-level raw-palette candidates and 158 `dark:` variants are all in pages, not in the kit.

**4. No i18n.** For a Bangladesh-market ERP, Bangla is likely a requirement. Print styles were recorded here as absent; **item 43 found they were present and produced blank paper**, which is the worse of the two — see finding 50. What is still genuinely absent is server-side PDF export (dompdf is installed and used by none of this) and stationery for the transactional documents: invoices, quotations, delivery challans, purchase orders, GRNs and journal vouchers print correctly on white paper with the shell's letterhead, but they have no document layout of their own. Both were put to the user as scope forks and both were declined for this round. Excel/PDF *exports* remain scaffolded and unstyled.

**5. Starter-kit leftovers.** `Welcome.vue` at 787 lines, settings pages with ~25 type errors, `resources/js/tsconfig.json` at 12.8 KB. The type-error figure is stale in an interesting way: **item 31** finds the type-checker had been reporting nothing at all since the vue upgrade, and measures 20 errors in the tree `tsconfig.json` covers and 562 across the whole front end once the module files are included. Item 39 re-measures it with a config that **extends the project's own** — same `paths`, `types` and `strict`, `include` widened to `modules/*/Resources/js/**` — and gets **562 across 433 files: 542 in `modules/`, 20 in `resources/js/`**, so the figure stands and its method is now recorded. The gap between the two numbers is the finding worth keeping: the project's `tsconfig.json` includes only `resources/js/**`, so the 275 module front-end files are type-checked by nothing today.

**6. A migration backfill that swallows its own failure — finding 41, from item 38.** `2026_10_28_000002_add_biometric_id_to_employees` populates the column it adds from `device_users` inside `try { … } catch (Throwable $e) { Log::warning(…) }`. If that throws, all 998 employees keep `biometric_id` null, device-to-employee matching degrades to manual, and the migration still exits 0. The write itself is legitimate — a backfill belongs with the column it fills, and item 38 allowlists it by name for exactly that reason — but the swallow is the P1.1 family in a place nobody watches, since the only trace is a `Log::info` count. Not fixed: the honest fix is a decision about whether a failed backfill should abort a deployment, which is a question about those 998 existing rows rather than about the migration.

**7. Per-line discount and VAT are computed and then dropped — finding 42, from item 39.** `PosSaleItem::$fillable` omits `discount_amount` and `vat_amount`, so a discount or rate entered against a line at the till reaches the header totals but leaves no per-line record. The consequence is not a wrong total; it is an unauditable one — a receipt can show a discount nobody can trace to the line it was given on, and the day-end VAT leg cannot be reconciled line by line. Either the two columns are added and filled, or the inputs come off the till. Not fixed in item 39, which was scoped to the day-end posting: adding columns to a line table means deciding whether existing rows backfill to zero or stay null, and the till UI has to change with it.

**8. Operator-facing copy claimed ledger effects the code did not perform — finding 44, from item 39.** `PosController::void()` flashed "Stock returned and the journal reversed" for a reversal it did not do, and `Terminal.vue` told the cashier that VAT "posts to 2110 on invoice" when no invoice was created. Both are corrected for the POS. The class is not: any message asserting "journal posted", "stock returned" or "payment recorded" is a claim about database state, and no test anywhere compares the claims a flash message makes against the code path that emits it. That is a cheap test to write — collect the literal strings, map each to the service call it describes, assert the call exists — and it would have caught this at the till and in the payroll reopen before it.

**9. A reservation against stock held in two locations lands on one arbitrary row — finding 47, from item 41.** `StockService::move()` keys the `inventory_stocks` cache on `location_id`; `reserve()` and `release()` key on `(product_id, variant_id, warehouse_id)` only and take `->first()` with no `ORDER BY`. Measured rather than inferred, by a characterisation test: receive 10 into Aisle A and 10 into Aisle B, reserve 5, and exactly one row carries the reservation while the other still advertises all 10 of its units — 20 on hand, 5 reserved, 15 available, and *which* row absorbed it is not defined by the query. Not reachable today, because no caller passes a location and no screen captures one, so every cache row has `location_id = NULL`. Not fixed: the honest fix is a decision about whether a reservation belongs to a bin or to the warehouse, and it changes `InventoryStockProvider::reserveStock()` and its sales callers, none of which has a location to pass.

**10. A batch-tracked product valued at weighted average never gives lot quantity back — finding 48, from item 41.** `move()` creates `Lot` and `FifoLayer` rows for a receipt when the product is `is_batch_tracked` **or** valued FIFO, but consumes them only inside `if ($sign < 0 && $isFifo)`. So for a batch-tracked product that is *not* FIFO-valued, issues never decrement lot quantities. Measured: receive 5, issue 2, the cache correctly says 3 on hand while `Lot.qty_remaining` and the layer's both still say **5.0**, permanently. Those two tables are what the Lots & Expiry report reads and what a recall would be answered from, so every unit ever shipped is missing from both. Not fixed: closing it means deciding whether a lot-less issue should consume lots oldest-first for products that are not valued by FIFO, which is the lot-consumption engine item 41 deliberately did not build.

**11. A lot number is a global identity, so a reused stamp is attributed to the wrong product — finding 49, from item 41.** `inventory_lots.lot_no` is `unique()` on its own rather than per product, and `move()` creates the lot with `Lot::firstOrCreate(['lot_no' => $lotNo], ['product_id' => …])`. Measured: receive 6 of product A against `LOT-SHARED`, then 4 of product B against the same stamp, and there is **one** lot row — owned by product A, holding `qty_received` 10 at A's 5.00 unit cost, with B's FIFO layer pointing at it. Two suppliers using the same lot stamp for different goods is ordinary practice, so this is reachable the moment lots are used at all. The one piece of good news, also measured: the stock cache is unaffected, because item 41 made it an aggregate that does not read the lot table — both products stay correct at 6 and 4 — so the defect is confined to lot reporting rather than to quantities on hand. Not fixed: `unique(product_id, lot_no)` is a schema decision that needs a migration over any lot already shared, and it belongs with the lot-tracking item.

**12. Every Print button in the application produced a blank sheet — finding 50, from item 43. FIXED.** Two independent causes, each measured rather than inferred. The shell's `@media print` rule hid `body *` and revealed only `[data-core-print]`, an attribute that **exactly one component in 394 files** set — so every other Print button printed nothing, while looking like it worked. And two pages, `PaySlips/Show.vue` and `BarcodePrint.vue`, had each responded by hiding `body *` in their own **unscoped** `<style>` block, which in an SPA is injected once and never removed: visiting either page blanked printing for the rest of that tab, including pages printed before it. A third defect sat underneath both — `Pos/Receipt.vue` called `window.print()` from a template, which compiles to `_ctx.window.print()` and throws, so that button had never printed anything at all. Item 43 puts all print CSS in one stylesheet, makes region isolation opt-in with a structural fallback for pages that mark nothing, routes every print through a single composable that waits for fonts and stamps the letterhead at print time, resets the tokens to light-on-white for paper, and migrates 16 files. `PrintContractTest` pins the architecture and `usePrint.test.ts` pins the regression: a page that marks nothing must *not* enter isolation, because that class is what blanked the sheet.

**13. The palettes promise five chart colours and nothing reads them — finding 55, from item 42.** `--chart-1..5` are defined in all eight palettes in both modes and mapped to utilities an author can write. The measured consumer count is **2** files, and both are the demos that document them: `UiKit.vue` and `Settings/Appearance.vue`. There is no chart component in the application, so the first one written will hand-pick its series colours unless its author already knows the tokens exist — which is precisely how the 143-candidate backlog was built. Not fixed, because there is nothing to migrate and adding a chart is a feature rather than a correction. What item 42 did was make the omission cheap to close later, and `ThemePaletteTest` fails the day a palette stops defining them.

### P3 · Process

- **72 PHP test files against 796 PHP source files** (re-measured for items 42 and 43, which added four tests — `ThemePaletteTest`, `PrintContractTest`, `UiSettingsTest`, and `ShellBindingTest`, which arrived late because finding 56 arrived late — and four sources: `UiTheme`, `CoreSetting`, the `core_ui_settings` migration and `UiSettingsController`. Item 41's round was 68 against 792. Item 40 added one of each — `SalaryPaymentPostingTest` and the posting-scope migration — for 67 against 791, and item 41 added one of each again: `StockLotCacheTest` and the lot-merge migration. Item 39's round was 66 against 790, when it added three tests — `PosDayEndPostingTest`, `PosCollectionReportTest`, `ReportAccessTest` — and four sources: the day-end migration, `PosCollection`, `PosDayEndPostingService`, `PosReportService`. Item 38's round was 63 against 786, when it added one of each — `tests/Feature/CORE/MigrationDataSeparationTest.php` and `modules/HRM/Database/Seeders/RosterFallbackShiftSeeder.php`. Item 32's round was 62 against 785, when it added `DemoDataIntegrityTest.php` and the source file `app/Console/Commands/SeedDemoData.php`; its own seeder lives under `database/`, outside this scope. Previous round: 61 against 784. Scope, unchanged: every `*Test.php` under `tests/`, and every `.php` under `app/` and `modules/`. The earlier figure of 791 sources came from an undocumented scope, and no PHP source file was added or removed in this round — the two new PHP files, `NativeDialogTest` and `SharedComponentThemeTest`, are both tests. The count was 34 test files at the first audit pass). Journal balancing, the fiscal-year lock and stock movements — the three gaps §7 item 2 named — are now covered by items 23 and 24; the money-column contract by item 26 and the deployment templates, the compose file and production debug rendering by item 27, the CI workflow itself by item 28, the shared-component theme contract plus the native-dialog ratchet by items 30 and 29, the rule that migrations change schema while seeders create content by item 38, the POS day-end posting, the collection report and — for the first time anywhere in this report — the *reachability of a menu item* by item 39, the per-slip payroll payment posting by item 40, the stock cache key together with the lot an issue now honours by item 41, and — for the first time — a *cross-language* contract by items 42 and 43: `ThemePaletteTest` compares a CSS token set, a PHP catalogue and a Blade template against each other, because those three agree or the feature does not work and no one of them can tell. One gap closed rather than widened: item 41 removed the suite's only `->skip()`, so **0 tests are
skipped** for the first time since item 24 added it. What genuinely remains uncovered is *concurrency*: every lock in the posting and stock paths is `lockForUpdate()`, which SQLite ignores, so the test database cannot observe two writers racing. That needs the MySQL job in CI to grow a parallel-writer test, and until then no claim of concurrency coverage is made anywhere in this report.
- **20+ loose report/plan `.md` files at the repo root** (some 96 KB) — move to `docs/`.
- **Single-commit history.** No branches, no PR review, no CI (CI now exists). Adopt branch protection requiring the `ci` job.
- **Demo data was extremely thin, and is now measurably less so — item 32.** The original figure published here was "168 tables, **132 empty**". Re-measured from `sqlite_master`: the database has **167 tables** (the 168th was `hrm_employees`, which does not exist — HRM's employees live in `employees`), and the base seed leaves **129 empty**, not 132, because item 1's `AccountsDemoSeeder` rewrite added the chart of accounts, three fiscal years and their 36 periods. Zero rows in `acc_journals`/`journal_lines`/`account_ledger`/`payables`/`receivables`, in every `payroll_*` table, in every attendance table against 998 employees, and in all sales and purchase documents; one row each in `sales_quotations`/`pos_sales` (the POS sale with **zero items**)/`purchase_orders`/`roster_shifts`. **Item 32 adds an opt-in `erp:demo-data` seeder that fills 60 of those tables** — 98 populated, 69 empty, in 10.8 s — driven through the services so the accounting effects are real, and pinned by 9 integrity tests. Seventeen intended tables remain empty and are asserted to be so, with reasons, in `DD_UNCOVERED`. The stub `SalesDemoSeeder`/`PurchaseDemoSeeder` keep their hardcoded document numbers and `if (Model::exists()) return;` guards; they were left alone deliberately, and `docs/UAT_CHITTAGONG_STORE.md` should now be walked against a demo-seeded database rather than a bare one.
- **22 of 39 seeder classes are never called — finding 39, from item 38.** `DatabaseSeeder` calls 17 seeders; 39 exist. The 22 orphans, of which 21 are referenced by nothing in the codebase (`RealEmployeeImportSeeder` is called by `RealEmployeeBasicSeeder`): `AMZDemoSeeder`, `AttendanceDailySeeder`, `AttendanceLogSeeder`, `AttendancePairSeeder`, `BimoetricDeviceSeeder`, `DepartmentSeeder`, `DesignationSeeder`, `DeviceUserSeeder`, `EmployeeEducationSeeder`, `EmployeeExperienceSeeder`, `EmployeePolicySeeder`, `EmployeeProfileSeeder`, `EmployeeSalarySeeder`, `EmployeeSeeder`, `EmployeeSkillSeeder`, `EmployeeSupervisorSeeder`, `LeaveTypeSeeder`, `OrganizationSeeder`, `RealEmployeeImportSeeder`, `RosterDutySeeder`, `RosterShiftSeeder`, `RosterShiftSwapSeeder`. Most are superseded by `RealEmployeeBasicSeeder`, which does create the departments, designations, employees and salaries they describe — so they are dead code that still reads like a provisioning promise. `RosterShiftSeeder` is the exception worth attention: it creates six real shifts *and* a fallback-shift block byte-identical to the one migration `2026_10_30_110000` used to write, so that row had two owners and neither was authoritative. Item 38 extracted the migration's copy into a dedicated seeder and left this one alone on purpose — wiring it would add six real shifts and change how every punch in every install is classified, which is a content decision needing its own measurement. *Recommendation:* wire or delete; a seeder nobody calls is a claim the codebase does not keep.
- `migrations` has **93 rows** as of item 42 (**91** at item 40, 90 at item 39, 89 at item 38; handover docs claim 86) and `permissions` **179** (178 until item 42 added `settings.appearance.manage`, the gate on the company-wide colour scheme; item 43 adds neither). `OptionEndpointAuthorisationTest` is re-pinned at 179 with that reason recorded in the test, since the number is the point of the assertion: it is what makes an unseeded permission in an option map visible. Documentation is stale — and partly corrected by item 38, which re-measures and rewrites `DEPLOY.md`'s outcome comment (it claimed 78 migrations and 4 Pest tests) and the two lines in `docs/PURCHASE_GRNI_HARDENING.md` that named a migration as the source of account 2115.
- **An unscoped `<style>` in a Vue SFC is global and outlives its page — finding 51, from item 43. FIXED and banned.** Vue injects a component's styles when it is first mounted and never removes them, so in a single-page application an unscoped block is a stylesheet edit for the rest of the tab's life. Two pages used that for print isolation and blanked every later print; both blocks are deleted, the seven `<style>` blocks that remain in 394 `.vue` files are all `scoped`, and `PrintContractTest` fails on any component that hides the body under `@media print`. The general hazard is wider than print — any unscoped rule is a global edit from inside a component — and nothing yet tests for unscoped blocks as such.
- **A number a page could not verify — finding 52, from item 42. FIXED.** `UiKit.vue`'s hero claimed "Components: 24" as a literal in the markup. It was already wrong the day `SchemePicker` arrived and would have stayed wrong silently, because the only way to check it is to count a directory by hand. The controller now counts `modules/CORE/Resources/js/Components/*.vue` and the page binds the result. The class is worth naming: a figure rendered into a UI is a claim, and a claim nobody can check is a claim nobody checks.
- **Two `Authenticatable` subclasses, so hinting the module's User is a latent 500 — finding 53, from item 42. FIXED inside the round.** `App\Models\User` and `Modules\CORE\Models\User` both extend the framework's authenticatable, and the auth provider resolves the module's at runtime — but the starter kit's own auth tests still sign in as the parent. `UiTheme::share()` was written against the subclass, so every Inertia response for those requests became a 500 `TypeError`, and the symptom that surfaced was `users can logout` failing on an `assertGuest()` that never ran because the request died before the redirect. The hint is the base class now, which costs nothing: `can()` and attribute access both come from it. Any new code that accepts "the current user" should take the base class unless it needs Spatie's role API specifically.
- **The starter kit's Profile and Password screens render outside the ERP shell — finding 54, from item 42. OPEN.** The account menu's Profile entry goes to `profile.edit`, which renders in `AppLayout`: no sidebar, no module chrome, a different header. Item 42 closed the same problem for Appearance by making `settings/appearance` a redirect to the ERP's own screen and deleting the starter's copy, and added a UI Settings entry that stays inside the shell — but Profile and Password were left alone, so the shell still changes underneath a user who edits their password. Not fixed: both screens work, and moving them means either rebuilding them on `AdminLayout` or wrapping the starter's layout, which is a decision about how much of the kit this project intends to keep.
- **A dropped template binding renders nothing, and only the type checker notices — finding 56, from item 42. FIXED inside the round, and now pinned.** Putting the theme engine into `AdminLayout.vue` replaced the line `const user = computed(() => page.props.auth.user)` and left five references to `user` in the template behind it: the account pill's `v-if`, its initials, its name, the `:user` prop handed to `UserMenuContent`, and the printed colophon's "Printed by". In a Vue SFC an unresolved template name is not an error — it is `undefined`, which is falsy, so the account menu and the letterhead's attribution silently rendered nothing while pint, ESLint, all nine Vitest files, the production build and all 589 Pest tests stayed green. `vue-tsc` reported it, and `vue-tsc` is the one gate this repository does not run in CI. The fix was three parts: restore the binding; correct the `usePage` generic that made it untypable, because the generic *is* the props type and wrapping it in `{ props: … }` types every read as `unknown` — which is also why the old line's error had been *masked* rather than absent; and narrow `UserInfo` and `UserMenuContent` to the three fields they actually read, so that both user shapes in this codebase satisfy them. That last part is finding 53 arriving through a prop type instead of a parameter hint: two `Authenticatable` subclasses, one component that would only accept one of them. `ShellBindingTest` (**3 tests, 20 assertions**) now scans every CORE layout for bare template names with no binding behind them, pins the shell's critical bindings in both directions so the list cannot rot, and ships fixtures both ways — one that fails if the scanner goes blind, one that fails if it starts over-reporting loop aliases, destructured composables, aliased imports, props and globals. Removing the binding by hand makes it fail at `AdminLayout.vue:52 user`, which was the check on the check.
- Future-dated `2026_11_03_*` migrations should be checked for ordering/duplicates. `inv_stocks_unique` is inconsistently named against the `inventory_` prefix. A typo'd duplicate factory exists: `BimoetricDeviceFactory.php` alongside both `BiometricDeviceSeeder.php` **and** `BimoetricDeviceSeeder.php`.
- **Demo-data minimums are floors, so deletions pass silently — finding 43, from item 39.** `DemoDataIntegrityTest::DD_MINIMUMS` asserts `>=` on row counts. A regression that *removes* demo rows down to the floor is green, and a regression that keeps the rows but fills them wrongly is green too — which is precisely how the `sale_price` defect survived: every POS and invoice line existed, and every one of them was priced at a `?: 900` fallback because the seeder read a column that does not exist. Shape assertions would have caught it (at least one *posted* session, at least one collection against a receivable, at least one part-paid receivable, no line priced at the fallback). Item 39 adds those three shapes for the POS path only.
- **The seed is not reproducible — finding 46, from item 39.** `InventoryDemoSeeder` creates its fifteen products through `Product::factory()` and opens their stock with `rand(50, 200)` units at `rand(50, 300)` cost, so every money figure downstream of the base seed differs between two runs of `migrate:fresh --seed` — measured: the POS demo day-end net at 16,020.00 and 5,887.67 from the same code. `DemoDataSeeder` itself is fully deterministic and `DemoDataIntegrityTest` asserts invariants rather than amounts, which is why nothing failed; but no demo figure quoted anywhere (including in this report) can be reproduced by a reader who re-seeds, and a UAT walkthrough that says "the day-end should show 16,020.00" is unusable. *Recommendation:* fixed prices for the demo products, or a demo product list of its own.
- **Nothing tested that a menu item was reachable — finding 45, from item 39.** Routes were tested (`RouteIntegrityTest`), permissions were tested (`OptionEndpointAuthorisationTest`), and `menu.ts` was tested for three literal strings. No test connected a menu entry to a rendered link, so **ten features were unreachable for every role including the Super Admin** while every gate was green: `Accounts › Financial Reports` and `Inventory › Inventory Reports` are third-level groups, and the two-level sidebar drew each as a single leaf linking to `#`. Now pinned by reachability at any depth in `treeSidebarPolish.test.ts`. The residual risk is the same shape elsewhere — a config that is read but not fully rendered, or rendered but not filtered — and the general test would assert that every leaf in every navigation config resolves to a route the current role can reach.

---

## 5. A note on the previously circulated audit report

An "ERP-Pilot-Project — Audit Report (4 Nov 2026)" was in circulation claiming a patch `erp-hardening.patch` had already applied 9 fixes. **Eight of the nine did not exist on disk.** Verified missing: `erp-hardening.patch`, `app/Http/Middleware/SecurityHeaders.php`, `.env.production.example`, `.github/workflows/ci.yml`. Its central claim — *"Added permission middleware to all 180 HRM routes … uses the 57 already-seeded `hrm.*` permissions"* — was false on both halves: middleware coverage was **0/181**, and the seeder had never been invoked. It also stated that Pest could not be run; it ran, at 193/193 after the seeder fix. It omitted the most severe defect in the project entirely (P0-B, the emptying of the general ledger).

Two of its *open* items were accurate and are confirmed here: the 7 missing Vue pages (P0-E) and the auth-only `api/options` endpoints (P1.3, since fixed).

Every number in this report was re-derived from the working tree. The four artefacts that report claimed existed are the four that §3 now actually creates.

---

## 6. Files changed

Measured with `git status --porcelain -uall` against the last commit (`f3e014a`), excluding build
output (`public/build/`), `node_modules/` and `vendor/`. Earlier revisions of this section reported
28 added / 143 modified, then 260 / 56, then 269 / 63, then 282 / 64, then 282 / 65, then 284 modified
/ 67 new, then 842 / 69, then 848 / 74, then 848 / 77, then 854 / 89, then 854 / 91, then 854 / 93, then 874 modified / 108 new / 2 deleted, then 875 modified / 109 new / 2 deleted, then 875
modified / 110 new / 2 deleted, then 876 modified / 111 new / 2 deleted, then 877 modified / 112 new / 2 deleted: each was
accurate when written
and went stale across the rounds of fixes since. The figures below were re-measured for this revision
rather than incremented by hand, which is how the previous two drifted.

**991 files touched — 877 modified, 112 new, and 2 deleted.** Deletions are a category this
section has not needed before: items 42 and 43 removed the starter kit's
`pages/settings/Appearance.vue` and `components/AppearanceTabs.vue` (30 and 37 lines), because
`settings/appearance` is a redirect to the ERP's own screen now and a second, weaker copy of a
settings page is how two of them drift. Two more paths entered after this section was first
measured this round, and they are why the headline moved again: `UserInfo.vue`, whose prop type had
to stop demanding the starter kit's wider `User` shape so that both user classes satisfy it
(finding 56), and `ShellBindingTest.php`, the contract that now pins it. Item 44 adds a third,
`KitConversionPostingTest.php`, and moves the new-file count from 109 to 110 while the modified count
stays at 875, because all five services it edits were already dirty from earlier rounds — which is
also why the substantive modified figure below does not move. Item 45 adds a fourth,
`SyncRateLimitTest.php`, and the first tracked entrant since item 41: `app/Providers/AppServiceProvider.php`,
an empty stub in the starter kit until this round registered two rate limiters in it. That is the one
path that moves the modified count, 875 to 876, and the substantive figure below moves with it. The count published here
is therefore the one taken after the round's last edit rather than before it. Item 28
normalised formatting across the whole tree, so the total is still not a measure of the audit's work.
It is split accordingly, and the split is measured rather than estimated: a file counts as substantive
if it differed from `f3e014a` before the `pint` run, and as formatting-only if `pint` was the first to
touch it.

**Substantive — 433 files.** 319 modified by items 1–46 — twenty-three more than item 41's 296,
every one of them a real edit and none of them `pint` — plus **112 new** and the **2 deleted**,
measured directly rather than incremented from the previous revision: **30,105** lines across the 112
untracked files, of which items 42 to 46's nineteen new files are **4,686** (`themes.css` 485,
`KitConversionPostingTest` 474, `PrintContractTest` 409, `ThemePaletteTest` 392, `ShellBindingTest` 356, `Settings/Appearance.vue`
320, `print.css` 297, `UiSettingsTest` 289, `UiTheme` 281, `useUiTheme.ts` 270, `SyncRateLimitTest` 229, `SeederNetworkHygieneTest` 191, `usePrint.ts` 164,
`useUiTheme.test.ts` 142, `usePrint.test.ts` 131, `UiSettingsController` 83, `SchemePicker.vue` 78,
the `core_ui_settings` migration 56, `CoreSetting` 39) and this report's own growth from 3,179
lines is **662** more. Item 41's two new
files were **641** of the previous total (`StockLotCacheTest` 513, the lot-merge migration 128), and
item 39's ten new files were **3,036** of that (the day-end test 613, `PosReportService` 438,
`DayEndReport.vue` 374, `PosDayEndPostingService` 373, the collection-report test 352,
`SessionDayEnd.vue` 266, `treeSidebarPolish.test.ts` 257, that migration 154, `ReportAccessTest` 140,
`PosCollection` 69), against **22,927** lines across 89 files.

**The untracked total reconciles exactly, for a fourth revision running.** 24,597 (item 41's close)
+ 4,686 (the nineteen new files) + **822** (the seven previously-untracked files that grew: this
report **662**, `modules/HRM/Config/hrm.php` +21 for the `sync.allow_simulation` block item 46 added, `SharedComponentThemeTest` +130 for its inverted assertions, its comment-aware
scanner and the two censuses it re-measured at item 43, `OptionEndpointAuthorisationTest` +4 and
`MoneyColumnCastTest` +3 for the two re-pinned figures, and `Pos/DayEndReport.vue` and
`Pos/SessionDayEnd.vue` +1 each for the print migration — all six re-checked file by file against the
item-41 tarball for this revision, with 86 of the 93 still byte-identical and the seventh grower
accounted for above) =
**30,105**, with nothing left over. Item 45's reconciliation, which this one supersedes, ran
24,597 + 4,495 + 727 = 29,819, and item 44's ran 24,597 + 4,266 + 599 = 29,462, and item 43's ran 24,597 + 3,792 + 459 = 28,848; item 41's ran 23,652 + 641 + 286 + 18 = 24,597, and item
40's ran 22,927 + 440 + 254 + 30 + 1 = 23,652, that last +1 being the demo seeder's rewritten
comment. The components were measured separately and summed before the total was measured, so the
agreement is a check rather than a construction. The method that closed item 39's unattributed
14-line gap was run again here — every file in the untracked set diffed against the backup manifest
taken at item 41's close — and the other 87 of the 93 previously-untracked files are
byte-identical to that manifest. The report's own **662** includes this paragraph: the figures are a
fixed point, re-measured after the edit that publishes them rather than before it. The
substantive/formatting split is declared historical below and is not re-derivable in general, but *this*
round's increment is: exactly two files entered the modified set — `DEPLOY.md` and
`docs/PURCHASE_GRNI_HARDENING.md` — and neither is a PHP file, so `pint` is not the reason they differ from
`f3e014a`. 290 + 2 = 292, and 292 + 558 = 850, which was the measured modified total at item 38. Item 39 puts four
more files in the modified set — all four substantive source edits, since the only `pint` run this round
fixed three files that were already modified — so 296 + 558 = **854**, and the formatting-only count is
unchanged. Item 40 put **no** file into the modified set: the six tracked files it edited were all already modified,
and its two additions were new, so the modified total stayed **854** while the new count went 89 → 91.
Items 42 and 43 put **twenty-one** files into the modified set and **two** into a deleted set that
did not exist before. The twenty-one are all substantive and all front-end or routing: the eight
accounting report pages and `UiKit.vue` (print buttons and the new colour section), `DataTable`,
`PageHeader`, `StatusBadge`, `EnterpriseStat` and `EnterpriseCard` (the token pass), `app.css`,
`app.blade.php`, `app.ts`, `useAppearance.ts`, `HandleInertiaRequests`, `CoreSeeder`, the CORE
route file, `UiKitController`, `UserMenuContent`, the starter settings layout,
`routes/settings.php`, and — entered after this section's first measurement — `UserInfo.vue`
(finding 56). `AdminLayout` was already in the set, and item 46 added one more — `BiometricDeviceSeeder`, which
had never been touched before. So 296 + 23 = 319 modified, and 319 + 112 + 2
= **433** substantive against 433 + 558 = **991**. The formatting-only 558 is unchanged: `pint`
fixed one style issue this round, in `modules/HRM/Config/hrm.php`, which is untracked and already
counted on the substantive side, so it put nothing into the modified set. Item 38 touched six
existing files in all; the other four (both migrations, `DatabaseSeeder`, `AccountsDemoSeeder`) were already
counted, two of them since item 28's normalisation. Item 32 modified no existing file whatsoever: all three of
its files are new, and they are 1,540 of those lines (the seeder 1,025, the integrity test 468, the command 47).

The new-file line total does **not** reconcile exactly, and the gap is published rather than smoothed over:
18,619 (previous revision) + 556 (item 38's two new files — the separation test 497, the fallback-shift seeder
59) + 152 (this report grew from 2,115 to 2,267 lines) − 1 (`ChartOfAccounts.php`'s rewritten docblock is one
line shorter) = **19,326**, against **19,340** measured with the same command. Fourteen lines are not
attributable to any file touched this turn. The two item-32 files are not the source: they still measure
exactly 1,025 and 468. The likeliest mechanism is this workspace's documented habit of restoring untracked
files between turns, which can leave one of the 77 pre-existing new files longer than it was when the previous
revision measured it; no explanation is asserted beyond that, because none can be checked now. The formatting-only
558 is unchanged across all three rounds: `pint` fixed one style issue at item 43, one at item 44,
one at item 45 and one at item 46, in each case in a file that is counted on the substantive side and never here.

Modified and deleted, by area — re-measured over all 879 tracked changes, which is 877 modified
plus the 2 deletions, and which the previous per-area lists were not: HRM 317, INVENTORY 118,
PAYROLL 101, SALES 80, ACCOUNTS 79, PURCHASE 64, PARTNER 46, CORE 26, `tests/` 17, `resources/` 10
modified plus both deletions, `routes/` 4, `app/` 4, and one each of `database/`,
`bootstrap/app.php`, `docs/PURCHASE_GRNI_HARDENING.md`, `artisan`, `docker-compose.yml`,
`vite.config.ts`, `tsconfig.json`, `package.json`, `package-lock.json`, `.env.example` and
`DEPLOY.md`. That list sums to 879. These are *combined* counts, because the
substantive/formatting per-area split below is historical.
New, by area — re-measured, and it sums to the published 112: `tests/` 41, CORE 29, HRM 15, SALES
9, INVENTORY 3, `resources/` 2, `app/` 2, PURCHASE 2, ACCOUNTS 2, and one each in PAYROLL,
PARTNER, `database/` and `.github/workflows/ci.yml`, plus `pint.json`, `.env.production.example`
and this report. Items 42 and 43 are sixteen of those — ten in CORE (the resolver, the model, the
controller, the migration, the picker, the settings page, the two composables and their two Vitest
files, which live under the module and so count as CORE rather than as `tests/`), four in `tests/`
(`ThemePaletteTest`, `PrintContractTest`, `UiSettingsTest` and `ShellBindingTest`) and two in
`resources/css/`. Item 44 is the seventeenth, `KitConversionPostingTest` in `tests/Feature/INVENTORY`, item 45 the
eighteenth, `SyncRateLimitTest` in `tests/Feature/HRM`, and item 46 the nineteenth,
`SeederNetworkHygieneTest` in `tests/Feature/CORE` — all three under `tests/`, which is why that
area's count is the only one that has moved since item 43. Item 38 is two, one in `tests/` and one in HRM, and item 32 is three, one each
in `tests/`, `database/` and `app/`.

**Formatting only — 558 files**, which `pint` normalised and the audit had no other reason to touch:
HRM 247, PAYROLL 75, INVENTORY 73, ACCOUNTS 42, SALES 37, PURCHASE 36, PARTNER 30, `tests/` 16, and one
each in CORE and `routes/`. That list sums to 558 and is carried forward unchanged. The substantive per-area
list it used to sit beside did **not** sum to its own headline: it added to 284 against a published 290, which
is the modified total of an earlier round showing through a later revision. It is replaced above by the
measured combined breakdown rather than being patched by hand.

**Line volume across tracked files: +25,200 / −9,068**, re-measured with `git diff --numstat` over
the 879 tracked changes — one more than item 45's 878, because `BiometricDeviceSeeder` entered. Items
42 and 43's net contribution is the **+553 / −264** between that revision and item 41's
+24,545 / −8,783, item 44's is the **+37 / −6** between that one and item 43's, and item 45's is the
**+35 / −1** between that one and item 44's, and item 46's is the **+30 / −14** between this one and
item 45's: `AttendanceSyncService` at +23 / −7 against its copy in the item-45 tarball, the two
demo seeders at +5 / −5 and +4 / −4 for the addresses they moved, and `hrm.php` — untracked, so
outside git's figure — at +21 / −0 for the config block and its comment. Those first three are
measured with `diff` against the tarball copy, which counts added blank lines that a `^+[^+]`
pattern silently drops; the first version of this sentence used that pattern and published +19 for
`hrm.php` against a file that had demonstrably grown by 21. Item 45's round was entirely
`AppServiceProvider`, which had never been touched before.

It is also attributed file by file, against two baselines on purpose. A path that was already
dirty at item 41's close is diffed against the copy of it in that round's backup tarball, which
isolates what *this* round wrote to it; a path that entered the dirty set this round has no such
copy, so it is diffed against `f3e014a`, because every line of that difference is this round's.
One caution about the tarball, learned by getting it wrong first: it holds 1,964 entries, not the
947 paths of the manifest it was built from, because `tar` recorded directories and a
`storage/framework` tree that `.gitignore` excludes. Iterating the tarball rather than the
manifest puts compiled Blade caches into the footprint — one appeared here at +32 / −1 before the
list was corrected — and a compiled view is not anybody's work.

The attribution: **12 carried tracked paths at +211 / −114**, **21 entered paths at +345 / −86**,
**2 deletions at −67**, and on the untracked side **5 carried documents at +165 / −26**, plus this report and **16
new files at +3,792**. Restricted to the tracked changes that is **35 paths at +556 / −267**,
against git's **+553 / −264** for the same round: a three-line gap on each side, which is `diff`'s
edit script disagreeing with git's Myers diff about how to pair up moved blocks. The previous
revision recorded the same disagreement at two lines. Git's figure is the one published as the
total, and the attribution is published as the explanation of it rather than as a rival.

Item 44's round is attributed the same way, against the item-43 backup tarball: **five carried tracked
paths at +51 / −23** — `KitService` +29 / −17, `accounts.defaults` +15 / −2 for the two new keys,
`LandedCostService` +5 / −2, and one changed line each in `InvoiceService` and `SalesReturnService` —
with **no entrants, no deletions, and one new untracked file at +474**. Git's cumulative count moved
by **+37 / −6** over the same five paths, and this gap is not `diff` disagreeing with Myers about
pairing but the two measuring different things: most of the seventeen lines `KitService` lost were
lines this audit had itself written — the old block carried the NOTE pointing at P1.20 — so replacing
them moves a cumulative figure against `f3e014a` by less than the edit is long.

Item 45's round is two tracked paths and one new file. `AppServiceProvider` entered at **+35 / −1**,
diffed against `f3e014a` because every line of that difference is this round's, and `web.php` carries
**+7 / −7** against the item-44 tarball — the seven throttle insertions, one line each, replacing the
same seven lines. By direct diff that is **+42 / −8**, while git's cumulative count moved by
**+35 / −1**, and the gap here is neither `diff` nor Myers being wrong: the seven route lines were
already additions in earlier rounds, so rewriting them changes only the `+` side of a difference git
had already counted. The new untracked file is `SyncRateLimitTest` at **+229**.

The largest movers, each of which is a sentence in section 3: `AdminLayout.vue` +98 / −79 for the
header switcher, the letterhead and the `user` binding that had to be restored (finding 56);
`UiKit.vue` +152 / −8 for the colour and print section; `app.blade.php` +38 / −1 for the
pre-paint bootstrap; `useAppearance.ts` +36 / −33 becoming a shim; `app.css` +27 for the utility
mappings; `PageHeader.vue` +22 / −13 and `StatusBadge.vue` +21 / −12 for the token pass;
`UiKitController` +18 for counting what the page used to hardcode; the CORE route file +14;
`EnterpriseCard` +12 / −4 and `EnterpriseStat` +11 / −4; `app.ts` +11 / −3 for installing the
print hook once; `PaySlips/Show.vue` +10 / −10, `Receipt.vue` +10 / −2 and `UserMenuContent` +10
/ −4; `BarcodePrint.vue` +10 / −5; `UserInfo.vue` +8 / −3; `HandleInertiaRequests` +7 for sharing
the resolved theme; `menu.ts` +5 / −1 for the settings entry; `LeaveRequests/Show.vue` +4 / −3;
`CoreSeeder` +3 for the permission; `DataTable.vue` +6 / −1 for `printDocument()`; the seven
accounting report pages that entered the set at +2 / −1 each, and `CashFlow.vue` at +2 / −1 for
the same migration — it is the eighth, and it lands on the carried side only because it was
already dirty at item 41's close; and `TreeSidebar.vue`
+1 / −1, a single line, which is the whole of the sidebar's token pass because its plate was one
line long. Item 41's net contribution was the **+47 / −3** between that and item 40's +24,498 /
−8,780, across three tracked files: `StockService`, where the cache key lost a component and the
consumption order gained one, and `IssueStockAction` and `InventoryStockProvider`, which now
forward the lot and serial they were already handed. Item 40's net contribution is the **+78 / −7** between this and item 39's +24,420 / −8,773,
across six tracked files: `PayrollPaymentPostingService` (42 lines to 74, most of the growth being the
docblock that says why a swallow is a policy decision it no longer gets to make), `MarkPayrollSlipPaymentAction`,
`PayrollJournalService`, `PayrollSlip`, `PayrollJournalPost`, and a comment in the historical migration
`2026_11_03_000073` whose index this item supersedes — its DDL is byte-identical. Item 38's net contribution
was the +31 / −22 between item 39's +24,420 / −8,773 and the +23,151 / −8,598 before it: comment blocks replacing data writes in two migrations, one seeder wired into
`DatabaseSeeder`, one chart-of-accounts entry carrying the semantics its migration used to set, and two
documents corrected. The step before that published +22,879 / −8,534, and that difference is **not** item 32, which
touched no tracked file: it is the recovery round, in which four files lost to this workspace's habit of
dropping untracked and reverted content were rewritten from context and are not byte-identical to the versions
that were measured then. The split of that volume into formatting-only (+13,024 / −4,732) and substantive
(+9,855 / −3,802) is carried forward from the round that derived it and is **not re-derivable now**: it was
computed by comparing each file against `f3e014a` as it stood *before* the `pint` run, and that intermediate
state no longer exists. The two sub-figures should be read as historical, the total as current. The 284 substantive
files of that earlier split are not purely substantive:
`pint` normalised them too, and separating the two *inside* a file would need a diff-of-diffs less
trustworthy than this sentence.

*New cross-cutting primitives:* `modules/CORE/Support/SqlDialect.php` ·
`modules/CORE/Support/Concerns/CoercesNullNumerics.php` · `modules/ACCOUNTS/Support/ChartOfAccounts.php` ·
`modules/HRM/Services/Attendance/OffDayResolver.php` · `modules/HRM/Config/hrm.php` ·
`modules/CORE/Support/OptionAccess.php` · `modules/CORE/Support/PublishedCredentialGuard.php` · `modules/CORE/Support/ProductionDebugGuard.php` ·
`modules/ACCOUNTS/Support/JournalBalance.php` ·
`app/Http/Middleware/SecurityHeaders.php` · `.github/workflows/ci.yml`.

*New shared front-end components (12):* the form primitives `FormField`, `FormSection`, `FormActions`,
`FormSelect`, `FormCheckbox`, `DetailItem` · the document forms `PartnerForm`, `ProductForm`,
`VendorBillForm`, `InvoiceForm`, `SalesOrderForm`, `QuotationForm` · plus `Pagination.vue`, the
rewritten `SearchableSelect.vue` and `modules/CORE/Resources/js/Utils/optionsPayload.ts`.

*New backend classes:* the six HRM controllers `EmployeeDocumentController`,
`EmployeeAssetController`, `EmployeeProbationController`, `EmployeeMovementController`,
`ChecklistController`, `ClearanceController` · `modules/HRM/Services/ProbationService.php` ·
`modules/PURCHASE/Http/Controllers/OptionsController.php` · Vue report pages
`Reports/{Headcount,Attrition,Leave,Attendance,Ot}.vue` and `LeaveYearEnd/EncashPreview.vue`.

*New tests (17 files):* `ACCOUNTS/ChartOfAccountsGuardTest` · `ACCOUNTS/JournalDraftGuardTest` ·
`DocumentPageRenderTest` · `HRM/OffDayClassificationTest` · `INVENTORY/ProductFormContractTest` ·
`LineItemCoercionTest` · `PURCHASE/VendorBillContractTest` · `PURCHASE/VendorBillVoidTest` ·
`RouteIntegrityTest` · `SALES/InvoiceContractTest` · `SALES/PosTerminalTest` · `SALES/PosVoidTest` ·
`SALES/QuotationContractTest` · `SALES/SalesOrderContractTest` · `SecurityHeadersTest` ·
`SelectorEndpointIntegrityTest` · `SilentFailureInventoryTest`.

---

## 7. Recommended sequence from here

1. **Close the silent-failure pattern** (P1.1) — **now done**, across P1.14, P1.17, P1.18 and P1.19:
   every financially consequential swallow rethrows or logs, and every silent account skip refuses the
   posting. What is left of P1.1 is 11 tolerated empty catches in HRM attendance, allowlisted with
   reasons and enforced by `SilentFailureInventoryTest`. The remaining work here is policy, not code:
   decide whether posting should be switchable into an advisory mode (`accounts.posting.require_accounts`)
   for deployments still building their chart, rather than the present all-or-nothing `app()->bound()`
   test. If that switch is added, `ChartOfAccounts` is where it belongs.
2. **Add the missing domain tests** (P3.1) — **done** as items 23 and 24: 25 tests over journal
   balancing, the ledger's running balance, voiding and the fiscal-year lock, and 17 over stock
   movements, weighted-average and FIFO valuation, overselling and reservations. They found five
   live defects in ACCOUNTS, all fixed, and one latent trap in INVENTORY, recorded as item 25. The
   float→decimal migration (P1.2) now has the net this item asked for. The one thing it does not
   cover is concurrent writers — see item 24's stated limitation.
3. **Float → decimal** for money (P1.2), one module at a time — **phase 1 done** as item 26: the model
   layer is complete and enforced, all 242 money columns declared `decimal:N` at their migration's own
   scale, and the one float-in-raw-SQL aggregate replaced — which turned out to drop five cents on a
large enough total, and had never been executed by a test before `PosSessionAggregateTest`. What is
left is the PHP arithmetic in
   ACCOUNTS (32 `(float)` sites). It is deferred rather than skipped, and item 26 records why: the cast
   formats on GET only, so no stored-cent defect has been demonstrated, and this report will not claim
   one. Doing it next means a `Money`/`BigDecimal` value object, not more casts.
4. **Add the production credential guard** (P1.4) — **done** as item 22: the published super-admin and
   demo passwords are now refused both when a seeder tries to write them and when a login tries to use
   them under `APP_ENV=production`. The deployment hygiene that was left is **done** as item 27:
   `docker-compose.yml` takes both database passwords from the environment and refuses to start
   without them, publishes no database port, connects the app to the MySQL it starts as a non-root
   user, and waits for it to accept connections; `ProductionDebugGuard` forces `APP_DEBUG` off under
   `APP_ENV=production` and logs that it did. What remains is not something this audit can honestly
   produce: the compose file's `build: .` has no `Dockerfile`, and writing one without a docker binary
   to build it with would be a guess. It is pinned by a characterisation test in item 27 so the gap
   cannot go stale.
5. **Normalise formatting** with `pint` as a standalone commit, then make it blocking in CI — **done**
   as item 28: `pint.json` added, all 865 PHP files normalised (662 diverged), `continue-on-error`
   deleted from the workflow's Pint step, and `CiWorkflowContractTest` pinning the workflow's
   load-bearing properties. The "standalone commit" half could not be honoured literally — nothing in
   this engagement is committed — so item 28 reports the formatting diff as its own figure instead.
6. **Replace native dialogs** with `ConfirmDialog` (P2.1) and fix the shared-component dark-mode classes first (P2.2) — `DataTable.vue` and `PageHeader.vue` give the widest return. **Done in
   reverse order, because the ordering premise was false.** Item 30 shows `DataTable.vue` and
   `PageHeader.vue` have no dark-mode defect to fix, so the dialogs became the whole of the actionable
   work: item 29 migrates 30 of the 77 calls onto a new `useConfirm` store and ratchets the remaining
   47. Both halves are pinned by tests rather than left as prose. Remaining: the 46 calls, the 144
   page-level palette candidates, and the saturated light tints no scanner measures.
7. **Seed realistic demo data** (P3) so UAT and screenshots aren't against empty states — **done** as item 32:
   an opt-in `erp:demo-data` seeder, 1,025 lines, which drives the real services through one coherent month for
   one department and takes the database from **38 populated / 129 empty tables to 98 / 69** in 10.8 s, with 9
   integrity tests asserting invariants (balanced journals, a ledger that mirrors them, a stock cache that agrees
   with its movements, slip and sheet arithmetic, idempotence, a production guard) rather than row counts alone.
   It also corrects two figures this report had published — 167 tables, not 168; 129 empty, not 132 — and it
   found five defects on the way, items 33 to 37, of which **35 is P1**: only the first slip of a salary sheet
   ever reaches the general ledger, because a unique index meant for idempotence collides with per-slip posting
   and the caller swallows the exception into a log line. None of the five is fixed, and each entry says why.
8. **Extract the DML from migration `2026_11_03_000075`** into a seeder — the root cause of P0-B —
   **done** as item 38, and the item turned out to be smaller and larger than its title. Smaller: the
   account it names is created by `AccountsDemoSeeder`, which already listed 2115 among its 34 children,
   so the migration's write was redundant and deleting it lost nothing. Larger: a token scan of all **89**
   migrations found a second one provisioning content — `2026_10_30_110000` inserts the `FLEXI / Ad-Hoc`
   fallback shift that attendance classification depends on — plus two legitimate data writes (a column
   backfill in `up()`, an enum conversion in `down()`) that are allowlisted by name with a reason rather
   than waved through. Both provisioning writes are extracted, into a seeder that already existed and a
   new one wired into `DatabaseSeeder`; a fresh `migrate:fresh --seed` holds the same 35 accounts and the
   same single fallback shift with byte-identical values. The extraction was nearly a regression and the
   test is what caught it: the migration's row set `is_system`, the seeder's did not, and `is_system`
   defaults to 0, so a naive move would have made the GRNI account deletable from the chart screen. Nine
   tests pin the policy, the two allowlists, the preserved row semantics, and the loud refusal — with the
   stock rollback that proves a refused posting leaves nothing behind. Two findings come out of it: **39**
   (22 of 39 seeder classes are never called, including one that duplicates the extracted shift) and
   **41** (the allowed backfill swallows its own failure). Neither is fixed; both entries say why.

9. **Post the POS day-end to the ledger, allow credit at the till, make the trial balance visible, give a
   day-end report, and fix the sidebar** — five sentences of user report rather than an item from this
   list, **done** as item 39, and four of the five were defects. The till created and posted an invoice
   per counter sale while `closeSession()` posted nothing at all, so a day's takings had no single
   posting to reconcile against and — worse — `JournalService::syncPayableReceivable()` raised an open
   receivable for every *cash* sale that named the counter customer, filling AR aging with settled
   sales. Now one balanced journal per session, eight legs, zero legs omitted, `partnerId` null so the
   day cannot raise a receivable of its own; credit sales with a `pos_collections` row per collection,
   credit control that runs before the stock moves, and per-customer terms; a day-end and collection
   report whose cash figures distinguish sale tender from counter collections.
   The trial balance was invisible for **two independent reasons**, and fixing either alone would have
   left the complaint true: `accounts.reports.view` was the Super Admin's alone, and the sidebar could
   not draw the menu's third level, so ten report items were dead `#` links for everybody. Both fixed,
   both pinned — by `ReportAccessTest` and by a reachability test that asserts every leaf in the config
   appears in the DOM. The sidebar itself had four causes (all sections open on first load, module-build
   ordering, an advertising footer, a `⌘K` with no handler) plus a fade that made the last row look
   disabled. Four findings come out of the round — **42**, **43**, **44**, **45** — none fixed, each entry
   saying why. `vue-tsc` is unchanged at 562 whole-tree errors, and the round's own six were fixed
   rather than added to it.
10. **Close finding 35 — the payroll payment posting** — the highest-value open P1, and the finding this report
   had cited three times without being able to fix, **done** as item 40. `payroll_journal_posts` keyed
   idempotence on `(salary_sheet_id, posting_type)` while `postSalaryPayment()` wrote one row per slip, so the
   second slip of any sheet violated the constraint, the transaction rolled its journal back, and two
   `catch (\Throwable) { Log::… }` layers meant the slip simply looked paid — 14 paid slips and **1** payment
   journal in the demo dataset. The key now carries a NOT NULL `posting_scope`, the posting service rethrows,
   the payment action records the reason on the slip instead of only logging it, and the same dataset measures
   14 payment journals whose credits equal the sheet's net pay to the cent, with **2121 Salary Payable at
   0.00**. The rejected alternative is recorded in the section, because it is the interesting half: a nullable
   slip id inside the unique index would have silently cost the accruals their idempotence, since SQLite and
   MySQL both treat NULLs as distinct.
11. **Fix the lot-keyed stock cache** (P1.25) — the defect item 24's safety net found and deliberately
   left alone, because the right fix was a decision about the data model rather than a patch, **done**
   as item 41 with all four forks put to the user first. `move()` keyed `inventory_stocks` on `lot_no`
   while only `ReceiveStockAction` ever forwarded one, so quantity received against a lot was refused
   with "On-hand: 0" by every issue path; and a lot typed on a delivery was validated, stored on the
   delivery item, then dropped by the three paths that build a `StockMoveData` without one. The cache is
   now an aggregate per product/variant/warehouse/location — which is what its seven other readers
   already assumed — lot identity stays in `inventory_lots` and `fifo_layers`, a named lot is consumed
   ahead of older layers and costs the movement at the layer that shipped, a lot that holds nothing is
   refused even where negative stock is allowed, and a merge migration folds any lot-keyed row a
   deployment already has into the aggregate instead of stranding it. The suite's only skipped test now
   runs, and the characterisation test that pinned the drop was inverted with its reasoning kept. Three
   defects found in the same method come out of the round — **47**, **48**, **49** — each pinned by a
   characterisation test rather than a skip, each saying which decision it needs before it can be fixed.
12. **Give the application a colour system** (P2.2, P2.3) — **done** as item 42, with all four scope
   forks put to the user first: curated palettes rather than a colour picker, a company default plus a
   per-user override rather than one or the other, a full component overhaul rather than a repaint of
   the shell, and no logo or letterhead upload. Eight palettes, each a complete light and dark set of
   twenty tokens, live in one stylesheet; the semantic status colours are defined per mode and never
   per palette, so a redecorating administrator cannot change what green means on a payroll exception
   report; the resolved theme reaches `<html>` before the first frame through a nonce'd bootstrap,
   because the CSP this project ships has no `'unsafe-inline'` and an un-nonced script is a script that
   never runs. Four shared components were tokenised and the two assertions that *demanded* their
   hardcoded colours were inverted. The round also produced finding 53 by breaking the suite and
   reading the failure: a type hint on the module's `User` made every Inertia response a 500 for the
   starter kit's parent class.
13. **Make printing work** (P2.4, and the blank sheets nobody had reported) — **done** as item 43,
   scoped by the user to every existing Print button with a safe fallback for unmarked pages, and
   explicitly not to transactional-document stationery or server-side PDF. The defect had two
   independent causes, and fixing either alone would have looked like a fix: the shell hid `body *` and
   revealed an attribute one component set, and two pages had answered by hiding `body *` themselves in
   unscoped styles that outlive their page in an SPA. One stylesheet owns paper now, isolation is opt-in
   with a structural fallback, one composable is the only caller of `window.print()`, and the tokens are
   reset to light-on-white for paper whatever the screen says. What CSS cannot do is published rather
   than papered over: `@page` margin boxes are unimplemented in Chrome and Firefox, so page numbers come
   from the browser's headers-and-footers option, and the colophon says so on the sheet.

---

## 8. Premium form system & full-width layout pass

Requested as *"check all pages where the form is not full width, it's looking very bad, need
redesign with premium ui… especially Employee CRUD, journal entry"*.

### 8.1 The diagnosis

`AdminLayout.vue` was **not** the constraint — its content wrapper is already
`mx-auto w-full max-w-[1500px]`. The problem was that **110 of the 225 `Pages/*.vue`** carried
their *own* narrow `max-w-(xs…5xl) mx-auto` wrapper, so forms floated centred in a 1500 px
canvas with dead space either side, and their grids were capped at `sm:grid-cols-2/3` — two
columns of inputs stretched across a third of the screen.

Three further defects surfaced while reading those pages:

| Defect | Where | Effect |
|---|---|---|
| Title rendered twice | **38 pages** | `PageHeader` *and* an inner `<h1>` with the same text |
| Literal `{{ }}` inside an attribute | `Employees/Show.vue` | `description="{{ employee.employee_id }} • …"` printed the braces as text |
| Placeholder title | `Employees/Show.vue` | Header read **"Pages Detail"** for every employee |
| Journal create/edit returned a 500 | `JournalRepository` | See P1.9 — `null` on the unused side vs a `NOT NULL` column |

### 8.2 New shared primitives (`modules/CORE/Resources/js/Components/`)

| Component | What it fixes |
|---|---|
| `FormSection.vue` | One card style for every section: icon in a tinted square, title, description, badge, `actions`/`footer` slots, optional collapse. `cols` **ramps density by breakpoint** (1→1, 2→+md, 3→+md/xl, 4→+sm/xl/2xl) and it **never sets its own max-width** |
| `FormField.vue` | Label + required marker + `help` + inline `role="alert"` error, and a `useId()`-generated `id`/`aria-describedby` handed to a **scoped slot** — so inputs finally get real labels and real error text |
| `FormActions.vue` | A single `sticky bottom-4` action bar: `backdrop-blur`, summary slot, cancel `Link`, spinner + auto-disable while processing, amber "Unsaved changes" pill |
| `FormSelect.vue` | There is **no `select` primitive** in the ui kit, so every page hand-rolled `<select class="h-9 …">` next to `h-10` inputs. This matches `Input` exactly, adds the chevron, a placeholder option and the destructive border |
| `FormCheckbox.vue` | Replaces bare `<input type="checkbox" class="rounded">` with a `defineModel<boolean>()` over the radix primitive, rendered as a selectable bordered row with a description |
| `DetailItem.vue` | Labelled value for read-only pages: booleans → Yes/No, nullish → em dash, optional `mono`/`tone`/`prefix`/`href`, `span` for wide values |

`PageHeader.vue` gained a named **`#badge`** slot in both its dark-glass and simple variants
(backwards compatible — existing call sites are untouched).

### 8.3 Reference rewrites

**`Employees/Create.vue`** — kept the exact `useForm` shape, `route('hrm.employees.store')`,
`forceFormData: true` and the `tabForError()` contract. Added a sticky tab bar with per-tab
icons and a **destructive badge on any tab carrying errors**, an error summary with a count,
8 `FormSection`s at 3–4 column density, every field now wired to its own error (previously
`last_name`, `email`, `phone`, `nid`, `department_id`, `designation_id`, `joining_date`,
`date_of_birth` and `photo` showed **none**), a photo dropzone with live preview, bank fields
that disable unless `payment_method === 'bank'`, and a sticky action bar.

**`Employees/Edit.vue`** — generated from the same design with the prefill and
`_method: PUT` contract preserved; repeater rows keep their `id`.

**`Employees/Show.vue`** — the two header bugs above are fixed; 10 collapsed accordions
became an always-open two-column section grid, a hero card with contact chips and a
biometric-mapping indicator, a current-salary tile, semantic tokens instead of hardcoded
`bg-slate-500 text-white` / inline hex, and a related-records grid.

**`Journals/Create.vue`** — no longer nests the entire form inside `PageHeader`. Voucher type
became an accessible **radio group** of six chips with arrow-key navigation (the old `<select>`
omitted `SV`, which the backend accepts). Lines use a 4-column ledger grid with `xl:sr-only`
labels under a real column-heading strip, per-line DR/CR chips, per-field errors keyed to
`lines.N.account_id` from `StoreJournalRequest`, `Ctrl+Enter` to append, and the request's
`min:2 / max:50` now surfaced as a `N / 50` badge with the buttons disabled at the bounds.
A **"Post difference"** helper appears when out of balance — deliberately disabled when the
last line already uses the opposing side, since a line carries Dr *or* Cr.

**`Journals/Edit.vue`** — same design, plus a draft lock: a non-draft journal explains why it
is locked and wraps every control in `<fieldset disabled>` rather than letting the user edit
and discover the refusal on submit. (The server already refused — see the correction at P1.11 —
but it said so only after the work was done.)

### 8.4 The width pass

Rather than one blind regex (which would have stretched two-field forms across 1500 px),
pages were classified by control count and by whether they contain a line-item repeater:

| Tier | Rule | Action | Pages |
|---|---|---|---|
| A | form with ≥8 controls **or** a repeater/`PermissionGrid` | drop the wrapper cap entirely | 36 |
| B | form with 5–7 controls | raise the cap one or two steps | 18 |
| B′ | form with ≤4 controls | raise only the tightest caps | 14 |
| C | `Show.vue` detail view | drop the cap | 26 |
| DENY | filter panels, printed receipt, dashboard, dev UI kit | left alone | 7 |

Grid density was then raised **additively only** — `sm:grid-cols-2` → `+ xl:grid-cols-3`,
`sm:grid-cols-3` → `+ 2xl:grid-cols-4` — so no breakpoint ever lost columns. Intentionally
narrow surfaces were protected: `Reports/Trial` (`max-w-sm`), `Reports/Ledger` (`max-w-md`),
`Pos/Receipt` (`max-w-md`), `Attendance/Fix`, `Reopens/Index` (`max-w-xs`), `Dashboard`,
`UiKit`.

*Verification:* ESLint `--max-warnings=0` clean across `modules/` and `resources/`, `vite build`
green, Vitest 203 passed (the 6 new components are picked up automatically by
`componentResolution.test.ts`), and only `Pos/Receipt.vue` still carries a narrow cap — by design.

### 8.5 Deduplicating the partner editor

`Customers/{Create,Edit}.vue` and `Vendors/{Create,Edit}.vue` were four hand-maintained copies
of the same editor over the same `partners` table — 426 lines between them, with different
formatting and different error handling (only `Customers/Create` jumped to the tab holding the
error). They are now one shared `modules/PARTNER/Resources/js/Components/PartnerForm.vue` plus
four ~31-line wrappers that supply `mode`, `partner`, `submitUrl` and `entityLabel`.

Preserved: the `useForm` field set, every route, `partner_code` auto-generation, and the
tab-jump-on-error behaviour (now on all four pages instead of one). Added: per-tab error badges,
errors keyed to `contacts.N.*` / `addresses.N.*` / `banks.N.*` (previously none of the repeater
fields displayed one), `FormSelect`/`FormCheckbox`, and a `promote()` helper — three separate
"primary"/"default" checkboxes previously let you tick two rows at once, which the schema
treats as an exclusive flag.

### 8.6 What reading these pages surfaced in the backend

Redesigning the journal entry form meant reading `StoreJournalRequest`, `JournalService` and
`JournalRepository` closely, which produced three findings now recorded as **P1.9** (every
journal create and draft edit returned a 500 — fixed, with tests), **P1.10** (`update()` skips
the fiscal-period check) and **P1.11** (a correction: an earlier draft of this report wrongly
claimed posted journals were editable; the guard was in the repository all along, just
untested). New file: `tests/Feature/ACCOUNTS/JournalDraftGuardTest.php` — 7 tests / 39
assertions, sensitivity-proven by unwiring the fix (3 failures).

### 8.7 Deduplicating the product editor

`Products/Create.vue` (145 lines) and `Products/Edit.vue` (68 lines) were separate copies that
had drifted: Create's tab bar had icons and Edit's did not, Create re-seeded a blank repeater row
when the last one was removed and Edit did not, Create jumped to the tab holding a validation
error and Edit did not, and Edit hardcoded `currency: 'BDT'` instead of reading the product.

They are now one shared `modules/INVENTORY/Resources/js/Components/ProductForm.vue` plus two
wrappers (23 and 36 lines). Added on top of parity: per-tab error badges, per-field errors keyed
to `variants.N.*` / `suppliers.N.*`, an image dropzone with a live preview (product images were
stored but **never rendered anywhere** — no `image_url` accessor and no `/storage/` reference
existed in the module), a computed gross-margin indicator on the pricing tab, exclusive
"primary supplier" promotion, and `FormSelect`/`FormCheckbox` throughout.

Edit's `form.post(url, { headers: { 'X-HTTP-Method-Override': 'PUT' } })` was preserved rather
than "cleaned up" to a `_method` field, after confirming Symfony's `Request::getMethod()` does
honour that header for a POST — a multipart body cannot travel on a real PUT, which is presumably
why it was written that way. `ProductFormContractTest` now pins the behaviour.


### 8.8 Deduplicating the vendor bill and invoice editors

`Bills/Create.vue` (68 lines) and `Bills/Edit.vue` (65) were four template lines apart and shared
three omissions: **no Discount % input at all**, even though `VendorBillRequest` validates
`items.*.discount_pct` and `enrichTotals()` computes with it; no per-field errors on the item
rows; and no totals breakdown — a single number in a heading.

`Invoices/Edit.vue` was worse: a 14-line minified stub rendering only Customer, Product and Qty.
Invoice date, warehouse, due date, unit price, discount and VAT were all in the form state and
*submitted*, but had no input, so they could never be changed. There was no way to add or remove a
line, nothing displayed validation errors, and `props.invoice.invoice_date.slice(0, 10)` throws
outright when the date is null.

`Invoices/Create.vue` seeded its customer from `(globalThis as any).__orderCustomer` — a global
that **nothing in the codebase ever assigns** (grep returns exactly one hit: the reader). Yet
`OrderController::createInvoice()` redirects to `sales.invoices.create?order_id=…`, and
`InvoiceController::create()` passes the order with `customer`, `warehouse` and `items.product`
eager-loaded — every field a real prefill needs was already on the prop. "Invoice this order"
therefore opened a **blank customer, blank warehouse and blank lines** every time.

They are now `modules/PURCHASE/Resources/js/Components/VendorBillForm.vue` and
`modules/SALES/Resources/js/Components/InvoiceForm.vue`, with the four pages as 19–20 line
wrappers (**197 → 77 lines**). Added on top of parity: real order/GRN prefill from the props that
were already being sent; Discount % and VAT % per line with a live line total; a
subtotal/discount/VAT/total breakdown; an `xl`-only column heading strip with `xl:sr-only` labels
so the fields stay named on narrow screens; posted-document locking — both repositories refuse a
non-draft edit (`Only draft can be edited.` / `Only draft invoices can be edited.`) and that is
now surfaced up front rather than discovered on submit; and a client-side
`due_date >= invoice_date` check matching the `after_or_equal:invoice_date` rule, which also
disables submit.

### 8.9 Page-render smoke tests

Nothing in the suite asserted that a page renders with the props its form reads — which is how
`Invoices/Edit.vue` came to be a 14-line stub exposing three of its eight fields, and
`Invoices/Create.vue` came to read a `globalThis` variable nothing ever assigned.
`tests/Feature/DocumentPageRenderTest.php` (6 tests / 100 assertions) pins the controller → page
contract for all four consolidated documents: the component that resolves, `whereNull('order')` on
a bare create, and — the regression test for the dead global — that
`sales.invoices.create?order_id=…` really is given `order.customer_id`, `order.warehouse_id` and
`order.items.0`. The bill edit case also writes a line with explicit null discounts and asserts
the form re-opens on `0` rather than an empty box, tying §8.8 to P1.13.

Two pages that looked like the same defect are not, and were left alone:
`SALES/Deliveries/Edit.vue` and `SALES/Returns/Edit.vue` report **0 lines** to `wc -l` only
because they are single-line files with no trailing newline (390 and 368 bytes), and
`Deliveries/Edit.vue` is a deliberate placeholder — *"Edit not needed — Ship/Cancel from Show
page. Draft can be deleted."* A `wc -l` of 0 is a formatting artefact, not an empty file;
`find -empty` is the check that means what it says.

`SALES::Orders` (49/15) and `SALES::Quotations` (53/15) have since been consolidated too — see
§8.10, §8.11 and P1.16. **No 14-line Edit stubs remain.** Remaining candidates are drifted full
copies rather than stubs, so consolidation there is consistency rather than repair:
`PURCHASE::Orders` (61/57), `PURCHASE::Returns` (61/59), `PURCHASE::Receipts` (59/57),
`SALES::Deliveries` (43/placeholder), `SALES::Returns` (43/placeholder). Their backend line handling is already covered by P1.13, and the
source-contract test in P1.16 now guards all of them against dropping a priced line field.

### 8.10 Deduplicating the sales order editor

`Orders/Create.vue` (49 lines) was reasonably complete; `Orders/Edit.vue` (15) was the stub behind
P1.16. They are now one `modules/SALES/Resources/js/Components/SalesOrderForm.vue` plus wrappers
of 16 and 19 lines.

Added on top of parity: Discount % and VAT % per line with a live line total; the
subtotal/discount/VAT/total breakdown; a `delivery_date` pre-check against the
`after_or_equal:order_date` rule, wired to the field, the alert *and* the submit button;
`shipping_address` and `quotation_id`, which `StoreOrderRequest` already accepted but neither page
ever sent; an xl-only column heading strip with `xl:sr-only` labels; and draft locking —
`OrderRepository::update()` refuses a non-draft with `Only draft orders can be edited.`, which the
page now states up front rather than letting the user discover it on submit.

`DocumentPageRenderTest` gained two cases for these pages, asserting the edit page really is given
`warehouse_id`, `status`, `items.0.discount_pct` and `items.0.vat_rate` — the props whose absence
made the old stub lose money.

### 8.11 Deduplicating the quotation editor

`Quotations/Create.vue` (53 lines) was the most complete of the stub family — it already had
discount and VAT inputs and a totals computed — while `Quotations/Edit.vue` (15) had no totals, no
currency field, no error display and no way to add or remove a line, and threw on
`quotation_date.slice(0, 10)` when the date was null. They are now one
`modules/SALES/Resources/js/Components/QuotationForm.vue` plus wrappers of 16 and 19 lines.

The interesting find is `terms`. `StoreQuotationRequest` validates it, `Quotation::$fillable` lists
it, and `QuotationRepository` writes whatever survives `validated()` — the backend supported it end
to end. **No page ever rendered a field for it**, so in the life of the application a quotation's
terms and conditions had never once been saved. That is a distinct failure mode from P1.12's
variants tab, where the request lacked the rule and the edit was discarded on the way in: here every
layer was ready and only the UI was missing. `QuotationContractTest` now pins that `terms` and
`currency` survive both create and update.

Also added: an expiry pre-check matching the `after:quotation_date` rule — *strictly* after, unlike
the invoice's `after_or_equal:due_date`, which is exactly the kind of difference a copied form gets
wrong. `DocumentPageRenderTest` asserts the edit page really is given `currency`, `terms` and each
line's `discount_pct`/`vat_rate`.
