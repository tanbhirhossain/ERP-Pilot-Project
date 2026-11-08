# ERP Pilot — Gap Analysis & What Is Missing
**Date:** 2026-11-02 Asia/Dhaka (Chattogram) | **Env:** SQLite dev, 78 migrations, 395 routes, 97 perms, Vite 12.31s, no `.env` file  
**Modules by maturity:** HRM 286 files + PAYROLL 116 files = *inherited/legacy mature*; CORE 79 + PARTNER 65 + INVENTORY 124 + ACCOUNTS 72 + SALES 61 = *production-grade pilot*; PURCHASE 43 = *fresh scaffold — E2E works, UX stub*.

---

## 1) Snapshot — What Exists vs. What the UI Says

| Area | Tables | Files | Routes | Seed | Status |
|------|--------|-------|--------|------|--------|
| **PARTNER** | 5 mig (groups, price_lists, partners, contacts, docs) | 65 | 34 | 22 partners (11V/11C), 7 groups, 0 price_lists | ✅ View/manage vendors/customers works. PriceLists empty — SALES/PURCHASE ignore them. Vendor/Customer same `partners` table with `type` flag — no distinct workflow. |
| **INVENTORY** | 10 mig (uoms, cats/brands, warehouses, products+variants/suppliers/BOM, stocks, moves, transfers, adjustments, counts/lots/serials) | 124 | 58 | 16 products, 1 stock line (P16 110 on-hand after GRN), 0 variants/lots/serials/BOM/suppliers populated | ⚠️ **Scaffold trap:** Models exist for Lot/Serial/Variant/BOM/Supplier but 0 rows, 0 UI flows wired, Transfers/Adjustments/Counts pages exist but `migrate:fresh` shows 0 transfers/adjustments/counts. Stock valuation Weighted-Avg only (FIFO field exists, never used). PriceLists, barcodes present but only P16 has stock. |
| **ACCOUNTS** | 4 mig (FY/periods, COA, journals/lines/ledger, banks/payables) | 72 | 40 | 26 COA (1000→5000), 3 FYs (FY2526-2728), 1 bank (Prime 100201), 3 journals, 10 ledger lines (post-purchase+sales) | ✅ Core double-entry, fiscal lock, posting provider, TB/P&L/BS/GL via `LedgerService`. ⚠️ Only 26 accounts — Bangladesh NBR depth missing (see §3). AR/AP subledgers via `partner_id` but no aging, no reconciliation, no bank rec, no VAT return. |
| **SALES** | 4 mig (quotations, orders, invoices/payments, pos_sessions/sales) | 61 | 39 (8 perms) | 1 QT, 0 orders, 1 invoice, 1 POS (after fresh) | ✅ QT→SO→INV→POS→LEDGER E2E fixed (stock reserve/deduct/restock, FY, COA 4001/5001, product snapshot). POS Terminal rebuilt (barcode, bKash/split, VAT 2110, receipt). ⚠️ No delivery note, no credit note/return, no sales commission, no price-tier logic. |
| **PURCHASE** | 3 mig (orders 2t, receipts 2t, bills 3t) | 43 | 24 new (11 perms) → 395 total | 1 PO-00001 confirmed (10×1000), GRN/bills after manual test | ✅ PO→GRN→Bill→Pay→Ledger verified today (110 stock, journals 1200/2110→2100 →2100→100201, sales COGS fix included). **⚠️ Scaffolding:** Vue Index/Create/Show/Edit are *copy-pasted stubs* (“Minimal create — posts via API. Add vendor/warehouse/items” with raw numeric ID inputs, no SearchableSelect, no price auto-fill, no order→GRN→Bill chaining UI). No purchase return, no debit note, no landed-cost, no GRNI accrual account. Edit routes exist but reuse Create template. No purchase report. |
| **HRM / PAYROLL** | 52 mig inherited | 286/116 | 230+ routes | 998 employees, 998 device_users | ✅ Mature, untouched by this pilot. Payroll tax/PF/bonus/settlements exist — not integrated with ACCOUNTS (payroll journals not posted). |

**Global counts after `migrate:fresh --seed --force`:** 78 migrations, 97 permissions, 395 routes, Vite 287kB, SQLite `database.sqlite` snapshot, FY 3×12 periods, **npm 35 vulns (3L/13M/15H/4C)**, **0 tests executed** (`tests/` has 36 files, Pest never run in CI), **no `.env` / `.env.example`** checked in.

---

## 2) Functional Gaps — By Module

