<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {Plus, Trash2, Package} from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
const props = defineProps<{ priceList:any }>();
const form = useForm({
  name: props.priceList.name, code: props.priceList.code, currency: props.priceList.currency, is_default: props.priceList.is_default, is_active: props.priceList.is_active,
  effective_from: props.priceList.effective_from ?? '', effective_to: props.priceList.effective_to ?? '', notes: props.priceList.notes ?? '',
  items: props.priceList.items?.length ? props.priceList.items : [{ sku:'', product_name:'', unit_price:null, min_qty:1, discount_pct:0 }],
});
function addItem(){ form.items.push({ sku:'', product_name:'', unit_price:null, min_qty:1, discount_pct:0 }); }
function removeItem(i:number){ form.items.splice(i,1); }
function submit(){ form.put(route('partner.pricelists.update', props.priceList.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Ven & Cust' }, { title: 'Price Lists', href: route('partner.pricelists.index') }, { title: priceList.code }, { title: 'Edit' }]">
    <Head :title="`Edit ${priceList.code}`" />
    <PageHeader title="Edit {{ priceList.code }} — {{ priceList.name }}" description="Complete the form — required *" :icon="Package">
    <div class="space-y-4">
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <div class="bg-card border rounded-xl shadow-sm p-4 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><div class="space-y-2"><Label>Name *</Label><Input v-model="form.name"/></div><div class="space-y-2"><Label>Code *</Label><Input v-model="form.code"/></div></div>
          <div class="grid grid-cols-1 sm:grid-cols-3 2xl:grid-cols-4 gap-4"><div class="space-y-2"><Label>Currency</Label><select v-model="form.currency" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>BDT</option><option>USD</option><option>EUR</option></select></div><div class="space-y-2"><Label>From</Label><Input v-model="form.effective_from" type="date"/></div><div class="space-y-2"><Label>To</Label><Input v-model="form.effective_to" type="date"/></div></div>
          <div class="space-y-2"><Label>Notes</Label><Textarea v-model="form.notes" rows="2"/></div>
          <div class="flex gap-4"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_default" class="rounded"/> Default</label><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label></div>
        </div>
        <div class="bg-card border rounded-xl shadow-sm p-4 space-y-3">
          <h3 class="font-semibold text-sm">Price Items</h3>
          <div v-for="(it,i) in form.items" :key="i" class="border rounded-lg p-3 space-y-3 relative">
            <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeItem(i)"><Trash2 class="size-3"/></Button><p class="text-xs text-muted-foreground">#{{ i+1 }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-3"><div class="space-y-1"><Label>SKU</Label><Input v-model="it.sku"/></div><div class="space-y-1"><Label>Product</Label><Input v-model="it.product_name"/></div><div class="space-y-1"><Label>Price</Label><Input v-model.number="it.unit_price" type="number" step="0.01"/></div><div class="space-y-1"><Label>Discount %</Label><Input v-model.number="it.discount_pct" type="number" step="0.01"/></div></div>
          </div>
          <Button type="button" variant="outline" size="sm" @click="addItem"><Plus class="size-4"/> Add Item</Button>
        </div>
        <Button type="submit" :disabled="form.processing">Update Price List</Button>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
