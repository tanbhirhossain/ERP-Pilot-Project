<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Plus, Trash2 } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props = defineProps<{ product:any; categoryOptions:any[]; brandOptions:any[]; uomOptions:any[]; warehouseOptions:any[] }>();
const form = useForm({
  name: props.product.name, sku: props.product.sku, barcode: props.product.barcode ?? '', category_id: props.product.category_id, brand_id: props.product.brand_id, uom_id: props.product.uom_id,
  type: props.product.type, description: props.product.description ?? '', hs_code: props.product.hs_code ?? '', weight: props.product.weight, reorder_level: props.product.reorder_level, reorder_qty: props.product.reorder_qty,
  valuation_method: props.product.valuation_method, cost_price: props.product.cost_price, selling_price: props.product.selling_price, mrp: props.product.mrp, vat_rate: props.product.vat_rate, currency: 'BDT',
  is_serialized: props.product.is_serialized, is_batch_tracked: props.product.is_batch_tracked, shelf_life_days: props.product.shelf_life_days, is_active: props.product.is_active, status: props.product.status,
  image: null as File|null,
  variants: props.product.variants?.length ? props.product.variants : [{ name:'', sku:'', barcode:'', cost_price:null, selling_price:null }],
  suppliers: props.product.suppliers?.length ? props.product.suppliers.map((s:any)=>({ partner_id:s.partner_id, supplier_sku:s.supplier_sku, last_purchase_price:s.last_purchase_price, is_primary:s.is_primary })) : [{ partner_id:null, supplier_sku:'', last_purchase_price:null, is_primary:true }],
});
const activeTab=ref('basic');
const tabs=[{id:'basic',label:'Basic'},{id:'pricing',label:'Pricing'},{id:'inventory',label:'Inventory'},{id:'suppliers',label:'Suppliers'},{id:'variants',label:'Variants'}];
function addVariant(){ form.variants.push({ name:'', sku:'', barcode:'', cost_price:null, selling_price:null }); }
function removeVariant(i:number){ form.variants.splice(i,1); }
function addSupplier(){ form.suppliers.push({ partner_id:null, supplier_sku:'', last_purchase_price:null, is_primary:false }); }
function removeSupplier(i:number){ form.suppliers.splice(i,1); }
function submit(){ form.post(route('inventory.products.update', props.product.id), { forceFormData:true, headers:{ 'X-HTTP-Method-Override':'PUT' } }); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Products',href:route('inventory.products.index')},{title:product.sku},{title:'Edit'}]">
    <Head :title="`Edit ${product.sku}`" />
    <div class="max-w-5xl mx-auto space-y-4">
      <h1 class="text-xl font-bold">Edit {{ product.sku }} — {{ product.name }}</h1>
      <form @submit.prevent="submit" class="space-y-4">
        <nav class="flex gap-2 overflow-x-auto rounded-xl border bg-card p-2"><button v-for="t in tabs" :key="t.id" type="button" @click="activeTab=t.id" class="shrink-0 rounded-lg px-3 py-2 text-sm font-medium transition" :class="activeTab===t.id?'bg-primary text-primary-foreground shadow-sm':'text-muted-foreground hover:bg-muted hover:text-foreground'">{{ t.label }}</button></nav>
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <section v-if="activeTab==='basic'" class="bg-card border rounded-xl p-4 space-y-4">
          <h2 class="font-semibold">Basic</h2>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4"><div class="space-y-2"><Label>Name *</Label><Input v-model="form.name"/></div><div class="space-y-2"><Label>SKU</Label><Input v-model="form.sku"/></div><div class="space-y-2"><Label>Barcode</Label><Input v-model="form.barcode"/></div></div>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4"><div class="space-y-2"><Label>Category</Label><SearchableSelect v-model="form.category_id" :options="categoryOptions" placeholder="Select" clearable /></div><div class="space-y-2"><Label>Brand</Label><SearchableSelect v-model="form.brand_id" :options="brandOptions" placeholder="Select" clearable /></div><div class="space-y-2"><Label>UOM</Label><SearchableSelect v-model="form.uom_id" :options="uomOptions" placeholder="Select" clearable /></div></div>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Type</Label><select v-model="form.type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="stockable">Stockable</option><option value="consumable">Consumable</option><option value="service">Service</option><option value="bundle">Bundle</option></select></div><div class="space-y-2"><Label>Status</Label><select v-model="form.status" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="active">Active</option><option value="draft">Draft</option><option value="discontinued">Discontinued</option></select></div></div>
          <div class="space-y-2"><Label>Description</Label><Textarea v-model="form.description" rows="3"/></div>
          <div class="space-y-2"><Label>Image</Label><Input type="file" @change="e=>form.image=(e.target as HTMLInputElement).files?.[0]||null"/></div>
        </section>
        <section v-if="activeTab==='pricing'" class="bg-card border rounded-xl p-4 space-y-4">
          <h2 class="font-semibold">Pricing</h2>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4"><div class="space-y-2"><Label>Cost *</Label><Input v-model.number="form.cost_price" type="number" step="0.01"/></div><div class="space-y-2"><Label>Price *</Label><Input v-model.number="form.selling_price" type="number" step="0.01"/></div><div class="space-y-2"><Label>MRP</Label><Input v-model.number="form.mrp" type="number" step="0.01"/></div><div class="space-y-2"><Label>VAT %</Label><Input v-model.number="form.vat_rate" type="number" step="0.01"/></div></div>
        </section>
        <section v-if="activeTab==='inventory'" class="bg-card border rounded-xl p-4 space-y-4">
          <h2 class="font-semibold">Inventory</h2>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4"><div class="space-y-2"><Label>Reorder Level</Label><Input v-model.number="form.reorder_level" type="number" step="0.01"/></div><div class="space-y-2"><Label>Reorder Qty</Label><Input v-model.number="form.reorder_qty" type="number" step="0.01"/></div><div class="space-y-2"><Label>Valuation</Label><select v-model="form.valuation_method" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="weighted">Weighted</option><option value="fifo">FIFO</option></select></div></div>
          <div class="flex gap-4"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_serialized" class="rounded"/> Serialized</label><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_batch_tracked" class="rounded"/> Batch</label><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label></div>
        </section>
        <section v-if="activeTab==='suppliers'" class="bg-card border rounded-xl p-4 space-y-4">
          <h2 class="font-semibold">Suppliers</h2>
          <div v-for="(s,i) in form.suppliers" :key="i" class="border rounded-lg p-3 space-y-3 relative"><Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeSupplier(i)"><Trash2 class="size-3"/></Button><p class="text-xs text-muted-foreground">#{{ i+1 }}</p><div class="grid grid-cols-1 sm:grid-cols-3 gap-3"><div class="space-y-1"><Label>Vendor</Label><SearchableSelect v-model="s.partner_id" endpoint="/partner/api/options/vendors" placeholder="Search" clearable /></div><div class="space-y-1"><Label>SKU</Label><Input v-model="s.supplier_sku"/></div><div class="space-y-1"><Label>Price</Label><Input v-model.number="s.last_purchase_price" type="number" step="0.01"/></div></div></div>
          <Button type="button" variant="outline" size="sm" @click="addSupplier"><Plus class="size-4"/> Add Supplier</Button>
        </section>
        <section v-if="activeTab==='variants'" class="bg-card border rounded-xl p-4 space-y-4">
          <h2 class="font-semibold">Variants</h2>
          <div v-for="(v,i) in form.variants" :key="i" class="border rounded-lg p-3 space-y-3 relative"><Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeVariant(i)"><Trash2 class="size-3"/></Button><div class="grid grid-cols-1 sm:grid-cols-3 gap-3"><div class="space-y-1"><Label>Name</Label><Input v-model="v.name"/></div><div class="space-y-1"><Label>SKU</Label><Input v-model="v.sku"/></div><div class="space-y-1"><Label>Price</Label><Input v-model.number="v.selling_price" type="number" step="0.01"/></div></div></div>
          <Button type="button" variant="outline" size="sm" @click="addVariant"><Plus class="size-4"/> Add Variant</Button>
        </section>
        <Button type="submit" :disabled="form.processing" class="w-full sm:w-auto">Update Product</Button>
      </form>
    </div>
  </AdminLayout>
</template>
