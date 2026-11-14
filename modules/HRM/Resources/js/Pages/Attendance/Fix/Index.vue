<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { confirmDialog } from '@core/Composables/useConfirm';
import { useToast } from '@core/Composables/useToast';
import StaticTable from '@core/Components/StaticTable.vue';

interface DeptOpt { value:number; label:string }
interface FixStatus { state:'idle'|'running'|'done'|'failed'; step?:string; scope?:string; from?:string; to?:string; started_at?:string; updated_at?:string; error?:string; result?:any }
interface Props { departmentOptions: DeptOpt[]; initialFilters?: { scope?: 'all'|'department'|'employee'; department_id?: number|null; employee_id?: string|null; from?: string|null; to?: string|null }; stats: { total_pairs:number; total_dailies:number; total_logs:number; pending_logs:number }; lastRepairableDate?: string; fixStatus?: FixStatus }
const props = defineProps<Props>();
const page = usePage();
const flash = computed(()=> (page.props as any).flash ?? {});
const preview = computed(()=> (page.props as any).preview ?? null);
// The repair runs on the queue, so its report arrives through `fixStatus` rather than a result flash.
const status = computed<FixStatus>(()=> (page.props as any).fixStatus ?? { state:'idle' });
const running = computed(()=> status.value.state === 'running');
const result = computed(()=> status.value.state === 'done' || status.value.state === 'failed' ? (status.value.result ?? null) : ((page.props as any).result ?? null));
const STEPS:any = { queued:'Queued', clearing:'Clearing the range', replaying_pairs:'Replaying the pairing stage', reconciling:'Recalculating dailies + reconciling the calendar', restoring:'Restoring calendar rows the rebuild could not derive' };

const form = ref({
  scope: props.initialFilters?.scope ?? 'all' as 'all'|'department'|'employee',
  department_id: props.initialFilters?.department_id ?? null as number|null,
  employee_id: props.initialFilters?.employee_id ?? null as string|null,
  from: props.initialFilters?.from ?? '',
  to: props.initialFilters?.to ?? '',
  dry_run: true as boolean,
});

const toast = useToast();

async function submit(dry:boolean){
  if (!form.value.from || !form.value.to) { toast.warning('Select both From and To dates first.'); return; }
  if (form.value.scope === 'employee' && !form.value.employee_id) { toast.warning('Select an employee first.'); return; }
  if (form.value.scope === 'department' && !form.value.department_id) { toast.warning('Select a department first.'); return; }
  if (!dry) {
    // The most destructive action in HRM: it deletes and rebuilds every pair and daily row in scope.
    const proceed = await confirmDialog({
      title: 'Clear and rebuild attendance',
      description: `This will clear and rebuild all attendance pairs and daily rows in the selected scope from ${form.value.from} through ${form.value.to}.`,
      confirmLabel: 'Clear and rebuild',
    });
    if (!proceed) return;
  }
  form.value.dry_run = dry;
  const payload:any = {
    scope: form.value.scope,
    from: form.value.from || null,
    to: form.value.to || null,
    dry_run: dry ? 1 : 0,
  };
  if(form.value.scope==='department') payload.department_id = form.value.department_id;
  if(form.value.scope==='employee') payload.employee_id = form.value.employee_id;
  if(form.value.scope==='all'){
    // no dept/emp needed
  }
  router.post(route('hrm.attendance.fix'), payload, {
    preserveScroll:true,
    onSuccess: ()=> { if (!dry) watchQueue(); },
  });
}

// Poll the page prop while a repair is in flight. Partial reload (`only`) so the form keeps its state and
// the page does not flicker; 5s is plenty for a job that itself takes minutes, and cheap enough to leave on.
let timer: ReturnType<typeof setTimeout> | null = null;
function watchQueue(){
  if (timer !== null) return;
  const tick = () => {
    if (!running.value) { timer = null; return; }
    router.reload({ only:['fixStatus'], preserveScroll:true, preserveState:true });
    timer = setTimeout(tick, 5000);
  };
  timer = setTimeout(tick, 2500);
}
onMounted(()=>{ if (running.value) watchQueue(); });
watch(running, (v)=>{ if (v) watchQueue(); else if (timer !== null) { clearTimeout(timer); timer = null; } });

const deptObj = ref<{value:number; label:string}|null>(null);
function onDept(v:any){ form.value.department_id = v; deptObj.value = null; }

