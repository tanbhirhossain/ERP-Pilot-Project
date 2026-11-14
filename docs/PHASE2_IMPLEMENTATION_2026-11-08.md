# Phase 2 — Premium UX Pass · Implementation Report

**Date:** 2026-11-08 · **Base:** `f8546a3` + uncommitted Phase 1 · **Status:** 11 of 13 work items shipped and verified; U2 partially shipped; U18 burn-down in progress (typing pass + entity interfaces for all 7 modules).

Phase 2 turns the functionally-complete Phase 1 ERP into a premium, low-friction operator experience: a smarter table, global record search, per-record history, attachments, branded document PDFs, and scheduled business jobs — without regressing a single existing gate.

---

## 1. Scoreboard

| Item | Scope | Status |
|---|---|---|
| **U17** | Module-alias codemod | ✅ 825 imports / 223 files → `@core/@hrm/@partner/@inventory/@accounts/@sales/@purchase`; 0 cross-module relative imports remain |
| **U12** | Native dialogs → `useConfirm`/`useToast` | ✅ 28 pages; only 3 comment mentions of `window.confirm` remain (no calls) |
| **U11** | Unsaved-changes guard | ✅ `useUnsavedGuard.ts` applied to 59 Create/Edit forms |
| **U7** | DataTable bulk selection + actions | ✅ generic infra + Products bulk activate/deactivate/delete wired end-to-end |
| **U8** | Saved views + density | ✅ server-side per-user views + localStorage density toggle |
| **U13** | Skeleton loading | ✅ shimmer rows on first load / empty-during-fetch |
| **U9** | Global record search | ✅ permission-scoped, debounced, wildcard-escaped, deep links |
| **U14** | Per-record activity timeline | ✅ service + component on Invoice / Sales Order / PO Show, field-level diffs |
| **F21** | Scheduled business jobs | ✅ 4 idempotent, logged commands registered + scheduled |
| **F19** | Document attachments | ✅ polymorphic, allow-listed, private-disk, permission-gated; wired to Invoice Show |
| **U16** | DomPDF document suite | ✅ 7 branded templates (letterhead, BIN, signatures, QR), ~28 KB each |
| **U2** | PageHeader adoption | 🟡 33 pages converted (101 → 68 bypassing); remainder are complex Show headers + special pages |
| **U18** | Zero `any` in pages | 🟡 entity interfaces shipped for **all 7 modules**; **60 pages'** primary entity props typed; entity-prop files **136 → 88** |

**Verification (all green):** Phase 2 features **24/59** · Phase 1 features **37/131** · regression **123/769** · all 8 guard contracts · Vitest **262** · ESLint clean · Pint **946 PASS** · `npm run build` ✓.

---

## 2. What was built

### U7/U8/U13 — DataTable v2 (`CORE/Components/DataTable.vue`)
- **Selection & bulk bar (U7):** opt-in `selectable` + `bulk-actions` props; per-page selection (cleared on navigate so a bulk action can never include hidden rows); select-all with indeterminate state; a bulk bar emitting `@bulk(action, ids)`. The parent owns the action + confirm.
- **Saved views (U8):** `core_saved_views` table + `SavedViewController` (index/store/destroy), **strictly user-scoped** — no route parameter can read or delete another person's views. A "Views" dropdown saves the current query + hidden columns under a name and re-applies them.
- **Density (U8):** comfortable/compact toggle persisted per-table in localStorage.
- **Skeletons (U13):** shimmer rows while loading with an empty result set.
- **Wired example:** Products Index offers Activate / Deactivate / Delete (permission-filtered) → `ProductController::bulk`, which checks the per-action permission and reuses the single-delete guard (stock / movement history) so guarded rows are **skipped and reported, never force-deleted**.

### U9 — Global search (`GlobalSearchController` + `CommandPalette.vue`)
- `GET core/api/global-search?q=` searches products, partners, invoices and purchase orders, **each gated on its own view permission**. LIKE wildcards are escaped (`%` is a literal). Debounced 300 ms in the palette; results render with type badges + deep links below the menu hits.

