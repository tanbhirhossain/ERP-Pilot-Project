<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import { printDocument } from '../../../../../CORE/Resources/js/Composables/usePrint';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ref, computed } from 'vue';

const props = defineProps<{ products: any[]; presets: any[] }>();
const selectedId = ref<number|null>(null);
const cart = ref<{product:any, qty:number}[]>([]);
const cols = ref(3);
const paper = ref('a4');

function addToCart(){
  if(!selectedId.value) return;
  const p = props.products.find(x=>x.id===selectedId.value);
  if(!p) return;
  const ex = cart.value.find(c=>c.product.id===p.id);
  if(ex) ex.qty++;
  else cart.value.push({product:p, qty:1});
  selectedId.value=null;
}
function remove(i:number){ cart.value.splice(i,1); }
const totalLabels = computed(()=> cart.value.reduce((s,c)=>s+c.qty,0));
const expanded = computed(()=>{
  const out:any[]=[]; cart.value.forEach(c=>{ for(let i=0;i<c.qty;i++) out.push(c.product); }); return out;
});

function printBrowser(){ printDocument({ portrait: true }); }

function downloadPdf(){
  router.post(route('inventory.products.barcode-pdf'), { items: cart.value.map(c=>({product_id:c.product.id, qty:c.qty})), cols: cols.value, paper: paper.value }, { onSuccess: ()=>{} });
}
function downloadZpl(){
  // trigger via form
  const form = document.createElement('form');
  form.method='POST'; form.action=route('inventory.products.barcode-zpl');
  form.style.display='none';
  const csrf = (document.querySelector('meta[name="csrf-token"]') as any)?.content || '';
  const tok=document.createElement('input'); tok.name='_token'; tok.value=csrf; form.appendChild(tok);
  cart.value.forEach((c, idx)=>{
    const i1=document.createElement('input'); i1.name=`items[${idx}][product_id]`; i1.value=c.product.id; form.appendChild(i1);
    const i2=document.createElement('input'); i2.name=`items[${idx}][qty]`; i2.value=String(c.qty); form.appendChild(i2);
  });
  document.body.appendChild(form); form.submit(); form.remove();
}

