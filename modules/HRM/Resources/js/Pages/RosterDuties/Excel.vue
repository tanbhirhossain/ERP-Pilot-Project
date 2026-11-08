<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, onMounted, watch } from 'vue';
import axios from 'axios';
import { Save, Copy, ClipboardPaste, Trash2, Search, ChevronLeft, ChevronRight, Grid3X3} from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';

interface Shift { id:number; shift_name:string; start_time:string; end_time:string; crosses_midnight:boolean; color?:string }
interface Employee { employee_id:string; full_name:string; department?:string; designation?:string }
const props = defineProps<{ initialFrom:string; initialTo:string; shiftOptions:any[]; shifts:Shift[] }>();

const from = ref(props.initialFrom);
const to = ref(props.initialTo);
const departmentId = ref<string>('');
const search = ref('');
const perPage = ref(50);
const page = ref(1);

const employees = ref<Employee[]>([]);
const duties = ref<Map<string, any>>(new Map());
const dates = ref<string[]>([]);
const meta = ref<any>({ current_page:1, last_page:1, total:0 });
const departments = ref<any[]>([]);
const loading = ref(false);
const saving = ref(false);
const dirty = ref<Set<string>>(new Set());
const selected = ref<Set<string>>(new Set());
const activeCell = ref<string|null>(null);
const clipboard = ref<number|null>(null);

// Shift color map
const shiftColors: Record<string,string> = {
  'Day Shift': 'bg-amber-100 text-amber-900 border-amber-200',
  'Night Shift': 'bg-slate-900 text-white border-slate-700',
  'Morning': 'bg-sky-100 text-sky-900 border-sky-200',
  'Evening': 'bg-violet-100 text-violet-900 border-violet-200',
  'Off': 'bg-muted text-muted-foreground border-transparent',
};
function shiftStyle(shiftId:number|null){
  if(!shiftId) return 'bg-muted/30 text-muted-foreground border-dashed';
  const s = props.shifts.find(x=>x.id===shiftId);
  if(!s) return 'bg-muted';
  return shiftColors[s.shift_name] || 'bg-emerald-100 text-emerald-900 border-emerald-200';
}
function shiftLabel(shiftId:number|null){
  if(!shiftId) return 'Off';
  const s = props.shifts.find(x=>x.id===shiftId);
  return s ? `${s.shift_name} (${s.start_time?.slice(0,5)}-${s.end_time?.slice(0,5)})` : `#${shiftId}`;
}
function dateLabel(d:string){
  const dt = new Date(d);
  const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
  return { d: dt.getDate().toString().padStart(2,'0'), dow: days[dt.getDay()], isWeekend: dt.getDay()===5 || dt.getDay()===6, isToday: new Date().toISOString().slice(0,10)===d };
}

function keyFor(empId:string, date:string){ return `${empId}|${date}`; }

async function fetchData(){
  loading.value = true;
  try{
    const { data } = await axios.get(route('hrm.duties.excel-data'), {
      params: { from: from.value, to: to.value, department_id: departmentId.value || undefined, search: search.value || undefined, page: page.value, per_page: perPage.value }
    });
    employees.value = data.employees;
    dates.value = data.dates;
    meta.value = data.meta;
    departments.value = data.departments;
    const m = new Map<string, any>();
    for(const d of data.duties){ m.set(keyFor(d.employee_id, d.roster_date), d); }
    duties.value = m;
    dirty.value = new Set();
    selected.value = new Set();
    activeCell.value = null;
  } finally { loading.value = false; }
}
function getDuty(empId:string, date:string){
  return duties.value.get(keyFor(empId, date)) || null;
}
function setDuty(empId:string, date:string, shiftId:number|null){
  const k = keyFor(empId, date);
  const prev = duties.value.get(k);
  const prevId = prev?.shift_id ?? null;
  if ((prevId ?? null) === (shiftId ?? null)) return;
  if (shiftId === null) {
    duties.value.delete(k);
  } else {
    duties.value.set(k, { employee_id: empId, roster_date: date, shift_id: shiftId, status: 'Scheduled' });
  }
  duties.value = new Map(duties.value); // trigger reactivity
  dirty.value.add(k);
  // use Set reactivity
  const nd = new Set(dirty.value); dirty.value = nd as any;
}

