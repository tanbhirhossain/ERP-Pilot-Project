<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { RefreshCw, Play, Settings, Wifi, Clock, CheckCircle, XCircle, AlertTriangle, Plus, Calendar } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { formatDateTime } from '../../../../../CORE/Resources/js/Utils/format';

interface Device { id:number; name:string; ip_address:string; port:number; status:string; is_enabled:boolean; auto_sync_enabled:boolean; sync_interval_minutes:number; last_sync_at?:string; syncSetting?:any }
interface SyncLog { id:number; device:string; status:string; records_fetched:number; records_processed:number; message?:string; error?:string; synced_at?:string; created_at:string }
defineProps<{ devices: Device[]; logs: SyncLog[] }>();

// Date filter for incremental sync (reduces DB work; note transfer still full per package docs)
const dateFilter = ref({ from: '', to: '' });
const presets = [
  { label: 'Today', get: () => ({ from: new Date().toISOString().slice(0,10), to: new Date().toISOString().slice(0,10) }) },
  { label: 'Last 3 days', get: () => { const d=new Date(); const from=new Date(); from.setDate(d.getDate()-3); return { from: from.toISOString().slice(0,10), to: d.toISOString().slice(0,10) }; } },
  { label: 'Last 7 days', get: () => { const d=new Date(); const from=new Date(); from.setDate(d.getDate()-7); return { from: from.toISOString().slice(0,10), to: d.toISOString().slice(0,10) }; } },
  { label: 'Incremental', get: () => ({ from: '', to: '' }) },
];
function applyPreset(idx:number){ const v=presets[idx].get(); dateFilter.value=v; }

function syncOne(id:number){
  const payload:any = {};
  if(dateFilter.value.from) payload.from_date = dateFilter.value.from;
  if(dateFilter.value.to) payload.to_date = dateFilter.value.to;
  router.post(route('hrm.attendance.sync.device',id), payload, {preserveScroll:true});
}
function syncAll(){
  const payload:any = {};
  if(dateFilter.value.from) payload.from_date = dateFilter.value.from;
  if(dateFilter.value.to) payload.to_date = dateFilter.value.to;
  router.post(route('hrm.attendance.sync.all'), payload, {preserveScroll:true});
}

function processPending(){ router.post(route('hrm.attendance.sync.process'),{},{preserveScroll:true}); }
function runPipelineAll(queue=false){ router.post(route('hrm.attendance.pipeline.all'), queue?{queue:true}:{}, {preserveScroll:true}); }
function runPipelineForDevice(id:number, queue=false){ router.post(route('hrm.attendance.pipeline.device',id), queue?{queue:true}:{}, {preserveScroll:true}); }
function runStage(stage:string){ router.post(route('hrm.attendance.pipeline.stage',stage), {}, {preserveScroll:true}); }

const editing=ref<number|null>(null);
const editForm=ref({auto_sync_enabled:false,sync_interval_minutes:15,sync_window_hours:24});
function openEdit(d:Device){ editing.value=d.id; editForm.value={auto_sync_enabled:d.auto_sync_enabled,sync_interval_minutes:d.sync_interval_minutes,sync_window_hours:d.syncSetting?.sync_window_hours??24}; }
function saveEdit(id:number){ router.put(route('hrm.attendance.sync.settings',id),editForm.value,{preserveScroll:true,onSuccess(){ editing.value=null; }}); }

