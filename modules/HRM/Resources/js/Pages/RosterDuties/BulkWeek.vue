<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import type { SelectOption } from '@core/types';
import { ref, computed} from 'vue';
import { CalendarDays, Users, Filter, Save, Eye } from 'lucide-vue-next';
import axios from 'axios';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import StaticTable from '@core/Components/StaticTable.vue';

const props = defineProps<{ shiftOptions:SelectOption[], shifts:any[] }>();
const departments = ref<any[]>([]);
const weekdays = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
const filters = ref({
  department_id: null as number|null,
  designation_id: null as number|null,
  search: '',
  shift_id: null as number|null,
  start_date: new Date().toISOString().slice(0,10),
  end_date: new Date(new Date().setDate(new Date().getDate()+13)).toISOString().slice(0,10),
  weekdays: [1,2,3,4,5] as number[], // Mon-Fri default 1-5 (Sun 0)
});
const employees = ref<any[]>([]);
const pattern = ref<Record<string, Record<number, number|null>>>({});
const loading = ref(false);
const saving = ref(false);
const msg = ref('');

async function loadEmployees(){
  loading.value=true;
  try{
    const res = await axios.get(route('hrm.duties.excel-data'), {
      params: { from: filters.value.start_date, to: filters.value.end_date, department_id: filters.value.department_id, search: filters.value.search || undefined, per_page: 100, page:1 }
    });
    employees.value = res.data.employees;
    departments.value = res.data.departments;
    // init pattern per employee per weekday
    employees.value.forEach((e:any)=>{
      if(!pattern.value[e.employee_id]) pattern.value[e.employee_id]={};
      weekdays.forEach((_, idx)=>{
        const dow = idx===6 ? 0 : idx+1; // Mon=1 ... Sun=0
        if(filters.value.weekdays.includes(dow) && filters.value.shift_id) pattern.value[e.employee_id][dow]=filters.value.shift_id;
        else if(!filters.value.weekdays.includes(dow)) pattern.value[e.employee_id][dow]=null;
      });
    });
    msg.value=`Loaded ${employees.value.length} employees`;
  }catch(e:any){ msg.value=e.response?.data?.message || 'Load failed'; }
  loading.value=false;
}

function cycleShift(empId:string, dow:number){
  const cur = pattern.value[empId]?.[dow] ?? null;
  const opts = [null, ...props.shifts.map(s=>s.id)];
  const idx = opts.indexOf(cur as any);
  const next = opts[(idx+1)%opts.length];
  if(!pattern.value[empId]) pattern.value[empId]={};
  pattern.value[empId][dow]=next;
}

function applyTemplate(){
  employees.value.forEach(e=>{
    weekdays.forEach((_, idx)=>{
      const dow = idx===6 ? 0 : idx+1;
      if(!pattern.value[e.employee_id]) pattern.value[e.employee_id]={};
      pattern.value[e.employee_id][dow] = filters.value.weekdays.includes(dow) ? filters.value.shift_id : null;
    });
  });
}

const previewCount = computed(()=>{
  if(!employees.value.length) return 0;
  let c=0;
  const start=new Date(filters.value.start_date), end=new Date(filters.value.end_date);
  for(let d=new Date(start); d<=end; d.setDate(d.getDate()+1)){
    const dow=d.getDay();
    employees.value.forEach(e=>{ if(pattern.value[e.employee_id]?.[dow]) c++; });
  }
  return c;
});

async function saveBulk(){
  if(!previewCount.value) return;
  saving.value=true;
  const duties:any[]=[];
  const start=new Date(filters.value.start_date), end=new Date(filters.value.end_date);
  for(let d=new Date(start); d<=end; d.setDate(d.getDate()+1)){
    const iso=d.toISOString().slice(0,10);
    const dow=d.getDay();
    employees.value.forEach(e=>{
      const shiftId=pattern.value[e.employee_id]?.[dow] ?? null;
      // only upsert if pattern has shift; if null we could delete but keep as no duty (off)
      if(shiftId) duties.push({employee_id:e.employee_id, roster_date:iso, shift_id:shiftId});
      // if you want to clear off days, push shift_id null for those weekdays
    });
  }
  try{
    // use existing excel-save which handles upsert
    await axios.post(route('hrm.duties.excel-save'), {duties, _token: (document.querySelector('meta[name=\"csrf-token\"]') as any)?.content});
    msg.value=`Saved ${duties.length} duties (${filters.value.start_date} → ${filters.value.end_date})`;
    // reload to confirm
  }catch(e:any){ msg.value=e.response?.data?.message || 'Save failed'; }
  saving.value=false;
}