function toggleSelect(empId:string, date:string, e:MouseEvent){
  const k = keyFor(empId, date);
  if (e.shiftKey && activeCell.value) {
    // range select
    const [aEmp, aDate] = activeCell.value.split('|');
    const aEmpIdx = employees.value.findIndex(x=>x.employee_id===aEmp);
    const bEmpIdx = employees.value.findIndex(x=>x.employee_id===empId);
    const aDateIdx = dates.value.indexOf(aDate);
    const bDateIdx = dates.value.indexOf(date);
    const minEmp = Math.min(aEmpIdx, bEmpIdx), maxEmp = Math.max(aEmpIdx, bEmpIdx);
    const minDate = Math.min(aDateIdx, bDateIdx), maxDate = Math.max(aDateIdx, bDateIdx);
    const next = new Set(selected.value);
    for(let i=minEmp; i<=maxEmp; i++){
      for(let j=minDate; j<=maxDate; j++){
        next.add(keyFor(employees.value[i].employee_id, dates.value[j]));
      }
    }
    selected.value = next;
  } else if (e.ctrlKey || e.metaKey) {
    const next = new Set(selected.value);
    if (next.has(k)) next.delete(k); else next.add(k);
    selected.value = next;
    activeCell.value = k;
  } else {
    selected.value = new Set([k]);
    activeCell.value = k;
  }
}

function applyToSelected(shiftId:number|null){
  if (selected.value.size===0 && activeCell.value) {
    const [emp,date] = activeCell.value.split('|');
    setDuty(emp, date, shiftId);
    return;
  }
  for(const k of selected.value){
    const [emp,date] = k.split('|');
    setDuty(emp, date, shiftId);
  }
}
function clearSelected(){
  for(const k of selected.value){
    const [emp,date] = k.split('|');
    setDuty(emp, date, null);
  }
}
function copySelected(){
  if (activeCell.value) {
    const d = duties.value.get(activeCell.value);
    clipboard.value = d?.shift_id ?? null;
  } else if (selected.value.size>0) {
    const first = selected.value.values().next().value as string;
    const d = duties.value.get(first);
    clipboard.value = d?.shift_id ?? null;
  }
}
function pasteSelected(){
  if (clipboard.value === undefined) return;
  if (selected.value.size===0 && activeCell.value) {
    const [emp,date] = activeCell.value.split('|');
    setDuty(emp, date, clipboard.value);
  } else {
    for(const k of selected.value){
      const [emp,date] = k.split('|');
      setDuty(emp, date, clipboard.value);
    }
  }
}

function handleKey(e:KeyboardEvent){
  if (!activeCell.value) return;
  const [empId, dateStr] = activeCell.value.split('|');
  let empIdx = employees.value.findIndex(x=>x.employee_id===empId);
  let dateIdx = dates.value.indexOf(dateStr);
  if (e.key==='ArrowRight') { e.preventDefault(); if(dateIdx < dates.value.length-1) dateIdx++; }
  else if (e.key==='ArrowLeft') { e.preventDefault(); if(dateIdx>0) dateIdx--; }
  else if (e.key==='ArrowUp') { e.preventDefault(); if(empIdx>0) empIdx--; }
  else if (e.key==='ArrowDown') { e.preventDefault(); if(empIdx < employees.value.length-1) empIdx++; }
  else if (e.key==='Delete' || e.key==='Backspace') { e.preventDefault(); const k=keyFor(empId, dateStr); const [emp,date]=k.split('|'); setDuty(emp,date,null); return; }
  else if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='c') { e.preventDefault(); copySelected(); return; }
  else if ((e.ctrlKey||e.metaKey) && e.key.toLowerCase()==='v') { e.preventDefault(); pasteSelected(); return; }
  else return;
  const newKey = keyFor(employees.value[empIdx].employee_id, dates.value[dateIdx]);
  activeCell.value = newKey;
  selected.value = new Set([newKey]);
  document.getElementById(`cell-${newKey}`)?.scrollIntoView({ block:'nearest', inline:'nearest' });
}

