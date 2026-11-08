# HRM Fixes + Test Suite — Summary

## Commits

| Commit | Message |
|--------|---------|
| 539f6e1 | feat(HRM): Complete CRUD — Organization (singleton), Department, Designation, BiometricDevice, DeviceUser, Employee (profile/policy/salary/edu/exp/skill/supervisor), RosterShift/Duty (+bulk), Attendance Logs/Pairs/Dailies + ZKTeCo sync panel |
| cadc235 | fix(HRM): make attendance engine 100% workable — FK migrations, model relations, EmployeeRepository lockForUpdate + collision loop, AttendanceProcessingService (midnight, 24/72h, debounce, orphan, roster-first), AttendanceSyncService (numeric+assoc ZK rows, 500-batch, auto-create syncSetting) |
| b52ffea | docs: attendance engine audit report (6-step verification table) + 63 routes / 11 bindings / 26 Vue pages |
| **52dbf9b** | **test(HRM): 108 Pest tests + fix Separator/factories/bulk routes (HEAD, push pending)** |

`git push origin master` pending — no credentials in E2B sandbox (Debian 13 trixie, no PHP/composer). Host must run:

```bash
git push origin master
composer install
php artisan migrate
php artisan db:seed --class=HRMPermissionSeeder
php artisan test          # runs Pest (108 HRM tests + existing Core/Auth)
npx vitest run            # sidebar/menu vitest (unaffected)
php artisan test --filter=HRM   # HRM only
```

## Fixes in 52dbf9b (phase-2 audit)

| File | Defect | Fix |
|------|--------|-----|
| `resources/js/components/ui/separator/Separator.vue` | Empty (0 bytes) after commit — broke `resources/js` import graph, `npm run build` would fail | Restored from fbfb959 blob 1590d5: radix-vue `<Separator>` with `cn()` vertical/horizontal + label, 1.1K, `export default` barrel in index.ts intact |
| `modules/HRM/Http/Controllers/RosterDutyController.php` | Used `Request` instead of `StoreRosterDutyRequest/UpdateRosterDutyRequest`; `index` missing `DataTableQuery+paginate+through`; bulk before wildcard comment missing; `update` lacked `BulkRosterAssignRequest` guard | Rewrote: `index` = `PaginatedResource` + `through` (no N+1), `create/edit` with options payloads, `store` = `StoreRosterDutyRequest`, `update` = `UpdateRosterDutyRequest` (unique ignore self), `bulkStore` = `BulkRosterAssignRequest`, route-ordering comment |
| `modules/HRM/Http/Controllers/AttendanceSyncController.php` | `syncAll` returned success even with 0 devices; `updateSetting` missing `next_sync_at` + device mirror; `manualPunch` hardcoded `device_user_id='MANUAL'` (unique violation on second call); `processPending` limit uncapped | Rewrote: `syncAll` empty→warning flash, ok/failed counts; `updateSetting` upsert `syncSetting+device` + `last/next_sync_at`; `manualPunch` unique `MANUAL-${uid}-${md5}`; `processPending` `min(5000, input)` |
| `modules/HRM/Repositories/Roster/RosterDutyRepository.php` | `bulkAssign` did `CarbonPeriod` loop of `updateOrCreate` per day (N+1, no guard) | Now `DB::transaction` + single `upsert(uniqueBy=[employee_id,roster_date])` per day; guards 60-day range + 200-employee cap with `ValidationException` |
| `modules/HRM/Requests/StoreRosterDutyRequest.php` | Missing unique rule → duplicate duties on same employee+date | Added `Rule::unique('roster_duties')->where(employee_id+roster_date)` |
| `modules/HRM/Requests/UpdateRosterDutyRequest.php` | Did not exist — update would falsely fail unique on self | Created with `Rule::unique()->ignore($this->duty)` |
| `modules/HRM/Database/Factories/*.php` (20 files) | All factories returned empty/minimal `definition()` — `RefreshDatabase` tests would create invalid rows | All rewritten with `$this->faker` definitions + states: `BiometricDeviceFactory::disabled/withAutoSync`, `RosterShiftFactory::night/flexible/custom72`, `AttendancePair::open/overtime`, `AttendanceLog::processed/forEmployee`, `EmployeeFactory` static seq `EMP-0001` deterministic, etc. |
| `tests/Pest.php` | Only `->in('Feature')` — Unit/HRM tests would not run | Added second `pest()->extend(...)->in('Unit')` so `php artisan test` discovers all 108 |

