<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Wallet, Landmark } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
interface Slip {id:number;employee_id:string;employee_name:string;department:string|null;gross_salary:number|string;total_deductions:number|string;net_salary:number|string;payment_status:string}
const p=defineProps<{sheet:any;slips:Paginator<Slip>;periodExclusions:Array<{employee_id:string;employee_name:string|null;reason:string;created_by_name:string|null;created_at:string|null}>;canRelease:boolean;canRefreshCutlist:boolean;releaseAudit:{actor_id:number|null;occurred_at:string}|null}>();
function generateCutlist(){router.post(route('payroll.cutlists.generate'),{pay_year:p.sheet.pay_year,pay_month:p.sheet.pay_month},{preserveScroll:true});}
function releaseSheet(){if(window.confirm('Release this completed salary sheet? Payment actions will be enabled for its slips.'))router.post(route('payroll.sheets.release',p.sheet.id),{},{preserveScroll:true});}
function money(value:unknown){return Number(value||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2});}
const slipColumns:DataTableColumn[]=[{key:'employee_name',label:'Employee',sortable:true},{key:'department',label:'Department'},{key:'gross_salary',label:'Gross',align:'right',sortable:true},{key:'total_deductions',label:'Deductions',align:'right',sortable:true},{key:'net_salary',label:'Net pay',align:'right',sortable:true},{key:'payment_status',label:'Payment',align:'center'},{key:'actions',label:'',align:'right',exportable:false}];
</script>
<template>
 <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Salary Sheets', href: route('payroll.sheets.index') }, { title: `${sheet.pay_year}-${String(sheet.pay_month).padStart(2,'0')}` }]">
  <Head :title="`Salary sheet ${sheet.pay_year}-${sheet.pay_month}`"/>
  <div class="flex flex-wrap items-center justify-between gap-3"><div><h1 class="text-xl font-bold">Salary sheet · {{sheet.pay_year}}-{{String(sheet.pay_month).padStart(2,'0')}}</h1><p class="text-sm text-muted-foreground">{{sheet.processed_count}} / {{sheet.employee_count}} slips · {{sheet.status}}</p><p v-if="sheet.status==='ready'" class="text-xs text-amber-700">Review the cutlist and slips before release; payment actions are disabled until release.</p><p class="mt-1 text-sm">Punishment mode: <strong>{{sheet.include_punishments?'With punishment':'Without punishment'}}</strong></p></div><div class="flex flex-wrap gap-2"><Button v-if="canRelease && sheet.status==='ready'" @click="releaseSheet">Release sheet</Button><span v-else-if="sheet.status==='released'" class="self-center rounded-full bg-emerald-500/10 px-3 py-1 text-sm text-emerald-700">Released {{releaseAudit?.occurred_at ? new Date(releaseAudit.occurred_at).toLocaleDateString() : ''}}</span><Button v-if="canRefreshCutlist" variant="outline" @click="generateCutlist"><Wallet class="size-4"/> Refresh attendance cutlist</Button><Link v-if="sheet.status==='failed'" :href="route('payroll.sheets.create',{pay_year:sheet.pay_year,pay_month:sheet.pay_month})"><Button variant="outline">Retry / adjust payroll</Button></Link><Link :href="route('payroll.sheets.index')"><Button variant="outline">All sheets</Button></Link><Link v-if="sheet.status==='released' || sheet.status==='ready'" :href="route('payroll.finance.sheets.show', sheet.id)"><Button variant="default" class="bg-indigo-600 hover:bg-indigo-700"><Landmark class="size-4"/> Finance Bridge</Button></Link></div></div>
  <div v-if="sheet.failure_message" class="mt-4 rounded-xl border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive">{{sheet.failure_message}}</div>
  <section v-if="periodExclusions.length" class="mt-4 rounded-xl border bg-amber-500/5 p-4">
   <h2 class="font-semibold">Documented exclusions for this pay period</h2>
   <div class="mt-2 divide-y">
    <article v-for="item in periodExclusions" :key="item.employee_id" class="py-2 text-sm">
     <strong>{{item.employee_name||item.employee_id}}</strong><span class="ml-2 font-mono text-xs text-muted-foreground">{{item.employee_id}}</span>
     <p class="mt-1 text-xs text-muted-foreground">Reason: {{item.reason}} · Recorded by {{item.created_by_name||'System'}}{{item.created_at ? ` · ${new Date(item.created_at).toLocaleString()}` : ''}}</p>
    </article>
   </div>
  </section>
  <div v-if="sheet.cutlist" class="mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-4"><div><strong>Attendance cutlist · {{sheet.cutlist.status}}</strong><p class="text-xs text-muted-foreground">Punishment eligibility, lateness/early-out, unpaid leave, missing punches and approved OT/extra-duty detail.</p></div><Link :href="route('payroll.cutlists.show',sheet.cutlist.id)" class="text-sm text-primary underline">Open full cutlist</Link></div>
  <div v-else class="mt-4 rounded-xl border border-dashed p-4 text-sm text-muted-foreground">No attendance cutlist generated yet. Refresh it to see each employee’s deductions and approved extra pay.</div>
  <DataTable class="mt-4" :route-name="'payroll.sheets.show'" :route-params="{salarySheet:sheet.id}" title="Employee pay slips" :columns="slipColumns" :rows="slips.data as unknown as Record<string,unknown>[]" :meta="slips.meta" :default-sort="{key:'employee_name',direction:'asc'}">
   <template #cell-employee_name="{row}"><div><strong>{{row.employee_name}}</strong><span class="block text-xs text-muted-foreground">{{row.employee_id}}</span></div></template>
   <template #cell-gross_salary="{row}">{{money(row.gross_salary)}}</template><template #cell-total_deductions="{row}">{{money(row.total_deductions)}}</template><template #cell-net_salary="{row}"><strong>{{money(row.net_salary)}}</strong></template>
   <template #cell-payment_status="{row}"><span class="rounded-full bg-muted px-2 py-1 text-xs capitalize">{{row.payment_status}}</span></template>
   <template #cell-actions="{row}"><Link :href="route('payroll.slips.show',row.id)" class="text-primary hover:underline">Pay slip →</Link></template>
  </DataTable>

 </AdminLayout>
</template>