async function save(){
  saving.value = true;
  const payload = Array.from(dirty.value).map(k=>{
    const [employee_id, roster_date] = k.split('|');
    const d = duties.value.get(k);
    return { employee_id, roster_date, shift_id: d?.shift_id ?? null };
  });
  // Also need to include deletions where duty was removed but dirty tracks removal — we already track via dirty set deletions, but need to ensure deletions are sent
  // Our dirty set only contains keys where we changed; for deletions we deleted from map but still marked dirty, so payload will have shift_id null which is correct
  // However if user cleared a cell that was previously Off (no duty), it was not in map, but we deleted (no op) — still need to send null to delete if it existed
  // For simplicity, send all dirty keys
  try{
    await new Promise<void>((resolve, reject)=>{
      router.post(route('hrm.duties.excel-save'), { duties: payload }, {
        preserveScroll: true,
        onSuccess: () => resolve(),
        onError: (errors)=> reject(errors),
        onFinish: ()=> saving.value = false,
      });
    });
    dirty.value = new Set() as any;
  } catch{
    saving.value = false;
  }
}

function setMonth(offset:number){
  const base = new Date(from.value);
  base.setMonth(base.getMonth()+offset);
  const y = base.getFullYear(), m = base.getMonth();
  const f = new Date(y,m,1).toISOString().slice(0,10);
  const t = new Date(y,m+1,0).toISOString().slice(0,10);
  from.value = f; to.value = t; page.value=1; fetchData();
}
function quickRange(days:number){
  const f = new Date().toISOString().slice(0,10);
  const t = new Date(Date.now()+days*86400000).toISOString().slice(0,10);
  from.value = f; to.value = t; fetchData();
}

onMounted(fetchData);
watch([from,to,departmentId,perPage,page], ()=> fetchData());
let searchTimer:any = null;
watch(search, ()=>{ clearTimeout(searchTimer); searchTimer = setTimeout(()=>{ page.value=1; fetchData(); }, 400); });

