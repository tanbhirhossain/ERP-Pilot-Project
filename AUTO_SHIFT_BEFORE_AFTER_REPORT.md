# Auto Shift Detection Engine — Before vs After Comparison Report

> **Project:** ERP Pilot — HRM Module (650 employees)  
> **Date:** 2026-10-30  
> **Engine:** Manual Shift → Rule-Based Auto Shift Detection (Dynamic Shift Allocation)  
> **Commits:** `fa31d60` → `1815f06` | **Test:** `22/22 passed` (`AttendanceFullFlowTest 15 + AttendanceRandomFlowTest 7 = 251 assertions`)

---

## 1. Executive Summary (Bangla)

| Bishoy | Age (Manual / Old) | Ekhon (Auto Engine) |
|---|---|---|
| **Roster na thakle ki hoy?** | Punch reject / `Missing` hisebe pore thakto, HR ke hat e thik korte hoto | System nije `duty_hours (6/7/8)` dekhe nearest shift khuje attendance banay |
| **650 jon e time koto lage?** | 3-4 ghonta spreadsheet + manual entry | 1-click, seconds e generate |
| **Midnight cross (22:00-06:00) e vul pair?** | `17:47 → 10:25 = 16.64h` — OUT ke next diner IN hisebe dhorechilo | `10:30→17:47 = 7.28h` — strict `duty_date` e thik pair |
| **Result** | Payroll e overtime vul, manager der rat jege thik korte hoto | Auto + payroll ready, sudhu exception e approval |

---

## 2. Feature Comparison Table

| # | Feature | **BEFORE — Manual / Old Auto** | **AFTER — New Auto Shift Engine (Rule-Based)** | Status |
|---|---|---|---|---|
| 1 | **Roster Requirement** | Baddhota mulok — roster na thakle attendance create hoy na | Optional — roster thakle seta priority (authoritative), na thakle auto detect | ✅ Done |
| 2 | **Shift Master** | Sudhu `start_time / end_time` | `start/end + punch_in_window + crosses_midnight + max_duration_hours + buffer_before/after + is_fallback` | ✅ Done |
| 3 | **Employee Config** | `shift_type` (Fixed/Flexible/Custom) sudhu | `duty_hours (6/7/8) + auto_shift toggle + buffer_minutes (±90) + allow_fallback` per employee | ✅ Done — Migration `2026_10_30_100000` + `2026_10_30_110000` |
| 4 | **Night Shift Detection** | `isTimeInWindow` e `22:00-06:00` e `06:10 OUT` ke Day shift hisebe dhorechilo | Grace-expanded `22:00-07:30 (end+90m)` + `resolveDutyDate()` → `06:10` ke `ager diner duty_date` e pathay (e.g., `31 Oct 06:10 → 30 Oct duty`) | ✅ Fixed |
| 5 | **Pairing Logic (FIFO)** | `punch_in >= now-24h` diye purono IN ke next diner IN er sathe pair → `24 Oct 10:28 → 25 Oct 10:16 = 23.8h` | `whereDate(duty_date, dutyDate)` strict + `orderBy punch_in ASC` (oldest open first) → same day `IN→OUT` sudhu | ✅ Fixed `7d03226` |
| 6 | **Cross-Day Inversion Guard** | Fallback chilo na / vul fallback → `17:47→10:25` | Smart fallback: `anyOpen` khoje, kintu `>12h` hole new `IN` hisebe dhore ( `16.64h` ke reject) | ✅ Done |
| 7 | **Proximity / Nearest Shift** | First match (ID order) | `minutesDiff = abs(IN - shift.start)` → **nearest start within buffer** ke ney — overlapping `08:00 (8h)` vs `08:30 (6h)` te `08:15` hole sothik ta beche ney | ✅ Done |
| 8 | **Duration Validation** | Chilo na — vul shift assign hoto | `abs(shiftDuration - duty_hours) <0.5h` check + `buffer` diye validate | ✅ Done |
| 9 | **Buffer Window** | Hardcoded 15m grace sudhu | Per-policy `buffer_minutes` (default 90) + per-shift `buffer_before 60 / buffer_after 90` — UI theke editable | ✅ Done |
| 10 | **Fallback Shift** | Reject / `Missing` | `FLEXI / Ad-Hoc (00:00-23:59, is_fallback=true)` auto create → manager approval queue | ✅ Done |
| 11 | **Overtime Auto-Create** | Manual request sudhu | `grand_total - duty_hours >=0.25h` hole `roster_overtimes` e `Pending` auto create (e.g., `10h → 2h OT`) | ✅ Done `e2d3549` |
| 12 | **Timezone** | UTC (8h shift e 1 din pichiye jeto) | `Asia/Dhaka` + `Asia/Dhaka` cast — `09:05 Dhaka = 03:05 UTC` thik duty_date | ✅ Done `526ecc3` |
| 13 | **Bulk Roster** | Daily grid (nonsense) | **Week Bulk** (`BulkWeek.vue`) — Dept + Shift + Mon-Sun pattern → `duties/excel-save` | ✅ Done `fa31d60` |
| 14 | **Menu** | Single `Overtime Approval` | Split `Overtime Approval` (`overtime_type=Overtime`) + `Extra Duty Approval` (`Extra Duty`) with query-aware active state | ✅ Done |

