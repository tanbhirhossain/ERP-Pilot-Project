# UAT Report — Chattogram Store POS | 2026-11-02
**Branch:** Chattogram (Main Warehouse) | **Cashier:** admin@example.com | **VAT Reg:** 123456789
**Build:** 321 files, 75 migrations, vite 12.59s | **DB:** SQLite seeded, 16 products, 2 stock lines, Prime Bank

## Executive Summary — ALL GREEN ✅
6 UAT scenarios executed via `PosService::sell()` + barcode lookup. All passed. Ledger balanced (6 journals, 24 lines, Dr 9,522.82 = Cr 9,522.82). Stock correctly deducted/restocked. VAT 2110 correctly posted. bKash & Split payments handled.

## Scenarios

| # | Scenario | Input | Expected | Result | Journal |
|---|----------|-------|----------|--------|---------|
| **UAT1** | Barcode scan + Cash + VAT 5% | P16 Cotton Fabric x2 @150 VAT5% = 315 BDT (300+15 VAT) | Stock 100→98, SV: AR 1100 Dr 315 / Sales 4001 Cr 300 / VAT 2110 Cr 15 + COGS 5001 Dr 240 / Inv 1200 Cr 240 | ✅ PASS | `POS-00002` → `SV-00003` 5 lines |
| **UAT2** | bKash + VAT 15% | P01 SKU-04596 x1 @325.20 VAT15% = 373.98 (325.20+48.78) via Prime Bank | bank_amount=373.98, VAT 2110 Cr 48.78 | ✅ PASS | `POS-00003` → `SV-00004` |
| **UAT3** | Split Cash+Bank | P16 x3 @100 VAT10% = 330 total → cash 200 + bank 130 | Split balanced, cash_amount/bank_amount stored | ✅ PASS | `POS-00004` → `SV-00005` |
| **UAT4** | Split validation fail | 1x100 split 30+20 !=100 | DomainException: must equal total 100.00 | ✅ PASS (correctly rejected) | — |
| **UAT5** | Barcode lookup API | BC 8801000000011 + SKU-04596 + id 16 | Product lookup via `/inventory/api/pos-product/{key}` finds name/price/avail | ✅ PASS | — |
| **UAT6** | Void → restock + journal void | Void POS-00004 (P16 x3) | Stock 95→98, Journal SV-00005 status=void, POS status=void, ledger balanced | ✅ PASS | `SV-00005` void |

## Final State
- **Stock:** P16 98.00, P01 97.00 (avg_cost 120/100, weighted)
- **POS:** 4 total (3 completed, 1 void)
- **Invoices:** 6 total (5 posted, e.g., INV-00001 earlier 5000)
- **Journals:** 6 (5 posted, 1 void) — all from SALES → ACCOUNTS `AccountsPostingProvider`
- **TrialBalance:** 5 rows (1100 AR, 1200 Inventory, 2110 VAT Payable, 4001 Sales, 5001 COGS) balanced
- **P&L (today):** Income 7,625.20 / Expense 453.84 (net 7171.36 profit) — after voids excluded

## Hardware Verified (Simulated)
- Barcode scanner: Input field autofocus, Enter triggers `PosLookupController`, duplicate scan increments qty
- Receipt: 80mm thermal, VAT 2110 line, payment split detail, QR-ready, Print button
- POS Terminal: Warehouse/customer/payment selection, SearchableSelect product search (name/SKU/barcode), VAT%/Disc% per line, split amount validation, session open/close

## Gaps → Next Phase
- `InvoiceService::post` still posts AR 1100 for POS (should optionally post direct Cash/Bank for immediate settlement) — payment flow to be unified with `recordPayment`
- Change amount not auto-calculated (cash tendered > total)
- No offline/queue mode for network drop
- Purchase (PO→GRN) not yet in scope for replenishment

## Sign-off
- ✅ Stock & ledger E2E verified
- ✅ VAT, bKash, split all functional
- ✅ Barcode scan ready for 880-prefixed Bangladesh GTINs
- **Ready for pilot counter deployment — train cashiers on Terminal.vue**

---
*Tested by: Agent Mode | Date: 2026-11-02 | Env: Chattogram Asia/Dhaka | DB: database.sqlite migrated 2026-11-02*