// simple code128-like visual (not scannable but preview)
function barcodePattern(code:string){
  let h=0; for(const ch of code) h=(h*31+ch.charCodeAt(0))%99999;
  const bars=[]; for(let i=0;i<code.length*6;i++){ const v=(h+i*37)%100; bars.push(v<50?2:1, v%3===0?3:1); }
  return bars.slice(0, 60);
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Products', href: route('inventory.products.index') }, { title: 'Barcode Labels' }]">
    <Head title="Barcode Labels" />
    <div class="space-y-4 max-w-7xl mx-auto print:max-w-none">
      <div class="flex items-center justify-between gap-3 print:hidden">
        <div><h1 class="text-xl font-bold flex items-center gap-2">🏷️ Barcode Label Print <span class="text-xs font-normal px-2 py-1 rounded bg-emerald-100 text-emerald-800">milon/barcode C128 + ZPL</span></h1>
        <p class="text-sm text-muted-foreground">Pick products → qty per SKU → preview → Print (browser) / PDF (dompdf) / ZPL (Zebra). Barcodes use <code>barcode</code> if present else <code>sku</code>.</p></div>
        <div class="flex gap-2">
          <Button variant="outline" @click="printBrowser">🖨️ Print (Browser)</Button>
          <Button @click="downloadPdf" :disabled="!cart.length">📄 Download PDF</Button>
          <Button variant="outline" @click="downloadZpl" :disabled="!cart.length">🖨️ ZPL (Zebra)</Button>
        </div>
      </div>

      <div class="grid lg:grid-cols-12 gap-4 print:hidden">
        <div class="lg:col-span-5 bg-card border rounded-xl p-4 space-y-3">
          <h3 class="font-semibold">1) Add products</h3>
          <div class="flex gap-2">
            <div class="flex-1">
              <SearchableSelect v-model="selectedId" :options="products.map(p=>({value:p.id, label: `${p.name} — ${p.sku} ${p.barcode?'('+p.barcode+')':''} — ${p.selling_price} BDT`}))" placeholder="Search by name / SKU / barcode..." />
            </div>
            <Button @click="addToCart" :disabled="!selectedId">Add</Button>
          </div>
          <div class="flex gap-3 items-end">
            <div class="space-y-1"><Label>Layout</Label>
              <select v-model="cols" class="h-9 rounded-md border bg-background px-3 text-sm">
                <option :value="3">A4 3×8 (70×33mm)</option><option :value="4">A4 4×10 (50×25mm)</option><option :value="2">A4 2×6</option><option :value="1">Thermal roll 1-col</option>
              </select>
            </div>
            <div class="space-y-1"><Label>Paper</Label>
              <select v-model="paper" class="h-9 rounded-md border bg-background px-3 text-sm"><option value="a4">A4</option><option value="thermal">Thermal</option></select>
            </div>
            <span class="text-xs text-muted-foreground">Total labels: <b class="text-foreground">{{ totalLabels }}</b> · {{ cart.length }} SKUs</span>
          </div>
          <div v-if="!cart.length" class="text-sm text-muted-foreground py-6 text-center border border-dashed rounded-lg">No items yet — search a product above and click Add. Try “Fabric”.</div>
          <div v-for="(c, i) in cart" :key="c.product.id" class="flex items-center gap-2 border rounded-lg p-2">
            <div class="flex-1 min-w-0"><div class="font-medium truncate">{{ c.product.name }}</div><div class="text-xs text-muted-foreground">{{ c.product.sku }} {{ c.product.barcode?'· '+c.product.barcode:'' }} · {{ c.product.selling_price }} BDT</div></div>
            <Input type="number" min="1" max="100" v-model.number="c.qty" class="w-16 h-8" />
            <Button variant="ghost" size="sm" @click="remove(i)">✕</Button>
          </div>
        </div>
        <div class="lg:col-span-7 bg-card border rounded-xl p-4">
          <h3 class="font-semibold mb-2">2) Preview — {{ paper.toUpperCase() }} · {{ cols }} cols · {{ totalLabels }} labels</h3>
          <div class="bg-white p-3 rounded border print:p-0 print:border-0 print-portrait print-keep-color" id="label-preview" data-core-print>
            <div :style="`display:grid; grid-template-columns: repeat(${cols}, 1fr); gap: 8px;`">
              <div v-for="(p, idx) in expanded" :key="idx" class="border border-dashed rounded p-2 text-center bg-white">
                <div class="text-[8px] font-bold truncate">{{ p.name }}</div>
                <div class="flex justify-center items-end my-1 h-8 gap-px">
                  <div v-for="(w, bi) in barcodePattern(p.barcode || p.sku)" :key="bi" :style="`width:${w}px; height:${14 + (bi%3)*6}px; background:${w>1?'#111':'#fff'}; display:inline-block;`"></div>
                </div>
                <div class="text-[7px] text-muted-foreground">{{ p.barcode || p.sku }}</div>
                <div class="text-[8px] font-bold">{{ Number(p.selling_price).toFixed(2) }} BDT</div>
              </div>
            </div>
            <div v-if="!expanded.length" class="py-12 text-center text-sm text-muted-foreground">Preview empty — labels appear here.</div>
          </div>
          <p class="text-xs text-muted-foreground mt-2">Preview uses CSS bars — actual PDF uses <code>milon/barcode C128</code> PNG (scannable). Use Download PDF for real barcodes; ZPL for Zebra label printers.</p>
        </div>
      </div>

      <!-- Print-only grid (clean) -->
      <div class="hidden print:block bg-white p-2">
        <div :style="`display:grid; grid-template-columns: repeat(${cols}, 1fr); gap: 6px;`">
          <div v-for="(p, idx) in expanded" :key="'pr'+idx" class="border border-dashed rounded p-2 text-center">
            <div class="text-[9px] font-bold truncate">{{ p.name }}</div>
            <div class="flex justify-center gap-px my-1"><div v-for="(w, bi) in barcodePattern(p.barcode || p.sku)" :key="bi" :style="`width:${w}px; height:18px; background:#000; display:inline-block;`"></div></div>
            <div class="text-[8px]">{{ p.barcode || p.sku }} · {{ Number(p.selling_price).toFixed(2) }} BDT</div>
          </div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
<!--
  Was: a global `@media print` block hiding `body *` and revealing only
  `#label-preview`. Unscoped styles survive navigation in an SPA, so opening
  Barcode Labels made every other page print blank for the rest of the
  session. `data-core-print` + print.css do the same job without the leak,
  and `print-keep-color` keeps the bars solid black on paper.
-->
