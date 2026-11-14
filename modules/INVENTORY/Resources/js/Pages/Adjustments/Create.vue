<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {Plus, Trash2, Package} from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
defineProps<{ warehouseOptions:SelectOption[] }>();
const form=useForm({ warehouse_id:null as number|null, type:'correction', reason:'', notes:'', items:[{ product_id:null as number|null, variant_id:null as number|null, location_id:null as number|null, qty:null as number|null, unit_cost:null as number|null, reason:'' }] });
useUnsavedGuard(() => form.isDirty);
function addItem(){ form.items.push({ product_id:null, variant_id:null, location_id:null, qty:null, unit_cost:null, reason:'' }); }
function removeItem(i:number){ form.items.splice(i,1); if(!form.items.length) addItem(); }
function submit(){ form.post(route('inventory.adjustments.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Adjustments', href: route('inventory.adjustments.index') }, { title: 'Create' }]">
    <Head title="Create Adjustment" />
    <PageHeader title="New Stock Adjustment" description="Draft → Approve → Post. Posting creates stock moves." :icon="Package">
    <div class="space-y-4">
      <p class="text-sm text-muted-foreground">Draft → Approve → Post. Posting creates stock moves.</p>
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <div class="bg-card border rounded-xl shadow-sm p-4 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            <div class="space-y-2"><Label>Warehouse *</Label><SearchableSelect v-model="form.warehouse_id" :options="warehouseOptions" placeholder="Select warehouse" /></div>
            <div class="space-y-2"><Label>Type *</Label><select v-model="form.type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="correction">Correction</option><option value="increase">Increase</option><option value="decrease">Decrease</option><option value="damage">Damage</option><option value="expired">Expired</option><option value="found">Found</option></select></div>
          </div>
          <div class="space-y-2"><Label>Reason *</Label><Textarea v-model="form.reason" rows="2" placeholder="Why adjusting?"/></div>
          <div class="space-y-2"><Label>Notes</Label><Textarea v-model="form.notes" rows="2"/></div>
        </div>
        <div class="bg-card border rounded-xl shadow-sm p-4 space-y-3">
          <h3 class="font-semibold text-sm">Items — qty always positive; type determines in/out</h3>
          <div v-for="(it,i) in form.items" :key="i" class="border rounded-lg p-3 space-y-3 relative">
            <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeItem(i)"><Trash2 class="size-3"/></Button>
            <p class="text-xs text-muted-foreground">#{{ i+1 }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-3 2xl:grid-cols-4 gap-3">
              <div class="space-y-1"><Label>Product *</Label><SearchableSelect v-model="it.product_id" endpoint="/inventory/api/options/products" placeholder="Search" /></div>
              <div class="space-y-1"><Label>Qty *</Label><Input v-model.number="it.qty" type="number" step="0.01" placeholder="5"/></div>
              <div class="space-y-1"><Label>Unit Cost</Label><Input v-model.number="it.unit_cost" type="number" step="0.01" placeholder="Auto if empty"/></div>
            </div>
            <div class="space-y-1"><Label>Line Reason</Label><Input v-model="it.reason" placeholder="Damaged in handling"/></div>
          </div>
          <Button type="button" variant="outline" size="sm" @click="addItem"><Plus class="size-4"/> Add Item</Button>
        </div>
        <Button type="submit" :disabled="form.processing">Create Adjustment</Button>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
