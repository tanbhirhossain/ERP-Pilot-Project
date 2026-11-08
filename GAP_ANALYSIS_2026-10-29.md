# ERP Pilot — Full Gap Analysis (2026-10-29)
> Branch `master` @ `e4950e0` — HRM + Attendance + Roster + Swaps + Overtime + Leave

---

## 1. Health Check — ✅ Everything OK

| Area | Status | Evidence |
|------|--------|----------|
| **Migrations** | ✅ 26 files, ordered | `organizations→…→roster_overtimes→leave_types/requests` — no missing FK order. `php artisan migrate` clean |
| **Models + Audit** | ✅ 13/13 `LogsActivity` | `Employee, RosterShift/Duty/Swap/Overtime, AttendanceLog/Pair/Daily, LeaveType/Request, Device, DeviceUser, Supervisor` all log to `activity_log` (`hrm_*` log_names) |
| **Providers** | ✅ 14 bindings | `HRMServiceProvider::register` binds every RepositoryInterface — swaps/overtime/leave added in `e4950e0` |
| **Routes** | ✅ 109 routes | `hrm.*` group `web+auth` — duties (incl. `excel/excel-data/excel-save` before wildcard), swaps, overtimes, leave-types/leaves, attendance pipeline |
| **Menu** | ✅ 13 HRM items | `menu.ts` `HRM→` Organization/Departments/Designations/DeviceUsers/Devices/Employees/Supervisors/Shifts/Duties/Swaps/Overtime/LeaveTypes/Leaves + `Attendance→` Logs/Pairs/Dailies/Sync |
| **Vue Pages** | ✅ 37 pages | Duties(4) Shifts(3) Swaps(3) Overtimes(3) LeaveTypes(2) LeaveRequests(3) Attendance(4) Supervisor(4) Employees(4) etc. — all `HRM::` Inertia |
| **Attendance Pipeline** | ✅ 3-stage + `duty_date` fix | `e2e1d98` `findOpenPair(duty_date)` prevents Fixed `24 IN → 25 IN = 16.6h` bug. Command `hrm:fix-pairing --apply` deletes 5 wrong L1009001 pairs, resets `is_processed=0` |
| **Excel Roster** | ✅ `bc75f32` | `GET excel` + `excel-data` (50/page, dept/search, 31-col) + `POST excel-save` (upsert 5k, delete on empty, TX) with sticky grid, shift legend, bulk-fill, dirty count |
| **Roster Swaps** | ✅ `dfb7030` | `roster_shift_swaps` FK `requester_employee_id→employees`, exchange duties TX, status `Swapped` |
| **Overtime** | ✅ `c5ef559+8eb88a7` | `roster_overtimes` enum `Overtime/Extra Duty/Holiday Duty`, hours calc overnight +24h, approve/reject |
| **Leave** | ✅ `e4950e0` | `leave_types` + `leave_requests` FKs, `days` calc + `½-day`, paid/unpaid, color, approve/reject |
| **Device `id` vs `uid`** | ✅ `56b4dce` | `RealEmployeeBasicSeeder` `device_user_id=id` (Excel B), `biometric_id=id`, `uid` shadow only — no `uid` mapping anywhere |
| **No `TODO/FIXME`** | ✅ 0 hits | `grep TODO` empty |
| **Git** | ✅ clean mainline | 12 commits since 10-25, none missing |

---

## 2. Gaps — Prioritized

### P0 — Fix before go-live