const manual=ref({employee_id:'',punch_time:'',device_id:null as number|null});
const showManual=ref(false);
function addManual(){ router.post(route('hrm.attendance.manual'),manual.value,{preserveScroll:true,onSuccess(){ showManual.value=false; manual.value={employee_id:'',punch_time:'',device_id:null}; }}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Attendance Sync'}]">
    <Head title="Attendance Sync Panel"/>
    <div class="flex flex-col gap-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div><h1 class="text-xl font-bold flex items-center gap-2"><RefreshCw class="size-5 text-primary"/>Attendance Sync Panel</h1><p class="text-sm text-muted-foreground">Sync from ZKTeCo — incremental by default (since last sync), or filter by date.</p></div>
        <div class="flex gap-2">
          <Button variant="outline" size="sm" @click="processPending"><Play class="size-4"/> Process Pending</Button>
        </div>
      </div>

      <!-- Date Filter Bar -->
      <div class="bg-card border rounded-xl p-4">
        <div class="flex flex-col gap-3">
          <div class="flex items-center gap-2 text-sm font-medium"><Calendar class="size-4"/> Sync Date Filter <span class="text-xs font-normal text-muted-foreground">— reduces DB inserts; device still transfers full log (see package docs)</span></div>
          <div class="flex flex-wrap items-end gap-3">
            <div class="space-y-1"><Label>From</Label><Input type="date" v-model="dateFilter.from" class="w-40"/></div>
            <div class="space-y-1"><Label>To</Label><Input type="date" v-model="dateFilter.to" class="w-40"/></div>
            <div class="flex gap-1 flex-wrap">
              <Button v-for="(p,i) in presets" :key="p.label" variant="outline" size="sm" @click="applyPreset(i)">{{ p.label }}</Button>
            </div>
            <div class="flex gap-2">
              <Button size="sm" @click="syncAll"><RefreshCw class="size-4"/> Sync All ({{ dateFilter.from || 'incremental' }} → {{ dateFilter.to || 'now' }})</Button>
            </div>
          </div>
          <p class="text-xs text-muted-foreground bg-blue-500/10 border border-blue-500/20 rounded-lg p-2">
            <strong>Note per <a href="https://github.com/jmrashed/zkteco#244-get-attendance-within-a-datetime-range" target="_blank" class="underline">jmrashed/zkteco README 24.4</a>:</strong>
            <code class="font-mono">getAttendanceByDateRange()</code> <strong>still downloads the FULL device log</strong> and filters client-side — it saves DB work, not transfer time. True speed fix = frequent 15-min incremental sync (auto since <code>last_sync_at - window</code>).
          </p>
          <p class="text-xs text-muted-foreground">CLI: <code class="bg-muted px-1 rounded">php artisan hrm:sync-attendance --device=1 --since=today</code> or <code>--from=2024-01-01 --to=2024-01-31</code></p>
        </div>
      </div>

      <!-- Manual Punch -->
      <div class="bg-card border rounded-xl p-4">
        <div class="flex items-center justify-between mb-3"><h3 class="font-semibold text-sm flex items-center gap-2"><Plus class="size-4"/> Manual Punch (for testing)</h3><Button variant="outline" size="sm" @click="showManual=!showManual">{{ showManual?'Close':'Add Manual' }}</Button></div>
        <div v-if="showManual" class="flex flex-wrap gap-3 items-end">
          <div class="space-y-1"><Label>Employee ID</Label><Input v-model="manual.employee_id" placeholder="EMP-0001" class="w-40"/></div>
          <div class="space-y-1"><Label>Punch Time</Label><Input v-model="manual.punch_time" type="datetime-local" class="w-56"/></div>
          <div class="space-y-1"><Label>Device (opt)</Label><select v-model="manual.device_id" class="h-9 rounded-md border bg-background px-3 text-sm w-40"><option :value="null">None</option><option v-for="d in devices" :key="d.id" :value="d.id">{{ d.name }}</option></select></div>
          <Button size="sm" @click="addManual">Add to Queue</Button>
        </div>
        <p class="text-xs text-muted-foreground mt-2">Manual punches go to attendance_logs as unprocessed → processed by Pair Engine into Pairs → Dailies.</p>
      </div>

      <!-- Pipeline (3 Stages) -->
      <div class="bg-gradient-to-r from-blue-500/10 to-violet-500/10 border border-blue-500/20 rounded-xl p-4">
        <h3 class="font-semibold text-sm mb-2 flex items-center gap-2"><RefreshCw class="size-4 text-blue-600"/> 3-Stage Pipeline: Raw → Pairs → Daily</h3>
        <p class="text-xs text-muted-foreground mb-3">Stage 1 syncs raw punches (attendance_logs), Stage 2 pairs IN→OUT (attendance_pairs), Stage 3 builds daily summaries (attendance_dailies). Use for clean step-by-step debugging.</p>
        <div class="flex flex-wrap gap-2">
          <Button size="sm" @click="runPipelineAll(false)"><Play class="size-4"/> Run Pipeline (All Devices)</Button>
          <Button size="sm" variant="outline" @click="runPipelineAll(true)"><Clock class="size-4"/> Queue Pipeline</Button>
          <Button size="sm" variant="ghost" @click="runStage('pairs')">Stage 2: Make Pairs Only</Button>
          <Button size="sm" variant="ghost" @click="runStage('daily')">Stage 3: Prepare Daily</Button>
        </div>
        <p class="text-xs text-muted-foreground mt-2">CLI: <code class="bg-white/60 px-1 rounded">php artisan hrm:pipeline --device=1</code> or <code>--stage=pairs</code></p>
      </div>

      <!-- Devices -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <div v-for="device in devices" :key="device.id" class="bg-card border rounded-xl p-4 shadow-sm">
          <div class="flex items-start justify-between">
            <div><h3 class="font-semibold flex items-center gap-2"><Wifi :class="device.is_enabled?'text-emerald-500':'text-zinc-400'" class="size-4"/>{{ device.name }}</h3><p class="text-sm font-mono text-muted-foreground">{{ device.ip_address }}:{{ device.port }} • {{ device.status }}</p><p class="text-xs text-muted-foreground mt-1">Last sync: {{ device.last_sync_at ? formatDateTime(device.last_sync_at) : 'Never (will fetch last 3 days)' }}</p></div>
            <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="device.auto_sync_enabled?'bg-blue-500/10 text-blue-600':'bg-muted text-muted-foreground'">{{ device.auto_sync_enabled?'Auto':'Manual' }}</span>
          </div>
          <div class="mt-3 flex flex-wrap gap-2">
            <Button size="sm" variant="outline" @click="syncOne(device.id)"><RefreshCw class="size-4"/> Sync Now</Button>
            <Button size="sm" @click="runPipelineForDevice(device.id)"><Play class="size-4"/> Pipeline</Button>
            <Button size="sm" variant="ghost" @click="openEdit(device)"><Settings class="size-4"/> Settings</Button>

            <span class="text-xs text-muted-foreground flex items-center gap-1"><Clock class="size-3"/> Every {{ device.sync_interval_minutes }} min</span>
          </div>
          <p class="text-xs text-muted-foreground mt-2">Incremental sync uses <code class="font-mono">last_sync_at - {{ device.syncSetting?.sync_window_hours ?? 24 }}h</code> → now. Use date filter above for custom range.</p>
          <div v-if="editing===device.id" class="mt-3 border-t pt-3 space-y-3">
            <div class="grid grid-cols-2 gap-3">
              <div class="space-y-1"><Label>Auto Sync</Label><select v-model="editForm.auto_sync_enabled" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option :value="true">Enabled</option><option :value="false">Disabled</option></select></div>
              <div class="space-y-1"><Label>Interval (min)</Label><Input v-model.number="editForm.sync_interval_minutes" type="number"/></div>
            </div>
            <div class="space-y-1"><Label>Window Hours</Label><Input v-model.number="editForm.sync_window_hours" type="number"/><p class="text-xs text-muted-foreground">Buffer for late punches. Incremental sync fetches from last_sync minus this.</p></div>
            <Button size="sm" @click="saveEdit(device.id)">Save Settings</Button>
          </div>
        </div>
        <div v-if="devices.length===0" class="col-span-2 text-center py-8 text-sm text-muted-foreground border rounded-xl border-dashed">No devices configured. Add one from Biometric Devices.</div>
      </div>

      <!-- Sync Logs -->
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold text-sm mb-3">Recent Sync Logs</h3>
        <div class="space-y-2">
          <div v-for="log in logs" :key="log.id" class="flex items-center justify-between text-sm border rounded-lg px-3 py-2">
            <div class="flex items-center gap-2">
              <CheckCircle v-if="log.status==='Success'" class="size-4 text-emerald-500"/>
              <XCircle v-else-if="log.status==='Failed'" class="size-4 text-red-500"/>
              <AlertTriangle v-else class="size-4 text-amber-500"/>
              <span class="font-medium">{{ log.device }}</span>
              <span class="text-muted-foreground text-xs">{{ log.message || log.error || '' }}</span>
            </div>
            <div class="flex items-center gap-3 text-xs text-muted-foreground">
              <span>Fetched: {{ log.records_fetched }} • Processed: {{ log.records_processed }}</span>
              <span>{{ log.created_at ? formatDateTime(log.created_at) : '' }}</span>
            </div>
          </div>
          <p v-if="logs.length===0" class="text-sm text-muted-foreground text-center py-4">No sync logs yet. Use date filter + Sync Now, or wait for auto cron.</p>
        </div>
      </div>

      <!-- Flow Diagram -->
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold text-sm mb-2">6-Step Flow (বায়োমেট্রিক লগ থেকে অ্যাটেনডেন্স)</h3>
        <ol class="text-sm text-muted-foreground list-decimal list-inside space-y-1">
          <li><b>Raw Log:</b> ZKTeCo → <code>attendance_logs</code> (is_processed = false) — via incremental <code>last_sync_at - window</code></li>
          <li><b>Policy Resolve:</b> Check <code>employee_policies</code> — Fixed / Flexible / Custom (72h)</li>
          <li><b>Open Pair Check:</b> Within 24/72h open <code>attendance_pairs</code> → close as punch_out else new punch_in</li>
          <li><b>Shift Auto-Detect:</b> punch_in_window for Flexible, roster for Fixed, determine duty_type (Primary/Overtime)</li>
          <li><b>Pair & Hours:</b> Calculate worked_hours, mark logs processed=true</li>
          <li><b>Daily Summary:</b> Sync <code>attendance_dailies</code> — primary + overtime + grand_total + status</li>
        </ol>
        <p class="text-xs text-muted-foreground mt-2">Package: <code>jmrashed/zkteco</code> <a href="https://github.com/jmrashed/zkteco#244-get-attendance-within-a-datetime-range" target="_blank" class="underline">README 24.4</a> — <code>getAttendanceByDateRange()</code> is client-side filter; device always transfers full log. Incremental sync (auto since <code>last_sync_at - window</code>) keeps sync fast.</p>
      </div>
    </div>
  </AdminLayout>
</template>