### PURCHASE — The Newest, Buzziest, Thinnest
- **Missing flows:** Purchase return, supplier debit note, GRN void/cancel (no `restock` reverse), bill void, partial receipt with variance, over-receipt guard is config but not enforced, multi-warehouse receipt split, lot/serial receipt capture.
- **Accounting:** No GRNI/Accrued Purchases account (direct 1200→2100 skips accrual gap between GRN and bill). No input VAT recoverable (all VAT goes to 2110 VAT Payable — should be 1330 VAT Receivable or net). No landed cost (freight/customs → inventory cost).
- **UX:** No vendor/warehouse/product searchable dropdowns (raw IDs), no line total live calc, no PO selector → auto-fill GRN/Bill, no history timeline, no approval threshold (e.g., >50k requires manager). `Edit.vue` is identical to `Create.vue` — no prefilled `findOrFail` data binding.

### SALES & POS
- **Missing:** Delivery challan, sales return/credit note + stock restock + VAT reversal, quotation versioning, order back-order, invoice void (has void? no — only delete draft), advance/deposit, installment, withholding tax, Mushak 6.3 print. POS: no offline queue, no change auto-calc, no cash drawer, no thermal ESC/POS, no weight-scale, no customer loyalty/discount tiers. `PriceList` seeded 0 — tier pricing dead code.

### INVENTORY — Wide but Shallow
- **Stock:** Only 1 product stocked (P16). Factory creates 16 products but `InventoryDemoSeeder` creates stock for 1–2 only. All other 15 SKUs will fail sales with “Insufficient stock” (proven: P01 fetch returned 0).
- **Features present-as-model-only:** Variants (0 rows), Lots (0), Serials (0), BOM/kitting (0), ProductSuppliers (0), Transfers (0 rows, UI exists), Adjustments (0 rows), Counts (0 rows). Weighted-Avg `avg_cost` works; FIFO/LIFO fields exist but `ValuationService::getValuation` FIFO stub returns `StockMove::sum(total_cost)` not actually FIFO. No cycle count approval, no bin/location put-away, no reorder engine, no ABC analysis. UOM conversions missing. Barcode lookup endpoint added for POS, but no label print (ZPL).

### ACCOUNTS — The Ledger Is Honest, But Thin
- **COA:** 26 accounts vs. Bangladesh NBR/ICAB depth (~80–120). Missing: 1330 VAT Current Account (input), 1310 TDS, 2300 Provisions, 2400 Accruals, 1400 WIP, 5100 Purchase, 5150 Purchase Return, 5200 Carriage Inward, 5300 Discounts. 4000 is group, 4001/5001 are leaves — correct now, but purchase still posts to 1200/2100 generically.
- **Reports:** TB/P&L/BS/GL via `LedgerService::trialBalance` work (tested `SUM debit = credit YES`), but **aging (AR/AP) by vendor/customer, bank reconciliation, VAT return (Mushak), cash flow, budget variance** absent. No multi-currency (BDT only, config says `currency` but never FX). Fiscal lock is `FiscalYear status open` — no period close, no year-end transfer to retained earnings (3200).
- **Banks:** 1 demo bank (Prime 100201, 500k opening). No bank statement import, no reconciliation, no bKash API — bKash is just a `payment_method= bkash` string that still posts to bank selected.

### PARTNER
- PriceLists table exists, 0 rows, no UI, no sales/purchase integration. Partner documents/notes tables exist, no flow. No credit limit, no payment terms (e.g., Net 14), no vendor rating.

### CROSS-CUTTING
- No `PURCHASE ↔ INVENTORY` stock price update for purchase price variance (GRN posts stock at PO unit_price, but no revaluation if bill price differs).
- No `SALES ↔ PURCHASE` dropship/back-to-back.
- No approval matrix (PO > X requires HOD). No notifications.

---

## 3) Data & Localization Gaps (Bangladesh)

- **Fiscal calendar:** Fixed to 3 FYs (FY2526-2728) in `AccountsDemoSeeder` — previously only FY2526, which broke on 2026-11-02. No auto-roll, no NBR holiday handling (Pohela Boishakh etc. is in HRM holidays but not FY).
- **Tax:** Single VAT rate per line; no NBR slab (15% standard, 10%/5%/0%/exempt), no VDS/TDS, no Mushak Challan 6.3/6.10 export, no BIN validation.
- **Locale:** `APP_TIMEZONE Asia/Dhaka` correct, but `APP_LOCALE en`, `Faker en_US` — no `bn_BD` translations, no Bengali invoice print, no `BDT` formatting beyond `formatCurrency`.
- **Demo data thin:** 22 partners, 16 products, 1 stock line, 1 PO, 1 QT. Not enough for UAT volume/stress.

---

## 4) Technical & DevOps Gaps