const shiftLabel = (id:number|null)=> props.shifts.find(s=>s.id===id)?.shift_name?.slice(0,3) ?? 'Off';
const shiftColor = (id:number|null)=> {
  if(!id) return 'bg-muted text-muted-foreground';
  const n=props.shifts.find(s=>s.id===id)?.shift_name?.toLowerCase() ?? '';
  if(n.includes('day')) return 'bg-amber-100 text-amber-800 border-amber-200';
  if(n.includes('night')) return 'bg-slate-800 text-white';
  if(n.includes('morn')) return 'bg-sky-100 text-sky-800';
  if(n.includes('even')) return 'bg-violet-100 text-violet-800';
  return 'bg-emerald-100 text-emerald-800';
};
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Roster' }, { title: 'Week Bulk' }]">
    <Head title="Roster Week Bulk"/>
    <PageHeader title="Week Bulk Roster" description="Filter by Department/Designation → pick weekdays template → preview by week → save. Replaces non-sense daily grid." :icon="CalendarDays">
        <template #actions>
            <Link :href="route('hrm.duties.excel')"><Button variant="outline" size="sm"><Eye class="size-4"/> Excel Grid</Button></Link>
        </template>
    </PageHeader>

    <div class="bg-card border rounded-xl p-4 space-y-4">
      <div class="flex items-center gap-2 font-semibold text-sm"><Filter class="size-4"/> Filters & Week Rule</div>
      <div class="grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <div class="space-y-1"><Label>Department</Label><SearchableSelect v-model="filters.department_id" :options="departments" placeholder="All departments" clearable/></div>
        <div class="space-y-1"><Label>Shift Template</Label><SearchableSelect v-model="filters.shift_id" :options="shiftOptions" placeholder="Pick shift"/></div>
        <div class="space-y-1"><Label>Start Date</Label><Input v-model="filters.start_date" type="date"/></div>
        <div class="space-y-1"><Label>End Date</Label><Input v-model="filters.end_date" type="date"/></div>
        <div class="space-y-1"><Label>Search</Label><Input v-model="filters.search" placeholder="Emp ID / name"/></div>
        <div class="flex items-end gap-2">
          <Button @click="loadEmployees" :disabled="loading" size="sm" class="w-full"><Users class="size-4"/>{{ loading?'Loading...':'Load Employees'}}</Button>
        </div>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <span class="text-xs font-semibold">Weekdays:</span>
        <label v-for="(wd, idx) in weekdays" :key="idx" class="flex items-center gap-1 text-xs border rounded-full px-2 py-1 cursor-pointer" :class="filters.weekdays.includes(idx===6?0:idx+1)?'bg-indigo-600 text-white border-indigo-600':'bg-muted'">
          <input type="checkbox" :value="idx===6?0:idx+1" v-model="filters.weekdays" class="hidden"/> {{ wd }}
        </label>
        <Button size="sm" variant="outline" @click="applyTemplate">Apply Template to Preview</Button>
        <span class="text-xs text-muted-foreground">Preview will generate <b>{{ previewCount }}</b> duties</span>
      </div>
      <p v-if="msg" class="text-xs text-emerald-700 bg-emerald-50 border border-emerald-200 rounded p-2">{{ msg }}</p>
    </div>

    <div v-if="employees.length" class="bg-card border rounded-xl overflow-auto">
      <div class="p-3 flex items-center justify-between">
        <h3 class="font-semibold text-sm flex items-center gap-2"><Users class="size-4"/> {{ employees.length }} employees × 7 weekdays</h3>
        <Button size="sm" @click="saveBulk" :disabled="saving || !previewCount" class="bg-emerald-600 hover:bg-emerald-700"><Save class="size-4"/>{{ saving?'Saving...':`Save ${previewCount} Duties`}}</Button>
      </div>
      <StaticTable
        flush
        dense
        :empty="!loading && !employees?.length"
        empty-title="No employees to roster"
        empty-description="Adjust the filters above to load employees into the week."
      >
        <thead class="bg-muted/50 sticky top-0">
          <tr>
            <th class="text-left p-2 font-semibold border min-w-[180px]">Employee</th>
            <th v-for="wd in weekdays" :key="wd" class="text-center p-2 font-semibold border min-w-[90px]">{{ wd }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="emp in employees" :key="emp.employee_id" class="hover:bg-muted/20">
            <td class="p-2 border"><p class="font-mono font-semibold text-xs">{{ emp.employee_id }}</p><p class="text-xs truncate max-w-[180px]">{{ emp.full_name }}</p><p class="text-[10px] text-muted-foreground">{{ emp.department }} • {{ emp.designation }}</p></td>
            <td v-for="(_, idx) in weekdays" :key="idx" class="p-1 border text-center">
              <button @click="cycleShift(emp.employee_id, idx===6?0:idx+1)" class="w-full h-8 rounded border text-xs font-medium" :class="shiftColor(pattern[emp.employee_id]?.[idx===6?0:idx+1] ?? null)">{{ shiftLabel(pattern[emp.employee_id]?.[idx===6?0:idx+1] ?? null) }}</button>
            </td>
          </tr>
        </tbody>
      </StaticTable>
      <p class="text-xs text-muted-foreground p-2">Click cell to cycle Off → Day → Night ... Each column is a weekday; saving expands to every date in range matching that weekday.</p>
    </div>
  </AdminLayout>
</template>
