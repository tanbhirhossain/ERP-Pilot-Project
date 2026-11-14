# ERP Pilot — Enterprise Readiness & UI Gap Audit
**Date:** 2026-11-08 · **Auditor:** Arena Agent (independent full-project verification) · **Commit:** `f8546a3` (master)
**Method:** Every claim below was verified against the live code — not taken from prior reports. PHP 8.4 + Composer installed in sandbox; `migrate:fresh --seed` executed; builds, linters, unit tests and critical-path feature tests run.

---

## 1. Executive Summary

**This is no longer a pilot scaffold.** The project has been through ~46 hardening rounds (see `AUDIT_REPORT.md`) and the core is genuinely strong: double-entry ledger with fiscal locking, GRNI accrual, retained-earnings year-end close, bank reconciliation, AR/AP aging, permission-gated routes (552), deny-by-default option endpoints, CSP nonce security headers, production credential guards, and a CI matrix (PHP 8.3/8.4 × SQLite/MySQL).

**Verdict: ~70% enterprise-ready.** The accounting spine and security posture are production-grade. What separates it from a sellable enterprise ERP is: **(a)** missing cross-cutting enterprise features (notifications, approval matrix, ESS, multi-branch, 2FA, API), **(b)** a UI that is premium in the shell but inconsistent across 229 pages, with **536 TypeScript errors** hidden by a missing CI gate, and **(c)** go-live tooling (opening balances, imports, backups, Dockerfile, demo data).

### Maturity Scorecard

| Domain | Score | Notes |
|---|---|---|
| Accounting core (GL, fiscal, GRNI, close, bank rec) | ★★★★★ 9/10 | Float arithmetic in service layer is the remaining flaw |
| Security (ACL, headers, guards, rate limits) | ★★★★☆ 8/10 | No 2FA, weak password policy. SSO ✅ 2026-11-10 (OIDC + provisioning + link manager) — `docs/PHASE3_IMPLEMENTATION_2026-11-08.md` §11 |
| Sales / POS / Purchase / Inventory flows | ★★★★☆ 7.5/10 | E2E verified; price lists & credit control partially dead |
| HRM / Payroll | ★★★★☆ 7.5/10 | Deep features + finance bridge; no ESS |
| Architecture & code quality | ★★★★☆ 8/10 | SOLID modular monolith; relative-import sprawl |
| Testing & CI | ★★★★☆ 8/10 | Strong Pest suite; **vue-tsc absent from CI** |
| UI consistency & premium feel | ★★★☆☆ 6/10 | Shell is premium; 101 pages ad-hoc; no charts lib |
| Enterprise features (workflow, notifications, multi-org) | ★★☆☆☆ 4/10 | Biggest functional gap |
| DevOps / go-live tooling | ★★★☆☆ 5.5/10 | No Dockerfile, backups, observability, imports |
| Localization (BD market) | ★★☆☆☆ 4/10 | Mushak 6.3 ✓; no Bengali, no TDS/VDS, no VAT return |

---

## 2. Verification Results (run in this audit)

| Check | Result |
|---|---|
| `composer install` + `migrate:fresh --seed` | ✅ 94 migrations, 170 tables, 552 routes, 179 permissions, 5 roles, 35 COA, 998 employees, 22 partners, 16 products (all stocked) |
| `npm run build` | ✅ 52.7s, vendor chunks split, no errors |
| `npm run test:unit` (Vitest) | ✅ **254 passed** (11 files) |
| `npx eslint . --max-warnings=0` | ✅ 0 problems |
| `vendor/bin/pint --test` | ✅ 896 files pass |
| `composer audit` | ✅ 0 advisories |
| `npm audit --omit=dev` | ⚠️ 4 (1 low, 3 moderate) — all in `vue-tsc`/`@vue/language-core` dev chain |
| Pest critical paths (E2E purchase+sales, ACCOUNTS domain, security headers, route integrity, option authz) | ✅ **85 passed, 484 assertions** |
| Full Pest suite | ️ Not completed in sandbox (CPU-bound); CI documents ~589 green — trust CI, re-run on deploy |
| **`vue-tsc --noEmit`** | ❌ **536 type errors** (HRM 110, SALES 101, INVENTORY 87, PURCHASE 51, PARTNER 50, PAYROLL 41, CORE 39, ACCOUNTS 38, starter 19) |
| Fresh-seed financial demo | ❌ **0 journals, 0 invoices, 0 ledger lines** — every financial report renders empty on first login |

---

## 3. What Is Already Enterprise-Grade (do not rebuild)

