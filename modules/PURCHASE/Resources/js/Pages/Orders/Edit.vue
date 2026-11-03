<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Plus, Trash2 } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import { computed } from 'vue';
const props = defineProps<{ order: any }>();
const form = useForm({
  vendor_id: props.order.vendor_id ?? null as number | null,
  warehouse_id: props.order.warehouse_id ?? null as number | null,
  order_date: props.order.order_date ? String(props.order.order_date).slice(0,10) : new Date().toISOString().slice(0, 10),
  expected_date: props.order.expected_date ? String(props.order.expected_date).slice(0,10) : '',
  notes: props.order.notes ?? '',
  items: (props.order.items?.length ? props.order.items : [{ product_id: null, qty: 1, unit_price: null, discount_pct: 0, vat_rate: 0 }]).map((it:any)=>({ product_id: it.product_id, qty: Number(it.qty), unit_price: Number(it.unit_price), discount_pct: Number(it.discount_pct||0), vat_rate: Number(it.vat_rate||0) })),
});
const total = computed(() => form.items.reduce((s,i)=>{ const g=(Number(i.qty)||0)*(Number(i.unit_price)||0); return s+g*(1-(Number(i.discount_pct)||0)/100)*(1+(Number(i.vat_rate)||0)/100); },0));
function addItem(){ form.items.push({ product_id: null, qty: 1, unit_price: null, discount_pct: 0, vat_rate: 0 }); }
function removeItem(i:number){ form.items.splice(i,1); if(!form.items.length) addItem(); }
function submit(){ form.put(route('purchase.orders.update', props.order.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Purchase',href:route('purchase.orders.index')},{title:order.order_no},{title:'Edit'}]">
    <Head :title="`Edit ${order.order_no}`" />
    <div class="max-w-4xl mx-auto space-y-4">
      <h1 class="text-xl font-bold">Edit {{ order.order_no }} — <span class="text-muted-foreground text-sm">{{ order.status }}</span></h1>
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] as string }}</p>
        <div class="bg-card border rounded-xl p-4 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="space-y-2"><Label>Vendor *</Label><SearchableSelect v-model="form.vendor_id" endpoint="/partner/api/options/vendors" placeholder="Search vendor"/></div>
            <div class="space-y-2"><Label>Warehouse *</Label><SearchableSelect v-model="form.warehouse_id" endpoint="/inventory/api/options/warehouses" placeholder="Receiving warehouse"/></div>
            <div class="space-y-2"><Label>Order Date *</Label><Input v-model="form.order_date" type="date"/></div>
          </div>
          <div class="space-y-2"><Label>Notes</Label><Textarea v-model="form.notes" rows="2"/></div>
        </div>
        <div class="bg-card border rounded-xl p-4 space-y-3">
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
        <Button type="submit" :disabled="form.processing">Update PO</Button>
      </form>
    </div>
  </AdminLayout>
</template>