const scopeOptions = [
  {value:'all', label:'All Employees (global fix)'},
  {value:'department', label:'By Department'},
  {value:'employee', label:'By Employee'},
];
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Attendance' }, { title: 'Attendance Fix' }]">
    <Head title="Fix Attendance Pairing"/>
    <div class="flex flex-col gap-6">
      <div>
        <PageHeader title="Fix Attendance Pairing" />
        <p class="text-sm text-muted-foreground">Repair wrong IN→OUT pairs (e.g., 16h overnight) and rebuild Daily with correct Late/Early. Applying queues a <b>background job</b> that replays the HRM pipeline for the range (clear → re-pair → Stage 3 recalc + reconcile), so a month keeps <b>one row per day</b>: punch days are rebuilt from logs, and <b>Off Day, Holiday, On Leave</b> days are reclassified from the roster/holiday/leave calendar — anything the calendar cannot reproduce is put back as it was. A repair only finalises days up to <b>{{ lastRepairableDate }}</b>; today belongs to the nightly pipeline.</p>
        <div class="mt-2 flex gap-4 text-xs text-muted-foreground">
          <span>Total Pairs: <b>{{ stats.total_pairs }}</b></span>
          <span>Dailies: <b>{{ stats.total_dailies }}</b></span>
          <span>Logs: <b>{{ stats.total_logs }}</b> (pending <b>{{ stats.pending_logs }}</b>)</span>
        </div>
      </div>

      <div v-if="running" class="bg-blue-500/10 border border-blue-200 text-blue-800 rounded-xl p-3 text-sm flex items-center gap-2">
        <span class="inline-block size-2 animate-pulse rounded-full bg-blue-500"></span>
        <span>Repair running in the background — {{ STEPS[status.step ?? ''] ?? status.step ?? 'working' }}<template v-if="status.scope"> · {{ status.scope }}</template><template v-if="status.from"> · {{ status.from }} → {{ status.to }}</template>. This page refreshes itself when it lands.</span>
      </div>
      <div v-else-if="status.state === 'failed'" class="bg-red-500/10 border border-red-200 text-red-700 rounded-xl p-3 text-sm">{{ status.step || status.error || 'The repair job stopped.' }}</div>
      <div v-if="flash.success" class="bg-emerald-500/10 border border-emerald-200 text-emerald-800 rounded-xl p-3 text-sm">{{ flash.success }}</div>
      <div v-if="flash.error" class="bg-red-500/10 border border-red-200 text-red-700 rounded-xl p-3 text-sm">{{ flash.error }}</div>

      <div class="bg-card border rounded-xl p-5 space-y-4">
        <div class="flex flex-col gap-3">
          <Label>Scope</Label>
          <div class="flex flex-wrap gap-2">
            <button v-for="o in scopeOptions" :key="o.value" @click="form.scope = o.value as any" :class="form.scope===o.value ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'" class="px-4 py-2 rounded-full text-sm font-medium border">{{ o.label }}</button>
          </div>
        </div>

        <div v-if="form.scope==='department'" class="space-y-1 max-w-sm">
          <Label>Department</Label>
          <SearchableSelect :model-value="form.department_id" endpoint="/hrm/api/options/departments" placeholder="Select department" clearable @update:model-value="onDept" />
        </div>

        <div v-if="form.scope==='employee'" class="space-y-1 max-w-sm">
          <Label>Employee</Label>
          <SearchableSelect :model-value="form.employee_id" endpoint="/hrm/api/options/employees" placeholder="Search employee by name or ID" clearable @update:model-value="(v:any)=>form.employee_id=v" />
          <p class="text-xs text-muted-foreground">Search by name (e.g., Md Arif) or code M106006</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-w-lg">
          <div class="space-y-1"><Label>From (required)</Label><Input type="date" v-model="form.from" /></div>
          <div class="space-y-1"><Label>To (required)</Label><Input type="date" v-model="form.to" /></div>
        </div>

        <div class="flex gap-2">
          <Button variant="outline" @click="submit(true)">Preview (Dry Run)</Button>
          <Button @click="submit(false)" class="bg-red-600 hover:bg-red-700">Fix & Reprocess</Button>
        </div>
        <p class="text-xs text-muted-foreground">A repair range is limited to 32 calendar days and 50,000 raw logs. Applying never runs inside the request — it queues a job, so closing this page (or waiting) cannot leave a half-repaired month. Apply rebuilds all pairs and daily rows in the selected scope, resets matching biometric logs, and reprocesses the range. Review the dry-run before applying.</p>
      </div>

      <!-- Preview / Result -->
      <div v-if="preview" class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold text-sm mb-2">Preview — {{ preview.wrong_count }} wrong pairs, {{ preview.daily_count }} dailies, {{ preview.log_count }} logs in range</h3>
        <StaticTable dense v-if="preview.wrong_pairs && preview.wrong_pairs.length">
            <thead><tr class="border-b text-muted-foreground"><th class="text-left p-2">Emp</th><th class="text-left p-2">Duty Date</th><th class="text-left p-2">Punch In → Out</th><th class="text-center p-2">Hours</th></tr></thead>
            <tbody>
              <tr v-for="w in preview.wrong_pairs" :key="w.id" class="border-b"><td class="p-2 font-mono">{{ w.employee_id }}</td><td class="p-2">{{ w.duty_date }}</td><td class="p-2 font-mono">{{ w.punch_in }} → {{ w.punch_out ?? 'OPEN' }}</td><td class="p-2 text-center font-bold text-red-600">{{ w.hours }}h</td></tr>
            </tbody>
          </StaticTable>
        <p v-else class="text-sm text-muted-foreground">No wrong pairs detected in this range — but Fix will still clean all pairs/dailies in range for full reprocess if you click Fix.</p>
      </div>

      <div v-if="result" class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold text-sm mb-2">Result — Reprocessed</h3>
        <p v-if="result?.message" class="text-sm text-muted-foreground mb-3">{{ result.message }}</p>
        <div v-if="result?.restored || result?.replaced || result?.clipped" class="mb-3 flex flex-wrap gap-2 text-xs">
          <span v-if="(result?.restored ?? 0) + (result?.replaced ?? 0) > 0" class="rounded-full bg-amber-500/10 px-2 py-1 text-amber-700">{{ (result?.restored ?? 0) + (result?.replaced ?? 0) }} calendar row(s) the rebuild could not derive were kept as they were ({{ result.restored ?? 0 }} re-added, {{ result.replaced ?? 0 }} put back over a synthetic Absent)</span>
          <span v-if="result?.clipped" class="rounded-full bg-blue-500/10 px-2 py-1 text-blue-700">Range cut at {{ result.to }} — {{ result.requested_to }} was beyond the nightly pipeline's finalisation line</span>
        </div>
        <div class="grid grid-cols-3 gap-3 text-sm mb-3">
          <div class="bg-muted rounded-lg p-3"><div class="text-muted-foreground text-xs">Processed logs</div><div class="text-lg font-bold">{{ result.reprocess?.processed }}</div></div>
          <div class="bg-blue-500/10 rounded-lg p-3"><div class="text-muted-foreground text-xs">New pairs</div><div class="text-lg font-bold text-blue-600">{{ result.reprocess?.new_pairs }}</div></div>
          <div class="bg-emerald-500/10 rounded-lg p-3"><div class="text-muted-foreground text-xs">Closed pairs</div><div class="text-lg font-bold text-emerald-600">{{ result.reprocess?.closed_pairs }}</div></div>
        </div>
        <StaticTable dense v-if="result.new_pairs?.length">
            <thead><tr class="border-b text-muted-foreground"><th class="text-left p-2">Duty Date</th><th class="text-left p-2">In → Out</th><th class="text-center p-2">Hours</th><th class="text-center p-2">Type</th></tr></thead>
            <tbody>
              <tr v-for="np in result.new_pairs" :key="np.duty_date" class="border-b"><td class="p-2 font-mono">{{ np.duty_date }}</td><td class="p-2 font-mono">{{ np.punch_in }} → {{ np.punch_out ?? 'OPEN' }}</td><td class="p-2 text-center font-semibold">{{ np.hours }}h</td><td class="p-2 text-center"><span class="px-2 py-0.5 rounded-full text-xs" :class="np.type==='Primary'?'bg-blue-500/10 text-blue-600':'bg-amber-500/10 text-amber-600'">{{ np.type }}</span></td></tr>
            </tbody>
          </StaticTable>
      </div>
    </div>
  </AdminLayout>
</template>