- **Ledger:** immutable `acc_account_ledger`, fiscal-period gate (`whereDate`, driver-safe), journal void/reversal, GRNI 2115 accrual → bill reversal, retained-earnings close (3200) with audit trail, POS day-end posting, payroll accrual/payment bridge with cost-center split + BEFTN advice.
- **Controls:** `PermissionMiddleware` on every route; `OptionAccess` deny-by-default gate on all 38 typeahead types (154 assertions); Spatie roles (Super Admin, Administrator, Manager, Auditor, Staff); activity log on 61 models; `lockForUpdate` in 43 files; DB transactions in 83 service/repo files; 242 money columns cast `decimal:N`.
- **Security:** CSP with Vite nonce, HSTS, frame/referrer/permissions policies; `ProductionDebugGuard`; `PublishedCredentialGuard` (blocks `password` in prod); registration off; login throttling; HRM device-sync/bulk rate limiters.
- **Flows verified by tests:** PO→GRN→Bill→Pay (with void + GRNI), QT→SO→Delivery→INV→Return, POS sell/void/split/day-end, kits/BOM conversion, FIFO layers, reorder/ABC, lots/expiry, landed costs, bank rec import/match, Mushak 6.3 PDF, barcode labels.
- **UI shell:** AdminLayout (glass header, progress bar, print isolation), TreeSidebar (3-level, permission-filtered, search), CommandPalette (⌘K), theme engine (server-persisted palettes + dark mode), DataTable (server-side, URL-state, exports PDF/Excel/print), ConfirmDialog host, toast stack.
- **CI:** PHP 8.3+8.4 × SQLite+MySQL, Pint blocking, composer/npm audits, ESLint, Vitest, seed-integrity floors, route-integrity structural tests.

---

## 4. Functional Gaps — Enterprise Blockers

### P0 — Before any production go-live

| # | Gap | Evidence | Impact |
|---|---|---|---|
| F1 | **No 2FA/MFA, no session management, weak password policy** | 0 hits for totp/2fa; `Password::defaults()` = min 8 only | Any leaked password = full ERP access. Enterprise buyers ask for this first. |
| F2 | **No notification system** | No `notifications` table, no Notification classes, no bell UI | Approvals, low stock, document expiry, payroll events all happen silently. |
| F3 | **No generic approval matrix / maker-checker (SoD)** | Only PO>50k threshold + leave/payroll approvals exist | Same user can create+post+pay a bill. No delegation, no escalation, no audit of approval chain. |
| F4 | **No opening-balance / go-live migration tool** | No OpeningBalance classes; imports absent | Cannot onboard a real company: no COA openings, stock openings, AR/AP take-on, employee leave balances. |
| F5 | **No Excel/CSV import framework** | `Imports/` dirs: none; employee import is seeder-only | Products, partners, COA, stock all hand-keyed. Blocker for real adoption. |
| F6 | **No backup/DR, no observability** | No backup config, no Sentry/Pulse/Telescope; scheduler = attendance sync only | An ERP without tested restore is a liability. No error visibility in prod. |
| F7 | **No Dockerfile / deploy path broken** | `docker-compose.yml` `build: .` — no Dockerfile (audit item 27, still open) | `docker compose up` fails as shipped. No nginx/php-fpm/supervisor configs. |
| F8 | **Financial demo data empty** | Fresh seed: 0 journals/invoices/ledger; stock seed uses `rand()` (non-reproducible) | First login shows empty TB/P&L/BS/dashboards; UAT figures unreproducible. |

### P1 — Enterprise feature parity

