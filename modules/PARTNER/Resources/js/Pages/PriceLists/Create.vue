<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {Plus, Trash2, Package} from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
const form = useForm({ name:'', code:'', currency:'BDT', is_default:false, is_active:true, effective_from:'', effective_to:'', notes:'', items:[{ sku:'', product_name:'', unit_price:null as number|null, min_qty:1, discount_pct:0 }] });
function addItem(){ form.items.push({ sku:'', product_name:'', unit_price:null, min_qty:1, discount_pct:0 }); }
function removeItem(i:number){ form.items.splice(i,1); if(!form.items.length) addItem(); }
function submit(){ form.post(route('partner.pricelists.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Price Lists',href:route('partner.pricelists.index')},{title:'Create'}]">
    <Head title="Create Price List" />
    <PageHeader title="Add Price List" description="Complete the form — required *" :icon="Package">
    <div class="max-w-3xl mx-auto space-y-4">
      <h1 class="text-xl font-bold">Add Price List</h1>
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <div class="bg-card border rounded-2xl shadow-sm p-4 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div class="space-y-2"><Label>Name *</Label><Input v-model="form.name" placeholder="Wholesale Price 2026"/><p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p></div>
            <div class="space-y-2"><Label>Code *</Label><Input v-model="form.code" placeholder="WS-2026"/><p v-if="form.errors.code" class="text-xs text-destructive">{{ form.errors.code }}</p></div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="space-y-2"><Label>Currency *</Label><select v-model="form.currency" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>BDT</option><option>USD</option><option>EUR</option></select></div>
            <div class="space-y-2"><Label>Effective From</Label><Input v-model="form.effective_from" type="date"/></div>
            <div class="space-y-2"><Label>Effective To</Label><Input v-model="form.effective_to" type="date"/></div>
          </div>
          <div class="space-y-2"><Label>Notes</Label><Textarea v-model="form.notes" rows="2"/></div>
          <div class="flex gap-4"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_default" class="rounded"/> Default (used when partner has no price list)</label><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label></div>
        </div>
        <div class="bg-card border rounded-2xl shadow-sm p-4 space-y-3">
          <h3 class="font-semibold text-sm">Price Items — SKU pricing (products link in Phase 2)</h3>
          <div v-for="(it,i) in form.items" :key="i" class="border rounded-lg p-3 space-y-3 relative">
            <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeItem(i)"><Trash2 class="size-3"/></Button>
            <p class="text-xs text-muted-foreground">#{{ i+1 }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
              <div class="space-y-1"><Label>SKU</Label><Input v-model="it.sku" placeholder="SKU-001"/></div>
              <div class="space-y-1"><Label>Product Name</Label><Input v-model="it.product_name" placeholder="Fabric — Cotton"/></div>
              <div class="space-y-1"><Label>Unit Price * (BDT)</Label><Input v-model.number="it.unit_price" type="number" step="0.01" placeholder="150.00"/></div>
              <div class="space-y-1"><Label>Min Qty</Label><Input v-model.number="it.min_qty" type="number" min="1"/></div>
              <div class="space-y-1"><Label>Discount %</Label><Input v-model.number="it.discount_pct" type="number" min="0" max="100" step="0.01"/></div>
            </div>
          </div>
          <Button type="button" variant="outline" size="sm" @click="addItem"><Plus class="size-4"/> Add Item</Button>
          <p class="text-xs text-muted-foreground">In Phase 2, SKU will link to Inventory products via SearchableSelect.</p>
        </div>
        <Button type="submit" :disabled="form.processing">Create Price List</Button>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
