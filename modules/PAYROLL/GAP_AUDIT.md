# PAYROLL gap audit and changes

Audit date: 2026-11-01

## Gaps addressed

- Pay slips now show approved overtime/extra-duty hours and pay, attendance counts, allowance components, currency, and a print action.
- Salary sheets require permission-gated release before slip payment changes. Release actor/time are recorded in the additive PAYROLL workflow-audit table.
- Cutlist rebuilds clear stale rows and cannot alter finalized snapshots. Failed-sheet retries reuse the saved cutlist; unsafe recalculation is blocked when partial slips exist.
- Bonus generation is serialized/idempotent per policy/period, rejects existing duplicates, enforces the annual run limit, and reads finalized sheets only. Bonus release actor/time are audited.
- Calculation snapshots now freeze the eligible employee roster and HRM salary segments, global settings, tax/PF policies, templates, recurring adjustments, employee tax profiles, and PF account state. Retries reuse the stored snapshot. Loan installments are captured at run start and locked/checked against it before attachment.
- Cutlist attendance/leave/holiday/roster queries are batched in groups of 50 employees, while preserving existing HRM auto-shift logic and summary rules.
- Loan tiers require distinct existing role mappings. Each approver/rejecter must hold the current stage role and route permission, cannot repeat an earlier decision, and cannot decide their own request.
- Calendar-day weighted salary segments handle new hires and mid-month salary changes. A full month at one salary remains the same in 28-, 29-, 30-, or 31-day months. Missing salary-history coverage stops the run instead of silently underpaying; percentage festival bonuses use the monthly base rather than a partial-month slip amount.
- Approved resignations exclude employees from regular payroll on or after their last-working-date period; duplicate active/paid settlements are blocked.
- Final settlements validate dates/amount precision and store manual-adjustment reasons and salary-segment details for approver review. Leave encashment, other benefits, and deductions remain manual per user choice.
- Relevant Payroll employee selectors support remote search. Progressive tax requires an unlimited final band.

## Remaining production checks / decisions

1. **Final-settlement HRM prefills:** leave encashment and other manual amounts remain manual, as selected. If you later want HRM-based prefills, confirm eligible leave types, cutoff date, salary basis, and override authority.
2. **Performance validation:** attendance source queries are batched, but the workload has not been benchmarked on a realistic 750+ employee database. A `payroll:benchmark-attendance --employees=750` command is available to record elapsed time, query count, and peak memory once a representative database is available.
3. **Existing bonus duplicates:** generation refuses to continue when multiple runs already exist for one policy/year/month. Run `php artisan payroll:preflight --strict` after database setup and reconcile any reported run IDs.
4. **Database constraint:** the new workflow-audit and calculation-snapshot migrations create only new PAYROLL tables. No existing table alterations were added in this pass. Bonus-period duplicate prevention uses policy-row locking and explicit checks rather than a unique index.
5. **Environment validation:** PHP lint, migrations, queue processing, database concurrency, and the Vue build have not run here (PHP is unavailable and Vite is missing). Run migrations, permission seeding, tests, `npm run build`, preflight, and a payroll dry run in the application environment.