| # | Gap | Evidence | Impact |
|---|---|---|---|
| F9 | **No ESS (Employee Self-Service)** | `User` has no `employee_id`; no `my/*` routes | 998 employees cannot view payslips/leave/attendance; HR is a bottleneck. Biggest HRM value-add. |
| F10 | **Price lists are dead code** | `price_lists` table + UI exist; **0 rows; `OrderService`/`PosService` never read them** | No tier/contract pricing — table-stakes for distribution businesses. |
| F11 | **Credit control only in POS** | `credit_limit` enforced in `PosService`; `OrderService`: 0 references | Credit sales via SO→INV bypass limits entirely. |
| F12 | **No multi-branch/company** | No `branch_id`/`company_id` on documents; single org | Warehouses ≠ branches: no branch P&L, no inter-branch transfers, no per-branch sequences. |
| F13 | **No API layer** | No Sanctum, no OpenAPI; 552 web-only routes | No e-commerce, no mobile app, no accounting export, no integrations. |
| F14 | **No budget module** | 0 hits | No budget vs actual, no department budgets — expected in enterprise finance. |
| F15 | **No fixed-asset register** | Depreciation *report* reads ledger; no asset cards/acquisition/disposal | Depreciation schedule without an asset subledger is a report, not a module. |
| F16 | **No multi-currency** | `currency` columns default BDT; no FX table/rates/revaluation | Import/export businesses blocked (landed cost exists but BDT-only). |
| F17 | **BD tax depth: no TDS/VDS withholding, no VAT return (Mushak 9.1), no BIN validation** | Mushak 6.3 ✓; withholding 0 hits | Month-end NBR compliance incomplete. |
| F18 | ✅ **Closed 2026-11-09 — requisitions + RFQ.** Was: procurement started at the PO, 0 hits | Now: 7 tables (`purchase_requisitions`+`_items`, `purchase_rfqs`+`_lines`, `purchase_rfq_quotes`+`_items`, `purchase_rfq_awards`) and `purchase_orders.requisition_id`; `REQ-`/`RFQ-` series; budget headroom enforced at *submit* with the waiver only on approve; one answer per vendor per round; per-line comparison with a lowest-complete-in-date recommendation; an award that writes **draft** POs, one per vendor | Asking and deciding are different grants, a bid is not an order, and nothing before the PO touches stock or the ledger. See `docs/PHASE3_IMPLEMENTATION_2026-11-08.md` §10 |
| F19 | **No document attachments** | Only HRM docs + partner docs | Bills/invoices/journals cannot carry scanned vendor invoices — audit requirement. |
| F20 | **No recurring documents** | No recurring invoice/journal services | Rent, retainers, subscriptions all manual. |
| F21 | **Scheduler underused** | Only `hrm:sync-attendance` | No FY auto-roll, no expiry alerts, no reorder alerts, no auto day-end, no payment reminders. |
| F22 | ✅ **Closed 2026-11-09 — offline POS.** Was: no offline POS, 0 hits | Now: `modules/SALES/Resources/js/offline/` (queue + stores + catalogue snapshot), `POST /sales/pos/offline/sync` with per-row verdicts on a `client_ref` idempotency key, `Sales → Offline POS Queue` desk | Counter keeps selling through a drop; the replay posts through `PosService`, so there is still one ledger. See `docs/PHASE3_IMPLEMENTATION_2026-11-08.md` §9 |

### P2 — Depth & scale
LDAP · webhooks · report subscriptions (email) · loyalty/gift cards · serial-number tracking UI · demand forecasting · quality inspection on GRN · drop-ship/back-to-back · consolidated multi-company reporting · data retention/archival policy · audit-log retention.

**Closed since the audit:** SSO (was in this list) — ✅ 2026-11-10, OIDC authorization-code login verified against the provider's `userinfo` endpoint, with domain gating, auto-provisioning, adoption of existing accounts, and a link manager that refuses to strand an account. Not built, and deliberately out: SAML, LDAP, SCIM, and any de-provisioning job — an identity whose upstream account is closed keeps its link until a human removes it. See `docs/PHASE3_IMPLEMENTATION_2026-11-08.md` §11.

---

## 5. UI / UX Gaps — The "Premium" Delta

### U-P0 — Fix first (quality gates & consistency)

| # | Gap | Evidence | Fix |
|---|---|---|---|
| U1 | **536 vue-tsc errors; gate absent from CI** | Ran `vue-tsc -p tsconfig.vuecheck.json`: 536 errors across all 8 modules | Add `vue-tsc` job to CI; burn down errors module-by-module (mostly untyped props/`any` rows). This is the single highest-leverage UI fix. |
| U2 | **101 of 229 pages bypass PageHeader** | 128/229 adoption | Script-transform remaining Index/Create/Edit/Show pages to `PageHeader` + `FormSection` + `EmptyState` (plan already exists in `ENTERPRISE_UI_PLAN.md` — execute it). |
| U3 | **No chart library; dashboards are hand-rolled** | 0 chart deps; SVG donut + div-bars | Add Apache ECharts (tree-shaken). Sales trend, cash position, stock aging, expense breakdown become real charts. |
| U4 | **Core dashboard is admin-centric, not business-centric** | Shows users/roles/permissions/audit counts | Build role-aware business dashboard: today's sales, cash/bank position, receivables overdue, stock value, top products, pending approvals. Admin stats move to a settings card. |
| U5 | **No module dashboards for Sales/Inventory/Accounts/Purchase** | Only Payroll dashboard + HRM reports exist | One dashboard per module with KPIs + 2 charts + action queue (e.g., "12 bills awaiting approval"). |
| U6 | **Starter-kit leftovers render outside the ERP shell** | Profile/Password use `AppLayout`; `Welcome.vue`, `AppHeader`, `AppSidebar` still shipped (19 type errors) | Rebuild Profile/Password on AdminLayout; delete dead starter pages/components (audit finding 54, open). |

