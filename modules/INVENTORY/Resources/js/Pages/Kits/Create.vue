<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
defineProps<{products:any[],warehouses:any[]}>();
const form=reactive({product_id:null as number | string | null, warehouse_id:null as number | string | null, qty_to_produce:1, notes:'', items:[{component_product_id:null, required_qty:1, is_optional:false}] as any[]});
function add(){form.items.push({component_product_id:null, required_qty:1, is_optional:false});}
function submit(){router.post(route('inventory.kits.store'), form as any);}
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Kits / BOM Production', href: route('inventory.kits.index') }, { title: 'Create' }]">
    <Head title="New Kit"/>
    <div class="space-y-4">
      <PageHeader title="New Kit / BOM Production Order" />
      <div class="bg-card border rounded-xl p-4 space-y-3">
        <div class="grid md:grid-cols-3 gap-3">
          <div class="space-y-1"><Label>Finished Product (Kit) *</Label><SearchableSelect v-model="form.product_id" endpoint="/inventory/api/options/products" placeholder="Kit SKU — e.g., Gift Box" /></div>
          <div class="space-y-1"><Label>Warehouse *</Label><SearchableSelect v-model="form.warehouse_id" endpoint="/inventory/api/options/warehouses" placeholder="Warehouse" /></div>
          <div class="space-y-1"><Label>Qty to Produce</Label><Input v-model.number="form.qty_to_produce" type="number" min="0.01" step="1" /></div>
        </div>
        <div class="space-y-1"><Label>Notes</Label><Input v-model="form.notes" placeholder="Batch #..." /></div>
        <h3 class="font-semibold mt-4">Components (BOM)</h3>
        <p class="text-xs text-muted-foreground">If product has BOM defined, it auto-loads below — or add ad-hoc. Cost taken from current FIFO/avg stock.</p>
        <div v-for="(it,i) in form.items" :key="i" class="flex gap-2 items-end border rounded p-2">
          <div class="flex-1"><Label>Component *</Label><SearchableSelect v-model="it.component_product_id" endpoint="/inventory/api/options/products" placeholder="Component SKU" /></div>
          <div class="w-28"><Label>Required Qty</Label><Input v-model.number="it.required_qty" type="number" step="0.01" /></div>
          <label class="flex items-center gap-1 text-xs"><input type="checkbox" v-model="it.is_optional" /> Optional</label>
          <Button variant="ghost" @click="form.items.splice(i,1)">✕</Button>
        </div>
        <Button variant="outline" size="sm" @click="add">+ Add Component</Button>
        <div class="flex justify-end"><Button @click="submit">Create Kit Order</Button></div>
      </div>
    </div>
  </AdminLayout>
</template>
