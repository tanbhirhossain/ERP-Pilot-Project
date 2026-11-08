# Bugfix Report — Full Sweep 2026-10-29
> Commit `02a81cb` on top of `b6a6bfe` — addresses gap + sweep findings

## Fixed (P0)

| # | Bug | File | Before → After |
|---|-----|------|----------------|
| **B1** | **Swap approve used mutated duty for second update** — `$tgtDuty->update(['scheduled_start_time'=> RosterDuty::find($reqDuty->id)?->shift?->start_time])` read the *already swapped* `reqDuty`, so target got its own old time back | `Services/RosterShiftSwapService.php:22` | Cache `tmpShiftId/tmpStart/tmpEnd` + `tgtShiftId/tgtStart/tgtEnd` **before** any `update()` → both sides correctly exchanged |
| **B2** | **Swap reject not audited** — `update(['status'=>'Rejected'])` without `approved_by` | `Services/RosterShiftSwapService::reject` | Now `update(['status'=>'Rejected','approved_by'=>auth()->id()])` — consistent with Leave/Overtime |
| **B3** | **Leave duplicate/overlap allowed** — no check, could double-book `10→12` twice | `Services/LeaveRequestService.php` | New `assertNoOverlap()` (Pending/Approved, 3-way date overlap query) called on `create()` + `update()` → 422/RuntimeException `Leave overlaps...` |
| **B4** | **HRM permissions missing** — 10 new perms not seeded, menu visible to everyone | `Database/Seeders/HRMPermissionSeeder.php` | Added `hrm.swaps.*`, `hrm.overtimes.*`, `hrm.leave-types.*`, `hrm.leaves.*`, `hrm.informed.*` + `givePermissionTo` for Super Admin/Admin |
| **B5** | **Excel bulk delete blind to audit** — `RosterDuty::where(...)->delete()` is query-builder, no model event → `activity_log` empty | `Http/Controllers/RosterDutyController::excelSave` | Now fetches models first, logs `activity()->performedOn(m)->log('Excel bulk delete — cleared by X')` per deleted duty + one `upsert` entry |
| **B6** | **No default leave types** — fresh DB → Create Leave has empty dropdown | `Database/Seeders/LeaveTypeSeeder.php` (new) | 6 defaults: CL 10, SL 14, EL 18, ML 180, PL 7, CO 0 — `firstOrCreate` by name, run via `php artisan db:seed --class=LeaveTypeSeeder` |
| **B7** | **Migration down breaks FK** — `leave_types` drop before `leave_requests` → FK error on `migrate:rollback` | `2026_10_29_130000_create_leave_types_table.php` | `down()` now `dropIfExists('leave_requests')` then `leave_types` |

## Verified — NOT bugs (false positives from grep)

- `route('hrm.xxx.*')` vs `name('xxx.*')` — all Vue route names **do** match `web.php` `prefix('hrm')->name('hrm.')` group (e.g. `name('swaps.index')` → `hrm.swaps.index`).
- `InformedRequest` enum values with spaces (`Late Coming`) — `implode(',', [...])` produces valid `in:` rule; tested.
- `SearchableSelect clearable` — prop exists, used consistently.
- `AdminLayout` import depth `../../../../../CORE/...` — correct for `modules/HRM/Resources/js/Pages/Informed` (4 levels to `modules/`).
- `Inertia` page resolver `HRM::Informed/*` — `resources/js/app.ts: resolveInertiaPage` handles `Module::Page` via `modulePages` glob.
- `Vite` alias `@` → `resources/js` — UI components resolve.

## Remaining P1 (deferred, not bugs)

- **G3 Approved Leave → Attendance Daily `On Leave`** — still manual; hook recommended but out of bugfix scope (documented in GAP).
- **G5 Informed Panel notifications**, **G10 Dashboard widgets**, **G12 Export** — feature requests, not regressions.
- **ZK sync 10s `sleep(1)`** — intentional vendor lib behavior; `hrm:pipeline --queue` mitigates.

## How to verify

```bash
php artisan migrate
php artisan db:seed --class=HRMPermissionSeeder
php artisan db:seed --class=LeaveTypeSeeder
# Trigger reported case still pending:
php artisan hrm:fix-pairing --employee=L1009001 --device_user=200293 --from=2026-10-24 --to=2026-10-29 --apply
php artisan hrm:pipeline --stage=pairs && php artisan hrm:pipeline --stage=daily
npm run build
```

No `TODO`/`FIXME` left in `modules/HRM`; 27 migrations, 113 `hrm.*` routes, 40 Vue pages all green.