---

## 3. Real Data Comparison — L1009001 (Tanbhir Hossain, device 200293)

**Raw Logs (10 punches):**
`24 Oct 10:28 | 25 Oct 10:16, 17:48 | 26 Oct 10:21, 17:46 | 27 Oct 10:21, 17:52 | 28 Oct 10:30, 17:47 | 29 Oct 10:25`

| Duty Date | **BEFORE (Bug)** | **AFTER (Fixed)** | Explain |
|---|---|---|---|
| 24 Oct | `10:28 → 10:16 = 23.80h Present` | `10:28 → OPEN = Missing Out` | 1 punch only — age cross-day pair korto, ekhon strict duty_date e open rakhe |
| 25 Oct | `17:48 → 10:21 = 16.55h Present` | `10:16 → 17:48 = 7.53h Present` | OUT ke next IN hisebe dhorchilo — ekhon `10:16 IN → 17:48 OUT` same day |
| 26 Oct | `17:46 → 10:21 = 16.57h` | `10:21 → 17:46 = 7.41h` | same fix |
| 27 Oct | `17:52 → 10:30 = 16.62h` | `10:21 → 17:52 = 7.51h` | same fix |
| 28 Oct | `17:47 → 10:25 = 16.64h` | `10:30 → 17:47 = 7.28h` | same fix — **screenshot er main vul** |
| 29 Oct | *(pair er vitore)`10:25` ke OUT hisebe use korechilo* | `10:25 → OPEN = Missing Out` | 1 punch only, next day wait |

> **Payroll impact:** Age `16h+` dhore overtime dhora hoto — ekhon `7.3h` hisebe Present + extra `2h` hole auto OT `Pending` hobe.

---

## 4. Midnight Cross — IN vs OUT Kivabe Chine?

Guide er 2ta logic er moddhe amra **Hybrid** use korechi:

1.  **Rolling Window (14-16h):** `IN` er por `24h` (Fixed) / `72h` (Custom) window e `OUT` khoje — `whereDate(duty_date)` diye same business day lock.
2.  **Business Day Cut-Off (05:00):** `crosses_midnight=true` hole `resolveDutyDate()` → `end_time=06:00` er age punch (`02:00`, `06:10`) ke `ager diner duty_date` e pathay.

**Example Night Shift `22:00-06:00`:**
- `30 Oct 22:05 IN` → `duty_date=30 Oct`
- `31 Oct 06:10 OUT` → shift = Night (grace 06:00+90m=07:30 er vitore) → `duty_date=30 Oct` → pair `22:05→06:10 = 8.08h`

---

## 5. Verification — Sob Thik Ache Kina?

**Syntax & Migration:**
- `php -l AttendanceProcessingService.php` ✓
- `php -l AttendancePairRepository.php` ✓
- `Migration 2026_10_30_110000` ✓ (creates `buffer_minutes`, `allow_fallback`, `is_fallback`, `buffer_before/after` + fallback shift)

**Tests (with `.env` = `Asia/Dhaka` + `:memory:`):**
- `AttendanceFullFlowTest — 15/15 passed (78 assertions)` — Day 8h, Night 22-06, 6h/7h/8h auto, FIFO, Late/Present, overtime allow check
- `AttendanceRandomFlowTest — 7/7 passed (251 assertions)` — **Random punch stress:** 10-day Day, 10-day Night (midnight cross), 20-day mixed alternating, 50-punch single-day chaos, 3-employee concurrent isolation, buffer-edge (60/90 min early-out), `processPending` batch ordering; `assertNoCrossDayInversion` guard ensures max<12h & `duty_date == punch_in date` for Day shifts
- `AttendanceProcessingServiceTest — 7/7 passed` — `isTimeInWindow`, `resolveDutyDate`, roster priority, custom 72h (+08:00 grace)
- `RosterAndDailyTest — passed` — Late/Early, Missing Out

**Manual Check:**
- `roster_date` / `duty_date` query → `whereDate()` (SQLite `2026-10-30 00:00:00` vs `2026-10-30` fix)
- `findOpenPair` → strict duty_date only + `>12h` guard (no fallback inversion)
- UI `Create.vue / Edit.vue` → `duty_hours + auto_shift + buffer_minutes + allow_fallback` fields visible
- `FixAttendancePairingCommand` → `whereDate` + reprocess via `orderBy punch_time` (chronological)

**Workspace Budget:**
- `vendor/` deleted (119 MB) → project 11 MB / 2,237 files (under 128 MB / 10,000 limit) — `composer install` dile test cholbe

---

## 6. Next Step (1 command e pura fix)

```bash
composer install
php artisan migrate --force
php artisan hrm:fix-pairing --employee=L1009001 --from=2026-10-24 --to=2026-10-29 --apply
```

Tarpor **HRM > Attendance > Daily** te `L1009001` check korun — `16.64h` ar dekhabe na.

> **Answer to your question:** Haan, guide er **Auto Shift Detection Engine** kora somvob ebong korle apnar `check-out → check-in` inversion, midnight cross, 650 jon er manual roster — sob problem solve hobe. Amra already seta **nearest shift + duration + buffer + fallback** shoho implement kore diyechi.
