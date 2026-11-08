# Quick Fix for stale workspace (without re-download)

You are building from:
C:\Users\TANBHIR HOSSAIN\Downloads\workspace-01a1a0fd-3ec1-7c85-88e4-d078123df124

This is old. The current workspace here has 0 template errors.

## Option A (recommended): Re-download
1. In Arena top bar, click **Download** / **Workspace** → Download ZIP
2. Delete the old `workspace-01a1a0fd-...` folder on C:\
3. Extract the NEW zip and run `npm run build` there

## Option B: Patch this one file now (then you'll hit the next one)
Replace C:\...\modules\HRM\Resources\js\Pages\LeaveRequests\Index.vue
with the good version from this workspace (open that file in Arena file browser → copy → paste on Windows).

After that, next error will be the next broken file in old snapshot (Overtimes, RosterDuties...). Repeating patch one-by-one is slow — Option A fixes all at once.

All 240 files in **this** workspace are now valid:
- Dailies / Logs / Pairs correctly premium-wrapped with form inside PageHeader
- 10 broken Index (Tax, ProvidentFund, Bonuses, Adjustments, ExtraDuty, Informed, LeaveRequests, Overtimes, RosterSwaps/Duties) reverted to valid originals
- Create forms with `title="{{ }}"` fixed to `:title="isEdit ? ..."`
