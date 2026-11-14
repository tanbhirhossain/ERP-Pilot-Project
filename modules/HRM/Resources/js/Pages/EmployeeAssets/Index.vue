<script setup lang="ts">
import { Head, router} from '@inertiajs/vue3';
import { ref, reactive } from 'vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import { Archive, Package, AlertTriangle, CheckCircle, ArrowRightLeft, Plus, Undo2 } from 'lucide-vue-next';
import Pagination from '@core/Components/Pagination.vue';
import StaticTable from '@core/Components/StaticTable.vue';

defineProps<{ assets: any; assignments: any; stats: any }>();

const showCreate = ref(false);
const showIssue = ref(false);

const createForm = reactive({ name: '', category: 'ID Card', serial_no: '', unit_cost: 0 as any, description: '' });
const issueForm = reactive({ employee_id: null as number | string | null, asset_id: null as number | string | null, issued_date: new Date().toISOString().slice(0,10), expected_return_date: '', issue_condition: 'Good', remarks: '' });
const returnState = reactive({ id: null as number | string | null, returned_date: new Date().toISOString().slice(0,10), return_condition: 'Good', recovery_amount: 0 as any, remarks: '' });

const categories = ['ID Card','Laptop','Mobile','SIM','Uniform','Locker','Tool','Vehicle','Access Card','Other'];

function createAsset() {
  router.post(route('hrm.assets.store'), createForm, { onSuccess: () => { showCreate.value=false; Object.assign(createForm,{name:'',category:'ID Card',serial_no:'',unit_cost:0,description:''}); } });
}
function issue() {
  router.post(route('hrm.assets.issue'), issueForm, { onSuccess: () => { showIssue.value=false; } });
}
function openReturn(a: any) { returnState.id = a.id; }
function doReturn() {
  router.post(route('hrm.assets.return', returnState.id), { returned_date: returnState.returned_date, return_condition: returnState.return_condition, recovery_amount: returnState.recovery_amount, remarks: returnState.remarks });
}

