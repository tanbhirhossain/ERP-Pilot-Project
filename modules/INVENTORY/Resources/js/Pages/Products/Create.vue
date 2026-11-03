<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Plus, Trash2, Package, Info, DollarSign, Boxes, Truck, Layers } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';

const props = defineProps<{ categoryOptions:any[]; brandOptions:any[]; uomOptions:any[]; warehouseOptions:any[] }>();

const form = useForm({
  name:'', sku:'', barcode:'', category_id:null as number|null, brand_id:null as number|null, uom_id:null as number|null,
  type:'stockable', description:'', hs_code:'', weight:null as number|null, reorder_level:10 as number|null, reorder_qty:50 as number|null,
  valuation_method:'weighted', cost_price:null as number|null, selling_price:null as number|null, mrp:null as number|null, vat_rate:0 as number|null,
  currency:'BDT', is_serialized:false, is_batch_tracked:false, shelf_life_days:null as number|null, is_active:true, status:'active',
  image:null as File|null,
  variants:[{ name:'', sku:'', barcode:'', cost_price:null as number|null, selling_price:null as number|null }],
  suppliers:[{ partner_id:null as number|null, supplier_sku:'', last_purchase_price:null as number|null, is_primary:true }],
});

const activeTab=ref('basic');
const tabs=[{id:'basic',label:'Basic',icon:Info},{id:'pricing',label:'Pricing',icon:DollarSign},{id:'inventory',label:'Inventory',icon:Boxes},{id:'suppliers',label:'Suppliers',icon:Truck},{id:'variants',label:'Variants',icon:Layers}];

function addVariant(){ form.variants.push({ name:'', sku:'', barcode:'', cost_price:null, selling_price:null }); }
function removeVariant(i:number){ form.variants.splice(i,1); if(!form.variants.length) addVariant(); }
function addSupplier(){ form.suppliers.push({ partner_id:null, supplier_sku:'', last_purchase_price:null, is_primary:false }); }
function removeSupplier(i:number){ form.suppliers.splice(i,1); if(!form.suppliers.length) addSupplier(); }