- **Env & Deploy:** No `.env` / `.env.example` in repo (git shows none) — fresh clone cannot `migrate:fresh`. `vite.config.ts` hardcodes `@sales/@purchase` aliases but `ModuleServiceProvider` globs `modules/*/Providers/*ServiceProvider` — order-dependent, no explicit dependency graph. No Docker/Sail, no Nginx sample, no `storage:link`, no queue (sync), no cron for FY roll/attendance sync. `database.sqlite` is git-ignored? but present on disk — prod expects MySQL; `fullText` guard for SQLite (`2026_11_03_000009`) hints MySQL fullText exists only on prod.
- **Vulns:** `npm audit 35 (3L/13M/15H/4C)` — tinypool/YAML. `composer audit` not run. `barryvdh/laravel-dompdf 3.1` present but no PDF template used (sales/purchase PDFs still stub).
- **Tests:** `tests/` 36 files (Pest 3.8) but **0 purchase/sales/inventory feature tests**; `phpunit.xml` uses `:memory:` SQLite — never tested with MySQL FKs; CI missing. Module tests in `modules/*/tests` absent.
- **Quality:** `BuildsDatatableQueries` trait reused, good; but `Purchase Repos` duplicated via copy-paste (DRY violation) — will diverge from Sales. No OpenAPI spec; 395 routes undocumented. No `php pint` run. `Edit.vue` copy of `Create.vue` will ship bug.
- **Observability:** No Sentry/Telescope/Pulse beyond env flag `PULSE_ENABLED false`; no audit retention policy; `activity_log` exists but not shown for PURCHASE.

---

## 5) UX / Product Gaps

- **Purchase UX stubs:** Raw numeric inputs (vendor_id, warehouse_id, product_id) — **unshippable**. No product search, no price history, no vendor last-price hint. SALES Terminal is polished; PURCHASE has none of that polish.
- **Mobile:** POS Terminal `lg:grid-cols-3` responsive, but `DataTable` on mobile overflows; no PWA/offline.
- **Print:** Receipt 80mm thermal HTML exists for POS; PO/GRN/Bill/Mushak prints are generic `pre JSON` dumps. No barcode/QR on any doc.
- **Accessibility:** No keyboard nav for POS quantity stepper, no screen-reader labels.

---

## 6) Security & Governance Gaps

- **Perms:** 97 total, Purchase adds 11 (view/manage/confirm/post/pay). But `Requests::authorize()` for Purchase is `return true` — **bypasses** `can('purchase.orders.manage')`. Sales requests enforce `StoreOrderRequest`. Purchase will allow any authed user.
- **Roles:** Only `Super Admin` seeded with all perms — no `Purchaser`, `Store Keeper`, `Accountant`, `Salesman` roles. No SoD (purchaser cannot post bill).
- **Audit:** `LogsActivity` on PO/GRN/Bill but not read in UI. `Spatie ActivityLog` retention unbounded.

---

## 7) What To Do — Prioritized

### P0 — Must Fix Before Pilot Counter (1–2 sprints)
1. **Fiscal & COA:** Keep 3-FY seed (done), add NBR accounts (1330, 1310, 5100, etc.) and `AccountsPostingProvider` mapping for purchase (GRNI 2115 if introduced).
2. **Purchase UX:** Replace stub Creates with SALES-grade: `SearchableSelect` for vendor/warehouse/products, `LineItemsRepeater`, live totals, PO→GRN→Bill chaining (select PO → autofill lines). Fix `Request::authorize` to check perms. Replace `Edit.vue` with real edit form prefilled via `findOrFail`.
3. **Stock seeding:** Seed all 16 products with opening stock (or block sales for out-of-stock with friendly msg). Seed Variants/Lots if selling them.
4. **Env:** Commit `.env.example` (APP_TIMEZONE=Asia/Dhaka, DB_CONNECTION=mysql/sqlite, QUEUE=database), `docker-compose.yml` + `README` deploy steps. Fix `npm audit` (at least `npm audit fix` for non-breaking).
5. **Tests:** Add 4 Pest feature tests: `PO→GRN stock IN`, `Bill→AP ledger`, `Sales deduct + COGS` (after purchase avg), `FY blocks posting outside open period`.

### P1 — Needed for Month-End Close
- GRN void, Bill void/return, purchase approval > threshold, aging reports (AR/AP), bank rec, VAT Mushak 6.3 PDF (dompdf template), budget vs actual, period close → retained earnings.

### P2 — Scale & Delight
- PriceLists tier pricing, landed cost, BOM kitting, lots/serials UI, FIFO, barcode label print, offline POS queue, Bengali, multi-currency, OpenAPI + Postman, Sentry, HRM→Payroll→Accounts journal bridge.

---

**Bottom line:** The 5th module (PURCHASE) proves the architecture (DIP providers, stock, ledger) — E2E numbers balance. But its UI is a scaffold with dummy inputs and open `authorize`. Inventory looks wide (124 files) but only 1 product stocked and 0 rows for its advanced features. Accounts is honest double-entry with 26 accounts, but Bangladesh NBR depth + VAT workflow is missing. Ship after P0; otherwise Chattogram will hit “Insufficient stock” or post with wrong VAT.

*Generated from live `migrate:fresh --seed` 2026-11-02, Vite 12.31s, Tinker E2E PO-00002→GRN-00001→PB-00001→INV-00001.*