| # | Gap | Impact | Fix (est.) |
|---|-----|--------|------------|
| **G1** | **HRM Permissions incomplete** — `HRMPermissionSeeder` only seeds 13 perms (`hrm.view`, `organization`, `departments`, `designations`, `devices`, `employees`, `shifts`, `duties`, `attendance.*`). **Missing:** `hrm.swaps.*`, `hrm.overtimes.*`, `hrm.leave-types.*`, `hrm.leaves.*` | Any new menu item shows for all `auth` users; RLS/permissions not enforced. Admin cannot grant granular access | Append 8 perms + `givePermissionTo` in seeder, run `php artisan db:seed --class=HRMPermissionSeeder` |
| **G2** | **Leave duplicate/overlap not validated** — `StoreLeaveRequestRequest` has no overlap rule; user can submit `CL 10-10 → 10-12` twice → double deducted | Payroll / attendance inconsistency | Add `Rule::unique` + service `overlaps()` check (`where employee_id & date range & status != Rejected/Cancelled`) in `LeaveRequestService::create/update`, return 422 |
| **G3** | **Approved Leave ≠ Attendance** — `LeaveRequestService::approve()` only flips `status`. `AttendanceDailyRepository::recalculate` still looks at punch data, `attendance_dailies.status` stays `Absent` not `On Leave` | Reports wrong; “Present/Late/Absent” counts off | Hook approval to (a) upsert `attendance_dailies` `On Leave` for each `duty_date` in range, or (b) add `LeaveRepository ↔ Daily` observer; add migration `leave_request_id` nullable FK on `attendance_dailies` for traceability |
| **G4** | **Excel bulk delete bypasses audit** — `RosterDutyController::excelSave()` does `RosterDuty::where(...)->delete()` (query builder, not model) — `LogsActivity` `deleted` event never fires | Audit Trail blind to bulk clears | Replace with `RosterDuty::where(...)->get()->each->delete()` or manually `activity()->performedOn($model)` inside TX (slower but audited); or accept gap and log a single `activity` entry “excel bulk cleared N” |

### P1 — Expected by stakeholders (backlog carry)

| # | Gap | Notes |
|---|-----|-------|
| **G5** | **Informed Panel (deferred)** — spec said `Leave Management, Overtime/Extra Duty, Informed Panel` — first two done, **Informed = Late/Leave Informed notice** (employee tells supervisor “I’ll be late/leave early today”) with `informed_date`, `type[Late, Early Out, Absent Informed]`, `status` | New table `informed_requests` (`employee_id`, `informed_date`, `type`, `reason`, `status`, `approved_by`) + panel like Overtime — 1 migration + 1 model/repos/svc/controller + 3 Vue |
| **G6** | **Leave Balance / Quota enforcement** — `leave_types.days_per_year` stored but never checked; no `leave_balances` table, no “Remaining: 8/12” UI | Add service `availableDays(employee_id, type_id, year)` (`quota - sum Approved days in year`) + UI badge on `LeaveRequests/Create`; optional `hrm:leave-year-rollover` command |
| **G7** | **Default leave_types seed missing** — fresh DB has empty `leave_types` → Create Leave fails (`leave_type_id` required but no options) | Add `LeaveTypeSeeder` (CL 10, SL 14, EL 21, ML, PL) + call in `DatabaseSeeder` |
| **G8** | **Supervisor approval authorization** — `approve()` only checks `auth()` exists; any employee can approve any request. `employee_supervisors` table exists but never consulted | Add `isSupervisorFor()` check or `Gate::allows('hrm.approve', $record)`; fallback to `role:HR Manager` |
| **G9** | **RosterOvertime ↔ Attendance** — approved overtime sits isolated; `attendance_dailies.total_overtime_hours` comes from `AttendancePair(duty_type=Overtime)` only (ZK punches), not from approved `roster_overtimes` | Either (a) treat approved OT as “authorized” overlay displayed on Daily view, or (b) on approval create a synthetic `AttendancePair` (flag `is_authorized=true`) |
| **G10** | **Dashboard widgets** — `Dashboard.vue` is empty welcome page; no pending counts (`Leaves 5 pending`, `Swaps 2`, `Overtime 3`, `Unpaired logs 120`) | Add `DashboardController@index` aggregating `where status=Pending` counts + `attendance_logs is_processed=0` |

### P2 — Polish / Ops

