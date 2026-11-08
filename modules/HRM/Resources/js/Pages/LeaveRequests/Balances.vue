<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CalendarDays, Search, RotateCcw } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';
const props=defineProps<{employeeOptions:{value:string;label:string}[];employeeId:string|null;year:number;balances:any[]}>();
const employeeId=ref(props.employeeId), year=ref(props.year);
function search(){router.get(route('hrm.leaves.balances'),{employee_id:employeeId.value||undefined,year:year.value},{preserveState:true,preserveScroll:true});}
function clear(){employeeId.value=null;search();}
</script>
<template><AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Leave Balances' }]"><Head title="Leave Balances"/>
  <PageHeader title="Leave Balances" description="Entitlement, accrued balance, approved leave and pending reservations for each leave type." :icon="CalendarDays" />
  <form @submit.prevent="search" class="grid sm:grid-cols-[1fr_12rem_auto_auto] items-end gap-3 p-4 border bg-card rounded-xl"><div class="space-y-1"><Label>Employee *</Label><SearchableSelect v-model="employeeId" :options="employeeOptions" endpoint="/hrm/api/options/employees" placeholder="Search employee by name or ID"/></div><div class="space-y-1"><Label>Balance year</Label><Input v-model.number="year" type="number" min="2000" max="2100"/></div><Button type="submit"><Search class="size-4"/> View Balances</Button><Button type="button" variant="outline" @click="clear"><RotateCcw class="size-4"/> Clear</Button></form>
  <div v-if="!employeeId" class="rounded-xl border border-dashed p-10 text-center text-muted-foreground">Choose an employee to view their balance across all active leave types.</div>
  <StaticTable v-else><thead class="bg-muted/60 text-left"><tr><th class="p-3">Leave Type</th><th class="p-3 text-right">Entitlement</th><th class="p-3 text-right">Approved</th><th class="p-3 text-right">Pending</th><th class="p-3 text-right">Available</th><th class="p-3">Calculation</th><th class="p-3">Accrual / Proration</th></tr></thead><tbody><tr v-for="b in balances" :key="b.leave_type_id" class="border-t"><td class="p-3 font-medium">{{b.leave_type}}</td><td class="p-3 text-right font-mono">{{b.entitlement}}d</td><td class="p-3 text-right font-mono">{{b.approved}}d</td><td class="p-3 text-right font-mono">{{b.pending}}d</td><td class="p-3 text-right font-mono font-bold" :class="b.available<=0?'text-red-600':'text-emerald-700'">{{b.available}}d</td><td class="p-3">{{b.calculation_mode.replace('_',' ')}}</td><td class="p-3">{{b.accrual_mode}} · {{b.proration_mode}}</td></tr><tr v-if="!balances.length"><td colspan="7" class="p-8 text-center text-muted-foreground">No active leave types.</td></tr></tbody></StaticTable>
</AdminLayout></template>