const statusColor = (s:string)=>({ 'In Stock':'bg-emerald-500 text-white','Assigned':'bg-blue-500 text-white','Returned':'bg-slate-500 text-white','Lost':'bg-red-500 text-white','Damaged':'bg-amber-500 text-white','Disposed':'bg-zinc-600 text-white'}[s]||'bg-muted');
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'Asset Inventory' }]">
    <Head title="Asset Inventory — HRM" />
    <div class="max-w-7xl mx-auto space-y-6">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
          <h1 class="text-2xl font-bold tracking-tight flex items-center gap-2"><Archive class="size-6 text-primary" /> Asset Inventory</h1>
          <p class="text-sm text-muted-foreground">Track every company asset (ID card, laptop, uniform…) from procurement → issue → return/loss. Unreturned value feeds into <b>PAYROLL settlement</b> recovery automatically.</p>
        </div>
        <div class="flex gap-2">
          <Button variant="outline" @click="showCreate=!showCreate"><Plus class="size-4 mr-1" /> New Asset</Button>
          <Button @click="showIssue=!showIssue" class="bg-primary"><ArrowRightLeft class="size-4 mr-2" /> Issue Asset</Button>
        </div>
      </div>

      <!-- Stats -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3"><div class="size-10 rounded-lg bg-emerald-500/10 flex items-center justify-center"><CheckCircle class="size-5 text-emerald-600" /></div><div><p class="text-xs text-muted-foreground">In Stock</p><p class="text-xl font-bold">{{ stats.in_stock }}</p></div></div>
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3"><div class="size-10 rounded-lg bg-blue-500/10 flex items-center justify-center"><Package class="size-5 text-blue-600" /></div><div><p class="text-xs text-muted-foreground">Assigned</p><p class="text-xl font-bold">{{ stats.assigned }}</p></div></div>
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3"><div class="size-10 rounded-lg bg-amber-500/10 flex items-center justify-center"><AlertTriangle class="size-5 text-amber-600" /></div><div><p class="text-xs text-muted-foreground">Overdue (expected)</p><p class="text-xl font-bold text-amber-600">{{ stats.overdue }}</p></div></div>
        <div class="bg-card border rounded-xl p-4 flex items-center gap-3"><div class="size-10 rounded-lg bg-primary/10 flex items-center justify-center"><Archive class="size-5 text-primary" /></div><div><p class="text-xs text-muted-foreground">Total Catalog</p><p class="text-xl font-bold">{{ assets.total }}</p></div></div>
      </div>

      <!-- Create Asset -->
      <div v-if="showCreate" class="bg-card border rounded-xl p-5 space-y-4">
        <h3 class="font-semibold flex items-center gap-2"><Plus class="size-4" /> New Asset — catalog entry</h3>
        <div class="grid md:grid-cols-3 gap-4">
          <div class="space-y-1"><Label>Name *</Label><Input v-model="createForm.name" placeholder="Dell Latitude 5440" /></div>
          <div class="space-y-1"><Label>Category *</Label><select v-model="createForm.category" class="h-9 w-full rounded-md border bg-background px-3 text-sm"><option v-for="c in categories" :key="c" :value="c">{{ c }}</option></select></div>
          <div class="space-y-1"><Label>Serial No</Label><Input v-model="createForm.serial_no" placeholder="SN-..." /></div>
          <div class="space-y-1"><Label>Unit Cost (BDT)</Label><Input type="number" v-model.number="createForm.unit_cost" min="0" step="0.01" /></div>
          <div class="md:col-span-2 space-y-1"><Label>Description</Label><Input v-model="createForm.description" placeholder="8GB RAM, 256GB SSD..." /></div>
        </div>
        <div class="flex justify-end gap-2"><Button variant="outline" @click="showCreate=false">Cancel</Button><Button @click="createAsset" class="bg-emerald-600 hover:bg-emerald-700">Create</Button></div>
      </div>

      <!-- Issue Asset -->
      <div v-if="showIssue" class="bg-card border rounded-xl p-5 space-y-4">
        <h3 class="font-semibold flex items-center gap-2"><ArrowRightLeft class="size-4" /> Issue Asset to Employee</h3>
        <div class="grid md:grid-cols-3 gap-4">
          <div class="space-y-1"><Label>Employee *</Label><SearchableSelect v-model="issueForm.employee_id" endpoint="/hrm/api/options/employees" placeholder="Employee" /></div>
          <div class="space-y-1"><Label>Asset * (In Stock only)</Label><SearchableSelect v-model="issueForm.asset_id" endpoint="/hrm/api/options/assets" placeholder="Asset code — e.g., AST-00001" /></div>
          <div class="space-y-1"><Label>Issued Date *</Label><Input type="date" v-model="issueForm.issued_date" /></div>
          <div class="space-y-1"><Label>Expected Return</Label><Input type="date" v-model="issueForm.expected_return_date" /></div>
          <div class="space-y-1"><Label>Condition *</Label><select v-model="issueForm.issue_condition" class="h-9 w-full rounded-md border bg-background px-3 text-sm"><option>New</option><option>Good</option><option>Used</option></select></div>
          <div class="space-y-1"><Label>Remarks</Label><Input v-model="issueForm.remarks" placeholder="Accessories included..." /></div>
        </div>
        <div class="flex justify-end gap-2"><Button variant="outline" @click="showIssue=false">Cancel</Button><Button @click="issue" class="bg-primary">Issue</Button></div>
      </div>

      <!-- Assets Catalog -->
      <div class="bg-card border rounded-xl overflow-hidden">
        <div class="p-4 border-b flex items-center justify-between"><h3 class="font-semibold text-sm">Catalog — {{ assets.total }} assets</h3><span class="text-xs text-muted-foreground">Code is immutable (AST-xxxxx)</span></div>
        <StaticTable flush>
            <thead class="bg-muted/50 text-xs text-muted-foreground"><tr><th class="text-left p-3">Code</th><th class="text-left p-3">Name</th><th class="p-3">Category</th><th class="p-3">Serial</th><th class="text-right p-3">Cost BDT</th><th class="p-3">Status</th></tr></thead>
            <tbody>
              <tr v-for="a in assets.data" :key="a.id" class="border-t hover:bg-muted/30"><td class="p-3 font-mono font-bold">{{ a.asset_code }}</td><td class="p-3 font-medium">{{ a.name }}</td><td class="p-3 text-center"><span class="px-2 py-1 rounded bg-muted text-xs font-medium">{{ a.category }}</span></td><td class="p-3 font-mono text-xs">{{ a.serial_no || '—' }}</td><td class="p-3 text-right">{{ Number(a.unit_cost).toFixed(2) }}</td><td class="p-3 text-center"><span :class="['px-2 py-1 rounded-full text-xs font-medium', statusColor(a.status)]">{{ a.status }}</span></td></tr>
              <tr v-if="!assets.data.length"><td colspan="6" class="p-8 text-center text-sm text-muted-foreground">No assets — create Laptop, ID Card, Uniform… to start.</td></tr>
            </tbody>
          </StaticTable>
        <Pagination :links="assets.links" :total="assets.total" />
      </div>

      <!-- Open Assignments -->
      <div class="bg-card border rounded-xl overflow-hidden">
        <div class="p-4 border-b"><h3 class="font-semibold text-sm">Open Assignments — not yet returned (settlement-relevant)</h3><p class="text-xs text-muted-foreground">Unreturned asset value is summed per employee for PAYROLL settlement recovery.</p></div>
        <StaticTable flush>
            <thead class="bg-muted/50 text-xs text-muted-foreground"><tr><th class="text-left p-3">Employee</th><th class="text-left p-3">Asset</th><th class="p-3">Issued</th><th class="p-3">Expected</th><th class="p-3">Condition</th><th class="p-3 text-right">Recovery</th><th class="p-3 text-right">Action</th></tr></thead>
            <tbody>
              <tr v-for="ea in assignments.data" :key="ea.id" class="border-t hover:bg-muted/30">
                <td class="p-3"><div class="font-medium">{{ ea.employee?.full_name || ea.employee_id }}</div><div class="text-xs text-muted-foreground font-mono">{{ ea.employee_id }}</div></td>
                <td class="p-3"><div class="font-medium">{{ ea.asset?.name }}</div><div class="text-xs text-muted-foreground">{{ ea.asset?.asset_code }} • {{ ea.asset?.category }}</div></td>
                <td class="p-3 text-center text-xs">{{ ea.issued_date }}</td>
                <td class="p-3 text-center text-xs" :class="ea.expected_return_date && new Date(ea.expected_return_date) < new Date() ? 'text-red-600 font-bold' : ''">{{ ea.expected_return_date || '—' }}</td>
                <td class="p-3 text-center"><span class="px-2 py-1 rounded border text-xs font-medium">{{ ea.issue_condition }}</span></td>
                <td class="p-3 text-right">{{ Number(ea.recovery_amount||0).toFixed(2) }}</td>
                <td class="p-3">
                  <div v-if="returnState.id===ea.id" class="flex flex-col gap-1 items-end">
                    <div class="flex gap-1"><Input type="date" v-model="returnState.returned_date" class="h-7 w-36" /><select v-model="returnState.return_condition" class="h-7 rounded border bg-background px-2 text-xs"><option>Good</option><option>Damaged</option><option>Lost</option></select></div>
                    <div class="flex gap-1"><Input type="number" v-model.number="returnState.recovery_amount" placeholder="Recovery BDT" class="h-7 w-28" /><Input v-model="returnState.remarks" placeholder="Remarks" class="h-7 w-36" /><Button size="sm" class="h-7 bg-emerald-600" @click="doReturn">Confirm</Button></div>
                  </div>
                  <Button v-else size="sm" variant="outline" class="h-7" @click="openReturn(ea)"><Undo2 class="size-3 mr-1" /> Return</Button>
                </td>
              </tr>
              <tr v-if="!assignments.data.length"><td colspan="7" class="p-8 text-center text-sm text-muted-foreground">No open assignments — all assets accounted for ✅</td></tr>
            </tbody>
          </StaticTable>
      </div>

      <p class="text-xs text-muted-foreground">Production: every issue/return writes `hrm_employee_asset` + flips `hrm_assets.status` + activity log; overdue = <code>expected_return_date &lt; today</code> + still open; settlement service will call <code>EmployeeAssetService::unreturnedValue(employeeId)</code> + <code>recoveryTotal</code> for auto-deduction.</p>
    </div>
  </AdminLayout>
</template>
