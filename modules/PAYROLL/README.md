# PAYROLL module

Additive Laravel/Inertia/Vue payroll module. It reads employee, salary, attendance, leave and holiday data through PAYROLL-owned provider contracts; the HRM adapter is the only integration boundary. PAYROLL migrations create their own tables and do not alter HRM tables or attendance/shift detection logic.

## Setup

```bash
php artisan migrate
php artisan db:seed --class="Modules\\PAYROLL\\Database\\Seeders\\PAYROLLPermissionSeeder"
php artisan queue:work
```

The application `DatabaseSeeder` also invokes the PAYROLL permission seeder. Payroll sheet calculation is queued; a running worker is required. The worker processes eligible employees using lazy ID-based reads, `chunk(50)`, and a database transaction per chunk. At the start of the first attempt it saves a sheet-level calculation snapshot of global settings, tax/PF rules, templates, recurring adjustments, and tax profiles in a new PAYROLL table; retries reuse this snapshot. Attendance sources and loan installments are fetched in employee batches. Each slip is written once with a component-level `breakdown_json` snapshot; retries skip existing slips. A completed sheet must be explicitly released by a user with `payroll.sheets.release` before any slip can be paid or held. Slip payment status (`unpaid`, `paid`, `hold`) is maintained per slip so a held slip does not block other payments.

## Configuration

PAYROLL deliberately creates no tax bands or PF rates by default. Configure the organization's approved values under **Payroll → Tax & profiles**, **Provident Fund**, **Benefits & deductions**, and **Settings** before running production payroll. Annual progressive tax slabs are validated for order/continuity, and their annual liability is stored alongside the monthly provision. Loan rates are fixed to zero in the service and approval flow. Configure loan tiers as `Stage=Role` pairs (for example, `Manager=Manager, Finance=Finance`); each tier needs a distinct existing role and approvers must hold that role.

## Operations

- Salary increment requests keep before/after amounts, requester, approver and effective date; approval adds an HRM salary-history revision through the employee provider adapter.
- Loan approvals snapshot ordered stage-to-role mappings, require distinct approvers with the configured role at each tier, allocate exact-principal monthly installments, link installments to slips and mark them paid only with the related slip.
- PF accounts are created when an employee meets the active policy's service threshold. Employee/employer contributions are posted to the ledger on slip payment.
- Attendance cutlists use HRM daily attendance, approved unpaid leave and configurable penalties for late/missing punches.
- Festival bonus runs are idempotent per policy/year/month, only use finalized salary sheets, and record release actor/time in the additive PAYROLL workflow-audit table. The configured maximum bonus runs per year is enforced.
- An approved resignation excludes the employee from regular payroll for any period ending on or after the last working date; process the final month through the final-settlement workflow to avoid paying it twice. Salary changes and new-hire pay are calendar-day weighted by effective salary-history segment, normalized to the actual month length so a full month’s salary is unchanged. Final settlements prevent a second active/paid settlement for the same employee and record approval/payment actors and timestamps; leave encashment and other manual amounts remain subject to Payroll review.

## Validation status

The sandbox used to prepare this module does not have PHP/Composer or installed Node dependencies (`php` is not installed; `vite` is not available). Run PHP lint/tests, `php artisan migrate`, the permission seeder, `php artisan payroll:preflight --strict`, `php artisan payroll:benchmark-attendance --employees=750`, and `npm run build` in the application environment before deployment.
