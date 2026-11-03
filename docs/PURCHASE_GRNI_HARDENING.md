# Purchase — GRNI Hardening (P1 Accounting) — Shipped 2026-11-03

**Migration:** `2026_11_03_000075_add_grni_to_receipts.php` (+ GRNI 2115 ensure)
**Gap:** Before, GRN only did `stock IN 1200` via Inventory, no accounting; Bill did `Dr 1200 / Cr 2100 AP` for both direct and GRN-linked bills → double-count inventory (stock + journal) and no accrual period.

## New flow (Bangladesh accrual correct)

```
PO confirmed (>50k needs approve) → GRN posted:
  Stock:  inventory_stocks qty+ / avg_cost recalc
  GL:     Dr 1200 Inventory (subtotal - discount)
          Cr 2115 GRNI Goods Received Not Invoiced (subtotal)
  (VAT not on GRN — only on Bill)

Bill posted:
  IF linked to GRN (receipt_id):
    Dr 2115 GRNI  (reversal of accrual)
    Dr 1330 VAT Input (VAT)
        Cr 2100 AP (total = subtotal + VAT)
  ELSE (direct bill, no GRN):
    Dr 1200 Inventory (or 5100 Purchases if expense) + Dr 1330 VAT
        Cr 2100 AP

Payment: Dr 2100 AP / Cr 1001 Cash or 1002 Bank (via bank_id)

Void GRN: reverses stock OUT + voids GRNI journal + decrements PO qty_received (status back)
Void Bill: voids AP journal (if paid_amount==0 guard)
```

## Files changed
- `GoodsReceiptService::post()` — now posts GRNI JV after restock; saves `grni_journal_id`; post uses `JournalData(type='JV', referenceNo=receipt_no)`
- `GoodsReceiptService::void()` — now also voids `grni_journal_id` if exists
- `VendorBillService::post()` — branch: if `receipt_id` + GRNI account exists → GRNI reversal, else legacy Inventory/Expense
- `GoodsReceipt` model: `grni_journal_id` FK to `acc_journals` (migration adds, nullable)
- Accounts ensure: `2115 GRNI` created if missing (liability, credit nature)

## Why it matters for Trial Balance
Before: GRN+Bill both hit 1200 → TB Inventory 2× real.
After: GRN hits 1200/2115, Bill hits 2115/2100 → TB balances, AP aging reflects GRNI vs Invoiced.

## Verify on deploy (where php exists)
```bash
php artisan migrate --force
php artisan db:seed --class=AccountsDemoSeeder # ensures 1200/2115/1330/2100 exist
# Create PO 10k → GRN post → check acc_journals: 1200 Dr / 2115 Cr
# Bill from GRN → check: 2115 Dr / 2100 Cr + VAT 1330 Dr
# Void GRN → stock -10, PO status back, GRNI voided
```

## Already hardened before this patch (confirmed via file audit)
- `Requests` authorize: `can('purchase.*.manage')` ✅ (not `true`)
- `Create.vue`/`Edit.vue` for Orders/Receipts/Bills: `SearchableSelect` vendor/warehouse/products, live `line_total = net+vat`, `total` computed, `Edit` prefilled via `order/items` ✅
- GRN→Bill chaining: `props.order.items` filtered `qty - qty_received` ✅
- Approval threshold 50k: `PurchaseOrderService::confirm()` checks `can('purchase.orders.approve')` ✅
- Returns/LandedCosts routes+migrations exist (000033/000060)

## Next Purchase P1 (still open, not in this batch)
- AP Aging by vendor/bill (for `acc-reports.payables-aging`)
- Mushak 6.3 Bill print via dompdf
- Approval matrix > threshold email + multi-level
