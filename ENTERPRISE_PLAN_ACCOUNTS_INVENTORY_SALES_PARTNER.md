# 🏢 ERP Pilot — Enterprise Expansion Plan
### ACCOUNTS + INVENTORY + SALES & POS + VENDOR & CUSTOMER MANAGEMENT
> **Mode:** Plan Only — No Code  
> **Date:** 2026-11-02  
> **Base:** Existing modular monolith (CORE, HRM, PAYROLL) — SOLID, Inertia + Vue 3, Laravel 12  
> **Target:** Premium, enterprise-level, production-grade ERP

---

## TABLE OF CONTENTS
1. [Executive Summary](#1-executive-summary)
2. [Current Architecture Audit](#2-current-architecture-audit)
3. [Guiding Principles & Standards](#3-guiding-principles--standards)
4. [Module Dependency Graph & Build Order](#4-module-dependency-graph--build-order)
5. [Module 1: VENDOR & CUSTOMER MANAGEMENT (PARTNER)](#5-module-1-vendor--customer-management-partner)
6. [Module 2: INVENTORY](#6-module-2-inventory)
7. [Module 3: ACCOUNTS (Financial Accounting)](#7-module-3-accounts-financial-accounting)
8. [Module 4: SALES & POS](#8-module-4-sales--pos)
9. [Cross-Cutting Enterprise Concerns](#9-cross-cutting-enterprise-concerns)
10. [Shared UI/UX System — Premium Admin Console](#10-shared-uiux-system--premium-admin-console)
11. [File & Folder Blueprint (Mirrors Existing Structure)](#11-file--folder-blueprint-mirrors-existing-structure)
12. [SOLID & Design Patterns Mapping](#12-solid--design-patterns-mapping)
13. [Database Design Summary](#13-database-design-summary)
14. [Permissions (RBAC) Matrix](#14-permissions-rbac-matrix)
15. [Menu & Routing Design](#15-menu--routing-design)
16. [Integration & Event Bus](#16-integration--event-bus)
17. [Reporting & Analytics Layer](#17-reporting--analytics-layer)
18. [Testing, Quality & DevOps Strategy](#18-testing-quality--devops-strategy)
19. [Phased Implementation Roadmap (12–16 Weeks)](#19-phased-implementation-roadmap-1216-weeks)
20. [Team & Effort Estimate](#20-team--effort-estimate)
21. [Risks & Mitigations](#21-risks--mitigations)
22. [What We Will NOT Do In This Session](#22-what-we-will-not-do-in-this-session)
23. [Decision Log — Awaiting Your Approval](#23-decision-log--awaiting-your-approval)
24. [Appendix: Naming Conventions](#24-appendix-naming-conventions)

---

## 1. EXECUTIVE SUMMARY

You have a **solid modular monolith** today:
- `CORE` = Platform kernel: Auth, RBAC (Spatie Permission + wildcard `*`), Audit Trail (`spatie/activitylog`), DataTable infrastructure, Exports, AdminLayout/TreeSidebar, ACL-inertia props.
- `HRM` = Human Capital: Organization → Departments/Designations → Employees → Roster → Attendance Pipeline (3-stage queued) → Leave/Overtime/Informed → Devices.
- `PAYROLL` = Compensation: Decoupled via `EmployeePayrollDataProviderInterface` + `AttendancePayrollDataProviderInterface` → Salary Sheets, Loans, PF, Tax, Bonuses, Settlements. Uses `Actions`, `DTOs`, `Jobs`.

**The expansion adds 4 enterprise modules** that turn the HR-centric system into a full **ERP**:

| New Module | Code Name | Purpose | Depends On |
|---|---|---|---|
| **VENDOR & CUSTOMER MANAGEMENT** | `PARTNER` | Single master-data hub for all external parties | `CORE` |
| **INVENTORY** | `INVENTORY` | Warehouses, products, stock ledger, valuation | `CORE` + `PARTNER` |
| **ACCOUNTS** | `ACCOUNTS` | Double-entry ledger, AP/AR, financial statements | `CORE` + `PARTNER` + `INVENTORY` + `HRM/PAYROLL` |
| **SALES AND POS** | `SALES` | Quotations → Orders → Invoices → POS terminal | `CORE` + `PARTNER` + `INVENTORY` + `ACCOUNTS` |

> **Why this order matters:** `PARTNER` is the foundation — every other module references a vendor/customer. `INVENTORY` must exist before `SALES` can deduct stock and before `ACCOUNTS` can value it. `ACCOUNTS` must exist before `SALES/POS` can post journal entries. This is **dependency-inversion done correctly**.

All 4 modules will **strictly follow your existing conventions**:
- `Modules/{NAME}/Providers/{NAME}ServiceProvider.php` with interface bindings
- `Interfaces/` → `Repositories/` (with `BuildsDatatableQueries` trait) → `Services/` → `Http/Controllers/` → `Requests/` → `Models/` (with `LogsActivity` + `SoftDeletes`)
- `Database/Migrations|Seeders|Factories`, `Routes/web.php|api.php`, `Resources/js/Pages/...`, `Console/Commands`, `Jobs`, `Actions`, `DTO`
- Inertia `MODULE::Page/Index` resolution via `resources/js/app.ts` (`modulePages` glob)
- Premium UI via `CORE`'s `AdminLayout`, `TreeSidebar`, `DataTable`, `SearchableSelect`, `ConfirmDialog`, `useAcl`, `useToast`, `format.ts`
- `menu.ts` declarative sidebar with `permission` gates + `PermissionMiddleware` on routes

---

## 2. CURRENT ARCHITECTURE AUDIT

### 2.1 What is Excellent (Keep & Reuse)
- **Module auto-discovery:** `App\Providers\ModuleServiceProvider` glob-loads `modules/*/Providers/*ServiceProvider.php` — zero manual registration.
- **SOLID bindings:** Every service depends on an interface (e.g., `EmployeeRepositoryInterface` → `EmployeeRepository`). PAYROLL never touches HRM tables directly — it uses `HrmEmployeePayrollDataProvider` behind an interface. This is textbook **Dependency Inversion**.
- **DataTable infrastructure:** `DataTableQuery` VO + `BuildsDatatableQueries` trait + `PaginatedResource` = consistent server-side pagination/search/sort/filter/exports across all modules. Cap `per_page` at 100, searchable columns whitelisted.
- **Audit trail:** `LogsActivity` on all models + `AuditTrailService` for auth/events. `activitylog` retention set to 3655 days (10 years) — enterprise compliance ready.
- **Inertia module pages:** `CORE::Users/Index` → `modules/CORE/Resources/js/Pages/Users/Index.vue` via `app.ts` resolver. No route/view drift.
- **Vite aliases:** `@`, `@core`, `@hrm` — add `@partner`, `@inventory`, `@accounts`, `@sales`.
- **Queue-ready pipeline:** `HRM` attendance uses `SyncDeviceJob → SyncRawLogsJob → MakePairsJob → PrepareAttendanceJob` + `AttendancePipeline` — proves the app can handle enterprise background processing.
- **Spatie RBAC with wildcard:** `Super Admin` has `*` → `is_super_admin` in Inertia `acl` prop → UI + server both enforce.

### 2.2 Gaps to Close Before Expansion
1. **Menu already large (13+ items):** New modules add ~30 routes. Must introduce **grouped collapsible sections** + icons — already supported in `menu.ts` (`children[]`). We will add 4 new top-level groups.
2. **Permission count will explode** from ~30 to ~120. Reuse `CORE/Database/Seeders/CoreSeeder` pattern: each module owns its `*PermissionSeeder.php` seeded via `DatabaseSeeder`.
3. **Vite config only has 2 aliases:** Add 4 more.
4. **No `PARTNER` concept yet:** HRM's `Employee` is internal; external parties need a separate bounded context — do NOT reuse `User` or `Employee`.
5. **Accounts needs `fiscal year locking`:** Must design period-close to prevent retroactive edits — new concern not present in HRM/PAYROLL.

---

## 3. GUIDING PRINCIPLES & STANDARDS

### 3.1 Non-Negotiable Engineering Standards
| Principle | How We Enforce It |
|---|---|
| **SOLID** | Interfaces for every repository/service cross-module boundary. Controllers depend on Services, Services on Interfaces, Repositories on Models. Single Responsibility per class. |
| **Modular Monolith** | Each module is **independently deployable in code, jointly deployed in runtime**. No direct `DB::table('other_module_table')` — only via published Interfaces/DTOs/Events. |
| **DRY & Reuse** | `DataTable`, `SearchableSelect`, `ConfirmDialog`, `AdminLayout`, `useAcl`, `ExportService`, `BuildsDatatableQueries`, `DataTableQuery`, `PaginatedResource` — never duplicate. |
| **Enterprise Audit** | Every model `LogsActivity`; every state transition (approve/reject/post/void) writes via `AuditTrailService`; `activity_log` is append-only. |
| **Security First** | `PermissionMiddleware` on every route, `policy` checks in services, `FormRequest` authorization, mass-assignment guarded, SQL injection via Eloquent only. |
| **Performance** | Pagination + indexed queries + `lazyById(50)` for payroll-style batches; queue for heavy jobs; no N+1 (eager load whitelisted). |
| **Production Grade** | Migrations are reversible, seeders idempotent, factories for QA, Pest feature tests + Vitest unit tests, `php artisan migrate --graceful`, zero-downtime deploys. |

### 3.2 Architectural Patterns We Will Reuse
- **Repository Pattern** + **Service Layer** (HRM style) for CRUD modules.
- **Action Pattern** (PAYROLL style: `GenerateSalaryCutlistAction`) for complex transactional workflows (e.g., `PostJournalEntryAction`, `TransferStockAction`, `CompleteSaleAction`).
- **DTO Pattern** (PAYROLL style: `EmployeePayrollData`) for cross-module data transfer — never leak Eloquent models across boundaries.
- **Provider Interface Pattern** (PAYROLL's `HrmEmployeePayrollDataProvider`) for decoupling — e.g., `InventoryStockProviderInterface` consumed by `SALES` and `ACCOUNTS`.
- **Event-Driven Integration:** `SaleCompleted` → `DeductInventory` → `PostToLedger` via Laravel Events/Listeners (sync in monolith, queue-ready for future microservice split).
- **State Machine:** Status enums with guarded transitions (`Draft → Pending → Approved → Posted → Voided`) — service-enforced, not just DB.

---

## 4. MODULE DEPENDENCY GRAPH & BUILD ORDER

```
                    ┌─────────────────────────────┐
                    │            CORE             │
                    │  Users/Roles/Perms/Audit   │
                    │  DataTable/Exports/Layout   │
                    └──────┬──────────────────────┘
                           │ depends
              ┌────────────┼─────────────────────────┐
              │            │                         │
        ┌─────▼─────┐ ┌───▼────┐           ┌────────▼────────┐
        │    HRM    │ │PARTNER │           │   (existing)    │
        │(existing) │ │ (NEW)  │           └─────────────────┘
        └─────┬─────┘ └───┬────┘
              │           │                 ┌─────────────────┐
              │     ┌─────▼──────┐          │    PAYROLL      │
              │     │ INVENTORY  │          │   (existing)    │
              │     │   (NEW)    │          └──────┬──────────┘
              │     └─────┬──────┘                 │
              │           │                        │
              └───────────┼────────────────────────┘
                          │
                    ┌─────▼──────┐
                    │  ACCOUNTS  │
                    │   (NEW)    │
                    └─────┬──────┘
                          │
                    ┌─────▼──────┐
                    │   SALES    │
                    │  + POS     │
                    │   (NEW)    │
                    └────────────┘

Arrow = "depends on" (consumes interface/DTO from)
```

**Build Order (Sequential, each phase shippable):**

| Phase | Module | Why This Order | Shippable Milestone |
|---|---|---|---|
| **Phase 0** | Foundation | Add aliases, menu scaffolding, base migrations, permission skeleton | Infra ready |
| **Phase 1** | `PARTNER` | No dependencies except CORE — unlocks all others | Vendors & Customers live |
| **Phase 2** | `INVENTORY` | Needs PARTNER (supplier linkage, customer for consignment) | Warehouses & Stock live |
| **Phase 3** | `ACCOUNTS` | Needs PARTNER (AP/AR sub-ledgers) + INVENTORY (valuation) + HRM/PAYROLL (payroll journals) | Financial statements live |
| **Phase 4** | `SALES & POS` | Needs all above (customer, stock, ledger posting) | Full order-to-cash + POS live |

> **Alternative considered & rejected:** Building ACCOUNTS before INVENTORY. Rejected because inventory valuation (FIFO/Weighted Average, landed cost) must post to GL — GL design would be incomplete without it.

---

## 5. MODULE 1: VENDOR & CUSTOMER MANAGEMENT (PARTNER)

### 5.1 Why `PARTNER` and Not Two Separate Modules?
Industry best practice (Odoo, ERPNext, SAP Business Partner) uses a **single `partners` table with `type` discriminator** (`vendor`, `customer`, `both`). This prevents duplicate records when a party is both (common in BD: a supplier who also buys). We implement **one module, two personas** — same table, filtered views, distinct permissions (`vendors.view` vs `customers.view`).

**Module name:** `PARTNER` (folder `modules/PARTNER`) — display label in UI remains "Vendor & Customer Management". Route prefix `partner` (aligns with HRM's `hrm`, PAYROLL's `payroll`).

### 5.2 Domain Entities (Tables)

#### Core Master
| Entity | Table | Purpose |
|---|---|---|
| **Partner** | `partners` | Central master: `partner_code` (auto `VEN-0001`/`CUS-0001`), `type` enum(vendor,customer,both), `name`, `legal_name`, `trade_license`, `tin`, `bin/vat`, `group_id`, `credit_limit`, `payment_terms`, `price_list_id`, `is_active`, `is_verified`, `opening_balance`, `current_balance` (cached, reconciled) |
| **Partner Group** | `partner_groups` | Hierarchical categories: e.g., `Vendors > Raw Material > Fabric`, `Customers > Retail > Dhaka Zone`. `parent_id` self-ref (like Departments). |
| **Partner Contact** | `partner_contacts` | Multiple contacts per partner: `name`, `designation`, `phone`, `email`, `is_primary`, `can_receive_statement` |
| **Partner Address** | `partner_addresses` | Multiple addresses: `type` (billing, shipping, factory, warehouse), `address_line`, `city`, `district`, `division`, `country`, `is_default` |
| **Partner Bank** | `partner_banks` | Bank details for payments: `bank_name`, `branch`, `account_no`, `routing_no`, `swift` |

#### Commercial & Compliance
| Entity | Table | Purpose |
|---|---|---|
| **Price List** | `price_lists` | `name`, `currency`, `is_default`, `effective_from/to` |
| **Price List Item** | `price_list_items` | `price_list_id`, `product_id` (FK nullable until INVENTORY exists — polymorphic-ready), `unit_price`, `min_qty`, `discount_pct` |
| **Partner Document** | `partner_documents` | KYC/docs: `type` (NID, Trade License, TIN, VAT, Agreement), `file_path`, `expiry_date`, `verified_at`, `verified_by` |
| **Partner Note** | `partner_notes` | Internal notes / follow-ups, `created_by`, `is_private` |

#### Workflow
| Entity | Table | Purpose |
|---|---|---|
| **Partner Approval** | `partner_approvals` | If verification workflow enabled: `requested_by`, `approved_by`, `status` (pending/approved/rejected), `remarks` |

### 5.3 Key Features & Pages

| Feature | Pages | Details |
|---|---|---|
| **Directory** | `Vendors/Index.vue`, `Customers/Index.vue`, `Partners/Index.vue` (all) | DataTable with filters: group, type, status, verified, district. Search by code/name/phone/TIN. Export Excel/PDF. |
| **Create/Edit** | `Vendors/Create.vue`, `Vendors/Edit.vue` (tabbed) | Tabs: Basic Info, Contacts (repeater), Addresses (repeater), Bank, Documents (upload), Commercial (price list, credit, terms). Auto-generate `partner_code` with `lockForUpdate` (same as `EMP-` pattern). |
| **Show/360° View** | `Vendors/Show.vue` | Header summary + tabs: Overview, Contacts, Addresses, Transactions (future: ledger), Documents, Activity Timeline, Related Sales/Purchases. |
| **Groups** | `Groups/Index.vue`, `Groups/Create.vue` | Tree view (like Departments) with parent/child. |
| **Price Lists** | `PriceLists/Index.vue`, `PriceLists/Show.vue` | Manage customer-specific pricing. |
| **Import/Export** | Import via `maatwebsite/excel` (like HRM `RealEmployeeImportSeeder`); Export via `CORE Exports` pattern. |
| **Verification Queue** | `Verifications/Index.vue` | Pending partner approvals — approve/reject with remarks. |
| **Options API** | `GET partner/api/options/{type}` | `vendors`, `customers`, `all-partners`, `partner-groups`, `price-lists` → powers `SearchableSelect` in INVENTORY/SALES/ACCOUNTS. |

### 5.4 Services & Repositories

```
Interfaces/
  PartnerRepositoryInterface
  PartnerGroupRepositoryInterface
  PriceListRepositoryInterface

Repositories/
  PartnerRepository (BuildsDatatableQueries, options(), findByCode(), balance recalc)
  PartnerGroupRepository (tree builder)
  PriceListRepository

Services/
  PartnerService (create with code gen + TX for contacts/addresses/banks)
  PartnerGroupService
  PriceListService
  PartnerCodeGeneratorService (single-responsibility: VEN-/CUS- sequential)
  PartnerBalanceService (recalculates cached balance from ledger — called by ACCOUNTS events)

Actions/
  VerifyPartnerAction (status transition + audit)
  MergePartnersAction (enterprise: deduplicate)

DTO/
  PartnerData (for cross-module consumption — never expose Eloquent)
```

### 5.5 Cross-Module Interface (What Others Consume)

```php
// PARTNER exposes — no other module queries partners table directly
interface PartnerDataProviderInterface {
    public function vendorOptions(?string $search, int $limit): Collection;
    public function customerOptions(?string $search, int $limit): Collection;
    public function findPartnerData(string $partnerCode): ?PartnerData; // DTO
    public function creditCheck(string $partnerCode, float $additionalAmount): CreditCheckResult; // DTO with isApproved, availableLimit
}
```

---

## 6. MODULE 2: INVENTORY

### 6.1 Domain Entities

#### Product Master
| Entity | Table | Purpose |
|---|---|---|
| **Category** | `inventory_categories` | Hierarchical (like partner_groups): `Electronics > Mobile > Accessories`. `parent_id`, `image`, `is_active` |
| **Brand** | `inventory_brands` | `name`, `logo`, `is_active` |
| **Unit of Measure** | `inventory_uoms` | `name` (Piece, Kg, Liter, Meter), `symbol`, `base_uom_id`, `conversion_factor` (e.g., 1 Carton = 12 Pieces) |
| **Product** | `inventory_products` | `sku` (auto `SKU-0001` + barcode), `name`, `description`, `category_id`, `brand_id`, `uom_id`, `type` (stockable, consumable, service, bundle), `barcode`, `hs_code`, `weight`, `dimensions`, `is_serialized`, `is_batch_tracked`, `shelf_life_days`, `reorder_level`, `reorder_qty`, `valuation_method` (fifo/weighted), `cost_price`, `selling_price`, `mrp`, `vat_rate`, `image`, `is_active` |
| **Product Variant** | `inventory_product_variants` | For size/color: `product_id`, `variant_code`, `attributes` (JSON: {color: Red, size: L}), `sku`, `barcode`, `cost_price`, `selling_price`, `image` |
| **Product Supplier** | `inventory_product_suppliers` | M2M: `product_id` ↔ `partner_id` (vendor), `supplier_sku`, `lead_time_days`, `min_order_qty`, `last_purchase_price` |
| **Bill of Materials** | `inventory_boms` | For manufacturing/bundle: `product_id` (finished), `component_product_id`, `qty`, `uom_id`, `is_optional` |
| **Product Image** | `inventory_product_images` | Gallery: `product_id`, `file_path`, `is_primary`, `sort_order` |

#### Warehouse & Location
| Entity | Table | Purpose |
|---|---|---|
| **Warehouse** | `inventory_warehouses` | `code` (WH-001), `name`, `partner_address_id` (link to PARTNER address for supplier warehouses), `manager_user_id` (CORE User), `is_default`, `is_active` |
| **Location** | `inventory_locations` | Bin/shelf inside warehouse: `warehouse_id`, `code` (A-01-03), `name`, `type` (receiving, storage, packing, shipping), `parent_id` (aisle → rack → bin), `capacity`, `is_active` |
| **Stock** | `inventory_stocks` | Current on-hand (materialized, updated via ledger): `product_id`, `variant_id`, `warehouse_id`, `location_id`, `lot_no`, `serial_no`, `qty_on_hand`, `qty_reserved`, `qty_available` (computed), `avg_cost`, `last_movement_at` — **unique** on (product, variant, warehouse, location, lot) |

#### Stock Movements (Ledger — Append-Only)
| Entity | Table | Purpose |
|---|---|---|
| **Stock Move** | `inventory_stock_moves` | The heart — every qty change: `move_no` (SM-00001), `product_id`, `variant_id`, `warehouse_id`, `location_id`, `qty` (+/-), `uom_id`, `unit_cost`, `total_cost`, `reference_type` (purchase, sale, transfer, adjustment, return, production), `reference_id`, `lot_no`, `serial_no`, `reason`, `moved_by`, `moved_at` — **never updated, only inserted + compensating entry** |
| **Stock Transfer** | `inventory_transfers` | `transfer_no` (TRF-00001), `from_warehouse_id`, `to_warehouse_id`, `status` (draft, in_transit, received, cancelled), `requested_by`, `approved_by`, `shipped_at`, `received_at` |
| **Transfer Item** | `inventory_transfer_items` | `transfer_id`, `product_id`, `variant_id`, `qty_requested`, `qty_shipped`, `qty_received`, `unit_cost` |
| **Stock Adjustment** | `inventory_adjustments` | `adjustment_no` (ADJ-00001), `warehouse_id`, `type` (increase, decrease, damage, expired, found), `reason`, `status` (draft, approved, posted), `approved_by` |
| **Adjustment Item** | `inventory_adjustment_items` | `adjustment_id`, `product_id`, `qty`, `unit_cost`, `reason` |
| **Stock Count** | `inventory_stock_counts` | Physical verification: `count_no`, `warehouse_id`, `status` (draft, counting, reconciled), `counted_by` |
| **Count Item** | `inventory_count_items` | `count_id`, `product_id`, `system_qty`, `counted_qty`, `variance`, `variance_cost`, `action` (adjust, recount) |
| **Lot/Batch** | `inventory_lots` | If batch-tracked: `lot_no`, `product_id`, `manufacture_date`, `expiry_date`, `supplier_lot_no`, `is_expired` |
| **Serial** | `inventory_serials` | If serialized: `serial_no`, `product_id`, `lot_id`, `status` (in_stock, sold, returned, damaged) |

### 6.2 Key Features & Pages

| Feature | Pages | Details |
|---|---|---|
| **Product Catalog** | `Products/Index.vue`, `Products/Create.vue` (tabbed), `Products/Show.vue` | DataTable with image thumb, category/brand filters, stock status badge (In Stock/Low/Out). Create tabs: General, Variants (repeater), Suppliers (SearchableSelect vendor), BOM, Images, Pricing, Inventory Settings. |
| **Categories/Brands/UOMs** | `Categories/Index.vue`, `Brands/Index.vue`, `Uoms/Index.vue` | Simple CRUD + tree for categories. |
| **Warehouses/Locations** | `Warehouses/Index.vue`, `Warehouses/Show.vue` (with location tree) | Warehouse list + detail showing location hierarchy + stock summary. |
| **Stock Overview** | `Stocks/Index.vue` | Aggregated view: product × warehouse × location → on_hand, reserved, available, avg cost, value. Filters: warehouse, category, low-stock, dead-stock. Export. |
| **Stock Ledger** | `Movements/Index.vue` | Chronological ledger: every move with reference link. Filters: product, warehouse, type, date range. |
| **Transfers** | `Transfers/Index.vue`, `Transfers/Create.vue`, `Transfers/Show.vue` | Create transfer → approve → ship (deduct source) → receive (add dest). Status workflow. |
| **Adjustments** | `Adjustments/Index.vue`, `Adjustments/Create.vue` | Draft → approve → post (creates stock_moves). Reason required for audit. |
| **Stock Count** | `Counts/Index.vue`, `Counts/Show.vue` | Initiate count → enter counted qty → variance report → reconcile (auto-creates adjustment). |
| **Barcode** | `Products/BarcodePrint.vue` | Print barcode labels (product SKU/barcode) using `barryvdh/laravel-dompdf`. |
| **Alerts** | Dashboard widgets | Low-stock alerts, expiring lots, dead stock, negative stock warnings. |

### 6.3 Services & Patterns

```
Services/
  ProductService, CategoryService, WarehouseService, LocationService
  StockService (central: moveStock(), reserveStock(), releaseReservation())
  TransferService (create → ship → receive with TX)
  AdjustmentService, CountService
  ValuationService (FIFO / Weighted Average — consumed by ACCOUNTS)
  ReorderService (generates purchase suggestions)

Actions/
  ReceiveStockAction (purchase receipt + valuation)
  IssueStockAction (sale issue + COGS calc)
  TransferStockAction (2 moves: out + in, same transfer_no)
  AdjustStockAction
  ReserveStockAction / ReleaseStockAction (for held sales orders)

DTO/
  ProductData, StockAvailabilityData, ValuationData

Providers Exposed:
  InventoryStockProviderInterface (for SALES: checkAvailability, reserve, deduct)
  InventoryValuationProviderInterface (for ACCOUNTS: getCost, getValue)
```

### 6.4 Critical Business Rules
- **No negative stock by default** (configurable per warehouse) — `StockService` throws `InsufficientStockException`.
- **All moves are append-only:** `inventory_stocks` is a cache updated via `stock_moves` inserts within DB transactions. Nightly reconcile job verifies.
- **Landed cost:** Future purchase module will add freight/customs to unit_cost — `ValuationService` already supports `total_cost = unit_cost + landed_cost_per_unit`.
- **Reservation pattern:** Sales order `reserves` stock (qty_reserved++), invoice `issues` (qty_on_hand-- & reserved--), cancellation `releases` (reserved--).

---

## 7. MODULE 3: ACCOUNTS (Financial Accounting)

### 7.1 The Most Critical Module — Double-Entry Foundation

> **Principle:** Every financial transaction is a **balanced journal entry** (debits = credits). No money moves without a voucher. This is non-negotiable for audit compliance.

#### Chart of Accounts (COA)
| Entity | Table | Purpose |
|---|---|---|
| **Account Group** | `accounts_groups` | Top-level: `Assets`, `Liabilities`, `Equity`, `Income`, `Expenses` → subgroups: `Current Assets > Cash & Bank > Cash in Hand` |
| **Account (Ledger)** | `accounts_chart` | `code` (1001, 1002 — hierarchical 4-digit), `name` (Cash in Hand — Head Office), `group_id`, `parent_id` (for sub-ledgers), `type` (asset/liability/equity/income/expense), `is_bank`, `is_cash`, `bank_account_no`, `opening_balance`, `current_balance` (cached), `is_active`, `is_system` (cannot delete: e.g., Sales, COGS, VAT Payable) |
| **Cost Center** | `accounts_cost_centers` | Dimensions: `Project`, `Department` (link to HRM Department), `Branch` — for P&L by cost center |
| **Fiscal Year** | `accounts_fiscal_years` | `name` (FY 2025-26), `start_date`, `end_date`, `status` (open, closed), `closed_by`, `closed_at` — **closed year is immutable** |
| **Fiscal Period** | `accounts_periods` | Monthly sub-periods of fiscal year: `fiscal_year_id`, `name` (July 2025), `start_date`, `end_date`, `status` (open, closed) |
| **Budget** | `accounts_budgets` | `fiscal_year_id`, `account_id`, `cost_center_id`, `period_id`, `budgeted_amount`, `actual_amount` (rolled up), `variance` |

#### Vouchers & Journals (The Transaction Core)
| Entity | Table | Purpose |
|---|---|---|
| **Voucher** | `accounts_vouchers` | Header: `voucher_no` (JV-2025-00001, PV-2025-00001, RV-2025-00001, CV-2025-00001, SV-2025-00001), `type` (journal, payment, receipt, contra, sales, purchase, payroll), `date`, `fiscal_year_id`, `period_id`, `reference_type` (sale, purchase, payroll_sheet, manual), `reference_id`, `narration`, `total_debit`, `total_credit` (must equal), `status` (draft, pending_approval, approved, posted, voided), `created_by`, `approved_by`, `posted_by`, `posted_at`, `voided_by`, `void_reason` |
| **Voucher Line** | `accounts_voucher_lines` | Detail: `voucher_id`, `account_id`, `partner_id` (nullable — for AP/AR sub-ledger), `cost_center_id`, `debit`, `credit`, `narration`, `reconciled` |
| **Voucher Approval** | `accounts_voucher_approvals` | Workflow: `voucher_id`, `approver_user_id`, `level` (1,2,3), `status`, `remarks` |

#### Voucher Types (Pre-configured Journals)
| Voucher Type | Voucher Prefix | Typical Entry | Auto-Generated By |
|---|---|---|---|
| **Journal (JV)** | `JV-` | Manual adjustments, opening balances, accruals | Manual |
| **Payment (PV)** | `PV-` | Bank/Cash payment to vendor/expense | Manual + Purchase |
| **Receipt (RV)** | `RV-` | Customer receipt, cash collection | Manual + Sales/POS |
| **Contra (CV)** | `CV-` | Cash ↔ Bank transfer | Manual |
| **Sales (SV)** | `SV-` | Customer invoice (AR) | SALES module |
| **Purchase (BV)** | `BV-` | Vendor bill (AP) | Future Purchase / Manual |
| **Payroll (PY)** | `PY-` | Salary disbursement | PAYROLL module |
| **Inventory (IV)** | `IV-` | Stock valuation, COGS, adjustments | INVENTORY module |

#### AP / AR (Sub-Ledgers)
| Entity | Table | Purpose |
|---|---|---|
| **Payable** | `accounts_payables` | Vendor bill tracking: `voucher_id`, `partner_id` (vendor), `bill_no`, `bill_date`, `due_date`, `amount`, `paid_amount`, `balance`, `status` (unpaid, partially_paid, paid, overdue) |
| **Receivable** | `accounts_receivables` | Customer invoice tracking: `voucher_id`, `partner_id` (customer), `invoice_no`, `invoice_date`, `due_date`, `amount`, `paid_amount`, `balance`, `status` |
| **Payment Allocation** | `accounts_payment_allocations` | Links payment voucher to bills/invoices: `payment_voucher_id`, `payable_id`/`receivable_id`, `allocated_amount` — supports partial payments |
| **Bank Account** | `accounts_bank_accounts` | `account_id` (FK to COA), `bank_name`, `account_no`, `opening_balance`, `current_balance` |
| **Bank Reconciliation** | `accounts_bank_reconciliations` | `bank_account_id`, `statement_date`, `statement_balance`, `book_balance`, `difference`, `status`, `reconciled_by` |
| **Reconciliation Item** | `accounts_reconciliation_items` | `reconciliation_id`, `voucher_line_id`, `is_matched`, `notes` |
| **Tax/VAT Config** | `accounts_tax_configs` | `name` (VAT 15%), `rate`, `account_id` (VAT Payable/Receivable leg), `is_active` |
| **Exchange Rate** | `accounts_exchange_rates` | `currency` (USD, BDT), `rate`, `effective_date` — for multi-currency (future) |

### 7.2 Key Features & Pages

| Feature | Pages | Details |
|---|---|---|
| **Chart of Accounts** | `Coa/Index.vue` (tree), `Coa/Create.vue`, `Coa/Show.vue` | Tree view (collapsible, like TreeSidebar) showing hierarchy + balances. Create with parent selector. System accounts locked. Search + filter by group. |
| **Fiscal Years/Periods** | `FiscalYears/Index.vue`, `FiscalYears/Show.vue` | List FY with period breakdown. Close period/year (with confirmation + audit). Closed periods block voucher posting for that date. |
| **Vouchers** | `Vouchers/Index.vue`, `Vouchers/Create.vue` (dynamic), `Vouchers/Show.vue` | DataTable with filters: type, status, date range, account. Create: select voucher type → dynamic form with line repeater (account SearchableSelect, debit/credit, running balance check). **Live validation: debits must equal credits before submit.** |
| **Voucher Workflow** | `Vouchers/Show.vue` actions | `Save Draft → Submit for Approval → Approve → Post` (creates GL entries + updates AP/AR). `Void` creates reversal entry (never delete). Role-gated. |
| **Payables** | `Payables/Index.vue`, `Payables/Show.vue` | Vendor balances, aging report, due/overdue highlighting. Pay button → payment voucher. |
| **Receivables** | `Receivables/Index.vue`, `Receivables/Show.vue` | Customer balances, aging, collection status. Receipt button → receipt voucher. |
| **Bank Accounts** | `Banks/Index.vue`, `Banks/Show.vue`, `Banks/Reconcile.vue` | List bank ledgers + reconcile interface (match statement lines to voucher lines). |
| **Trial Balance** | `Reports/TrialBalance.vue` | Date-range TB: all accounts with opening, period movement, closing. Export. |
| **Profit & Loss** | `Reports/ProfitLoss.vue` | Income vs Expenses for period, by cost center. |
| **Balance Sheet** | `Reports/BalanceSheet.vue` | Assets = Liabilities + Equity as of date. |
| **General Ledger** | `Reports/Ledger.vue` | Account-wise transaction history with running balance. |
| **Aging Reports** | `Reports/AgingPayable.vue`, `Reports/AgingReceivable.vue` | Buckets: Current, 1-30, 31-60, 61-90, 90+ days. |
| **Cash Flow** | `Reports/CashFlow.vue` | Inflows/outflows by category. |
| **Day Book** | `Reports/DayBook.vue` | Chronological all vouchers for a day. |
| **Budgets** | `Budgets/Index.vue`, `Budgets/Create.vue` | Set budget per account/cost-center/period → variance report. |
| **Opening Balances** | `OpeningBalances/Index.vue` | One-time FY opening JV — migrated from legacy. |
| **Period Close** | `Periods/Close.vue` | Wizard: validate all vouchers posted → carry forward → lock period. |

### 7.3 Services & Critical Actions

```
Services/
  ChartOfAccountsService, FiscalYearService, PeriodService
  VoucherService (create/update/submit/approve/post/void with TX)
  PayableService, ReceivableService, BankReconciliationService
  TrialBalanceService, ProfitLossService, BalanceSheetService, LedgerService
  BudgetService, OpeningBalanceService

Actions/  (PAYROLL-style — transactional, single-purpose)
  CreateVoucherAction (validates debit=credit, period open, account active)
  PostVoucherAction (status → posted, updates account balances, creates AP/AR entries, fires VoucherPosted event)
  VoidVoucherAction (creates reversal voucher, never deletes)
  ClosePeriodAction (validates no draft vouchers in period, locks)
  CloseFiscalYearAction (transfers P&L to retained earnings, opens next FY)
  ReconcileBankAction
  AllocatePaymentAction (links payment to invoices/bills, handles partial)

DTO/
  VoucherData, VoucherLineData, TrialBalanceRow, LedgerEntryData

Providers Exposed:
  AccountingProviderInterface (for SALES/INVENTORY/PAYROLL to post journals without knowing COA)
    -> postSaleInvoice(dto): Voucher
    -> postPurchaseBill(dto): Voucher
    -> postPayrollSheet(dto): Voucher
    -> postInventoryAdjustment(dto): Voucher
    -> getAccountBalance(accountCode): float

Events/Listeners:
  VoucherPosted -> UpdateAccountBalances, UpdatePartnerBalances (if partner_id set)
  PeriodClosed -> PreventBackdatedPosting
  SaleCompleted (from SALES) -> PostSaleToLedgerListener
  PayrollApproved (from PAYROLL) -> PostPayrollToLedgerListener
```

### 7.4 Business Rules (Non-Negotiable for Accounts)
1. **Double-entry invariant:** `SUM(debit) = SUM(credit)` per voucher — enforced in `FormRequest` + `CreateVoucherAction` + DB check constraint.
2. **No edit after posting:** Posted vouchers are immutable. Correction = void + new voucher (audit trail preserved).
3. **Period lock:** Cannot post/void in a closed period or closed fiscal year. `VoucherService` checks `PeriodService::isOpen(date)` before any write.
4. **Account balances are cached but reconcilable:** `accounts_chart.current_balance` updated via `PostVoucherAction` within TX; nightly `ReconcileAccountBalancesJob` verifies `SUM(voucher_lines)` per account.
5. **AP/AR are sub-ledgers:** Partner balance in `partners.current_balance` must equal `SUM(receivables.balance - payables.balance)` — cross-checked by `AccountsReconciliationJob`.
6. **Multi-currency ready (Phase 2):** Schema includes `currency` + `exchange_rate` on vouchers, but Phase 1 is BDT-only to reduce complexity.

---

## 8. MODULE 4: SALES & POS

### 8.1 Domain Entities

#### Sales Pipeline
| Entity | Table | Purpose |
|---|---|---|
| **Quotation** | `sales_quotations` | `quote_no` (QT-2025-00001), `partner_id` (customer), `date`, `valid_until`, `warehouse_id`, `price_list_id`, `subtotal`, `discount_type` (percent/fixed), `discount_value`, `vat_amount`, `total`, `status` (draft, sent, accepted, rejected, expired, converted), `created_by`, `approved_by`, `converted_to_order_id`, `notes`, `terms` |
| **Quotation Item** | `sales_quotation_items` | `quotation_id`, `product_id`, `variant_id`, `description`, `qty`, `uom_id`, `unit_price`, `discount_pct`, `vat_rate`, `line_total` |
| **Sales Order** | `sales_orders` | `order_no` (SO-2025-00001), `partner_id`, `quotation_id` (nullable), `date`, `delivery_date`, `warehouse_id`, `subtotal`, `discount`, `vat`, `total`, `paid_amount`, `balance`, `status` (draft, confirmed, partially_delivered, delivered, invoiced, cancelled), `created_by`, `approved_by` |
| **Order Item** | `sales_order_items` | `order_id`, `product_id`, `variant_id`, `qty_ordered`, `qty_delivered`, `qty_invoiced`, `unit_price`, `discount`, `vat`, `line_total` |
| **Delivery Note** | `sales_deliveries` | `delivery_no` (DN-2025-00001), `order_id`, `warehouse_id`, `delivery_date`, `delivered_by`, `received_by`, `status` (draft, shipped, delivered, returned) |
| **Delivery Item** | `sales_delivery_items` | `delivery_id`, `order_item_id`, `product_id`, `qty_delivered`, `lot_no`, `serial_no` |
| **Sales Invoice** | `sales_invoices` | `invoice_no` (INV-2025-00001), `partner_id`, `order_id` (nullable — direct invoice allowed), `delivery_id` (nullable), `warehouse_id`, `date`, `due_date`, `subtotal`, `discount`, `vat`, `total`, `paid_amount`, `balance`, `status` (draft, posted, partially_paid, paid, voided, returned), `payment_terms`, `posted_voucher_id` (FK to ACCOUNTS), `created_by`, `posted_by` |
| **Invoice Item** | `sales_invoice_items` | `invoice_id`, `product_id`, `variant_id`, `qty`, `unit_price`, `discount`, `vat`, `line_total`, `cogs` (captured at posting for P&L) |
| **Sales Return** | `sales_returns` | `return_no` (SR-2025-00001), `invoice_id`, `partner_id`, `date`, `reason`, `subtotal`, `vat`, `total`, `status` (draft, approved, refunded, restocked), `refund_voucher_id`, `restocked` |
| **Return Item** | `sales_return_items` | `return_id`, `invoice_item_id`, `product_id`, `qty`, `unit_price`, `line_total`, `condition` (resalable, damaged) |
| **Discount/Promotion** | `sales_promotions` | `name`, `code` (coupon), `type` (percent, fixed, buy_x_get_y), `value`, `min_order_amount`, `max_discount`, `product_id` (nullable — all products if null), `category_id` (nullable), `start_date`, `end_date`, `usage_limit`, `used_count`, `is_active` |

#### POS-Specific
| Entity | Table | Purpose |
|---|---|---|
| **POS Terminal** | `sales_pos_terminals` | `code` (POS-01), `name` (Counter 1 — Gulshan), `warehouse_id` (stock source), `account_id` (cash account in COA), `user_id` (assigned cashier), `is_active`, `receipt_header`, `receipt_footer` |
| **POS Shift** | `sales_pos_shifts` | `terminal_id`, `user_id` (cashier), `opening_time`, `closing_time`, `opening_cash`, `closing_cash_expected`, `closing_cash_actual`, `cash_difference`, `total_sales`, `total_transactions`, `status` (open, closed, discrepancy_review) |
| **POS Sale** | `sales_pos_sales` | Lightweight fast table for POS: `sale_no` (POS-2025-00001), `terminal_id`, `shift_id`, `partner_id` (nullable — walk-in), `invoice_id` (FK after posting), `date`, `subtotal`, `discount`, `vat`, `total`, `payment_method` (cash, card, mobile_banking, credit), `payment_details` (JSON: {card_last4, bkash_trx}), `status` (completed, voided, held), `created_by` |
| **POS Sale Item** | `sales_pos_sale_items` | `pos_sale_id`, `product_id`, `variant_id`, `qty`, `unit_price`, `discount`, `line_total` |
| **Held Sale** | `sales_held_sales` | Parked transactions: `terminal_id`, `user_id`, `items` (JSON), `partner_id`, `reason` (customer picking more) |
| **Cash Drawer Log** | `sales_cash_drawer_logs` | `shift_id`, `type` (open, sale, cash_in, cash_out, close), `amount`, `reason`, `performed_by`, `performed_at` |

### 8.2 Key Features & Pages

| Feature | Pages | Details |
|---|---|---|
| **Quotations** | `Quotations/Index.vue`, `Quotations/Create.vue`, `Quotations/Show.vue` | DataTable with status badges. Create: customer SearchableSelect → add products (search with stock availability + price from price_list) → auto-calc totals. Actions: Send (email/PDF), Accept (→ SO), Reject, Duplicate, Convert to Order. |
| **Sales Orders** | `Orders/Index.vue`, `Orders/Create.vue`, `Orders/Show.vue` | Similar with delivery tracking. Confirm → reserve stock (INVENTORY). Partial delivery support. |
| **Deliveries** | `Deliveries/Index.vue`, `Deliveries/Create.vue` | Create from SO: select items + qty to deliver → deduct stock → update SO delivered qty. Print delivery challan. |
| **Invoices** | `Invoices/Index.vue`, `Invoices/Create.vue`, `Invoices/Show.vue` | Create from SO/Delivery or direct. Post → creates ACCOUNTS voucher (AR) + deducts stock (if not already via delivery) + fires events. PDF invoice with company letterhead. Payment allocation. |
| **Returns** | `Returns/Index.vue`, `Returns/Create.vue`, `Returns/Show.vue` | Create from invoice → approve → restock (if resalable) + credit note voucher. |
| **Promotions** | `Promotions/Index.vue`, `Promotions/Create.vue` | Manage discounts/coupons. Auto-applied at POS/Sales if eligible. |
| **POS Terminal — The Crown Jewel** | `Pos/Terminal.vue` (full-screen) | **Premium, keyboard-first, offline-resilient POS:** Left: product grid (category filter, barcode scan, search). Center: cart (qty +/- , discount, void line). Right: customer (walk-in/customer select), totals, payment. Bottom: action bar (Hold, Discount, Customer, Pay). **Must work without mouse.** |
| **POS Pay Flow** | `Pos/PayModal.vue` | Select payment method → enter amount → calculate change → choose print/email → complete. Creates `pos_sale` + `invoice` + `voucher` + `stock_moves` in one TX. |
| **POS Shifts** | `Pos/Shifts/Index.vue`, `Pos/Shifts/Show.vue` | Open shift (enter opening cash) → sales → close shift (enter counted cash → variance). Manager approves discrepancy. |
| **POS Held/Parked** | `Pos/Held.vue` | List parked sales → resume. |
| **POS Reports** | `Pos/Reports.vue` | Shift summary, terminal summary, cashier performance, hourly sales, product mix. |
| **Pricing** | Auto-resolved | Price priority: `Customer Price List > Product Selling Price > MRP`. Discount priority: `Promotion > Line Discount > Order Discount`. |
| **Barcode Scan** | Everywhere | Scan SKU/barcode in quotation/order/POS → instant add. Uses `inventory_products.barcode`. |

### 8.3 POS — Production-Grade Requirements (Premium Feel)

```
Performance:
  - Product search: debounced, indexed, returns in <100ms for 10k SKUs
  - Cart operations: instant (client-side), no server round-trip until Pay
  - Offline queue: if network drops, queue sales in localStorage/IndexedDB → sync when back (use @vueuse/core useStorage)

UX:
  - Full keyboard shortcuts: F2=Customer, F3=Product Search, F4=Discount, F6=Hold, F8=Pay, F9=Print Last, Esc=Clear
  - Barcode scanner: listens to rapid keystrokes (scanner emulates keyboard)
  - Touch-friendly: large buttons for tablet POS
  - Receipt: thermal 80mm + A4 PDF; auto-print on Pay (configurable)
  - Sound: beep on scan/add (optional)

Reliability:
  - Shift must be open to sell — prevents unattributed cash
  - Held sales survive page refresh (persisted)
  - No negative stock at POS (real-time check via InventoryStockProvider)
  - Concurrent safety: optimistic locking on stock; retry on conflict

Security:
  - Cashier can only see own terminal/shift
  - Manager PIN required for: discount > threshold, void, price override, close with variance
  - Every POS action logged (who, when, terminal, amount)
```

### 8.4 Services & Actions

```
Services/
  QuotationService, OrderService, DeliveryService, InvoiceService, ReturnService
  PosTerminalService, PosShiftService, PosSaleService
  PromotionService, PricingService (resolves effective price)

Actions/
  ConfirmOrderAction (reserve stock + status → confirmed)
  DeliverOrderAction (create delivery + deduct stock)
  PostInvoiceAction (create ACCOUNTS voucher + update receivables + deduct stock if needed)
  VoidInvoiceAction (reversal voucher + restock)
  ApproveReturnAction (restock + credit voucher)
  OpenShiftAction, CloseShiftAction
  CompletePosSaleAction (THE big TX: pos_sale + invoice + stock_moves + voucher + drawer log)
  HoldSaleAction, ResumeSaleAction

DTO/
  SaleData, InvoiceData, PosSaleData, PricingResultData

Providers Exposed:
  SalesDataProviderInterface (for ACCOUNTS reporting: sales by period/customer/product)
```

---

## 9. CROSS-CUTTING ENTERPRISE CONCERNS

### 9.1 Audit & Compliance
- All new models use `LogsActivity` + `SoftDeletes` (like HRM Employee).
- State transitions use `AuditTrailService::log()` with before/after snapshots.
- Vouchers, stock moves, POS sales are **never hard-deleted** — only voided/cancelled with reversal entries.
- `activity_log` already retains 3655 days — sufficient for BD regulatory (5-year) + internal (10-year).

### 9.2 Numbering & Code Generation
- Central `CodeGeneratorService` per module (like `Employee`'s `EMP-0001` with `lockForUpdate`) — prevents race in concurrent POS.
- Format: `{PREFIX}-{FY}-{SEQ}` e.g., `INV-2025-00001`, `JV-2025-00001`, `POS-2025-00001`. FY in prefix enables FY-wise reset.
- Configurable in `accounts_settings` / `sales_settings` tables.

### 9.3 File Uploads & Documents
- Reuse HRM `photo` pattern: `$request->file('doc')->store('partners|products|vouchers', 'public')`.
- Partner KYC, product images, voucher attachments, invoice PDFs — all via `storage/app/public` + `php artisan storage:link`.
- Future: S3-compatible (MinIO/AWS) via `filesystems.php` — no code change, just env.

### 9.4 Exports & Imports
- Exports: Reuse `CORE Exports/` + `ExportController` + `ExportService` (Excel via `maatwebsite/excel`, PDF via `dompdf`). Already supports chunked streaming + row limits.
- Imports: New `Imports/` per module (e.g., `PartnerImport`, `ProductImport`, `OpeningBalanceImport`) with validation + dry-run + error report — same UX as HRM employee import.

### 9.5 Notifications (Future-Ready)
- Schema-ready: `notifications` table exists (Laravel default). New modules fire `PartnerVerified`, `LowStockAlert`, `VoucherPendingApproval`, `ShiftClosedWithVariance` notifications — queued via `ShouldQueue`.
- Phase 1: In-app bell (like HRM's future plan). Phase 2: Email + SMS (SSL Wireless / Bulk SMS BD).

### 9.6 Multi-Currency & Localization
- Phase 1: BDT only, English. Schema includes `currency` columns for Phase 2 (USD/EUR for import/export businesses).
- Date format: `d-m-Y` (BD standard) — already used in HRM. Use `formatDate()` util.
- Number format: `1,23,456.00` (BD). Add `formatCurrency()` util.

### 9.7 Search & Performance
- All DataTables use `BuildsDatatableQueries` — indexed `LIKE` searches.
- For large catalogs (100k products): add `FULLTEXT` index on `products.name, sku, barcode` + consider Meilisearch in Phase 2.
- POS product grid: paginated + cached via `queryCache` (5-min TTL).
- Ledger reports: aggregate via materialized `account_balances` + `stock_ledger_summary` — nightly job.

---

## 10. SHARED UI/UX SYSTEM — PREMIUM ADMIN CONSOLE

### 10.1 Design System (Already Exists — Extend)
| Component | Location | Reuse For |
|---|---|---|
| `AdminLayout.vue` | `CORE/Resources/js/Layouts/` | All new pages — breadcrumbs, header, sidebar |
| `TreeSidebar.vue` | `CORE/Resources/js/Layouts/` | Already supports nested `children` — add 4 new groups |
| `DataTable.vue` | `CORE/Resources/js/Components/` | Every Index page — search, sort, filter, pagination, export |
| `SearchableSelect.vue` | `CORE/Resources/js/Components/` | Customer/vendor/product/account dropdowns |
| `ConfirmDialog.vue` | `CORE/Resources/js/Components/` | Delete/void/close confirmations |
| `ToastStack.vue` | `CORE/Resources/js/Components/` | Success/error feedback |
| `useAcl.ts` | `CORE/Resources/js/Composables/` | `can('vendors.create')` to show/hide buttons |
| `useToast.ts` | `CORE/Resources/js/Composables/` | Unified toast |
| `format.ts` | `CORE/Resources/js/Utils/` | `formatDate`, `formatCurrency` (extend) |
| `PermissionGrid.vue` | `CORE/Resources/js/Components/` | Role permission assignment — auto-discovers new perms |

### 10.2 New Shared Components (To Build Once, Use Everywhere)
| Component | Purpose | Used In |
|---|---|---|
| `MoneyInput.vue` | Currency input with formatting, BDT symbol, 2 decimals | Vouchers, invoices, prices |
| `LineItemsRepeater.vue` | Reusable line-item table (add/remove rows, auto-totals) | Vouchers, quotations, orders, invoices, transfers |
| `StatusBadge.vue` | Colored pill for status (draft/approved/posted/voided) | All modules |
| `BalanceDisplay.vue` | Dr/Cr balance with color (green debit, red credit) | COA, ledgers, partner 360° |
| `StockBadge.vue` | In Stock / Low / Out with qty | Product catalog, POS |
| `BarcodeScanner.vue` | Hidden input + beep, emits `onScan` | POS, product search |
| `ReceiptPreview.vue` | Thermal + A4 receipt preview | POS, invoice print |
| `AgingTable.vue` | AP/AR aging buckets | Accounts receivables/payables |

### 10.3 Page Layout Pattern (Tab Standard)
All create/edit pages follow HRM `Employees/Create.vue` tab pattern:
```
Header: Title + subtitle + Save/Cancel buttons
Tabs:   General | Additional | Related | Documents
        (using radix-vue Tabs or simple step navigation)
Repeater sections: Contacts, Addresses, Line Items — "Add Row" button
Footer: Save as Draft | Submit — with validation summary
Show pages: Header stats (4 KPI cards) + tabbed detail + activity timeline
```

### 10.4 POS Terminal Design (Premium — Separate Layout)
- **Does NOT use `AdminLayout`** — full-screen dedicated layout (`PosLayout.vue`) with: top bar (terminal name, cashier, shift timer, cash amount) + main grid + bottom action bar.
- **Dark-friendly** for low-light retail; high contrast for readability.
- **Responsive:** Desktop (3-column), Tablet (2-column with drawer), Mobile (not supported — POS is desktop/tablet only).

---

## 11. FILE & FOLDER BLUEPRINT (Mirrors Existing Structure)

Each new module follows the **exact HRM/PAYROLL skeleton**:

```
modules/
├── CORE/                          (existing — unchanged, but menu.ts + vite.config.ts extended)
├── HRM/                           (existing)
├── PAYROLL/                       (existing)
│
├── PARTNER/                       ← NEW: Vendor & Customer Management
│   ├── Config/partner.php
│   ├── Database/
│   │   ├── Migrations/            (5-6 migrations)
│   │   ├── Seeders/PartnerPermissionSeeder.php, PartnerGroupSeeder.php
│   │   └── Factories/PartnerFactory.php, PartnerGroupFactory.php
│   ├── Models/
│   │   ├── Partner.php, PartnerGroup.php, PartnerContact.php
│   │   ├── PartnerAddress.php, PartnerBank.php, PartnerDocument.php
│   │   ├── PriceList.php, PriceListItem.php
│   │   └── PartnerNote.php
│   ├── Interfaces/
│   │   ├── PartnerRepositoryInterface.php
│   │   ├── PartnerGroupRepositoryInterface.php
│   │   ├── PriceListRepositoryInterface.php
│   │   └── PartnerDataProviderInterface.php      (cross-module contract)
│   ├── Repositories/
│   │   ├── PartnerRepository.php
│   │   ├── PartnerGroupRepository.php
│   │   └── PriceListRepository.php
│   ├── Services/
│   │   ├── PartnerService.php, PartnerGroupService.php
│   │   ├── PriceListService.php, PartnerCodeGeneratorService.php
│   │   └── PartnerBalanceService.php
│   ├── Actions/
│   │   ├── VerifyPartnerAction.php
│   │   └── MergePartnersAction.php
│   ├── DTO/
│   │   ├── PartnerData.php, CreditCheckResult.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── PartnerController.php (vendors + customers via filter)
│   │   │   ├── VendorController.php, CustomerController.php (thin wrappers)
│   │   │   ├── PartnerGroupController.php, PriceListController.php
│   │   │   └── OptionsController.php
│   │   └── Requests/
│   │       ├── StorePartnerRequest.php, UpdatePartnerRequest.php
│   │       └── StorePartnerGroupRequest.php
│   ├── Routes/web.php, api.php
│   ├── Resources/js/
│   │   ├── Pages/
│   │   │   ├── Vendors/Index.vue, Create.vue, Edit.vue, Show.vue
│   │   │   ├── Customers/Index.vue, Create.vue, Edit.vue, Show.vue
│   │   │   ├── Groups/Index.vue, Create.vue, Edit.vue
│   │   │   ├── PriceLists/Index.vue, Create.vue, Show.vue
│   │   │   └── Verifications/Index.vue
│   │   └── Components/ (if partner-specific)
│   ├── Providers/PARTNERServiceProvider.php
│   ├── Exports/PartnerExport.php
│   └── Console/Commands/ (if needed)
│
├── INVENTORY/                     ← NEW: Inventory Management
│   ├── Config/inventory.php
│   ├── Database/Migrations/       (8-10 migrations)
│   ├── Database/Seeders/InventoryPermissionSeeder.php, UomSeeder.php, CategorySeeder.php
│   ├── Database/Factories/
│   ├── Models/
│   │   ├── Category.php, Brand.php, Uom.php
│   │   ├── Product.php, ProductVariant.php, ProductSupplier.php, Bom.php, ProductImage.php
│   │   ├── Warehouse.php, Location.php, Stock.php
│   │   ├── StockMove.php, Transfer.php, TransferItem.php
│   │   ├── Adjustment.php, AdjustmentItem.php
│   │   ├── StockCount.php, CountItem.php
│   │   ├── Lot.php, Serial.php
│   ├── Interfaces/
│   │   ├── ProductRepositoryInterface.php, WarehouseRepositoryInterface.php
│   │   ├── StockRepositoryInterface.php, etc.
│   │   ├── InventoryStockProviderInterface.php     (for SALES)
│   │   └── InventoryValuationProviderInterface.php (for ACCOUNTS)
│   ├── Repositories/
│   ├── Services/
│   │   ├── ProductService.php, WarehouseService.php, LocationService.php
│   │   ├── StockService.php, TransferService.php, AdjustmentService.php
│   │   ├── CountService.php, ValuationService.php, ReorderService.php
│   ├── Actions/
│   │   ├── ReceiveStockAction.php, IssueStockAction.php
│   │   ├── TransferStockAction.php, AdjustStockAction.php
│   │   ├── ReserveStockAction.php, ReleaseStockAction.php
│   ├── DTO/ProductData.php, StockAvailabilityData.php, ValuationData.php
│   ├── Http/Controllers/
│   │   ├── ProductController.php, CategoryController.php, BrandController.php, UomController.php
│   │   ├── WarehouseController.php, LocationController.php
│   │   ├── StockController.php, MovementController.php
│   │   ├── TransferController.php, AdjustmentController.php, CountController.php
│   │   └── OptionsController.php
│   ├── Routes/web.php, api.php
│   ├── Resources/js/Pages/
│   │   ├── Products/Index.vue, Create.vue, Edit.vue, Show.vue, BarcodePrint.vue
│   │   ├── Categories/Index.vue, Brands/Index.vue, Uoms/Index.vue
│   │   ├── Warehouses/Index.vue, Show.vue
│   │   ├── Stocks/Index.vue, Movements/Index.vue
│   │   ├── Transfers/Index.vue, Create.vue, Show.vue
│   │   ├── Adjustments/Index.vue, Create.vue, Show.vue
│   │   └── Counts/Index.vue, Show.vue
│   ├── Providers/INVENTORYServiceProvider.php
│   ├── Jobs/ReconcileStockJob.php
│   └── Console/Commands/CheckLowStockCommand.php
│
├── ACCOUNTS/                      ← NEW: Financial Accounting
│   ├── Config/accounts.php
│   ├── Database/Migrations/       (8-10 migrations)
│   ├── Database/Seeders/AccountsPermissionSeeder.php, ChartOfAccountsSeeder.php, FiscalYearSeeder.php
│   ├── Models/
│   │   ├── AccountGroup.php, Account.php, CostCenter.php
│   │   ├── FiscalYear.php, Period.php, Budget.php
│   │   ├── Voucher.php, VoucherLine.php, VoucherApproval.php
│   │   ├── Payable.php, Receivable.php, PaymentAllocation.php
│   │   ├── BankAccount.php, BankReconciliation.php, ReconciliationItem.php
│   │   ├── TaxConfig.php, ExchangeRate.php
│   ├── Interfaces/
│   │   ├── AccountRepositoryInterface.php, VoucherRepositoryInterface.php
│   │   ├── PayableRepositoryInterface.php, etc.
│   │   └── AccountingProviderInterface.php         (for SALES/INVENTORY/PAYROLL)
│   ├── Repositories/
│   ├── Services/
│   │   ├── ChartOfAccountsService.php, FiscalYearService.php, PeriodService.php
│   │   ├── VoucherService.php, PayableService.php, ReceivableService.php
│   │   ├── BankReconciliationService.php, BudgetService.php
│   │   ├── TrialBalanceService.php, ProfitLossService.php, BalanceSheetService.php, LedgerService.php
│   ├── Actions/
│   │   ├── CreateVoucherAction.php, PostVoucherAction.php, VoidVoucherAction.php
│   │   ├── ClosePeriodAction.php, CloseFiscalYearAction.php
│   │   ├── ReconcileBankAction.php, AllocatePaymentAction.php
│   ├── DTO/VoucherData.php, VoucherLineData.php, TrialBalanceRow.php
│   ├── Http/Controllers/
│   │   ├── ChartOfAccountsController.php, FiscalYearController.php, PeriodController.php
│   │   ├── VoucherController.php, PayableController.php, ReceivableController.php
│   │   ├── BankController.php, ReconciliationController.php
│   │   ├── ReportController.php (trial, P&L, BS, ledger, aging, cashflow, daybook)
│   │   ├── BudgetController.php, OpeningBalanceController.php
│   │   └── OptionsController.php
│   ├── Routes/web.php, api.php
│   ├── Resources/js/Pages/
│   │   ├── Coa/Index.vue, Create.vue, Edit.vue, Show.vue
│   │   ├── FiscalYears/Index.vue, Show.vue, Close.vue
│   │   ├── Vouchers/Index.vue, Create.vue, Show.vue
│   │   ├── Payables/Index.vue, Show.vue, Receivables/Index.vue, Show.vue
│   │   ├── Banks/Index.vue, Show.vue, Reconcile.vue
│   │   ├── Reports/TrialBalance.vue, ProfitLoss.vue, BalanceSheet.vue, Ledger.vue, AgingPayable.vue, AgingReceivable.vue, CashFlow.vue, DayBook.vue
│   │   ├── Budgets/Index.vue, Create.vue
│   │   └── OpeningBalances/Index.vue
│   ├── Providers/ACCOUNTSServiceProvider.php
│   ├── Jobs/ReconcileAccountBalancesJob.php
│   ├── Listeners/PostVoucherListener.php (update balances), PeriodClosedListener.php
│   └── Exports/ + Imports/
│
├── SALES/                         ← NEW: Sales & POS
│   ├── Config/sales.php
│   ├── Database/Migrations/       (8-10 migrations)
│   ├── Database/Seeders/SalesPermissionSeeder.php, PosTerminalSeeder.php
│   ├── Models/
│   │   ├── Quotation.php, QuotationItem.php
│   │   ├── Order.php, OrderItem.php
│   │   ├── Delivery.php, DeliveryItem.php
│   │   ├── Invoice.php, InvoiceItem.php
│   │   ├── Return.php, ReturnItem.php
│   │   ├── Promotion.php
│   │   ├── PosTerminal.php, PosShift.php, PosSale.php, PosSaleItem.php
│   │   ├── HeldSale.php, CashDrawerLog.php
│   ├── Interfaces/
│   │   ├── QuotationRepositoryInterface.php, OrderRepositoryInterface.php
│   │   ├── InvoiceRepositoryInterface.php, PosTerminalRepositoryInterface.php
│   │   └── SalesDataProviderInterface.php
│   ├── Repositories/
│   ├── Services/
│   │   ├── QuotationService.php, OrderService.php, DeliveryService.php
│   │   ├── InvoiceService.php, ReturnService.php
│   │   ├── PosTerminalService.php, PosShiftService.php, PosSaleService.php
│   │   ├── PromotionService.php, PricingService.php
│   ├── Actions/
│   │   ├── ConfirmOrderAction.php, DeliverOrderAction.php
│   │   ├── PostInvoiceAction.php, VoidInvoiceAction.php, ApproveReturnAction.php
│   │   ├── OpenShiftAction.php, CloseShiftAction.php
│   │   ├── CompletePosSaleAction.php, HoldSaleAction.php
│   ├── DTO/SaleData.php, InvoiceData.php, PosSaleData.php, PricingResultData.php
│   ├── Http/Controllers/
│   │   ├── QuotationController.php, OrderController.php, DeliveryController.php
│   │   ├── InvoiceController.php, ReturnController.php, PromotionController.php
│   │   ├── PosTerminalController.php, PosShiftController.php, PosSaleController.php
│   │   └── OptionsController.php
│   ├── Routes/web.php, api.php
│   ├── Resources/js/
│   │   ├── Pages/
│   │   │   ├── Quotations/Index.vue, Create.vue, Show.vue
│   │   │   ├── Orders/Index.vue, Create.vue, Show.vue
│   │   │   ├── Deliveries/Index.vue, Create.vue, Show.vue
│   │   │   ├── Invoices/Index.vue, Create.vue, Show.vue
│   │   │   ├── Returns/Index.vue, Create.vue, Show.vue
│   │   │   ├── Promotions/Index.vue, Create.vue
│   │   │   ├── Pos/Terminal.vue, Pos/PayModal.vue, Pos/Held.vue
│   │   │   ├── Pos/Shifts/Index.vue, Show.vue
│   │   │   └── Pos/Reports.vue
│   │   ├── Layouts/PosLayout.vue      (full-screen POS layout)
│   │   └── Components/PosCart.vue, PosProductGrid.vue, PosPayment.vue, ReceiptPreview.vue
│   ├── Providers/SALESServiceProvider.php
│   ├── Events/SaleCompleted.php, InvoicePosted.php
│   └── Jobs/SyncOfflineSalesJob.php
│
└── _shared additions_
    ├── resources/js/app.ts            (add modulePages glob already supports SALES::* etc.)
    ├── vite.config.ts                 (add @partner, @inventory, @accounts, @sales aliases)
    ├── modules/CORE/Resources/js/Config/menu.ts  (add 4 new groups)
    └── modules/CORE/Resources/js/Components/  (add MoneyInput, LineItemsRepeater, etc.)
```

**Total new files estimate:** ~280–340 files (70–85 per module) — consistent with HRM (130+ files) and PAYROLL (80+ files).

---

## 12. SOLID & DESIGN PATTERNS MAPPING

| SOLID Principle | How Each New Module Honors It |
|---|---|
| **S — Single Responsibility** | `PartnerService` only handles partner CRUD; `PartnerCodeGeneratorService` only generates codes; `PostVoucherAction` only posts; `PricingService` only resolves price. No god classes. |
| **O — Open/Closed** | New voucher types (e.g., future `Purchase`) extend via new `CreatePurchaseVoucherAction` without modifying `VoucherService`. New discount types extend `PromotionService` via strategy pattern. |
| **L — Liskov Substitution** | Any `PartnerDataProviderInterface` implementation (PARTNER) can be swapped with a mock in tests; `AccountingProviderInterface` has one contract consumed by 3 modules — any implementation must honor `postSaleInvoice(dto): Voucher`. |
| **I — Interface Segregation** | `InventoryStockProviderInterface` (check/reserve/deduct) is separate from `InventoryValuationProviderInterface` (cost/value) — SALES needs only stock, ACCOUNTS needs only valuation. No fat interface. |
| **D — Dependency Inversion** | SALES never `use Modules\INVENTORY\Models\Product`; it depends on `InventoryStockProviderInterface`. ACCOUNTS never touches `sales_invoices` table; it receives `VoucherData` DTO via `AccountingProviderInterface`. High-level modules depend on abstractions. |


| Pattern | Where Used |
|---|---|
| **Repository + Service** | All CRUD (like HRM Employee) |
| **Action (Single-Use Transaction)** | Complex workflows (like PAYROLL Actions): `CompletePosSaleAction`, `PostVoucherAction`, `TransferStockAction` |
| **DTO** | Cross-module data (like PAYROLL DTOs): `PartnerData`, `VoucherData`, `PosSaleData`, `StockAvailabilityData` |
| **Provider Interface** | Cross-module decoupling (like PAYROLL's HRM providers): `PartnerDataProviderInterface`, `AccountingProviderInterface` |
| **Event/Listener** | Async integration: `SaleCompleted → DeductInventory + PostToLedger` |
| **State Machine** | Voucher/Order/Shift status transitions — guarded in Service, not just DB enum |
| **Strategy** | Valuation (FIFO vs Weighted Avg), Pricing (Price List vs Promo vs MRP), Discount (percent vs fixed vs BxGy) |
| **Factory** | Code generation (`PartnerCodeGenerator`, `VoucherNoGenerator`), Model factories for testing |
| **Observer** | Model `LogsActivity` (Spatie) + custom `VoucherPosted` observer for balance cache |

---

## 13. DATABASE DESIGN SUMMARY

### 13.1 Entity-Relationship Overview

```
CORE: users, roles, permissions, activity_log
  │
  ├── HRM: organizations, departments, designations, employees ──► PAYROLL: salary_sheets, loans, pf
  │
  ├── PARTNER: partners ─┬─► partner_contacts, partner_addresses, partner_banks
  │                      ├─► partner_groups (self-ref)
  │                      ├─► price_lists → price_list_items ──► INVENTORY.products (FK, nullable Phase 1)
  │                      └─► partner_documents, partner_notes
  │
  ├── INVENTORY: categories, brands, uoms, products, variants ──► product_suppliers ──► PARTNER.partners
  │             warehouses, locations, stocks
  │             stock_moves (ledger) ◄── transfers, adjustments, counts, lots, serials
  │
  ├── ACCOUNTS: account_groups, chart (self-ref), cost_centers, fiscal_years, periods
  │             vouchers, voucher_lines ──► chart + PARTNER.partners + cost_centers
  │             payables, receivables, payment_allocations ──► PARTNER.partners + vouchers
  │             bank_accounts, reconciliations, budgets, tax_configs
  │
  └── SALES: quotations, orders, deliveries, invoices, returns ──► PARTNER.partners + INVENTORY.products/warehouses
             pos_terminals, pos_shifts, pos_sales ──► INVENTORY.warehouses + ACCOUNTS.accounts
             promotions ──► INVENTORY.products/categories
```

### 13.2 Key Constraints & Indexes
- **Unique:** `partners.partner_code`, `products.sku`, `products.barcode`, `warehouses.code`, `vouchers.voucher_no`, `invoices.invoice_no`, `pos_terminals.code`, `chart.code`
- **Composite unique:** `stocks(product_id, variant_id, warehouse_id, location_id, lot_no)`, `voucher_lines` no unique (multiple lines per account allowed)
- **FK + cascade:** `partner_contacts.partner_id → partners.id cascade`, `stock_moves.product_id → products.id restrict` (prevent delete if moves exist), `voucher_lines.account_id → chart.id restrict`
- **Soft deletes:** All masters (`partners`, `products`, `accounts`, `quotations`, etc.) — never hard delete if ledger entries exist
- **Indexes:** `partners.name`, `partners.phone`, `products.name`, `products.barcode`, `stock_moves.product_id + moved_at`, `vouchers.date`, `voucher_lines.account_id`, `invoices.partner_id + date`, `pos_sales.terminal_id + date`
- **Check constraints:** `vouchers.total_debit = total_credit`, `stocks.qty_on_hand >= 0` (if negative disallowed), `periods.end_date > start_date`

### 13.3 Migration Count
| Module | Migrations | Tables |
|---|---|---|
| PARTNER | 5–6 | 8 tables |
| INVENTORY | 8–10 | 15 tables |
| ACCOUNTS | 8–10 | 13 tables |
| SALES | 8–10 | 15 tables |
| **Total** | **~30 migrations** | **~51 tables** |

All migrations are **ordered by dependency** (PARTNER first → SALES last) and use `foreignId` with `constrained()` + `softDeletes()` + `timestamps()`.

---

## 14. PERMISSIONS (RBAC) MATRIX

Each module seeds its permissions via `*PermissionSeeder.php` (like `HRMPermissionSeeder`, `PAYROLLPermissionSeeder`). Permissions follow `module.resource.action` dot notation and are checked with `PermissionMiddleware` on routes + `useAcl().can()` in Vue.

### PARTNER (12 permissions)
```
partner.vendors.view, partner.vendors.create, partner.vendors.update, partner.vendors.delete
partner.customers.view, partner.customers.create, partner.customers.update, partner.customers.delete
partner.groups.manage
partner.pricelists.manage
partner.verifications.manage
partner.exports
```

### INVENTORY (18 permissions)
```
inventory.products.view, inventory.products.create, inventory.products.update, inventory.products.delete
inventory.categories.manage, inventory.brands.manage, inventory.uoms.manage
inventory.warehouses.manage, inventory.locations.manage
inventory.stocks.view, inventory.movements.view
inventory.transfers.manage, inventory.transfers.approve
inventory.adjustments.manage, inventory.adjustments.approve
inventory.counts.manage
inventory.reports.view
inventory.exports
```

### ACCOUNTS (20 permissions)
```
accounts.chart.view, accounts.chart.manage
accounts.fiscalyears.manage, accounts.periods.manage
accounts.vouchers.view, accounts.vouchers.create, accounts.vouchers.approve, accounts.vouchers.post, accounts.vouchers.void
accounts.payables.view, accounts.payables.manage
accounts.receivables.view, accounts.receivables.manage
accounts.banks.manage, accounts.reconciliation.manage
accounts.budgets.manage
accounts.reports.view (covers TB, P&L, BS, Ledger, Aging, Cashflow, Daybook)
accounts.exports
accounts.period.close, accounts.year.close
```

### SALES & POS (22 permissions)
```
sales.quotations.view, sales.quotations.create, sales.quotations.update, sales.quotations.delete, sales.quotations.approve
sales.orders.view, sales.orders.create, sales.orders.confirm, sales.orders.deliver
sales.invoices.view, sales.invoices.create, sales.invoices.post, sales.invoices.void
sales.deliveries.manage
sales.returns.view, sales.returns.create, sales.returns.approve
sales.promotions.manage
sales.reports.view
pos.terminal.view, pos.terminal.sell, pos.shifts.manage, pos.shifts.close, pos.void
sales.exports
```

**Total new permissions: ~72** → Added to `Super Admin` wildcard `*` automatically; other roles assigned via `Roles/Edit.vue → PermissionGrid`.

---

## 15. MENU & ROUTING DESIGN

### 15.1 Sidebar Additions (in `modules/CORE/Resources/js/Config/menu.ts`)

```typescript
// Existing: Dashboard, HRM, PAYROLL, Roster, Leave Management, Overtime, Attendance, Access Control, Design System, Audit

// NEW — 4 groups, ~35 items:

{
  key: 'partner', label: 'Vendors & Customers', icon: Handshake, // lucide: Handshake
  children: [
    { key: 'vendors', label: 'Vendors', route: 'partner.vendors.index', icon: Truck, permission: 'partner.vendors.view' },
    { key: 'customers', label: 'Customers', route: 'partner.customers.index', icon: UsersRound, permission: 'partner.customers.view' },
    { key: 'partner-groups', label: 'Partner Groups', route: 'partner.groups.index', icon: Network, permission: 'partner.groups.manage' },
    { key: 'price-lists', label: 'Price Lists', route: 'partner.pricelists.index', icon: Tag, permission: 'partner.pricelists.manage' },
  ]
},
{
  key: 'inventory', label: 'Inventory', icon: Package, // lucide: Package
  children: [
    { key: 'products', label: 'Products', route: 'inventory.products.index', icon: Package, permission: 'inventory.products.view' },
    { key: 'categories', label: 'Categories', route: 'inventory.categories.index', icon: Layers, permission: 'inventory.categories.manage' },
    { key: 'brands', label: 'Brands', route: 'inventory.brands.index', icon: Award, permission: 'inventory.brands.manage' },
    { key: 'warehouses', label: 'Warehouses', route: 'inventory.warehouses.index', icon: Warehouse, permission: 'inventory.warehouses.manage' },
    { key: 'stocks', label: 'Stock Overview', route: 'inventory.stocks.index', icon: Boxes, permission: 'inventory.stocks.view' },
    { key: 'movements', label: 'Stock Ledger', route: 'inventory.movements.index', icon: History, permission: 'inventory.movements.view' },
    { key: 'transfers', label: 'Stock Transfers', route: 'inventory.transfers.index', icon: ArrowLeftRight, permission: 'inventory.transfers.manage' },
    { key: 'adjustments', label: 'Adjustments', route: 'inventory.adjustments.index', icon: ClipboardList, permission: 'inventory.adjustments.manage' },
    { key: 'counts', label: 'Physical Count', route: 'inventory.counts.index', icon: ScanLine, permission: 'inventory.counts.manage' },
  ]
},
{
  key: 'accounts', label: 'Accounts', icon: BookOpen, // lucide: BookOpen
  children: [
    { key: 'coa', label: 'Chart of Accounts', route: 'accounts.coa.index', icon: ListTree, permission: 'accounts.chart.view' },
    { key: 'fiscal-years', label: 'Fiscal Years', route: 'accounts.fiscal-years.index', icon: CalendarDays, permission: 'accounts.fiscalyears.manage' },
    { key: 'vouchers', label: 'Vouchers', route: 'accounts.vouchers.index', icon: Receipt, permission: 'accounts.vouchers.view' },
    { key: 'payables', label: 'Payables (AP)', route: 'accounts.payables.index', icon: CreditCard, permission: 'accounts.payables.view' },
    { key: 'receivables', label: 'Receivables (AR)', route: 'accounts.receivables.index', icon: Wallet, permission: 'accounts.receivables.view' },
    { key: 'banks', label: 'Banks & Reconciliation', route: 'accounts.banks.index', icon: Building2, permission: 'accounts.banks.manage' },
    { key: 'budgets', label: 'Budgets', route: 'accounts.budgets.index', icon: PiggyBank, permission: 'accounts.budgets.manage' },
    { key: 'reports', label: 'Financial Reports', icon: BarChart3, permission: 'accounts.reports.view',
      children: [
        { key: 'trial-balance', label: 'Trial Balance', route: 'accounts.reports.trial', icon: Scale },
        { key: 'profit-loss', label: 'Profit & Loss', route: 'accounts.reports.pl', icon: TrendingUp },
        { key: 'balance-sheet', label: 'Balance Sheet', route: 'accounts.reports.bs', icon: Sheet },
        { key: 'ledger', label: 'General Ledger', route: 'accounts.reports.ledger', icon: BookMarked },
        { key: 'aging-payable', label: 'Aging — Payable', route: 'accounts.reports.aging-payable', icon: Clock3 },
        { key: 'aging-receivable', label: 'Aging — Receivable', route: 'accounts.reports.aging-receivable', icon: Clock3 },
        { key: 'cash-flow', label: 'Cash Flow', route: 'accounts.reports.cashflow', icon: Droplets },
        { key: 'day-book', label: 'Day Book', route: 'accounts.reports.daybook', icon: Calendar },
      ]
    },
  ]
},
{
  key: 'sales', label: 'Sales & POS', icon: ShoppingCart, // lucide: ShoppingCart
  children: [
    { key: 'quotations', label: 'Quotations', route: 'sales.quotations.index', icon: FileText, permission: 'sales.quotations.view' },
    { key: 'orders', label: 'Sales Orders', route: 'sales.orders.index', icon: ClipboardCheck, permission: 'sales.orders.view' },
    { key: 'deliveries', label: 'Deliveries', route: 'sales.deliveries.index', icon: Truck, permission: 'sales.deliveries.manage' },
    { key: 'invoices', label: 'Sales Invoices', route: 'sales.invoices.index', icon: Receipt, permission: 'sales.invoices.view' },
    { key: 'returns', label: 'Sales Returns', route: 'sales.returns.index', icon: Undo2, permission: 'sales.returns.view' },
    { key: 'promotions', label: 'Promotions', route: 'sales.promotions.index', icon: Percent, permission: 'sales.promotions.manage' },
    { key: 'pos-terminal', label: 'POS Terminal', route: 'sales.pos.terminal', icon: Monitor, permission: 'pos.terminal.sell' },
    { key: 'pos-shifts', label: 'POS Shifts', route: 'sales.pos.shifts.index', icon: Timer, permission: 'pos.shifts.manage' },
    { key: 'pos-reports', label: 'POS Reports', route: 'sales.pos.reports', icon: BarChart3, permission: 'sales.reports.view' },
  ]
},
```

> Icon imports extended in `iconLibrary.ts` + `menu.ts` — same pattern as HRM's `Building2, Network, BadgeCent...`.

### 15.2 Route Prefixes & Naming

| Module | Prefix | Name Prefix | Middleware |
|---|---|---|---|
| PARTNER | `partner` | `partner.` | `web, auth` + `PermissionMiddleware` per route |
| INVENTORY | `inventory` | `inventory.` | `web, auth` + `PermissionMiddleware` |
| ACCOUNTS | `accounts` | `accounts.` | `web, auth` + `PermissionMiddleware` |
| SALES | `sales` | `sales.` | `web, auth` + `PermissionMiddleware` |
| POS (inside SALES) | `sales/pos` | `sales.pos.` | `web, auth` + `PermissionMiddleware:pos.terminal.sell` |

All routes follow HRM's proven pattern: `Route::middleware(['web','auth'])->prefix('...')->name('...')->group(...)` with `PermissionMiddleware` per endpoint and `OptionsController` for searchable selects.

---

## 16. INTEGRATION & EVENT BUS

### 16.1 Synchronous (In-Transaction) Integrations
These happen **within the same DB transaction** — if one fails, all rollback:

| Flow | Steps in One TX |
|---|---|
| **Complete POS Sale** | `pos_sale` insert → `invoice` insert → `invoice_items` → `stock_moves` (deduct, with lot/serial) → `voucher` (SV: Dr Cash/AR, Cr Sales, Cr VAT Payable, Dr COGS / Cr Inventory) → `receivable` (if credit) → `cash_drawer_log` |
| **Post Invoice** | `invoice` status → posted → `stock_moves` (if not via delivery) → `voucher` → `receivable` |
| **Deliver Order** | `delivery` + `delivery_items` → `stock_moves` (deduct) → update `order` delivered qty |
| **Approve Return** | `return` → approved → `stock_moves` (restock) → reversal `voucher` → update `receivable` |

### 16.2 Asynchronous (Event-Driven) Integrations
For looser coupling — modules emit events, others listen (can be queued):

| Event (Emitter) | Listener (Consumer) | Action |
|---|---|---|
| `SaleCompleted` (SALES) | `DeductInventoryListener` (INVENTORY) | Reserve/deduct stock (if not already in TX) |
| `SaleCompleted` (SALES) | `PostSaleToLedgerListener` (ACCOUNTS) | Create sales voucher via `AccountingProvider` |
| `InvoicePosted` (SALES) | `UpdateReceivableListener` (ACCOUNTS) | Create/update receivable |
| `StockAdjusted` (INVENTORY) | `PostAdjustmentToLedgerListener` (ACCOUNTS) | Create inventory adjustment voucher |
| `PayrollSheetApproved` (PAYROLL) | `PostPayrollToLedgerListener` (ACCOUNTS) | Create payroll voucher (Dr Salaries, Cr Bank/Payable) |
| `PartnerCreditLimitExceeded` (PARTNER) | `NotifyManagerListener` (CORE) | Send notification |
| `PeriodClosed` (ACCOUNTS) | `PreventBackdatedPostingListener` (ALL) | Block any backdated voucher/invoice/move |

### 16.3 Cross-Module Contracts (Interfaces — The Only Allowed Coupling)

```
PARTNER exposes:
  PartnerDataProviderInterface
     → vendorOptions(), customerOptions(), findPartnerData(), creditCheck()

INVENTORY exposes:
  InventoryStockProviderInterface
     → checkAvailability(sku, warehouse, qty): bool
     → reserveStock(items, orderId): void
     → deductStock(items, invoiceId): void
     → restock(items, returnId): void
     → getStockLevels(productId): StockAvailabilityData[]
  InventoryValuationProviderInterface
     → getUnitCost(productId, variantId): float
     → getStockValue(warehouseId): float
     → getValuation(productId): ValuationData

ACCOUNTS exposes:
  AccountingProviderInterface
     → postVoucher(VoucherData): Voucher
     → postSaleInvoice(SaleInvoiceData): Voucher
     → postPurchaseBill(PurchaseData): Voucher
     → postPayrollSheet(PayrollData): Voucher
     → postInventoryAdjustment(AdjustmentData): Voucher
     → getAccountBalance(accountCode): float
     → isPeriodOpen(date): bool

SALES exposes:
  SalesDataProviderInterface
     → getSalesByPeriod(from, to): Collection<SaleData>
     → getCustomerSales(partnerId, from, to): Collection<SaleData>
     → getProductSales(productId, from, to): Collection<SaleData>
     (consumed by ACCOUNTS reports, dashboards)
```

> **Rule:** No module `use`s another module's `Models\` directly. Only `Interfaces\` + `DTO\`. Violation = code review rejection.

---

## 17. REPORTING & ANALYTICS LAYER

### 17.1 Operational Reports (Per Module, via `DataTable` + Export)

| Module | Reports |
|---|---|
| PARTNER | Partner directory, partner ledger (future: per-partner AP/AR from ACCOUNTS), group-wise summary, KYC expiry, credit limit utilization |
| INVENTORY | Stock summary (by warehouse/product/category), stock ledger, low-stock, dead-stock, expiring lots, valuation, movement history, transfer history, adjustment log, count variance |
| ACCOUNTS | Trial Balance, P&L, Balance Sheet, General Ledger, AP Aging, AR Aging, Cash Flow, Day Book, Budget vs Actual, Bank Reconciliation, Voucher listing, Period summary |
| SALES | Sales summary (by date/customer/product/cashier/terminal), order pipeline, delivery pending, invoice aging, return analysis, promotion effectiveness, POS shift summary, cashier performance, hourly sales |

### 17.2 Executive Dashboard (Extends `CORE DashboardController`)

New widgets per module — visible based on permissions:

```
Row 1 (KPIs):
  [Total Receivables] [Total Payables] [Stock Value] [Today's Sales] [Open Quotations] [Pending Deliveries]

Row 2 (Charts):
  [Sales Trend — 30 days] [Top 10 Products] [Stock by Warehouse] [Revenue vs Expenses — 6 months]

Row 3 (Alerts):
  [Low Stock — 12 items] [Overdue Receivables — 5 invoices] [Overdue Payables — 3 bills]
  [Pending Voucher Approvals — 4] [Held POS Sales — 2] [Expiring Lots — 7]

Row 4 (Recent Activity):
  [Recent Invoices — 5] [Recent Vouchers — 5] [Recent Stock Moves — 5]
```

Dashboard data comes from `DashboardService` per module, aggregated in `CORE DashboardController` (like PAYROLL's `PayrollDashboardService` pattern). Each stat respects ACL — cashier sees only own terminal, accountant sees all.

---

## 18. TESTING, QUALITY & DEVOPS STRATEGY

### 18.1 Testing Pyramid

| Layer | Tool | Coverage Target | Examples |
|---|---|---|---|
| **Unit** | Pest (PHP) + Vitest (JS) | 70%+ services/actions | `PartnerCodeGeneratorService`, `ValuationService::fifo()`, `PricingService::resolve()`, `MoneyInput.vue` |
| **Feature** | Pest + Laravel HTTP | Critical flows | Create vendor → search → edit; Create product → receive stock → check ledger; Create voucher → post → verify TB; Complete POS sale → verify invoice + stock + voucher |
| **Browser** | Manual QA checklist | POS terminal | Full keyboard flow, barcode scan, shift open/close, held sale, offline queue |
| **Integration** | Pest with real DB | Cross-module | POS sale posts correct GL entries; payroll posts to ledger; return restocks + creates credit note |
| **Vitest** | `vitest.config.ts` (existing) | Vue components | `DataTable` with new filterDefs, `PosCart` totals, `MoneyInput` formatting |

Factories for all new models (like `EmployeeFactory`) enable `php artisan db:seed --class=DemoSeeder` for QA.

### 18.2 Code Quality

| Tool | Purpose |
|---|---|
| **Laravel Pint** | PHP formatting (already in `composer.json`) |
| **Prettier** | JS/Vue formatting via `npm run format` |
| **ESLint** | Vue/TS linting via `npm run lint` |
| **Pest** | `php artisan test` — must pass before merge |
| **Vitest** | `npm run test:unit` — must pass before merge |
| **Larastan/PHPStan** | Level 5+ for new modules (add in Phase 0) |

### 18.3 DevOps & Deployment

| Concern | Strategy |
|---|---|
| **Migrations** | `php artisan migrate --graceful` — all new migrations are additive; no `dropColumn` on existing tables in Phase 1 |
| **Seeders** | `PARTNERPermissionSeeder`, `ChartOfAccountsSeeder`, etc. — idempotent (`firstOrCreate`), safe to re-run |
| **Zero-downtime** | Migrations first → code second (separate deploy steps); no breaking column renames |
| **Queue** | `php artisan queue:work` (existing `composer dev` runs `queue:listen`) — new jobs use `ShouldQueue`, `tries=3`, `backoff` |
| **Storage** | `php artisan storage:link` — product images, partner docs, voucher attachments |
| **Backup** | Daily DB dump + `storage/app` sync — before any `CloseFiscalYear` operation, auto-backup |
| **Env** | No new required env vars in Phase 1; `CORE_EXPORT_EXCEL_MAX_ROWS` already configurable |

---

## 19. PHASED IMPLEMENTATION ROADMAP (12–16 WEEKS)

### Phase 0 — Foundation (Week 1) — 1 week
| Task | Deliverable |
|---|---|
| Add Vite aliases `@partner`, `@inventory`, `@accounts`, `@sales` | `vite.config.ts` |
| Extend `menu.ts` skeleton (4 groups, placeholder routes) | Sidebar shows new sections (disabled until perms) |
| Create `shared` components: `MoneyInput.vue`, `LineItemsRepeater.vue`, `StatusBadge.vue` | Reusable across all phases |
| Define `CodeGeneratorService` base | Used by all modules |
| Create `modules/PARTNER|INVENTORY|ACCOUNTS|SALES` empty skeletons with `Providers` | Module auto-discovery works |
| Write `ENTERPRISE_TESTING_STRATEGY.md` | QA plan |
| **Exit criteria** | `npm run build` passes, menu renders, no broken routes |

### Phase 1 — PARTNER: Vendor & Customer Management (Weeks 2–3) — 2 weeks
| Track | Tasks |
|---|---|
| **Backend** | 5 migrations → 8 models → 3 interfaces → 3 repos → 4 services → 2 actions → 2 DTOs → 8 controllers → 8 request classes → 3 seeders |
| **Frontend** | Vendors (Index/Create/Edit/Show), Customers (same), Groups (Index/Create/Edit), PriceLists (Index/Create/Show), Verifications — ~12 Vue pages |
| **Integration** | `PartnerDataProviderInterface` + `OptionsController` for all future modules |
| **QA** | Factory + Pest feature tests + Excel import/export |
| **Exit criteria** | Can create vendor+customer, assign groups/contacts/addresses, search via DataTable, use in SearchableSelect from any page |

### Phase 2 — INVENTORY (Weeks 4–6) — 3 weeks
| Track | Tasks |
|---|---|
| **Week 4** | Categories, Brands, UOMs, Products (with variants, suppliers, BOM, images), Warehouses, Locations — masters |
| **Week 5** | Stocks, Movements, Transfers, Adjustments — transactional core |
| **Week 6** | Counts, Lots/Serials, Valuation, Reorder, Reports, Barcode Print — polish |
| **Frontend** | ~15 Vue pages + product image gallery + stock badges |
| **QA** | Stock ledger append-only test, negative-stock guard, valuation (FIFO/WAVG) unit tests |
| **Exit criteria** | Can manage 10k products, track stock per warehouse/location, transfer/adjust with audit, valuation correct |

### Phase 3 — ACCOUNTS (Weeks 7–10) — 4 weeks
| Track | Tasks |
|---|---|
| **Week 7** | COA (tree CRUD), Cost Centers, Fiscal Years/Periods, COA Seeder (80+ BD-standard accounts) |
| **Week 8** | Vouchers (all 8 types) with double-entry validation + workflow (draft→posted→voided) |
| **Week 9** | Payables, Receivables, Banks, Reconciliation, Budgets, Opening Balances |
| **Week 10** | Reports: Trial Balance, P&L, Balance Sheet, Ledger, Aging, Cash Flow, Day Book — all with export + period filter |
| **Frontend** | ~18 Vue pages + tree COA + voucher line repeater with live debit=credit check |
| **QA** | Double-entry invariant, period-lock, TB balances, P&L = Income - Expenses, BS = A = L + E |
| **Exit criteria** | Can run full accounting cycle: open FY → create vouchers → view TB/P&L/BS → close period |

### Phase 4 — SALES & POS (Weeks 11–14) — 4 weeks
| Track | Tasks |
|---|---|
| **Week 11** | Quotations, Orders, Deliveries — pipeline |
| **Week 12** | Invoices, Returns, Promotions — + ACCOUNTS voucher posting + INVENTORY deduction |
| **Week 13** | POS Terminal (Terminal.vue + PayModal + Held + full keyboard/barcode) — the premium experience |
| **Week 14** | POS Shifts, Reports, Receipt Print, Offline Queue, End-to-End QA |
| **Frontend** | ~14 Vue pages + PosLayout (full-screen) + PosCart/ProductGrid/Payment/ReceiptPreview |
| **QA** | Order→Delivery→Invoice→Ledger flow; POS sale → stock + voucher + shift; return → restock + credit note; promotion auto-apply |
| **Exit criteria** | Can run POS sale in <10s, shift reconciles, all financial reports reflect sales |

### Phase 5 — Hardening & Go-Live (Weeks 15–16) — 2 weeks
| Task | Deliverable |
|---|---|
| Cross-module E2E test suite (40+ Pest scenarios) | Green CI |
| Performance: index audit, query optimization, ledger aggregates | <200ms DataTable, <100ms POS search |
| Security: permission audit, void/reversal review, period-lock stress test | No unauthorized access |
| UAT with real BD data (1k partners, 5k products, 10k vouchers) | Stakeholder sign-off |
| Training: user manual (BD context: VAT, bKash), video walkthroughs | Enablement |
| Production deploy: migrate → seed → smoke test → handover | Live |
| **Exit criteria** | Production ERP with all 4 modules live, 99.5% uptime SLA |

> **Timeline is for a 2–3 person team** (1 backend + 1 frontend + 1 QA/lead). Single developer: ~20–24 weeks. Add 2 weeks buffer for BD-specific VAT/NBR compliance if required.

---

## 20. TEAM & EFFORT ESTIMATE

| Role | Responsibility | Allocation |
|---|---|---|
| **Tech Lead / Architect** | Module scaffolding, interface contracts, code review, integration points | 30% across all phases |
| **Backend Developer** | Migrations, models, repos, services, actions, controllers, events, tests | 100% (primary) |
| **Frontend Developer** | Vue pages, POS terminal, shared components, menu, reports, print layouts | 80% (Weeks 2–14) |
| **QA Engineer** | Factories, Pest/Vitest, manual POS testing, UAT, performance | 50% (from Week 3) |
| **Product Owner (You)** | Requirements sign-off, UAT, BD compliance (VAT, NBR, labor law) | Weekly review |

| Module | Backend (days) | Frontend (days) | QA (days) | Total (person-days) |
|---|---|---|---|---|
| Phase 0 Foundation | 3 | 2 | 1 | 6 |
| PARTNER | 8 | 6 | 3 | 17 |
| INVENTORY | 12 | 10 | 5 | 27 |
| ACCOUNTS | 18 | 14 | 8 | 40 |
| SALES & POS | 16 | 16 | 8 | 40 |
| Hardening | 6 | 4 | 8 | 18 |
| **Total** | **63** | **52** | **33** | **148 person-days** |
| **Calendar (2 devs)** | | | | **~14 weeks** |

---

## 21. RISKS & MITIGATIONS

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| **Scope creep — VAT/NBR e-invoicing** | High | High | Phase 1 is manual VAT (rate + VAT Payable account). NBR SDC/e-invoice integration is **Phase 2** — not in this plan. Documented as future. |
| **Performance with 100k+ products/1M+ stock moves** | Medium | High | Indexed `LIKE` + pagination now; Meilisearch + ledger aggregates + nightly `ReconcileStockJob` in Phase 5. Load test with factories. |
| **Double-entry errors (unbalanced vouchers)** | Low | Critical | Triple guard: FormRequest validation + Action invariant + DB check constraint. Nightly `ReconcileAccountBalancesJob` alert. |
| **Negative stock at POS (race)** | Medium | Medium | `SELECT ... FOR UPDATE` on `stocks` row + `InsufficientStockException` + retry. Configurable allow-negative per warehouse. |
| **Period-close data loss** | Low | Critical | Period close creates backup + requires `accounts.period.close` perm + double confirmation + audit log. Reversible only by Super Admin with new reversal period. |
| **Menu explosion (too many items)** | Medium | Low | Sidebar is collapsible + searchable (already). Group permissions hide empty sections. |
| **POS offline sales loss** | Medium | High | localStorage queue + sync job + `pos_sales` has `is_synced` flag. Shift close validates all queued synced. |
| **Cross-module coupling drift** | Medium | High | Code review rule: zero `use Modules\OTHER\Models` imports. CI grep check. Only `Interfaces` + `DTO`. |
| **Chart of Accounts mismatch (BD GAAP)** | Medium | Medium | Seed with BD-standard COA (80 accounts covering manufacturing/trading) — reviewed by your accountant before Phase 3. Configurable after. |

---

## 22. WHAT WE WILL NOT DO IN THIS SESSION

Per your instruction — **no code in this session**:

- ❌ No `php artisan make:*` or file creation for new modules
- ❌ No migrations, models, controllers, or Vue pages
- ❌ No `npm install` or `composer install` for new dependencies
- ❌ No menu.ts or vite.config.ts edits

**What we deliver in this session:** This plan document — your single source of truth for the build.

**What happens next session (when you say "proceed"):** We start **Phase 0 → Phase 1 (PARTNER)** — scaffolding + first migration, one module at a time, with your approval at each milestone.

---

## 23. DECISION LOG — AWAITING YOUR APPROVAL

Please confirm these decisions so Phase 1 can start without rework:

| # | Decision | Recommendation | Your Choice |
|---|---|---|---|
| **D1** | **Module folder name for Vendor & Customer** | `PARTNER` (industry standard: Business Partner) — UI still says "Vendors & Customers" | ☐ Approve `PARTNER` ☐ Keep `VENDOR_CUSTOMER` ☐ Other: _______ |
| **D2** | **Separate Vendor/Customer tables vs single `partners` with `type`** | **Single `partners` table** (`type`: vendor/customer/both) — prevents duplicates when a party is both | ☐ Approve single table ☐ Require separate tables ☐ Need discussion |
| **D3** | **POS offline support in Phase 1** | **Queue in localStorage + sync** — critical for BD retail (unstable internet) | ☐ Must have ☐ Phase 2 ☐ Not needed |
| **D4** | **Accounting method** | **Double-entry, BDT-only, FIFO/WAVG valuation** — BD GAAP compliant, multi-currency deferred | ☐ Approve ☐ Need multi-currency now ☐ Other: _______ |
| **D5** | **VAT handling** | **Manual VAT rate per product + VAT Payable/Receivable accounts** — NBR e-invoice is Phase 2 | ☐ Approve ☐ Need NBR integration now ☐ Not needed |
| **D6** | **Build order** | **PARTNER → INVENTORY → ACCOUNTS → SALES/POS** (dependency order) | ☐ Approve ☐ Want different order: _______ |
| **D7** | **POS hardware** | **Web POS (browser + thermal printer + barcode scanner)** — no native app, works on any PC/tablet | ☐ Approve web POS ☐ Need native/desktop app ☐ Need mobile POS |
| **D8** | **Fiscal year** | **July–June (BD FY)** default, configurable — with period open/close + FY close | ☐ July–June ☐ Jan–Dec ☐ Custom: _______ |
| **D9** | **Team & timeline** | **~14 weeks with 2 devs, or ~22 weeks solo** — phased, each phase shippable | ☐ Approve timeline ☐ Need faster: _______ ☐ Need slower/more thorough |
| **D10** | **Start trigger** | **Say "proceed Phase 1" to scaffold PARTNER** — or "revise plan" to iterate | ☐ Proceed ☐ Revise: _______ |

---

## 24. APPENDIX: NAMING CONVENTIONS

| Concern | Convention | Example |
|---|---|---|
| **Module folder** | `UPPERCASE` | `PARTNER`, `INVENTORY`, `ACCOUNTS`, `SALES` |
| **Namespace** | `Modules\{MODULE}` | `Modules\PARTNER\Models\Partner` |
| **Table prefix** | `module_snake_` | `partners`, `inventory_products`, `accounts_vouchers`, `sales_invoices` — but prefixed in code via `protected $table` if needed to avoid collisions |
| **Route prefix** | `lowercase` | `partner`, `inventory`, `accounts`, `sales` |
| **Route name** | `prefix.resource.action` | `partner.vendors.index`, `inventory.products.create`, `accounts.vouchers.post`, `sales.pos.terminal` |
| **Permission** | `prefix.resource.action` | `partner.vendors.view`, `inventory.stocks.view`, `accounts.vouchers.post`, `pos.terminal.sell` |
| **Inertia page** | `MODULE::Path/Name` | `PARTNER::Vendors/Index`, `INVENTORY::Products/Create`, `ACCOUNTS::Vouchers/Show`, `SALES::Pos/Terminal` |
| **Vue file** | `PascalCase` | `Index.vue`, `Create.vue`, `Show.vue`, `Terminal.vue` |
| **Model** | `PascalCase` singular | `Partner`, `Product`, `Voucher`, `Invoice` |
| **Migration** | `YYYY_MM_DD_HHMMSS_create_module_table.php` | `2026_11_03_000001_create_partners_table.php` |
| **Model code** | `PREFIX-XXXX` | `VEN-0001`, `CUS-0001`, `SKU-0001`, `JV-2025-00001`, `INV-2025-00001`, `POS-2025-00001` |
| **Service** | `EntityService` | `PartnerService`, `StockService`, `VoucherService`, `PosSaleService` |
| **Action** | `VerbEntityAction` | `VerifyPartnerAction`, `TransferStockAction`, `PostVoucherAction`, `CompletePosSaleAction` |
| **DTO** | `EntityData` | `PartnerData`, `VoucherData`, `SaleData`, `StockAvailabilityData` |
| **Seeder** | `ModulePermissionSeeder` | `PartnerPermissionSeeder`, `ChartOfAccountsSeeder` |
| **Factory** | `EntityFactory` | `PartnerFactory`, `ProductFactory` |

---

> **Next step:** Review this plan and reply with your decisions on **D1–D10**. When ready, say **"proceed Phase 1"** and we will scaffold the `PARTNER` module — migrations, models, and first pages — in the next session.

*Plan authored by Arena AI — Enterprise ERP Architect mode — No code written, as requested.*

