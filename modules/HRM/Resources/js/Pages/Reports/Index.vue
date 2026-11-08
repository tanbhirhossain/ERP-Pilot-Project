<script setup lang="ts">
import { Head, Link} from '@inertiajs/vue3';
import { BarChart3, Users, Plane, Clock3, Timer, Download, Building2} from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';
const props = defineProps<{ overview:any; filters:any }>();
const o = props.overview;
function pct(part:number, total:number){ return total? Math.round(part/total*100) : 0; }
function barW(val:number, max:number){ return max? Math.round(val/max*100) : 0; }
/** BDT amounts arrive as floats from SQL aggregates; render them as whole taka. */
function money(v:any):string { return new Intl.NumberFormat('en-BD', { maximumFractionDigits: 0 }).format(Math.round(Number(v ?? 0))); }
</script>
<template>
 <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'HR Reports & Analytics' }]">
  <Head title="HR Reports"/>
  <div class="overflow-hidden rounded-xl border bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 p-6 text-white">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="flex items-center gap-2 text-xs uppercase tracking-widest text-indigo-200"><BarChart3 class="size-4"/> HR Reports & Analytics — Headcount, Attrition, Leave Liability</p>
        <h1 class="mt-1 text-2xl font-bold">HRM Analytics <span class="rounded-full bg-white/15 px-2.5 py-0.5 text-xs">{{o.headcount.total}} total · {{o.headcount.active}} active</span></h1>
        <p class="mt-1 text-sm text-indigo-100/80">Live from employees, resignations, leave_requests, attendance_dailies, roster_overtimes. Export CSV per section.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <a :href="route('hrm.reports.export', {type:'headcount'})" class="rounded-md bg-white px-3 py-1.5 text-sm font-medium text-slate-900"><Download class="size-4 inline"/> Export CSV</a>
        <Link :href="route('hrm.employees.index')"><Button variant="outline" class="bg-white/10 border-white/20 text-white hover:bg-white/20">Employees</Button></Link>
      </div>
    </div>
    <!-- KPI Row -->
    <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-5">
      <div class="rounded-xl bg-white/10 p-3"><p class="text-xs text-indigo-200">Total / Active</p><p class="text-xl font-bold">{{o.headcount.total}} / <span class="text-emerald-300">{{o.headcount.active}}</span></p><p class="text-xs text-indigo-200">{{pct(o.headcount.active, o.headcount.total)}}% active</p></div>
      <div class="rounded-xl bg-white/10 p-3"><p class="text-xs text-indigo-200">Pending Leaves (Y)</p><p class="text-xl font-bold">{{o.leave.pending}} days</p><p class="text-xs text-amber-200">{{o.leave.approved}} approved</p></div>
      <div class="rounded-xl bg-white/10 p-3"><p class="text-xs text-indigo-200">Late (6M)</p><p class="text-xl font-bold">{{o.attendance.trend.reduce((s:any,r:any)=>s+Number(r.late),0)}} events</p><p class="text-xs text-indigo-200">{{o.attendance.trend.length}} months</p></div>
      <div class="rounded-xl bg-white/10 p-3"><p class="text-xs text-indigo-200">OT Hours (MTD)</p><p class="text-xl font-bold">{{(o.ot.ot.reduce((s:any,r:any)=> r.status==='Approved' ? s+Number(r.hrs||0): s,0)).toFixed(1)}}h</p><p class="text-xs text-indigo-200">{{o.ot.byDept.length}} depts</p></div>
      <div class="rounded-xl bg-white/10 p-3"><p class="text-xs text-indigo-200">Resignations (6M)</p><p class="text-xl font-bold">{{o.attrition.resignations.reduce((s:any,r:any)=>s+r.cnt,0)}}</p><p class="text-xs text-red-200">turnover</p></div>
    </div>
  </div>

  <!-- Headcount -->
  <section class="mt-6 grid gap-6 md:grid-cols-2">
    <div class="rounded-xl border bg-card p-6">
      <h2 class="flex items-center gap-2 font-bold"><Building2 class="size-5 text-primary"/> Headcount by Department</h2>
      <div class="mt-3 space-y-2">
        <div v-for="r in o.headcount.byDept" :key="r.dept" class="flex items-center gap-3">
          <span class="w-36 truncate text-sm">{{r.dept}}</span>
          <div class="flex-1 h-2 rounded-full bg-muted"><div class="h-2 rounded-full bg-primary" :style="{width: barW(r.cnt, Math.max(...o.headcount.byDept.map((x:any)=>x.cnt)))+'%'}"></div></div>
          <span class="w-12 text-right text-sm font-bold">{{r.cnt}}</span><span class="text-xs text-muted-foreground">{{pct(r.active, r.cnt)}}% act</span>
        </div>
      </div>
      <StaticTable
          class="mt-4"
          :empty="!o?.headcount?.byStatus?.length"
          empty-title="No employees yet"
          empty-description="Headcount by status appears once employees are added."
      ><thead class="bg-muted/50"><tr><th class="px-3 py-1 text-left">Status</th><th class="px-3 py-1 text-right">Count</th></tr></thead><tbody><tr v-for="r in o.headcount.byStatus" :key="r.status" class="border-t"><td class="px-3 py-1">{{r.status}}</td><td class="px-3 py-1 text-right font-bold">{{r.cnt}}</td></tr></tbody></StaticTable>
    </div>
    <div class="rounded-xl border bg-card p-6">
      <h2 class="flex items-center gap-2 font-bold"><Users class="size-5 text-primary"/> Attrition (Resignations 12M)</h2>
      <div class="mt-3 h-24 flex items-end gap-1">
        <div v-for="r in o.attrition.resignations" :key="r.ym" class="flex-1 flex flex-col items-center gap-1"><div class="w-full rounded-t bg-amber-500" :style="{height: Math.max(4, barW(r.cnt, Math.max(1, Math.max(...o.attrition.resignations.map((x:any)=>x.cnt)))) * 0.8 + 20)+'px'}"></div><span class="text-[10px]">{{r.ym.slice(5)}}</span></div>
        <div v-if="o.attrition.resignations.length===0" class="text-sm text-muted-foreground">No resignations in period — healthy.</div>
      </div>
      <div class="mt-3 flex flex-wrap gap-2 text-xs"><span v-for="r in o.attrition.monthlyRate" :key="r.ym" class="rounded-full border px-2 py-0.5">{{r.ym}} {{r.rate}}%</span></div>
      <div class="mt-3 rounded-lg border p-2 text-xs"><p class="font-semibold">Movements (transfers/promotions) 6M: {{o.attrition.movements.reduce((s:any,r:any)=>s+r.cnt,0)}}</p><p class="text-muted-foreground">Internal mobility vs external attrition.</p></div>
    </div>
  </section>

  <!-- Leave -->
  <section class="mt-6 rounded-xl border bg-card p-6">
    <div class="flex items-center justify-between"><h2 class="flex items-center gap-2 font-bold"><Plane class="size-5 text-primary"/> Leave Liability — {{o.leave.year}}</h2><a :href="route('hrm.reports.export',{type:'leave'})" class="text-xs text-primary hover:underline">Export →</a></div>
    <div class="mt-3 grid grid-cols-3 gap-3 text-center">
      <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Approved</p><p class="text-xl font-bold text-emerald-600">{{o.leave.approved}}</p></div>
      <div class="rounded-lg border p-3 bg-amber-50"><p class="text-xs text-muted-foreground">Pending (liability)</p><p class="text-xl font-bold text-amber-600">{{o.leave.pending}}</p></div>
      <div class="rounded-lg border p-3"><p class="text-xs text-muted-foreground">Carry / Lapsed</p><p class="text-sm font-bold">{{o.leave.carry}} / {{o.leave.lapsed}}</p></div>
    </div>
    <div class="mt-3 grid md:grid-cols-2 gap-4">
      <div><p class="text-xs font-semibold">By leave type (approved/pending)</p><div class="mt-1 space-y-1"><div v-for="r in o.leave.byType" :key="r.type" class="flex justify-between text-sm border-b py-1"><span>{{r.type}}</span><span class="font-mono">{{r.approved}} / {{r.pending}}</span></div></div></div>
      <div><p class="text-xs font-semibold">Top leave takers (10)</p><div class="mt-1 space-y-1"><div v-for="r in o.leave.topTakers" :key="r.employee_id" class="flex justify-between text-sm"><span class="font-mono text-xs">{{r.employee_id}}</span><span class="font-bold">{{r.total_days}}d</span></div></div></div>
    </div>
  </section>

  <!-- Attendance -->
  <section class="mt-6 rounded-xl border bg-card p-6">
    <h2 class="flex items-center gap-2 font-bold"><Clock3 class="size-5 text-primary"/> Attendance Trend (6M) — Late / Early / Absent / Missing</h2>
    <StaticTable
        class="mt-3"
        :empty="!o?.attendance?.trend?.length"
        empty-title="No attendance recorded yet"
        empty-description="Monthly attendance appears once daily records are processed."
    ><thead class="bg-muted/60 text-xs"><tr><th class="px-3 py-2">Month</th><th class="px-3 py-2 text-right">Total</th><th class="px-3 py-2 text-right">Present</th><th class="px-3 py-2 text-right text-amber-600">Late</th><th class="px-3 py-2 text-right">Early</th><th class="px-3 py-2 text-right text-red-600">Absent</th><th class="px-3 py-2 text-right">Missing</th><th class="px-3 py-2 text-right text-emerald-600">Regularized</th></tr></thead>
        <tbody class="divide-y"><tr v-for="r in o.attendance.trend" :key="r.ym" class="hover:bg-muted/20"><td class="px-3 py-1 font-mono text-xs">{{r.ym}}</td><td class="px-3 py-1 text-right">{{r.total}}</td><td class="px-3 py-1 text-right">{{r.present}}</td><td class="px-3 py-1 text-right font-bold text-amber-600">{{r.late}}</td><td class="px-3 py-1 text-right">{{r.early_out}}</td><td class="px-3 py-1 text-right text-red-600">{{r.absent}}</td><td class="px-3 py-1 text-right">{{r.missing}}</td><td class="px-3 py-1 text-right text-emerald-600">{{r.regularized}}</td></tr>
        </tbody></StaticTable>
    <div class="mt-3"><p class="text-xs font-semibold">Late by department (6M)</p><div class="flex flex-wrap gap-2 mt-1"><span v-for="r in o.attendance.lateByDept" :key="r.dept" class="rounded-full bg-amber-100 px-2.5 py-1 text-xs">{{r.dept}}: {{r.late_cnt}}</span></div></div>
  </section>

  <!-- OT -->
  <section class="mt-6 rounded-xl border bg-card p-6">
    <div class="flex items-center justify-between"><h2 class="flex items-center gap-2 font-bold"><Timer class="size-5 text-primary"/> OT Cost — {{o.ot.from}} → {{o.ot.to}}</h2><a :href="route('hrm.reports.export',{type:'ot'})" class="text-xs text-primary hover:underline">Export →</a></div>
    <div class="mt-3 grid md:grid-cols-3 gap-3">
      <div class="rounded-lg border p-3 text-center"><p class="text-xs text-muted-foreground">Approved Hours</p><p class="text-xl font-bold">{{o.ot.byDept.reduce((s:any,r:any)=>s+Number(r.hrs),0).toFixed(1)}}h</p></div>
      <div class="rounded-lg border p-3 text-center"><p class="text-xs text-muted-foreground">OT Cost</p><p class="text-sm font-bold">{{ money(o.ot.ot.find((x:any)=>x.status==='Approved')?.cost) }} BDT est.</p></div>
      <div class="rounded-lg border p-3 text-center"><p class="text-xs text-muted-foreground">Top Dept</p><p class="text-sm font-bold">{{o.ot.byDept[0]?.dept ?? '—'}} {{o.ot.byDept[0]?.hrs ?? 0}}h</p></div>
    </div>
    <div class="mt-3 grid md:grid-cols-2 gap-4">
      <div><p class="text-xs font-semibold">By dept (Approved OT)</p><div class="mt-1 space-y-1"><div v-for="r in o.ot.byDept" :key="r.dept" class="flex justify-between text-sm"><span>{{r.dept}}</span><span class="font-mono">{{Number(r.hrs).toFixed(1)}}h / {{r.cnt}}</span></div></div></div>
      <div><p class="text-xs font-semibold">Top OT takers</p><div class="mt-1 space-y-1"><div v-for="r in o.ot.topOt" :key="r.employee_id" class="flex justify-between text-sm"><span class="font-mono text-xs">{{r.employee_id}}</span><span class="font-bold">{{Number(r.hrs).toFixed(1)}}h</span></div></div></div>
    </div>
  </section>

  <p class="mt-4 text-center text-xs text-muted-foreground">All metrics via <code>hrm_leave_balances</code>, <code>leave_requests</code>, <code>attendance_dailies</code>, <code>roster_overtimes</code> — add <code>?year=2025&months=12</code> filters. See subpages: /headcount /attrition /leave /attendance /ot</p>
</AdminLayout>
</template>
