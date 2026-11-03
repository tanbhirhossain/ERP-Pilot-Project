<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {Plus, Trash2, Package} from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
defineProps<{ warehouseOptions:any[] }>();
const form=useForm({ from_warehouse_id:null as number|null, to_warehouse_id:null as number|null, reason:'', notes:'', items:[{ product_id:null as number|null, variant_id:null as number|null, qty_requested:null as number|null, unit_cost:null as number|null }] });
function addItem(){ form.items.push({ product_id:null, variant_id:null, qty_requested:null, unit_cost:null }); }
function removeItem(i:number){ form.items.splice(i,1); if(!form.items.length) addItem(); }
function submit(){ form.post(route('inventory.transfers.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Transfers',href:route('inventory.transfers.index')},{title:'Create'}]">
    <Head title="Create Transfer" />
    <PageHeader title="New Stock Transfer" description="Complete the form — required *" :icon="Package">
    <div class="max-w-3xl mx-auto space-y-4">
      <h1 class="text-xl font-bold">New Stock Transfer</h1>
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <div class="bg-card border rounded-2xl shadow-sm p-4 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="space-y-2"><Label>From Warehouse *</Label><SearchableSelect v-model="form.from_warehouse_id" :options="warehouseOptions" placeholder="Source" /></div>
            <div class="space-y-2"><Label>To Warehouse *</Label><SearchableSelect v-model="form.to_warehouse_id" :options="warehouseOptions" placeholder="Destination" /></div>
          </div>
          <div class="space-y-2"><Label>Reason</Label><Textarea v-model="form.reason" rows="2" placeholder="Stock rebalancing for factory"/></div>
          <div class="space-y-2"><Label>Notes</Label><Textarea v-model="form.notes" rows="2"/></div>
        </div>
        <div class="bg-card border rounded-2xl shadow-sm p-4 space-y-3">
          <h3 class="font-semibold text-sm">Items</h3>
          <div v-for="(it,i) in form.items" :key="i" class="border rounded-lg p-3 space-y-3 relative">
            <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeItem(i)"><Trash2 class="size-3"/></Button>
            <p class="text-xs text-muted-foreground">#{{ i+1 }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <div class="space-y-1"><Label>Product *</Label><SearchableSelect v-model="it.product_id" endpoint="/inventory/api/options/products" placeholder="Search product" /></div>
              <div class="space-y-1"><Label>Qty *</Label><Input v-model.number="it.qty_requested" type="number" step="0.01" placeholder="10"/></div>
              <div class="space-y-1"><Label>Unit Cost</Label><Input v-model.number="it.unit_cost" type="number" step="0.01" placeholder="Auto from stock"/></div>
            </div>
          </div>
          <Button type="button" variant="outline" size="sm" @click="addItem"><Plus class="size-4"/> Add Item</Button>
        </div>
        <Button type="submit" :disabled="form.processing">Create Transfer</Button>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