</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Roster' }, { title: 'Roster Duties', href: route('hrm.duties.index') }, { title: 'Excel' }]">
    <Head title="Excel Roster" />
    <div class="space-y-3">
      <!-- Header -->
      <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3">
        <div>
          <h1 class="text-xl font-bold flex items-center gap-2"><Grid3X3 class="size-5"/> Excel Roster — Like Excel Sheet</h1>
          <p class="text-sm text-muted-foreground">Click cells, select range (Shift+click), copy/paste (Ctrl+C/V), arrow keys. Bulk fill from toolbar.</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <Link :href="route('hrm.duties.index')"><Button variant="outline" size="sm">List View</Button></Link>
          <Link :href="route('hrm.shifts.index')"><Button variant="outline" size="sm">Manage Shifts</Button></Link>
          <Button size="sm" :disabled="saving || dirty.size===0" @click="save"><Save class="size-4"/> Save {{ dirty.size ? `(${dirty.size})` : '' }}</Button>
        </div>
      </div>

      <!-- Filters -->
      <div class="bg-card border rounded-xl p-3 space-y-3">
        <div class="flex flex-wrap items-end gap-3">
          <div class="space-y-1"><Label class="text-xs">From</Label><Input type="date" v-model="from" class="h-8"/></div>
          <div class="space-y-1"><Label class="text-xs">To</Label><Input type="date" v-model="to" class="h-8"/></div>
          <div class="flex items-end gap-1">
            <button v-for="d in [1,7,30]" :key="d" type="button" @click="quickRange(d)"
              class="h-8 rounded-md border bg-card px-2.5 text-xs font-medium hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
              :title="`Set the range to today → +${d - 1} day(s)`">{{ d === 1 ? 'Today' : d + 'd' }}</button>
          </div>
          <div class="flex gap-1">
            <Button variant="outline" size="sm" @click="setMonth(-1)" class="h-8"><ChevronLeft class="size-4"/></Button>
            <Button variant="outline" size="sm" @click="setMonth(0)" class="h-8">This Month</Button>
            <Button variant="outline" size="sm" @click="setMonth(1)" class="h-8"><ChevronRight class="size-4"/></Button>
          </div>
          <div class="space-y-1">
            <Label class="text-xs">Department</Label>
            <select v-model="departmentId" class="h-8 rounded-md border bg-background px-3 text-sm">
              <option value="">All Departments</option>
              <option v-for="d in departments" :key="d.value" :value="String(d.value)">{{ d.label }}</option>
            </select>
          </div>
          <div class="space-y-1 min-w-[200px]">
            <Label class="text-xs">Search Employee</Label>
            <div class="relative"><Search class="absolute left-2 top-1/2 -translate-y-1/2 size-4 text-muted-foreground"/><Input v-model="search" placeholder="ID or name..." class="h-8 pl-8"/></div>
          </div>
          <div class="space-y-1"><Label class="text-xs">Per page</Label><select v-model.number="perPage" class="h-8 rounded-md border bg-background px-3 text-sm"><option :value="25">25</option><option :value="50">50</option><option :value="100">100</option></select></div>
          <Button variant="outline" size="sm" @click="fetchData" class="h-8" :disabled="loading">{{ loading ? 'Loading...' : 'Reload' }}</Button>
        </div>

        <!-- Shift legend + bulk toolbar -->
        <div class="flex flex-wrap items-center gap-2 border-t pt-3">
          <span class="text-xs font-semibold">Shifts:</span>
          <button v-for="s in shifts" :key="s.id" @click="applyToSelected(s.id)" class="px-2.5 py-1 rounded-full text-xs font-medium border" :class="shiftStyle(s.id)">{{ s.shift_name }}</button>
          <button @click="applyToSelected(null)" class="px-2.5 py-1 rounded-full text-xs font-medium border bg-muted">Off</button>
          <span class="mx-2 h-4 w-px bg-border hidden sm:inline"/>
          <Button variant="outline" size="sm" @click="copySelected" :disabled="!activeCell && selected.size===0"><Copy class="size-4"/> Copy</Button>
          <Button variant="outline" size="sm" @click="pasteSelected" :disabled="clipboard===null"><ClipboardPaste class="size-4"/> Paste {{ clipboard!==null ? shiftLabel(clipboard) : '' }}</Button>
          <Button variant="outline" size="sm" @click="clearSelected" :disabled="selected.size===0"><Trash2 class="size-4"/> Clear</Button>
          <span v-if="dirty.size" class="ml-auto text-xs text-amber-600 font-medium">{{ dirty.size }} unsaved changes</span>
          <span v-if="selected.size" class="text-xs text-muted-foreground">{{ selected.size }} cells selected</span>
        </div>
      </div>

      <!-- Excel Grid -->
      <div class="bg-card border rounded-xl overflow-hidden">
        <StaticTable
            flush
            dense
            sticky
            :empty="!loading && !employees?.length"
            empty-title="No employees to roster"
            empty-description="Adjust the filters above to load employees into the grid."
            @keydown="handleKey"
            tabindex="0"
        >
            <thead class="sticky top-0 z-20 bg-muted/80 backdrop-blur">
              <tr>
                <th class="sticky left-0 z-30 bg-muted border-b border-r min-w-[240px] text-left p-2 font-semibold">Employee <span class="text-xs font-normal text-muted-foreground">({{ meta.total }} total)</span></th>
                <th v-for="d in dates" :key="d" class="border-b border-r min-w-[110px] text-center p-1.5" :class="dateLabel(d).isWeekend ? 'bg-amber-50' : dateLabel(d).isToday ? 'bg-sky-50 ring-1 ring-sky-200' : 'bg-muted'">
                  <div class="text-[11px] font-semibold">{{ dateLabel(d).d }}</div>
                  <div class="text-[10px] text-muted-foreground">{{ dateLabel(d).dow }}</div>
                  <div class="text-[10px] truncate">{{ d.slice(5) }}</div>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="emp in employees" :key="emp.employee_id" class="hover:bg-muted/30">
                <td class="sticky left-0 z-10 bg-card border-b border-r p-2">
                  <div class="font-mono text-xs font-semibold">{{ emp.employee_id }}</div>
                  <div class="text-sm leading-tight truncate max-w-[200px]">{{ emp.full_name }}</div>
                  <div class="text-[11px] text-muted-foreground truncate">{{ emp.department }} • {{ emp.designation }}</div>
                </td>
                <td v-for="d in dates" :key="d" :id="`cell-${keyFor(emp.employee_id,d)}`"
                    @click="toggleSelect(emp.employee_id, d, $event)"
                    class="border-b border-r p-1 text-center cursor-pointer select-none relative h-[44px]"
                    :class="[
                      getDuty(emp.employee_id,d)?.shift_id ? shiftStyle(getDuty(emp.employee_id,d).shift_id) : 'bg-card hover:bg-muted/50 border-dashed',
                      selected.has(keyFor(emp.employee_id,d)) ? 'ring-2 ring-primary ring-inset' : '',
                      activeCell===keyFor(emp.employee_id,d) ? 'ring-2 ring-primary' : '',
                      dirty.has(keyFor(emp.employee_id,d)) ? 'after:absolute after:top-0 after:right-0 after:size-0 after:border-l-[10px] after:border-l-amber-500 after:border-b-[10px] after:border-b-transparent' : ''
                    ]">
                  <span v-if="getDuty(emp.employee_id,d)" class="text-[11px] font-medium leading-tight block">{{ shiftLabel(getDuty(emp.employee_id,d).shift_id).split(' ')[0] }}</span>
                  <span v-else class="text-[10px] text-muted-foreground">Off</span>
                  <span v-if="getDuty(emp.employee_id,d)" class="text-[10px] opacity-70 block">{{ getDuty(emp.employee_id,d).shift_id ? shifts.find(s=>s.id===getDuty(emp.employee_id,d).shift_id)?.start_time?.slice(0,5) : '' }}</span>
                </td>
              </tr>
              <tr v-if="employees.length===0">
                <td :colspan="dates.length+1" class="p-8 text-center text-muted-foreground">{{ loading ? 'Loading...' : 'No employees found. Try different department/search.' }}</td>
              </tr>
            </tbody>
          </StaticTable>
        <!-- Pagination -->
        <div class="flex items-center justify-between p-3 border-t bg-muted/20">
          <div class="text-xs text-muted-foreground">Page {{ meta.current_page }} of {{ meta.last_page }} • {{ meta.total }} employees • {{ dates.length }} days • {{ employees.length * dates.length }} cells</div>
          <div class="flex gap-1">
            <Button variant="outline" size="sm" :disabled="page<=1" @click="page=Math.max(1,page-1);">Prev</Button>
            <span class="px-3 py-1 text-sm border rounded-md bg-card">{{ page }} / {{ meta.last_page }}</span>
            <Button variant="outline" size="sm" :disabled="page>=meta.last_page" @click="page=Math.min(meta.last_page, page+1)">Next</Button>
          </div>
        </div>
      </div>

      <!-- Help -->
      <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-900 flex gap-2">
        <Grid3X3 class="size-4 shrink-0 mt-0.5"/>
        <div>
          <strong>Excel tips:</strong> Click = select, <kbd class="px-1 py-0.5 bg-white border rounded text-[10px]">Shift</kbd>+click = range, <kbd class="px-1 py-0.5 bg-white border rounded text-[10px]">Ctrl</kbd>+click = multi, Arrow keys = navigate, <kbd class="px-1 py-0.5 bg-white border rounded text-[10px]">Delete</kbd> = Off, <kbd class="px-1 py-0.5 bg-white border rounded text-[10px]">Ctrl+C</kbd>/<kbd class="px-1 py-0.5 bg-white border rounded text-[10px]">Ctrl+V</kbd> = copy/paste shift. Select many cells → click a shift in legend to bulk-fill. Orange corner = unsaved.
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
