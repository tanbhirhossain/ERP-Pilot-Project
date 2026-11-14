<script setup lang="ts">
import { Head, router} from '@inertiajs/vue3';
import { CalendarRange, Save, ShieldCheck, AlertTriangle, Building2 } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { reactive } from 'vue';
import StaticTable from '@core/Components/StaticTable.vue';
import { useConfirm } from '@core/Composables/useConfirm';
const { confirm } = useConfirm();
const props = defineProps<{ year:number; types:any[]; summary:any[]; balances:any }>();
const form = reactive({ settings: props.types.map(t=>({
  leave_type_id: t.id,
  is_carry_forward: !!(t.setting?.is_carry_forward ?? false),
  max_carry_days: t.setting?.max_carry_days ?? null,
  carry_expiry_months: t.setting?.carry_expiry_months ?? 3,
  is_encashable: !!(t.setting?.is_encashable ?? false),
  encash_max_days: t.setting?.encash_max_days ?? null,
  encash_salary_component: t.setting?.encash_salary_component ?? 'basic',
}))});
async function saveSettings(){ router.post(route('hrm.leave-year-end.settings'), {settings: form.settings}, {preserveScroll:true}); }
async function closeYear(){ if(await confirm({ description: `Close ${props.year}? This will: carry unused → next year opening, lapse remainder, encashable computed. Cannot undo without admin.`, danger: true })) router.post(route('hrm.leave-year-end.close'), {year: props.year}, {preserveScroll:true}); }
</script>
<template>
 <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Year-End (Carry/Encash)' }]">
  <Head title="Leave Year-End"/>
  <div class="overflow-hidden rounded-xl border bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 p-6 text-white">
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div><p class="flex items-center gap-2 text-xs uppercase tracking-widest text-indigo-200"><CalendarRange class="size-4"/> Leave Year-End — Carry Forward & Encashment</p>
        <h1 class="mt-1 text-2xl font-bold">Year {{year}} <span class="ml-2 rounded-full bg-white/15 px-2.5 py-0.5 text-xs">{{types.length}} leave types</span></h1>
        <p class="mt-1 text-sm text-indigo-100/80">Configure per-type policy, then close year → auto seeds next year's opening from carry.</p>
      </div>
      <div class="flex gap-2">
        <a :href="route('hrm.leave-year-end.index', {year: year-1})" class="rounded-md border border-white/20 px-3 py-1.5 text-sm hover:bg-white/10">{{year-1}} ←</a>
        <a :href="route('hrm.leave-year-end.index', {year: year+1})" class="rounded-md border border-white/20 px-3 py-1.5 text-sm hover:bg-white/10">→ {{year+1}}</a>
      </div>
    </div>
    <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
      <div v-for="s in summary" :key="s.leave_type" class="rounded-xl bg-white/10 p-3 backdrop-blur"><p class="text-xs text-indigo-200">{{s.leave_type}}</p><p class="text-sm font-bold">{{s.emp_count}} emps · Ent {{s.entitlement}} · Availed {{s.availed}} · Carry {{s.carry}} · Lapsed {{s.lapsed}}</p></div>
      <div v-if="summary.length===0" class="rounded-xl bg-white/10 p-3 text-sm">No snapshot yet — run Close Year to generate.</div>
    </div>
  </div>

  <section class="mt-6 rounded-xl border bg-card p-6">
    <h2 class="flex items-center gap-2 font-bold"><ShieldCheck class="size-5 text-primary"/> Carry / Encash Policy (per Leave Type)</h2>
    <p class="mt-1 text-sm text-muted-foreground">Example: Annual Leave → Carry max 5 days, expiry 3 months, encash max 10 days × basic/30. CL/SL usually no carry.</p>
    <div class="mt-4 grid gap-4 md:grid-cols-2">
      <div v-for="row in form.settings" :key="row.leave_type_id" class="rounded-lg border p-4">
        <p class="font-semibold flex items-center gap-2"><Building2 class="size-4"/>{{types.find(t=>t.id===row.leave_type_id)?.name ?? row.leave_type_id}}</p>
        <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" v-model="row.is_carry_forward" class="rounded"/> Carry forward unused</label>
        <div v-if="row.is_carry_forward" class="mt-2 grid grid-cols-2 gap-2">
          <label class="text-xs">Max carry days<input v-model="row.max_carry_days" type="number" step="0.5" class="mt-1 w-full rounded-md border px-2 py-1"/></label>
          <label class="text-xs">Expiry months<input v-model="row.carry_expiry_months" type="number" class="mt-1 w-full rounded-md border px-2 py-1"/></label>
        </div>
        <label class="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" v-model="row.is_encashable" class="rounded"/> Encashable at settlement</label>
        <div v-if="row.is_encashable" class="mt-2 grid grid-cols-2 gap-2">
          <label class="text-xs">Max encash days<input v-model="row.encash_max_days" type="number" step="0.5" class="mt-1 w-full rounded-md border px-2 py-1"/></label>
          <label class="text-xs">Rate<select v-model="row.encash_salary_component" class="mt-1 w-full rounded-md border px-2 py-1"><option value="basic">Basic/30</option><option value="gross">Gross/30</option></select></label>
        </div>
      </div>
    </div>
    <Button class="mt-4" @click="saveSettings"><Save class="size-4"/> Save Policies</Button>
    <p class="mt-2 text-xs text-muted-foreground">Saved to <code>leave_type_settings</code> — used by <code>LeaveBalanceService::balance()</code> and <code>LeaveYearEndService::encashableForSettlement()</code>.</p>
  </section>

  <section class="mt-6 rounded-xl border bg-card p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="font-bold flex items-center gap-2"><CalendarRange class="size-5 text-primary"/> Year {{year}} Balances</h2>
      <Button variant="outline" @click="closeYear" class="border-amber-300 text-amber-700 hover:bg-amber-50"><AlertTriangle class="size-4"/> Close Year {{year}}</Button>
    </div>
    <StaticTable class="mt-4">
        <thead class="bg-muted/60 text-xs uppercase"><tr><th class="px-3 py-2 text-left">Employee</th><th class="px-3 py-2">Leave Type</th><th class="px-3 py-2 text-right">Ent</th><th class="px-3 py-2 text-right">Availed</th><th class="px-3 py-2 text-right">Pending</th><th class="px-3 py-2 text-right">Carry</th><th class="px-3 py-2 text-right">Lapsed</th><th class="px-3 py-2 text-right">Avail</th><th class="px-3 py-2">Closed</th></tr></thead>
        <tbody class="divide-y">
          <tr v-for="b in balances.data" :key="b.id" class="hover:bg-muted/20"><td class="px-3 py-2 font-mono text-xs">{{b.employee_id}}</td><td class="px-3 py-2">{{b.leave_type?.name ?? b.leave_type_id}}</td><td class="px-3 py-2 text-right">{{b.entitlement}}</td><td class="px-3 py-2 text-right">{{b.availed}}</td><td class="px-3 py-2 text-right">{{b.pending}}</td><td class="px-3 py-2 text-right font-bold text-emerald-600">{{b.carry_forward}}</td><td class="px-3 py-2 text-right text-red-500">{{b.lapsed}}</td><td class="px-3 py-2 text-right font-bold">{{b.available}}</td><td class="px-3 py-2 text-center"><span class="rounded-full px-2 py-0.5 text-xs" :class="b.is_closed?'bg-slate-800 text-white':'bg-amber-100 text-amber-700'">{{b.is_closed?'closed':'open'}}</span></td></tr>
          <tr v-if="balances.data.length===0"><td colspan="9" class="px-3 py-6 text-center text-sm text-muted-foreground">No snapshot — click Close Year to generate for all active employees.</td></tr>
        </tbody>
      </StaticTable>
    <div class="mt-2 text-xs text-muted-foreground">Pagination: {{balances.current_page}}/{{balances.last_page}} — idempotent re-run updates open rows only.</div>
  </section>
 </AdminLayout>
</template>