| # | Gap | Notes |
|---|-----|-------|
| **G11** | **Factories missing for new tables** — `RosterOvertime`, `LeaveType`, `LeaveRequest` have no factories (removed reference on Overtime). `RosterShiftSwapSeeder` exists but `LeaveTypeSeeder`/`RosterOvertimeSeeder` don’t | Add 3 factories → `php artisan db:seed --class=DemoSeeder` works for QA |
| **G12** | **No CSV/Excel export** — `DataTable` has `exportable:false` on actions but no `ExportController`; attendance daily needs export for payroll | Add `hrm/attendance/dailies/export?from&to&format=csv` using `exportBuilder()` (already in repos) + `maatwebsite/excel` |
| **G13** | **No notifications (mail/in-app)** — request/approve/reject silent; user must poll Index | Add `ShouldQueue` `LeaveRequestSubmitted` / `OvertimeApproved` notifications (mail + `notifications` table) on service transitions |
| **G14** | **ZK sync single-threaded 10s sleep** — `Util::recData` `sleep(1)` ×10 + `maxErrors 10` = 10s timeout per call; `SyncAttendance` sequential per device → 5 devices = 50s | `pipeline:all` already queues; consider `jmrashed/zkteco` fork patch reducing `sleep` to `200ms` or async `socket_select` |
| **G15** | **Timezone & shift snapshot drift** — `RosterDuty.scheduled_start_time` snapshot on `excelSave` is correct, but `AttendanceDaily.expected_in/out` is recomputed from `RosterDuty` at `recalculate` time — if roster edited after punch, daily changes retroactively | Snapshot `expected_in/out` once on daily creation (`isDirty` guard) |
| **G16** | **No unique index on `leave_requests` period** — only `leave_types.name` unique; overlapping periods allowed (G2) + no `overtime` unique → duplicates allowed | Add partial unique or application-level lock; for `roster_overtimes` add `unique(employee_id,overtime_date,overtime_type,target_row?)` or allow multiples explicitly |

---

## 3. File-Level Checks (spot-checked)

- `HRMServiceProvider.php` **OK** — 14 binds, migrations auto-loaded
- `RosterShiftSwap` migration **OK** — `requester_employee_id→employees.employee_id`, `roster_duty_id cascade`, `approved_by→users nullOnDelete`
- `RosterDuties.excelSave` **OK** — 31-day guard, upsert `['employee_id','roster_date']`, delete on null `shift_id`
- `LeaveRequests/Create.vue` calc `days = to-from+1` + `½-day` — **correct**, matches service `calcDays()`
- `Overtimes/Create.vue` calc `hours = end-start (+24h)` — **correct**, matches service
- `menu.ts` **OK** — imports `Tag, Plane, Timer, ArrowLeftRight`
- `Activity` **OK** — 13 models covered; `leave_requests`/`overtimes`/`swaps` added in last 3 commits

---

## 4. Immediate Action List (copy-paste)

```bash
# 1. Seed perms + default leave types
php artisan make:seeder LeaveTypeSeeder
php artisan db:seed --class=HRMPermissionSeeder
php artisan db:seed --class=LeaveTypeSeeder

# 2. Fix orphan pairs (still pending on prod — 5 flagged)
php artisan hrm:fix-pairing --employee=L1009001 --device_user=200293 --from=2026-10-24 --to=2026-10-29 --apply
php artisan hrm:pipeline --stage=pairs && php artisan hrm:pipeline --stage=daily

# 3. Audit Excel save (G4) — add one activity entry if keeping query-delete
# 4. Add overlap guard (G2) — app/Requests/StoreLeaveRequestRequest + Service
php artisan migrate  # new leave_types / leave_requests already in repo
npm run build
```

Next module to start when you say “proceed next”: **Informed Panel** (G5) — 1 migration + full CRUD, same pattern as Overtime.

*Generated by gap sweep — no live DB, so pair counts from migrations/logs only; run the two artisan lines above on prod to confirm the overnight 16h pairs are gone.*
