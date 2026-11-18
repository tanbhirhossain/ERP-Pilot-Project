<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2, Eye, KeyRound, UserX } from 'lucide-vue-next';
import { computed, ref } from 'vue';

import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import ConfirmDialog from '@core/Components/ConfirmDialog.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import type { Paginator } from '@core/types';
import { formatDate, formatNumber } from '@core/Utils/format';
interface Row { id:number; employee_id:string; biometric_id?:string|null; full_name:string; email?:string; phone?:string; department?:{name:string}; designation?:{name:string}; employment_type:string; status:string; joining_date?:string; user_id?:number|null; user_name?:string|null; payment_method?:string|null; bank_name?:string|null; bank_account_no?:string|null }
const props = defineProps<{ employees: Paginator<Row>; selfService?: { active:number; linked:number; accounts:number } }>();

/**
 * Self-service coverage. An employee with no `user_id` cannot sign in, so My HR, my payslips and my
 * leave show them nothing but a card asking HR to link the account. That is invisible from the
 * directory until one person complains, so the number is stated here instead.
 */
const unlinked = computed(() => Math.max(0, (props.selfService?.active ?? 0) - (props.selfService?.linked ?? 0)));
const coveragePct = computed(() => {
  const active = props.selfService?.active ?? 0;
  return active === 0 ? 0 : Math.round(((props.selfService?.linked ?? 0) / active) * 100);
});
const columns: DataTableColumn[] = [
  {key:'employee_id',label:'ID',sortable:true,width:'9rem'},
  {key:'biometric_id',label:'Biometric ID',sortable:true,width:'8rem'},
  {key:'full_name',label:'Employee',sortable:true},
  {key:'department',label:'Department'},
  {key:'designation',label:'Designation'},
  {key:'payment_method',label:'Paid by',align:'center',width:'8rem'},
  {key:'bank_account_no',label:'Bank account',width:'13rem'},
  {key:'user_id',label:'Sign-in',align:'center',width:'9rem'},
  {key:'status',label:'Status',align:'center'},
  {key:'joining_date',label:'Joining',sortable:true,width:'10rem'},
  {key:'actions',label:'',align:'right',exportable:false,width:'6rem'},
];
const confirmOpen=ref(false), pending=ref<Row|null>(null), processing=ref(false);
function askDelete(r:Record<string,unknown>){ pending.value=r as unknown as Row; confirmOpen.value=true; }
function confirmDelete(){ if(!pending.value) return; processing.value=true; router.delete(route('hrm.employees.destroy',pending.value.id),{preserveScroll:true,onFinish(){processing.value=false;confirmOpen.value=false;pending.value=null;}}); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Employees'}]">
    <Head title="Employees"/>
    <PageHeader title="Employees" description="Central HR directory — profiles, salaries, policies linked." :icon="Pencil" :actions="[{ label:'Add Employee', route:'hrm.employees.create', icon: Plus }]">
    <div
      v-if="selfService && unlinked > 0"
      class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-warning/30 bg-warning/[0.06] px-4 py-2.5 text-[13px]"
    >
      <UserX class="size-4 shrink-0 text-warning" />
      <span>
        <strong>{{ formatNumber(unlinked) }}</strong> of {{ formatNumber(selfService.active) }} active
        employees cannot sign in &mdash; {{ coveragePct }}% have an account. Their My HR, payslip and
        leave pages show &ldquo;ask HR to link your account&rdquo; and nothing else.
      </span>
      <span class="text-muted-foreground">
        Link one from the employee&rsquo;s Edit page, field &ldquo;Linked user account&rdquo;.
      </span>
    </div>
    <DataTable route-name="hrm.employees.index" title="Employees" :columns="columns" :rows="employees.data" :meta="employees.meta" search-placeholder="Search by ID, name, email..."
      :filter-defs="[
        { key: 'payment_method', label: 'Paid by', kind: 'select', options: [
          { value: 'bank', label: 'Bank transfer' },
          { value: 'cash', label: 'Cash' },
          { value: 'none', label: 'No salary record' },
        ] },
      ]">
      <template #cell-employee_id="{row}"><span class="font-mono text-sm font-semibold bg-primary/10 text-primary px-1.5 py-0.5 rounded">{{ row.employee_id }}</span></template>
      <template #cell-biometric_id="{row}"><span class="font-mono text-xs px-1.5 py-0.5 rounded" :class="row.biometric_id ? 'bg-emerald-500/10 text-emerald-600 font-semibold' : 'bg-amber-500/10 text-amber-600'">{{ row.biometric_id || '—' }}</span></template>
      <template #cell-full_name="{row}"><div><p class="font-semibold">{{ row.full_name }}</p><p class="text-xs text-muted-foreground">{{ row.email || row.phone || '' }}</p></div></template>
      <template #cell-department="{row}"><span class="text-sm">{{ row.department?.name || '—' }}</span></template>
      <!--
        How each person is paid, straight from the salary record the payroll pays from. Before this
        column existed the only way to answer "who is still not on the bank list?" was to open
        employees one at a time; 348 of the 998 are paid through DBBL and the rest in cash.
      -->
      <template #cell-payment_method="{row}">
        <StatusBadge v-if="row.payment_method" :label="row.payment_method === 'bank' ? 'Bank' : 'Cash'" :tone="row.payment_method === 'bank' ? 'success' : 'neutral'" />
        <span v-else class="text-xs text-muted-foreground" title="No salary record, so the payroll has no way to pay this person">No salary</span>
      </template>
      <template #cell-bank_account_no="{row}">
        <span v-if="row.bank_account_no" class="font-mono text-xs">
          {{ row.bank_account_no }}
          <span v-if="row.bank_name" class="ml-1 text-muted-foreground">{{ row.bank_name }}</span>
        </span>
        <span v-else class="text-xs text-muted-foreground">—</span>
      </template>
      <template #cell-designation="{row}"><span class="text-sm">{{ row.designation?.name || '—' }}</span></template>
      <!--
        "—" here is not decoration: it means this person cannot sign in at all. It reads as a gap
        rather than as a status, which is what it is.
      -->
      <template #cell-user_id="{row}">
        <span v-if="row.user_id" class="inline-flex items-center gap-1 text-xs" :title="row.user_name ?? ''">
          <KeyRound class="size-3 text-success" />
          <span class="truncate max-w-20">{{ row.user_name }}</span>
        </span>
        <span v-else class="text-xs text-muted-foreground" title="No sign-in account is linked">
          —
        </span>
      </template>
      <template #cell-status="{row}"><span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="row.status==='Active'?'bg-emerald-500/10 text-emerald-600': row.status==='Inactive'?'bg-zinc-500/10 text-zinc-600':'bg-amber-500/10 text-amber-600'">{{ row.status }}</span></template>
      <template #cell-joining_date="{row}"><span class="text-sm text-muted-foreground">{{ row.joining_date?formatDate(row.joining_date):'—' }}</span></template>
      <template #cell-actions="{row}"><div class="flex justify-end gap-1">
        <Link :href="route('hrm.employees.show',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent" title="View"><Eye class="size-4"/></Link>
        <Link :href="route('hrm.employees.edit',row.id)" class="size-8 grid place-items-center rounded-md hover:bg-accent"><Pencil class="size-4"/></Link>
        <button @click="askDelete(row)" class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"><Trash2 class="size-4"/></button>
      </div></template>
    </DataTable>
    <ConfirmDialog v-model:open="confirmOpen" :processing="processing" title="Delete employee?" :description="`Delete ${pending?.full_name} (${pending?.employee_id})?`" confirm-label="Delete" @confirm="confirmDelete"/>
      </PageHeader>
  </AdminLayout>
</template>