### U-P1 — Enterprise UX table-stakes

| # | Gap | Evidence | Fix |
|---|---|---|---|
| U7 | **DataTable: no row selection / bulk actions** | No checkbox/selection code | Bulk post/approve/export/delete with permission checks + confirm. |
| U8 | **No saved views / column persistence / density toggle** | 0 hits | Per-user saved filter+column sets (server-side); comfortable/compact density. |
| U9 | **No global record search** | ⌘K searches menu only | Extend CommandPalette: search invoices/POs/products/partners by no/name (permission-scoped, debounced API). |
| U10 | **No notification center UI** | — | Bell + inbox panel (pairs with F2), mark-read, deep links. |
| U11 | **Unsaved-changes guard on only 5 files** | grep: 5 | Global `useUnsavedGuard` composable on every Create/Edit form. |
| U12 | **8 pages still use `window.confirm`/`alert`** | grep: 8 files | Replace with existing `useConfirm` + toast. |
| U13 | **No skeleton loaders** | 1 file | Skeleton rows/cards on DataTable + dashboards (perceived performance). |
| U14 | **No per-record activity timeline** | Show pages have no history feed | `ActivityTimeline` component reading Spatie log (data already exists on 61 models). |
| U15 | **No branded error pages** | `resources/views/errors/` absent | 403/404/419/500/503 on-brand pages with request-id. |
| U16 | **Document PDFs: only Mushak 6.3 + barcode** | 2 blade templates; everything else browser-print | DomPDF templates: Invoice, PO, GRN, Bill, Delivery, Payslip, Receipt — letterhead, BIN/VAT, signature blocks, QR. |
| U17 | **215 files use `../../../../../CORE` relative imports** | grep: 215 | Codemod to `@core` alias (already configured in vite/tsconfig). Prevents refactor breakage. |
| U18 | **188 `any` usages in pages** | grep | Type the Inertia props per module (pairs with U1 burn-down). |

### U-P2 — Delight & market fit

| # | Gap | Evidence | Fix |
|---|---|---|---|
| U19 | **No Bengali (bn_BD) localization; 0 i18n usage** | grep: 0 | laravel-lang + vue-i18n; Bengali invoice/payslip print; BDT digit grouping (lakh/crore option). |
| U20 | **Accessibility partial** | aria in 49/396 files | Audit POS keyboard flow, form labels, focus rings, contrast in both themes; target WCAG 2.1 AA. |
| U21 | 🔶 **PWA half closed 2026-11-09; mobile tables still open.** Was: no PWA | Now: manifest + 4 icons + generator, `sw.js` (build-asset cache-first, POS documents only, sync never cached), `offline.html`, header offline badge | Remaining: the `md:` card-list fallback for `DataTable` — the overflow is a layout problem, and shipping it next to a service worker that caches pages would have made it twice as hard to diagnose |
| U22 | **No form autosave/draft recovery** | — | localStorage drafts keyed by route+id; restore prompt. |
| U23 | **No keyboard-shortcut cheatsheet** | ⌘K only | `?` overlay; POS hotkeys (F2 pay, F4 hold…) documented. |
| U24 | **Empty first-run experience** | F8 | Deterministic financial demo seed + guided "start here" checklist (create FY → opening balances → first PO → first sale). |

---

## 6. Technical Debt (verified, non-blocking)

1. **Float arithmetic in ACCOUNTS services** — 32 `(float)` sites (`JournalService`, `LedgerService`, DTOs). Storage is decimal-correct; computation is binary float. Move to integer cents or `bcmath` (audit P1.2, deliberately deferred — schedule it).
2. **No Eloquent strict mode** — `Model::shouldBeStrict()` absent; N+1s invisible in dev. Enable in local/testing.
3. **22 orphan seeders** (audit finding 39) — wire or delete; `RosterShiftSeeder` decision needed.
4. **11 empty catch blocks** — all in HRM attendance, test-pinned with reasons; acceptable, but delete when the fail-loud guard lands.
5. **HRM report date comparisons** — `whereBetween` on date-cast columns still driver-divergent (audit P1.5 residual); MySQL CI leg catches these — keep it required.
6. **`inv_stocks_unique` index naming** + `BimoetricDevice*` typo duplicates — cosmetic cleanup.
7. **Docs drift** — handover says 75 migrations/394 routes; reality is 94/552. Regenerate `ERP_PILOT_HANDOVER.md` counts from a live seed (the repo already has tests that pin counts — reuse them).

