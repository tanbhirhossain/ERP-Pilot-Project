# PURCHASE Module — Handover | 2026-11-02

## What Was Built (Option 2 → Purchase)
**New module `PURCHASE` — PO → GRN → Vendor Bill → Payment, closes inbound loop.**  
Stack mirrors `SALES`: Config, 3 migrations, 6 models, DTO, 3 interfaces, 3 repositories, 3 services, 3 requests, 3 controllers, 12 Vue pages, Seeder, Provider.

### Files (31 new)
- `Config/purchase.php` (PO/GRN/PB prefixes, perm list)
- `Database/Migrations/2026_11_03_000030/031/032` → `purchase_orders(2 tables)`, `purchase_receipts(2)`, `purchase_bills(3)` = 7 tables
- `Models/PurchaseOrder+Item, GoodsReceipt+Item, VendorBill+Item, PurchasePayment` (SoftDeletes, ActivityLog, isDraft guards)
- `DTO/PurchaseLineData` (qty>0, lineTotal = net+vat)
- `Interfaces/*RepositoryInterface` (paginate, exportBuilder, CRUD, nextNo)
- `Repositories/*` (BuildsDatatableQueries, DB::transaction + lockForUpdate nextNo)
- `Services/PurchaseOrderService` (enrichTotals via Product snapshot, confirm/send/cancel), `GoodsReceiptService::post` (restock via `InventoryStockProvider::restock` → StockMove IN, weighted-avg, PO status → received), `VendorBillService::post` (AP posting: Inventory 1200 Dr + VAT 2110 Dr / AP 2100 Cr → Journal PV, pay → AP Dr / Bank Cr)
- `Requests/*Request` (FormRequest authorize true, items array max 100)
- `Http/Controllers/*` (Inertia render Index/Create/Show/Edit, PaginatedResource, confirm/post/pay)
- `Routes/web.php` (12 purchase routes, PermissionMiddleware)
- `Providers/PURCHASEServiceProvider` (bind 3 repos, loadMigrations)
- `Resources/js/Pages/{Orders,Receipts,Bills}/{Index,Create,Show,Edit}.vue` (DataTable, Search, StatusBadge, minimal create form, show JSON + action buttons)
- `Database/Seeders/PurchasePermissionSeeder` (11 perms), `PurchaseDemoSeeder` (PO-00001 10x1000 confirmed)
- `CORE/Config/menu.ts` → Purchase group (Orders/GRN/Bills), `vite.config.ts` → `@purchase` alias

### Integration (DIP)
- **INVENTORY**: `PurchaseOrder confirm` → no stock; `GoodsReceipt post` → `restock([product_id, variant_id, warehouse_id, qty, unit_cost], GRN-no, purchase_receipt)` → `inventory_stock_moves` (string ref) + `inventory_stocks` avg_cost recalc ( (100*120+10*80)/110=116.36 )
- **ACCOUNTS**: `VendorBill post` + `recordPayment` via `AccountsPostingProvider::postJournal` (PV type, partner vendor). GRN has no journal (pure stock). Bill journal: 1200/2110 → 2100. Payment: 2100 → 100201.

### Verification — 100% GREEN ✅
```bash
migrate:fresh --seed --force  # 78 migrations (75→78 +3 purchase), 97 perms (86→97 +11), 3 FYs (FY2526/2627/2728, 36 periods), PO 1, Accounts 26
npm run build                  # 12.31s, 287kB app, 84 chunks, @purchase alias ok
tinker E2E: PO-00002 10x80 VAT5% 840 → GRN-00001 posted stock 100→110 avg116.36 PO received → PB-00001 posted (1200 Dr800 /2110 Dr40 /2100 Cr840) → Pay PP-00001 (2100 Dr840 /100201 Cr840) → Sales INV-00001 3x150 VAT5% 472.5 (AR 1100 Dr472.5 /Sales4001 Cr450 /VAT2110 Cr22.5 + COGS5001 Dr349.08 /Inv1200 Cr349.08) → Ledger balanced YES (3 journals, 10 entries)
```

### Fixes Applied
- `INVENTORY OptionsController` vat_rate in ProductRepository
- `PosLookupController` barcode/SKU/id lookup
- `SALES Terminal/Receipt` barcode scan, VAT split, bKash/bank
- `InvoiceService cost_price` → check `>0.001` + refresh load (was stuck at 0 due to "0.00" truthiness + stale collection) — now COGS posts correctly after purchase
- `AccountsDemoSeeder` 3 FYs (was 1, FY2627 missing caused "No fiscal year covers 2026-11-02")
- `vite.config` + `menu` purchase entries

### Known Gaps
- No Purchase return / debit note; void of GRN/Bill not wired (Sales has void)
- No landed cost allocation; no GRNI accrual account (uses direct 1200)
- PriceLists not yet linked to Purchase (SALES uses them)
- Audit vulns still 35 (npm audit)
- Stock for 15 products empty (demo creates 2 stock lines; P16 only stocked)

### Next
- Docs (ERD/API/perm matrix) or Deploy to MySQL staging

---
*Chattogram store — PO→GRN→Bill→Pay→Ledger all posted, stock IN/OUT verified, FY2627 open.*
