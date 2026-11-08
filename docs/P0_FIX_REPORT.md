# P0 Fix Report — 2026-11-02

## Fixed
1. **COA 26→35**: Added 1330 VAT Input, 1310 TDS Recv, 1400 WIP, 2115 GRNI, 2120 Accrued, 2310 TDS Pay, 5100 Purchases, 5101 Returns, 5150 Freight, 5200→Salaries shift, 5500 Bank Charges. `config/accounts.php` defaults now `purchase_code=5100`, `vat_input_code=1330`, `grni_code=2115`. VendorBill posts VAT to 1330 Dr (was 2110).

2. **Inventory seed 1→16 stocks**: `InventoryDemoSeeder` now loops all 16 products, random 50–200 qty each via `ReceiveStockAction`. Before: only SKU-TEST001 stocked → 15 SKUs threw “Insufficient stock”.

3. **Purchase UX stubs → SALES-grade**: `Orders/Receipts/Bills Create.vue` now `SearchableSelect` for vendor/warehouse/products (`/partner/api/options/vendors`, `/inventory/api/options/*`), live total computed, `order_id`→GRN auto-fill remaining qty, receipt→Bill chaining, notes. `Show.vue` with tables, StatusBadge, action buttons (Confirm, Post GRN, Post Bill, Pay). `Edit.vue` prefilled via props.

4. **Auth guard**: `PurchaseOrder/Receipt/BillRequest::authorize()` from `return true` → `can('purchase.*.manage')`.

5. **Invoice COGS bug**: `InvoiceService::post` checked `if (! cost_price)` where `cost_price="0.00"` is truthy → never valuated → 2-line journal only. Fixed to `>0.001` check + `load('items')` refresh. Now 5-line journal (COGS 5001/Inv 1200).

6. **Env**: Added `.env.example` (Asia/Dhaka, sqlite default, MySQL commented, all prefixes), `.env` generated + `key:generate`, `docker-compose.yml`, `DEPLOY.md`.

7. **FY**: Already 3 FYs (FY2526-2728) in `AccountsDemoSeeder`; verified `migrate:fresh` gives FY2627 open for 2026-11-02.

8. **Build & Tests**: `npm audit fix` (non-breaking), Vite 319kB 12.49s ✅, 4 Pest feature tests PASS (PO→GRN, Bill→AP, Sales COGS, FY).

## Verification
```bash
migrate:fresh --seed --force # 78 mig, 35 COA, 3 FY/36 periods, 16 stocks (50–202), 395 routes, 97 perms
tinker E2E: PO-00002 5×80 VAT5% 420 → GRN 197→202 → PB-00001 (1200 Dr400/1330 Dr20/2100 Cr420) balanced YES
php artisan test # 4 passed
npm run build # 12.49s
```

## Remaining P1 (from GAP_ANALYSIS)
- Purchase return/GRN void, approval matrix, aging/AP reports, Mushak 6.3, period-close, bank rec.