function submit(){
  form.post(route('inventory.products.store'), {
    forceFormData:true,
    onError:(e)=>{ const k=Object.keys(e)[0]; if(k?.startsWith('variants')) activeTab.value='variants'; else if(k?.startsWith('suppliers')) activeTab.value='suppliers'; }
  });
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Products',href:route('inventory.products.index')},{title:'Create'}]">
    <Head title="Create Product" />
    <PageHeader title="New Product" description="Create a catalog item — stockable, consumable, service, or bundle. SKU auto-generates if blank. Variants per size/color." :icon="Package">
      <form @submit.prevent="submit" class="space-y-5 max-w-5xl mx-auto w-full">
        <nav class="flex gap-1 overflow-x-auto rounded-2xl border bg-card p-1.5 shadow-sm">
          <button v-for="t in tabs" :key="t.id" type="button" @click="activeTab=t.id" class="shrink-0 flex items-center gap-1.5 rounded-full px-4 py-2 text-sm font-medium transition" :class="activeTab===t.id?'bg-primary text-primary-foreground shadow-md':'text-muted-foreground hover:bg-muted hover:text-foreground'"><component :is="t.icon" class="size-3.5" /> {{ t.label }}</button>
        </nav>
        <p v-if="Object.keys(form.errors).length" class="rounded-2xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive flex items-center gap-2">⚠ {{ Object.values(form.errors)[0] as string }}</p>

        <section v-if="activeTab==='basic'" class="bg-card border rounded-2xl shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b bg-muted/20 flex items-center gap-2"><Info class="size-4 text-primary" /><h2 class="font-semibold">Basic Information</h2><span class="ml-auto text-xs text-muted-foreground">SKU auto if blank</span></div>
          <div class="p-5 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="space-y-2"><Label>Name *</Label><Input v-model="form.name" placeholder="Cotton Fabric — Premium" class="h-9" /><p v-if="form.errors.name" class="text-xs text-destructive">{{ form.errors.name }}</p></div>
              <div class="space-y-2"><Label>SKU</Label><Input v-model="form.sku" placeholder="SKU-00001" class="h-9" /></div>
              <div class="space-y-2"><Label>Barcode</Label><Input v-model="form.barcode" placeholder="880..." class="h-9" /></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="space-y-2"><Label>Category</Label><SearchableSelect v-model="form.category_id" :options="categoryOptions" placeholder="Select category" clearable /></div>
              <div class="space-y-2"><Label>Brand</Label><SearchableSelect v-model="form.brand_id" :options="brandOptions" placeholder="Select brand" clearable /></div>
              <div class="space-y-2"><Label>UOM</Label><SearchableSelect v-model="form.uom_id" :options="uomOptions" placeholder="Piece / Kg" clearable /></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="space-y-2"><Label>Type *</Label><select v-model="form.type" class="w-full h-9 rounded-xl border bg-background px-3 text-sm focus:ring-2 focus:ring-ring/20"><option value="stockable">Stockable</option><option value="consumable">Consumable</option><option value="service">Service</option><option value="bundle">Bundle</option></select></div>
              <div class="space-y-2"><Label>Status *</Label><select v-model="form.status" class="w-full h-9 rounded-xl border bg-background px-3 text-sm focus:ring-2 focus:ring-ring/20"><option value="active">Active</option><option value="draft">Draft</option><option value="discontinued">Discontinued</option></select></div>
            </div>
            <div class="space-y-2"><Label>Description</Label><Textarea v-model="form.description" rows="3" placeholder="Detailed description" /></div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="space-y-2"><Label>HS Code</Label><Input v-model="form.hs_code" class="h-9" /></div>
              <div class="space-y-2"><Label>Weight</Label><Input v-model.number="form.weight" type="number" step="0.001" class="h-9" /></div>
              <div class="space-y-2"><Label>Image</Label><Input type="file" @change="e=>form.image=(e.target as HTMLInputElement).files?.[0]||null" class="h-9" /></div>
            </div>
          </div>
        </section>

        <section v-if="activeTab==='pricing'" class="bg-card border rounded-2xl shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b bg-muted/20 flex items-center gap-2"><DollarSign class="size-4 text-emerald-600" /><h2 class="font-semibold">Pricing & VAT</h2></div>
          <div class="p-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="space-y-2"><Label>Cost Price *</Label><Input v-model.number="form.cost_price" type="number" step="0.01" placeholder="120.00" class="h-9 font-mono" /><p v-if="form.errors.cost_price" class="text-xs text-destructive">{{ form.errors.cost_price }}</p></div>
            <div class="space-y-2"><Label>Selling Price *</Label><Input v-model.number="form.selling_price" type="number" step="0.01" placeholder="150.00" class="h-9 font-mono" /></div>
            <div class="space-y-2"><Label>MRP</Label><Input v-model.number="form.mrp" type="number" step="0.01" class="h-9 font-mono" /></div>
            <div class="space-y-2"><Label>VAT Rate %</Label><Input v-model.number="form.vat_rate" type="number" min="0" max="100" step="0.01" class="h-9" /></div>
            <div class="space-y-2"><Label>Currency</Label><select v-model="form.currency" class="w-full h-9 rounded-xl border bg-background px-3 text-sm"><option>BDT</option><option>USD</option><option>EUR</option></select></div>
          </div>
        </section>

        <section v-if="activeTab==='inventory'" class="bg-card border rounded-2xl shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b bg-muted/20 flex items-center gap-2"><Boxes class="size-4 text-primary" /><h2 class="font-semibold">Inventory Settings</h2></div>
          <div class="p-5 space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div class="space-y-2"><Label>Reorder Level</Label><Input v-model.number="form.reorder_level" type="number" step="0.01" class="h-9" /></div>
              <div class="space-y-2"><Label>Reorder Qty</Label><Input v-model.number="form.reorder_qty" type="number" step="0.01" class="h-9" /></div>
              <div class="space-y-2"><Label>Valuation</Label><select v-model="form.valuation_method" class="w-full h-9 rounded-xl border bg-background px-3 text-sm"><option value="weighted">Weighted Average</option><option value="fifo">FIFO</option></select></div>
              <div class="space-y-2"><Label>Shelf Life (days)</Label><Input v-model.number="form.shelf_life_days" type="number" min="0" class="h-9" /></div>
            </div>
            <div class="flex flex-wrap gap-4 pt-2"><label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" v-model="form.is_serialized" class="rounded border" /> Serialized</label><label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" v-model="form.is_batch_tracked" class="rounded border" /> Batch Tracked</label><label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" v-model="form.is_active" class="rounded border" /> Active</label></div>
          </div>
        </section>

        <section v-if="activeTab==='suppliers'" class="bg-card border rounded-2xl shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b bg-muted/20 flex items-center justify-between"><h2 class="font-semibold flex items-center gap-2"><Truck class="size-4" /> Suppliers — PARTNER Vendors</h2><span class="text-xs text-muted-foreground">Primary drives PO default</span></div>
          <div class="p-5 space-y-3">
            <div v-for="(s,i) in form.suppliers" :key="i" class="border rounded-xl p-4 space-y-3 relative bg-muted/10">
              <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeSupplier(i)"><Trash2 class="size-3" /></Button>
              <p class="text-xs font-semibold tracking-widest uppercase text-muted-foreground">#{{ i+1 }}</p>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="space-y-1"><Label>Vendor</Label><SearchableSelect v-model="s.partner_id" endpoint="/partner/api/options/vendors" placeholder="Search vendors" clearable /></div>
                <div class="space-y-1"><Label>Supplier SKU</Label><Input v-model="s.supplier_sku" placeholder="Vend-SKU" class="h-9" /></div>
                <div class="space-y-1"><Label>Last Price</Label><Input v-model.number="s.last_purchase_price" type="number" step="0.01" class="h-9 font-mono" /></div>
              </div>
              <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="s.is_primary" class="rounded" /> Primary</label>
            </div>
            <Button type="button" variant="outline" size="sm" @click="addSupplier" class="rounded-full"><Plus class="size-4" /> Add Supplier</Button>
          </div>
        </section>

        <section v-if="activeTab==='variants'" class="bg-card border rounded-2xl shadow-sm overflow-hidden">
          <div class="px-5 py-4 border-b bg-muted/20"><h2 class="font-semibold flex items-center gap-2"><Layers class="size-4" /> Variants — Size/Color</h2></div>
          <div class="p-5 space-y-3">
            <div v-for="(v,i) in form.variants" :key="i" class="border rounded-xl p-4 space-y-3 relative bg-muted/10">
              <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeVariant(i)"><Trash2 class="size-3" /></Button>
              <p class="text-xs font-semibold tracking-widest uppercase text-muted-foreground">#{{ i+1 }}</p>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="space-y-1"><Label>Variant Name *</Label><Input v-model="v.name" placeholder="Red — XL" class="h-9" /></div>
                <div class="space-y-1"><Label>SKU</Label><Input v-model="v.sku" placeholder="SKU-...-RED-XL" class="h-9 font-mono" /></div>
                <div class="space-y-1"><Label>Barcode</Label><Input v-model="v.barcode" class="h-9 font-mono" /></div>
                <div class="space-y-1"><Label>Cost</Label><Input v-model.number="v.cost_price" type="number" step="0.01" class="h-9 font-mono" /></div>
                <div class="space-y-1"><Label>Price</Label><Input v-model.number="v.selling_price" type="number" step="0.01" class="h-9 font-mono" /></div>
              </div>
            </div>
            <Button type="button" variant="outline" size="sm" @click="addVariant" class="rounded-full"><Plus class="size-4" /> Add Variant</Button>
          </div>
        </section>

        <div class="sticky bottom-4 z-10 bg-card/95 backdrop-blur border rounded-2xl p-4 shadow-lg flex flex-wrap items-center gap-3">
          <p class="text-sm text-muted-foreground">Required fields marked * — review tabs before submit.</p>
          <div class="ml-auto flex gap-2">
            <Button type="button" variant="outline" class="rounded-full" @click="$inertia.visit(route('inventory.products.index'))">Cancel</Button>
            <Button type="submit" :disabled="form.processing" class="rounded-full">Create Product</Button>
          </div>
        </div>
      </form>
    </PageHeader>
  </AdminLayout>
</template>
