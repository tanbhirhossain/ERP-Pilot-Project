<script setup lang="ts">
import { Head, router} from '@inertiajs/vue3';
import { ref, reactive } from 'vue';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { BadgeCent } from 'lucide-vue-next';
import Pagination from '../../../../../CORE/Resources/js/Components/Pagination.vue';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';
defineProps<{ allowances:any; shifts:any }>();
const showCreate=ref(false);
const form=reactive({shift_id:null as any, name:'Night Shift Allowance', amount:500 as any, amount_type:'Fixed', applicable_day:'All', effective_from:'', effective_to:''});
function create(){ router.post(route('hrm.shift-allowances.store'), form as any, {onSuccess:()=> showCreate.value=false}); }
function del(id:number){ if(confirm('Delete allowance?')) router.delete(route('hrm.shift-allowances.destroy', id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Attendance'},{title:'Shift Allowances'}]">
    <Head title="Shift Allowances — HRM"/>
    <div class="max-w-6xl mx-auto space-y-6">
      <PageHeader title="Shift Allowances" description="Premium for night/weekend/holiday duties. Fixed BDT or % of basic. Flows to PAYROLL cutlist as benefit when employee works that shift." :icon="BadgeCent">
          <template #actions>
              <Button @click="showCreate=!showCreate" class="bg-primary">{{ showCreate?'Close':'+ Add Allowance' }}</Button>
          </template>
      </PageHeader>
      <div v-if="showCreate" class="bg-card border rounded-xl p-5 space-y-4">
        <div class="grid md:grid-cols-3 gap-4">
          <div class="space-y-1"><Label>Shift *</Label><select v-model="form.shift_id" class="h-9 w-full rounded-md border bg-background px-3 text-sm"><option :value="null">Select shift</option><option v-for="s in shifts" :key="s.id" :value="s.id">{{ s.name }} ({{ s.start_time }}–{{ s.end_time }})</option></select></div>
          <div class="space-y-1"><Label>Name *</Label><Input v-model="form.name" placeholder="Night Premium"/></div>
          <div class="space-y-1"><Label>Amount *</Label><Input type="number" v-model.number="form.amount" step="0.01"/></div>
          <div class="space-y-1"><Label>Type *</Label><select v-model="form.amount_type" class="h-9 w-full rounded-md border bg-background px-3 text-sm"><option>Fixed</option><option>Percentage of Basic</option></select></div>
          <div class="space-y-1"><Label>Applies To *</Label><select v-model="form.applicable_day" class="h-9 w-full rounded-md border bg-background px-3 text-sm"><option>All</option><option>Weekend</option><option>Holiday</option><option>Night</option></select></div>
          <div class="space-y-1"><Label>Effective From</Label><Input type="date" v-model="form.effective_from"/></div>
        </div>
        <div class="flex justify-end"><Button @click="create" class="bg-emerald-600 hover:bg-emerald-700">Create</Button></div>
      </div>
      <div class="bg-card border rounded-xl overflow-hidden">
        <div class="p-4 border-b"><h3 class="font-semibold text-sm">Allowances — {{ allowances.total }}</h3></div>
        <StaticTable flush>
            <thead class="bg-muted/50 text-xs text-muted-foreground"><tr><th class="text-left p-3">Shift</th><th class="text-left p-3">Allowance</th><th class="p-3">Amount</th><th class="p-3">Applies</th><th class="p-3 text-right">Action</th></tr></thead>
            <tbody>
              <tr v-for="a in allowances.data" :key="a.id" class="border-t hover:bg-muted/30">
                <td class="p-3"><div class="font-medium text-sm">{{ a.shift?.name }}</div><div class="text-xs text-muted-foreground">{{ a.shift?.start_time }}–{{ a.shift?.end_time }}</div></td>
                <td class="p-3 font-medium">{{ a.name }}</td>
                <td class="p-3 text-center"><span class="font-bold text-emerald-600">{{ a.amount }} {{ a.amount_type==='Fixed'?'BDT':'% basic' }}</span></td>
                <td class="p-3 text-center"><span class="px-2 py-1 rounded bg-muted text-xs">{{ a.applicable_day }}</span></td>
                <td class="p-3 text-right"><Button size="sm" variant="ghost" class="h-7 text-red-600 text-xs" @click="del(a.id)">Delete</Button></td>
              </tr>
              <tr v-if="!allowances.data.length"><td colspan="5" class="p-8 text-center text-sm text-muted-foreground">No allowances — add Night/Weekend premium for shifts that deserve it.</td></tr>
            </tbody>
          </StaticTable>
        <Pagination :links="allowances.links" :total="allowances.total" />
      </div>
      <p class="text-xs text-muted-foreground">Payroll integration: when generating cutlist, <code>ShiftAllowanceService::forPayroll(employee, date)</code> returns applicable allowances for that duty's shift; engine adds them as benefits before tax/PF.</p>
    </div>
  </AdminLayout>
</template>