---

## 7. Phased Roadmap to Enterprise/Premium/Production

### Phase 0 — Production Blockers (≈2 weeks, 1–2 devs)
F1 2FA + password policy + session list · F6 backups (`spatie/laravel-backup`) + Sentry + health checks · F7 Dockerfile + nginx/supervisor + queue worker · F8 deterministic demo seed with posted financial documents · U1 vue-tsc in CI + first 200 errors · U15 error pages · U6 starter-kit removal.
**Exit gate:** `docker compose up` → seeded demo → all reports populated; CI green incl. vue-tsc; restore-from-backup rehearsed.

### Phase 1 — Enterprise Core (≈3–4 weeks)
F2 notifications (database + bell U10) · F3 approval matrix + SoD rules · F4 opening-balance wizard · F5 Excel imports (products/partners/COA/stock) · F9 ESS (user↔employee link, my-payslips/my-leave/my-attendance) · F10 price-list pricing engine · F11 credit control on SO/INV · U4/U5 business dashboards + ECharts (U3).
**Exit gate:** a real company can be onboarded end-to-end; every document type has an approval path; employees self-serve.

### Phase 2 — Premium UX Pass (≈3 weeks, parallelizable)
U2 PageHeader 100% · U7/U8 DataTable v2 (bulk, saved views, density) · U9 global search · U11–U14 guards/skeletons/timeline · U16 document PDF suite · U17/U18 alias codemod + typing · F19 attachments · F21 scheduler jobs (expiry, reorder, reminders, FY roll).
**Exit gate:** every page passes a UI checklist (header, empty, loading, error, confirm, print, mobile); zero `any` in pages.

### Phase 3 — Scale & Market (≈4–6 weeks)
F12 multi-branch (branch dimension + per-branch P&L) · **F13 API + Sanctum + OpenAPI ✅** (v1: 10 paths, generated spec, scoped tokens, self-service screens — see `docs/PHASE3_IMPLEMENTATION_2026-11-08.md` §8; **webhooks not built**) · F16 multi-currency · **F17 TDS/VDS + Mushak 9.1 ✅ (see `docs/PHASE3_IMPLEMENTATION_2026-11-08.md`)** · F14 budgets · **F15 fixed assets ✅** · **F14 budgets ✅** (see `docs/PHASE3_IMPLEMENTATION_2026-11-08.md` §7) · **F22 offline POS + PWA (U21) ✅** (device queue, idempotent replay through `PosService`, queue desk, installable till — see `docs/PHASE3_IMPLEMENTATION_2026-11-08.md` §9; **DataTable mobile card fallback not built**) · **U19 Bengali ✅** (locale kernel + RTL + key screens) · **F18 requisitions + RFQ ✅** (request → approve → quote → compare → award, with the budget waiver only on the approval — see `docs/PHASE3_IMPLEMENTATION_2026-11-08.md` §10; **no vendor portal, no RFQ email, no print sheet**) · **SSO (OIDC) ✅** (state single-use, subject-not-address, provision/adopt/link — see `docs/PHASE3_IMPLEMENTATION_2026-11-08.md` §11; **no SAML/LDAP/SCIM, no de-provisioning job**).
**Exit gate:** multi-branch UAT with real Chattogram data; NBR month-end pack complete; API consumed by one external client.

---

## 8. Top 10 Quick Wins (≤1 day each, immediate premium feel)

1. Add `vue-tsc` to CI (fail on new errors via baseline file) — stops the bleeding today.
2. Seed 10 posted invoices + 5 bills + payroll journal in demo seeder → dashboards/reports alive on first login.
3. Business KPI cards on CORE dashboard (today's sales, cash, overdue AR, stock value) — data already in `LedgerService`.
4. Replace 8 `window.confirm/alert` pages with `useConfirm`.
5. Branded 403/404/500 pages.
6. `@core` alias codemod (215 files, mechanical, build-verified).
7. Skeleton rows in DataTable.
8. Unsaved-changes guard composable applied to all forms.
9. Activity timeline on Invoice/PO/Bill Show pages (data exists).
10. Deterministic demo prices (kill `rand()` in `InventoryDemoSeeder`).

---

*All counts measured on commit f8546a3, 2026-11-08. Test logs: `/home/user/test-critical.log`. This report supersedes the open-items lists in `docs/GAP_ANALYSIS_2026-11-02.md` where they conflict (returns, landed cost, GRNI, bank rec, aging, Mushak 6.3, purchase UX are now shipped and verified).*
