# HRM Reports & Analytics — Shipped 2026-11-03 (P1-7)

**Route:** `hrm.reports.index` → `HRM::Reports/Index` + 5 subpages + export
**Service:** `HrmReportsService::overview()` aggregates headcount/attrition/leave/attendance/OT in one call

## What you get
- **Headcount:** total/active, by dept (with active%), by designation (top10), by status/employment_type
- **Attrition 12M:** resignations by month (bar), monthly turnover %, movements (transfers/promotions) proxy
- **Leave liability:** pending (liability) vs approved, by type, carry/lapsed from `hrm_leave_balances`, top 10 takers
- **Attendance 6M trend:** total/present/late/early/absent/missing/regularized per month + late by dept (8)
- **OT cost MTD:** approved hours/cost, by dept (8), top 10 takers
- **Export CSV:** `hrm.reports.export?type=headcount|attrition|leave|attendance|ot`

## Verify on deploy
```
php artisan migrate --force # no new tables, only queries
php artisan db:seed --class=HRMPermissionSeeder --force
npm run build
# Open /hrm/reports → KPI 5 cards + 5 sections
# Filters: ?year=2025&months=12&from=2026-01-01&to=2026-10-31
```

## Why it matters for payroll
Late/missing trend justifies regularization workload (Step 3), OT hours feed payroll cutlist cost, leave pending = liability for settlement encashment (Step P1). All numbers tie to existing tables — no new snapshot except `hrm_leave_balances` already shipped in 000074.

## Next
- ESS My HR (row-level my/*) — requires `employee_id = auth->employee` scoping
- Purchase UX hardening (still queued P0)