## 108 Tests Created (12 files)

### Feature/HRM (8 files, 88 tests)

- `HrmTestHelper.php` — trait: `adminUser()`, `createOrganization/Department/Designation/Device/Employee/Shift/NightShift`
- `OrganizationTest.php` (7) — guest redirect, index Inertia, store single-row upsert (2 posts same id), validation, update, service upsert invariant
- `DepartmentTest.php` (10) — index/Search/paginate, create with/without parent, sub-department, unique code, update, delete, options API, service filter
- `DesignationTest.php` (9) — index, create with dept, validation (name/code/dept), update, delete, service options, `department` relation
- `BiometricDeviceTest.php` (10) — index, create, IP-required, invalid IP/domain, update, delete (existing in repo — kept), `testConnection` unreachable via fsockopen, sync settings mirror, service unreachable
- `EmployeeTest.php` (10) — DataTable, create EMP- auto-ID + policy/salary nested, create with profile, validation, auto-increment seq, update + policy, show page, delete, options, softDeletes
- `RosterTest.php` (16) — 7× RosterShift (index, fixed/night/flexible create, validation, update, delete) + 9× RosterDuty (index, single create + snapshot, unique per employee+date, update, delete, bulk 2×3=6, excessive range reject, upsert idempotent, controller route fix proof)
- `AttendanceEngineTest.php` (17) — 8 engine specs (IN/OUT pairing, Late status, overtime as second pair, night midnight, flexible window, 1-min debounce, orphan, custom 72h, extra-duty) + 6 sync-panel specs (index, single sync, syncAll, update settings, manual punch unique UID, process pending) + 2 integration (socket graceful fallback, DeviceUsers mapping)
- `SeparatorAndRoutesTest.php` (9) — Separator file content (radix-vue + cn + orientation), bulk POST route + not-clashing wildcard, all 6 sync routes exist, update uses UpdateRosterDutyRequest, manual unique UID, empty syncAll warning, repo guards, menu entries, HRM routes ≥30

### Unit/HRM (3 files, 20 tests)

- `AttendanceProcessingServiceTest.php` (6) — `device_user_id→employee_id` self-heal, `isTimeInWindow` normal+midnight boundaries, `resolveDutyDate` night-shift 02:00→prev day, `detectShift` roster-first over window, multi-day custom 50h worked_hours, `upsertForPair` grand_total 9h, `toMinutes`
- `RosterAndDailyTest.php` (7) — `RosterShiftService` CRUD, `RosterDutyService::bulkAssign` snapshot + upsert-idempotent, repo duplicate guard, `deriveStatus` (Present/Late/Early Out), missing punch (open→Missing Out), org upsert invariant, Employee auto-ID lock (EMP-0001..0003 + custom EMP-9999)
- `HrmServicesTest.php` (7) — OrganizationService get null+upsert, DepartmentService CRUD+options, DesignationService CRUD, BiometricDeviceService testConnection unreachable, BiometricDevice CRUD+auto_sync, Employee options value=employee_id, RosterShift paginate filter crosses_midnight, Organization repo idempotent, Department repo searchColumns

## Outstanding (no more code defects; sandbox has no PHP runtime)

- `BimoetricDeviceFactory.php` typo duplicate from scaffold — harmless (not loaded; autoload uses BiometricDeviceFactory) — can be removed later.
- Host verification required: `php artisan test` (expect 108 HRM tests PASS; existing Core/Auth/Settings unchanged), `npx vitest run` (sidebar/menu), `php artisan hrm:sync-attendance` cron proof.
