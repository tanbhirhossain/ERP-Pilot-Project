<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive, computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import { onMounted } from 'vue';
const props = defineProps<{ invoice:any }>();
const form = reactive({ invoice_id: null as any, return_date: new Date().toISOString().slice(0,10), reason:'', notes:'', items: [] as any[] });
function loadInvoice(inv:any){ if(!inv) return; form.invoice_id = inv.id; form.items = inv.items.map((it:any)=>({ invoice_item_id: it.id, product_id: it.product_id, product_name: it.product_name, qty: it.qty, unit_price: it.unit_price, maxQty: it.qty, condition:'resalable' })); }
onMounted(()=>{ if(props.invoice) loadInvoice(props.invoice); });
function removeLine(i:number){ form.items.splice(i,1); }
function submit(){ router.post(route('sales.returns.store'), form as any); }
const total = computed(()=> form.items.reduce((s:any,it:any)=> s + (Number(it.qty)||0)*(Number(it.unit_price)||0),0));
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'Sales Returns', href: route('sales.returns.index') }, { title: 'Create' }]">
    <Head title="New Sales Return" />
    <div class="space-y-4">
      <h1 class="text-xl font-bold">New Sales Return (Credit Note)</h1>
      <div class="bg-card border rounded-xl p-4 space-y-3">
        <div class="grid md:grid-cols-3 gap-3">
          <div class="space-y-1"><Label>Invoice *</Label><SearchableSelect v-model="form.invoice_id" endpoint="/sales/api/options/invoices" placeholder="Select posted invoice" @update:modelValue="val=>{ /* fetch via props not api, user picks then manually adds items */ }" /></div>
          <div class="space-y-1"><Label>Return Date</Label><Input v-model="form.return_date" type="date" /></div>
          <div class="space-y-1"><Label>Reason</Label><Input v-model="form.reason" placeholder="Damaged / Wrong item" /></div>
        </div>
        <div v-if="props.invoice" class="bg-sky-50 border border-sky-200 rounded-lg p-3 text-sm">Loaded invoice <b>{{ props.invoice.invoice_no }}</b> — {{ props.invoice.customer?.name }}. Adjust qty per line below (max = invoiced qty). Resalable will restock; damaged will not.</div>
        <h3 class="font-semibold mt-4">Return Lines — Total {{ total.toFixed(2) }} BDT</h3>
        <div v-for="(it, idx) in form.items" :key="idx" class="flex gap-2 items-end border rounded-lg p-2">
          <div class="flex-1 text-sm"><div class="font-medium">{{ it.product_name }}</div><div class="text-xs text-muted-foreground">Max {{ it.maxQty }} @ {{ it.unit_price }}</div></div>
          <div class="w-20"><Label>Qty</Label><Input v-model.number="it.qty" type="number" min="0.01" :max="it.maxQty" step="1" /></div>
          <div class="w-32"><Label>Condition</Label><select v-model="it.condition" class="h-9 w-full rounded border bg-background px-2 text-sm"><option value="resalable">Resalable → restock</option><option value="damaged">Damaged → scrap</option></select></div>
          <Button variant="ghost" @click="removeLine(idx)">✕</Button>
        </div>
        <div v-if="!form.items.length" class="text-sm text-muted-foreground py-6 text-center border border-dashed rounded-lg">No lines — if creating from invoice URL ?invoice_id=ID, lines auto-load. Otherwise add via invoice lookup.</div>
        <div class="flex justify-end gap-2"><Link :href="route('sales.returns.index')"><Button variant="outline">Cancel</Button></Link><Button @click="submit" :disabled="!form.invoice_id || !form.items.length">Create Return Draft</Button></div>
        <p class="text-xs text-muted-foreground">Tip: Create via Invoice Show → Return button to auto-load items.</p>
      </div>
    </div>
  </AdminLayout>
</template>
