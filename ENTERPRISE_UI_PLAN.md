# Enterprise Premium UI — Global Plan

## Scope: 238 Vue pages across 8 modules

### Already Shipped (Premium Shell)
- app.css tokens → 0.625rem radius, 240-hue neutrals, glass-header, premium-scrollbar
- AdminLayout header → glass + pill breadcrumbs + pill search + pill user
- TreeSidebar → premium-scrollbar (5px Linear-style), brand PRO pill, focused search, depth shadow
- PageHeader → dark glass with grid/glow (stats) + minimal card (no stats)
- Accounting Reports (6) → BalanceSheet, P&L, Trial, Ledger, CashFlow, Depreciation premium

### Remaining — Enterprise System

#### Global Components to Upgrade (affects ALL pages automatically)
1. DataTable toolbar → more enterprise (done but verify)
2. StatusBadge → ensure premium tones consistent
3. EmptyState → premium
4. New: EnterpriseFormShell.vue
5. New: EnterpriseDetailHeader.vue
6. New: EnterpriseSection.vue (unified card)
7. New: EnterpriseKPI.vue

#### Bulk Patterns (to auto-transform)
- **Index** (≈70): header div → PageHeader with icon + description + action
- **Create/Edit** (≈60): h1 → PageHeader, form → sections with rounded-2xl shadow-sm, sticky footer
- **Show** (≈40): hero → EnterpriseDetailHeader, collapsibles → EnterpriseSection

#### Execution Order
1. Create global enterprise components (CORE)
2. Script-transform Index batch (HRM → PARTNER → INVENTORY → PURCHASE → SALES → PAYROLL → CORE)
3. Script-transform Create/Edit batch
4. Script-transform Show batch
5. Verify build

## Decision: Global-first, then local
Keeps module logic untouched, visual upgrade is CSS + wrapper swap. Lowest regression risk.
