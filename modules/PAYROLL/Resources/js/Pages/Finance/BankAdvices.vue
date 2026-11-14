<script setup lang="ts">
import { Head, Link} from '@inertiajs/vue3';
import { CreditCard, Download, Building2 } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import Pagination from '@core/Components/Pagination.vue';
import StaticTable from '@core/Components/StaticTable.vue';
defineProps<{ advices:any }>();
function money(v:any){ return Number(v||0).toLocaleString('en-BD',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function statusCls(s:string){ return s==='paid'?'bg-emerald-600 text-white': s==='confirmed'?'bg-amber-500 text-white': s==='draft'?'bg-slate-200 text-slate-700':'bg-red-100 text-red-700'; }
</script>
<template>
 <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Bank Advices' }]">
  <Head title="Bank Advices — Payroll"/>
  <PageHeader title="Bank Advices (BEFTN)" description="All salary disbursement advices — download Excel/CSV for bank upload." :icon="CreditCard">
      <template #actions>
          <Link :href="route('payroll.sheets.index')"><Button variant="outline">Salary Sheets</Button></Link>
      </template>
  </PageHeader>

  <div v-if="advices.data.length===0" class="mt-6 rounded-2xl border border-dashed p-10 text-center">
    <p class="text-sm text-muted-foreground">No bank advices yet. Generate from a released salary sheet → Finance Bridge.</p>
  </div>
  <div v-else class="mt-6 overflow-hidden rounded-2xl border">
    <StaticTable
      :empty="!advices?.data?.length"
      empty-title="No bank advices yet"
      empty-description="Bank advices generated from salary sheets appear here."
    >
      <thead class="bg-muted/60 text-xs uppercase tracking-wide"><tr><th class="px-4 py-3 text-left">Advice No</th><th class="px-4 py-3">Period</th><th class="px-4 py-3 text-left">Disbursement Bank</th><th class="px-4 py-3 text-right">Amount</th><th class="px-4 py-3">Employees</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Actions</th></tr></thead>
      <tbody class="divide-y">
        <tr v-for="a in advices.data" :key="a.id" class="hover:bg-muted/30">
          <td class="px-4 py-3 font-mono text-xs font-semibold">{{a.advice_no}}</td>
          <td class="px-4 py-3 text-center">{{a.pay_year}}-{{String(a.pay_month).padStart(2,'0')}}</td>
          <td class="px-4 py-3"><div class="flex items-center gap-2"><Building2 class="size-4 text-muted-foreground"/>{{a.bank_name||'—'}}<span class="text-xs text-muted-foreground">{{a.branch_name}}</span></div></td>
          <td class="px-4 py-3 text-right font-bold">{{money(a.total_amount)}}</td>
          <td class="px-4 py-3 text-center">{{a.items_count}}</td>
          <td class="px-4 py-3 text-center"><span class="rounded-full px-2.5 py-1 text-xs capitalize" :class="statusCls(a.status)">{{a.status}}</span></td>
          <td class="px-4 py-3 text-right"><div class="flex justify-end gap-1"><Link :href="route('payroll.bank-advices.show', a.id)" class="text-primary hover:underline text-xs">View</Link><span class="text-muted-foreground">·</span><a :href="route('payroll.bank-advices.download', a.id)" class="inline-flex items-center gap-1 text-xs text-primary hover:underline"><Download class="size-3"/> Download</a></div></td>
        </tr>
      </tbody>
    </StaticTable>
    <Pagination :links="advices.links" :total="advices.total" :current="advices.data.length" />
  </div>
 </AdminLayout>
</template>