### U14 — Activity timeline (`ActivityTimelineService` + `ActivityTimeline.vue`)
- Reads the Spatie activity log for one subject and produces a render-ready timeline with **field-level old → new diffs**. The Show controller (already authorised for the record) passes it as a prop. Surfaced on Invoice, Sales Order and Purchase Order Show pages.

### F21 — Scheduler (`routes/console.php` + 4 commands)
| Command | Schedule | Idempotency |
|---|---|---|
| `erp:low-stock-alerts` | daily 08:00 | daily cache lock |
| `erp:overdue-invoice-reminders` | daily 08:15 | daily cache lock |
| `erp:credit-limit-review` | daily 08:30 | daily cache lock |
| `erp:leave-balance-accrual` | monthly (1st, 01:00) | `firstOrCreate` per employee/type/year |
- All notify the right permission holders via `NotificationService`, log their run, and are `withoutOverlapping()`. Added `CreditControlService::currentExposure()` (reusable, additive — the tested `assertWithinLimit` is untouched). Smoke-tested: each detects its condition and a second same-day run is a no-op.

### F19 — Attachments (`core_attachments` + `AttachmentController` + `AttachmentPanel.vue`)
- Polymorphic, **allow-listed by type** in `config('core.attachments.types')` (invoice, bill, purchase-order, journal), each with view (download) / manage (upload, delete) permissions. Files live on the **private local disk** and stream through an authorised controller — never under `public/`. Wired to Invoice Show. Verified: upload, download, 403 for unauthorised, 404 for unknown type, delete removes row + file.

### U16 — Document PDFs (`DocumentPdfService` + 7 blades + `DocumentPdfController`)
- One shared letterhead layout (company identity from `core_settings`, BIN, signature blocks, verification **QR** via `milon/barcode`) + seven templates: **Invoice, Purchase Order, GRN, Vendor Bill, Delivery Challan, Payslip, Payment Receipt**. Each type is allow-listed with its model, view permission and eager loads. Font subsetting keeps files ~28 KB. "Branded PDF" buttons added to Invoice / PO / Bill Show. All seven render valid PDFs; permission + unknown-type gates verified.

### U2 — PageHeader adoption (33 pages this pass)
- Two conservative codemods: plain-text `<h1>` → `<PageHeader>` (title/description split on " — "), and text+interpolation `<h1>` → `:title` template literal. Flex-row headers move their action verbatim into PageHeader's `#actions` slot. Print pages (`data-core-print`) and section-header h1s are excluded. Build + ESLint clean after each pass.

---

## 3. Guard-pin updates (intentional census moves)

| Contract | Old → New | Why |
|---|---|---|
| PrintContract `.vue` census | 409 → **411** | + `ActivityTimeline.vue`, `AttachmentPanel.vue` |
| PrintContract boot import | relative → **`@core/Composables/usePrint`** | U17 rewrote the boot file's import to the alias |
| MoneyColumnCast model census | 160 → **162** | + `SavedView`, `Attachment` (no decimal columns, so the money-column count is unchanged) |

No new permissions were added (Phase 2 reuses existing ones), so `OptionEndpointAuthorisation` stays at 183.

---

## 4. Remaining work (the final coverage sweep)

### U2 — 68 pages still bypass PageHeader
The safe mechanical patterns are done. What remains needs per-page care:
- **Complex Show headers** (Products, Vendors, Banks, Transfers, Adjustments, PriceLists, …): the `<h1>` carries an icon + code chip + `StatusBadge`. Convert to `PageHeader` with `:icon`, `:title`, and the badge/code moved into the `#badge` slot.
- **Special pages** that legitimately keep their own chrome: Dashboard (hero), POS Receipt + Payslip print (`data-core-print`), UiKit (component showcase).
- **PAYROLL section headers** (Tax/Bonuses/Adjustments): the `<h1>` is a card section title, not a page header — correctly left alone.

