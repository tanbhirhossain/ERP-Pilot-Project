<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { GoodsReceipt } from '@purchase/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Plus, Trash2 } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { computed } from 'vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props = defineProps<{ receipt: GoodsReceipt }>();
const form = useForm({
  order_id: props.receipt.order_id ?? null as number|null,
  vendor_id: props.receipt.vendor_id ?? null as number|null,
  warehouse_id: props.receipt.warehouse_id ?? null as number|null,
  receipt_date: String(props.receipt.receipt_date).slice(0,10),
  notes: props.receipt.notes ?? '',
  items: (props.receipt.items?.length ? props.receipt.items : [{ product_id: null, qty: 1, unit_price: null, vat_rate: 0 }]).map((it:any)=>({ product_id: it.product_id, order_item_id: it.order_item_id ?? null, qty: Number(it.qty), unit_price: Number(it.unit_price), discount_pct: Number(it.discount_pct||0), vat_rate: Number(it.vat_rate||0) })),
});
useUnsavedGuard(() => form.isDirty);
const total = computed(()=> form.items.reduce((s,i)=>{ const g=(Number(i.qty)||0)*(Number(i.unit_price)||0); return s+g*(1-(Number(i.discount_pct)||0)/100)*(1+(Number(i.vat_rate)||0)/100); },0));
function addItem(){ form.items.push({ product_id: null, order_item_id: null, qty: 1, unit_price: null, discount_pct: 0, vat_rate: 0 }); }
function removeItem(i:number){ form.items.splice(i,1); if(!form.items.length) addItem(); }
function submit(){ form.put(route('purchase.receipts.update', props.receipt.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: receipt.receipt_no }, { title: 'Goods Receipts (GRN)', href: route('purchase.receipts.index') }, { title: 'Edit' }]">
    <Head :title="`Edit ${receipt.receipt_no}`" />
    <div class="space-y-4">
      <PageHeader :title="`Edit ${receipt.receipt_no}`" />
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] as string }}</p>
        <FormSection title="Details" :cols="2" class="overflow-visible">

        <div class="bg-card border rounded-xl p-4 space-y-4 col-span-full">
          <div class="grid grid-cols-1 sm:grid-cols-3 2xl:grid-cols-4 gap-4">
            <FormField label="Vendor" required>
    <template #default="{ id, invalid }">
        <SearchableSelect :id="id" v-model="form.vendor_id" endpoint="/partner/api/options/vendors" placeholder="Search vendor"/>
    </template>
</FormField>
            <FormField label="Warehouse" required>
    <template #default="{ id, invalid }">
        <SearchableSelect :id="id" v-model="form.warehouse_id" endpoint="/inventory/api/options/warehouses" placeholder="Receiving WH"/>
    </template>
</FormField>
            <FormField label="Receipt Date" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.receipt_date" type="date"/>
    </template>
</FormField>
          </div>
          <FormField label="Notes">
    <template #default="{ id, invalid }">
        <Textarea :id="id" :aria-invalid="invalid" v-model="form.notes" rows="2"/>
    </template>
</FormField>
        </div>
        <div class="bg-card border rounded-xl p-4 space-y-3 col-span-full">
          <h3 class="font-semibold text-sm">Items — {{ total.toFixed(2) }} BDT</h3>
          <div v-for="(it,i) in form.items" :key="i" class="border rounded-lg p-3 space-y-3 relative bg-muted/10">
            <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeItem(i)"><Trash2 class="size-3"/></Button>
            <div class="grid grid-cols-1 sm:grid-cols-5 gap-3">
              <div class="space-y-1 sm:col-span-2"><Label>Product *</Label><SearchableSelect v-model="it.product_id" endpoint="/inventory/api/options/products" placeholder="Search"/></div>
              <div class="space-y-1"><Label>Qty *</Label><Input v-model.number="it.qty" type="number" step="0.01"/></div>
              <div class="space-y-1"><Label>Price *</Label><Input v-model.number="it.unit_price" type="number" step="0.01"/></div>
              <div class="space-y-1"><Label>VAT%</Label><Input v-model.number="it.vat_rate" type="number" step="0.01"/></div>
            </div>
          </div>
          <Button type="button" variant="outline" size="sm" @click="addItem"><Plus class="size-4"/> Add Item</Button>
        </div>
        <FormActions submit-label="Update GRN" :processing="form.processing" :dirty="form.isDirty" :cancel-href="route('purchase.receipts.index')" />
          </FormSection>
    </form>
    </div>
  </AdminLayout>
</template>
