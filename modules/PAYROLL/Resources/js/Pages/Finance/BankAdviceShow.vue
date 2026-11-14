<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import type { BankAdvice } from '../../types';
import { CreditCard, Download, CheckCircle2, Clock3} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import StaticTable from '@core/Components/StaticTable.vue';
import { useConfirm } from '@core/Composables/useConfirm';
const { confirm } = useConfirm();
const p = defineProps<{ advice:BankAdvice }>();
async function money(v:any){ return Number(v||0).toLocaleString('en-BD',{minimumFractionDigits:2,maximumFractionDigits:2}); }
async function confirmAdvice(){ router.post(route('payroll.bank-advices.confirm', p.advice.id), {}, {preserveScroll:true}); }
async function markPaid(){ if(await confirm({ description: 'Mark as PAID and update slips?', danger: false })) router.post(route('payroll.bank-advices.paid', p.advice.id), {}, {preserveScroll:true}); }
</script>
<template>
 <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Bank Advices (BEFTN)', href: route('payroll.bank-advices.index') }, { title: advice.advice_no }]">
  <Head :title="advice.advice_no"/>
  <div class="flex flex-wrap items-center justify-between gap-3">
    <div><h1 class="flex items-center gap-2 text-xl font-bold"><CreditCard class="size-6 text-primary"/> {{advice.advice_no}}</h1><p class="text-sm text-muted-foreground">{{advice.pay_year}}-{{String(advice.pay_month).padStart(2,'0')}} · {{advice.bank_name}} · {{advice.branch_name}} · {{advice.account_no}}</p></div>
    <div class="flex gap-2">
      <a :href="route('payroll.bank-advices.download', advice.id)" class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm text-primary-foreground"><Download class="size-4"/> Download {{(advice.file_format||'xlsx').toUpperCase()}}</a>
      <Link :href="route('payroll.finance.sheets.show', advice.salary_sheet_id)"><Button variant="outline">Finance Sheet</Button></Link>
    </div>
  </div>

  <div class="mt-4 grid grid-cols-2 gap-3 md:grid-cols-4">
    <div class="rounded-xl border bg-card p-4"><p class="text-xs text-muted-foreground">Total Amount</p><p class="text-lg font-bold">{{money(advice.total_amount)}} BDT</p></div>
    <div class="rounded-xl border bg-card p-4"><p class="text-xs text-muted-foreground">Employees</p><p class="text-lg font-bold">{{advice.items.length}}</p></div>
    <div class="rounded-xl border bg-card p-4"><p class="text-xs text-muted-foreground">Status</p><p class="mt-1 inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm capitalize" :class="advice.status==='paid'?'bg-emerald-600 text-white': advice.status==='confirmed'?'bg-amber-500 text-white':'bg-slate-200'"><CheckCircle2 v-if="advice.status!=='draft'" class="size-4"/><Clock3 v-else class="size-4"/>{{advice.status}}</p></div>
    <div class="rounded-xl border bg-card p-4"><p class="text-xs text-muted-foreground">Period</p><p class="text-sm font-semibold">{{advice.pay_year}}-{{String(advice.pay_month).padStart(2,'0')}} · Sheet #{{advice.salary_sheet_id}}</p><p class="text-xs text-muted-foreground">{{advice.narration}}</p></div>
  </div>

  <div v-if="advice.status==='draft'" class="mt-4 flex gap-2">
    <Button @click="confirmAdvice">Confirm Advice (ready for bank)</Button><span class="self-center text-xs text-muted-foreground">Confirm → bank upload → Mark Paid after transfer.</span>
  </div>
  <div v-else-if="advice.status==='confirmed'" class="mt-4 flex gap-2">
    <Button @click="markPaid" class="bg-emerald-600 hover:bg-emerald-700">Confirm Bank Transfer — Mark Paid</Button>
  </div>

  <StaticTable
      class="mt-6"
      :empty="!advice?.items?.length"
      empty-title="No payees on this advice"
      empty-description="Employees paid through this bank advice appear here."
  >
      <thead class="bg-muted/60 text-xs"><tr><th class="px-3 py-2 text-left">#</th><th class="px-3 py-2 text-left">Employee</th><th class="px-3 py-2 text-left">Dept</th><th class="px-3 py-2 text-left">Bank A/C</th><th class="px-3 py-2 text-left">Bank / Branch</th><th class="px-3 py-2">Routing</th><th class="px-3 py-2 text-right">Amount</th><th class="px-3 py-2">Status</th></tr></thead>
      <tbody class="divide-y">
        <tr v-for="(it, i) in advice.items" :key="it.id" class="hover:bg-muted/20">
          <td class="px-3 py-2 text-xs">{{i+1}}</td>
          <td class="px-3 py-2"><span class="font-medium">{{it.employee_name}}</span><span class="ml-1 font-mono text-xs text-muted-foreground">{{it.employee_id}}</span></td>
          <td class="px-3 py-2 text-xs">{{it.department||'—'}}</td>
          <td class="px-3 py-2 font-mono text-xs">{{it.bank_account_no||'⚠️ N/A'}}</td>
          <td class="px-3 py-2 text-xs">{{it.bank_name||'—'}} <span class="text-muted-foreground">{{it.branch_name}}</span></td>
          <td class="px-3 py-2 text-center font-mono text-xs">{{it.routing_no||'—'}}</td>
          <td class="px-3 py-2 text-right font-bold">{{money(it.net_amount)}}</td>
          <td class="px-3 py-2 text-center"><span class="rounded-full bg-muted px-2 py-0.5 text-xs capitalize">{{it.status}}</span></td>
        </tr>
      </tbody>
      <tfoot class="bg-muted/30 font-bold"><tr><td colspan="6" class="px-3 py-2 text-right">Total — {{advice.items.length}} employees</td><td class="px-3 py-2 text-right">{{money(advice.total_amount)}}</td><td></td></tr></tfoot>
    </StaticTable>
  <p class="mt-3 text-xs text-muted-foreground">BEFTN ready: SL, Account No, Amount, Routing, Reference are included in the exported file. Missing A/C numbers are flagged — update employee bank in HRM.</p>
 </AdminLayout>
</template>