### U18 — typing pass + entity interfaces for all 7 modules
**Done this pass (all behind Vitest 262 + build + ESLint gates):**
- **DataTable is now generic** (`generic="T extends { id: string | number }"`): `rows: T[]`, and cell slots receive a typed `row`. Removed all **56** `as unknown as Record<string,unknown>[]` row casts so `T` infers the page's `Row`.
- **`(row as any)` eliminated (24)** across 7 Index pages — the `Row` interfaces already carried the accessed fields; `askDelete` signatures tightened to `Row`.
- **41 option props typed `SelectOption[]`** across 31 files (the ones bound to `SearchableSelect :options`).
- **21 nullable form fields** `null as any` → `null as number | string | null`.
- Removed redundant `(props as any)`/`(src as any)` casts (4 files) + 2 Paginator `.meta` casts + the `querySelector … as any` → `HTMLMetaElement | null`.

**Entity interfaces shipped (this pass):** generated a `types.ts` per module straight from the migrations (`$t->` column parser), then codemodded the primary entity `defineProps` to them. Money/qty `decimal` columns are typed **`string`** (they serialize as decimal strings in JSON — pages already wrap with `Number()` at the display edge); FKs `number | null`, dates/timestamps `string | null`, relations optional minimal shapes to avoid cross-module coupling.

| Module | `types.ts` | Primary entity props typed |
|---|---|---|
| SALES | Invoice, Order, Quotation, Payment, Delivery, SalesReturn (+ items) | 12 pages |
| PURCHASE | PurchaseOrder, GoodsReceipt, VendorBill, PurchaseReturn, LandedCost (+ items) | 10 pages |
| INVENTORY | Product, Warehouse, Transfer, Adjustment, Kit, StockCount, Category, Brand, Uom | 13 pages |
| PARTNER | Partner, PriceList, PartnerGroup (+ contacts/addresses/banks) | 6 pages |
| ACCOUNTS | Account, Journal, Bank, FiscalYear (+ lines/periods) | 10 pages |
| HRM | Employee, LeaveType, LeaveRequest, EmployeeMovement, ShiftSwap | 5 pages |
| PAYROLL | SalarySheet, PayrollSlip, BankAdvice (relative imports — no path alias) | 4 pages |

**60 pages** now type their primary entity. Entity-prop files dropped **136 → 88**; the 88 remaining mostly still carry *secondary* `any` props (option lists, `activities[]`, `attachments[]`, report `data/stats`) even where the main entity is typed. One name collision (lucide `Warehouse` icon vs the `Warehouse` entity) was resolved with an aliased type import. Vue census unchanged at **411**; ESLint whole-project 0; build ✓; Vitest **262**.

**Remaining:**
1. **Secondary `any` props** on the 88 entity pages — option lists → `SelectOption[]`, `activities/attachments` → CORE shapes, list/paginator props → `Paginator<T>`.
2. **HRM/PAYROLL list & report props** (`data/stats/summary/…: any`) — bespoke controller shapes, type per page.
3. **`form as any` in `router.post`** — Inertia `useForm`/`RequestPayload` friction; fix with a typed submit helper.

**Recommendation:** burn down the secondary props on the already-typed entity pages next (highest leverage — the entity shape is already in place), then the HRM/PAYROLL report shapes; finish U2's complex Show headers alongside, since both touch the same pages.

---

## 5. Reproduce the verification

```bash
# Phase 2 features (24)
php artisan test tests/Feature/CORE/Phase2SavedViewsSearchTest.php \
  tests/Feature/CORE/Phase2AttachmentsDocumentsTest.php \
  tests/Feature/INVENTORY/Phase2BulkProductsTest.php \
  tests/Feature/CORE/Phase2SchedulerTimelineTest.php

# Guards (census pins updated) + Phase 1 features
php artisan test tests/Feature/CORE/PrintContractTest.php tests/Feature/CORE/MoneyColumnCastTest.php \
  tests/Feature/RouteIntegrityTest.php tests/Feature/CORE/MigrationDataSeparationTest.php \
  tests/Feature/CORE/OptionEndpointAuthorisationTest.php

# Frontend gates
npx vitest run && npx eslint . --max-warnings=0 && npm run build && vendor/bin/pint --test
```

*Changes are left uncommitted (no commit was requested). Phase 0 remains skipped per direction.*
