<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ShoppingCart, Scan, Trash2, CreditCard, Banknote, Wallet, Smartphone, Percent, AlertCircle, CheckCircle, Package } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import { ref, computed, watch, onMounted } from 'vue';

const props = defineProps<{ warehouses:any[]; customers:any[]; banks:any[]; openSession:any }>();

const warehouseId = ref<number | null>(null);
const customerId = ref<number | null>(null);
const paymentMethod = ref('cash');
const bankId = ref<number | null>(null);
const sessionId = ref<number | null>(null);
const cart = ref<any[]>([]);
const searchProduct = ref<number | null>(null);
const barcodeInput = ref('');
const barcodeRef = ref<HTMLInputElement | null>(null);
const cashOpening = ref(0);
const cashClosing = ref(0);
const cashAmt = ref<number | null>(null);
const bankAmt = ref<number | null>(null);
const lastScanned = ref<string>('');
const scanError = ref('');
const adding = ref(false);

// flash
const page = usePage() as any;

function lineGross(it: any) { return (Number(it.qty) || 0) * (Number(it.unit_price) || 0); }
function lineDisc(it: any) { return lineGross(it) * ((Number(it.discount_pct) || 0) / 100); }
function lineNet(it: any) { return lineGross(it) - lineDisc(it); }
function lineVat(it: any) { return lineNet(it) * ((Number(it.vat_rate) || 0) / 100); }
function lineTotal(it: any) { return lineNet(it) + lineVat(it); }

const subtotal = computed(() => cart.value.reduce((s, it) => s + lineGross(it), 0));
const discountTotal = computed(() => cart.value.reduce((s, it) => s + lineDisc(it), 0));
const vatTotal = computed(() => cart.value.reduce((s, it) => s + lineVat(it), 0));
const grandTotal = computed(() => subtotal.value - discountTotal.value + vatTotal.value);

const splitValid = computed(() => {
  if (paymentMethod.value !== 'split') return true;
  const c = Number(cashAmt.value) || 0, b = Number(bankAmt.value) || 0;
  return Math.abs((c + b) - grandTotal.value) < 0.02;
});
const needsBank = computed(() => ['bank','bkash','card','split'].includes(paymentMethod.value));
const canSell = computed(() => warehouseId.value && cart.value.length > 0 && (paymentMethod.value !== 'split' || splitValid.value));

async function fetchProduct(key: string | number) {
  if (!key) return null;
  try {
    const url = `/inventory/api/pos-product/${encodeURIComponent(String(key))}${warehouseId.value ? `?warehouse_id=${warehouseId.value}` : ''}`;
    const res = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const j = await res.json();
    return j.found ? j.data : null;
  } catch { return null; }
}

async function addItem() {
  if (!searchProduct.value) return;
  adding.value = true;
  const data = await fetchProduct(searchProduct.value);
  if (data) {
    const existing = cart.value.find(c => c.product_id === data.id);
    if (existing) { existing.qty = Number(existing.qty) + 1; }
    else {
      cart.value.push({
        product_id: data.id, product_name: data.name, sku: data.sku, barcode: data.barcode,
        qty: 1, unit_price: Number(data.selling_price), discount_pct: 0, vat_rate: Number(data.vat_rate) || 0,
        available: data.available,
      });
    }
    lastScanned.value = `${data.name} (${data.sku})`;
  } else {
    cart.value.push({ product_id: searchProduct.value, qty: 1, unit_price: 0, discount_pct: 0, vat_rate: 0 });
  }
  searchProduct.value = null;
  scanError.value = '';
  adding.value = false;
}

async function scanBarcode() {
  const code = barcodeInput.value.trim();
  if (!code) return;
  adding.value = true;
  scanError.value = '';
  const data = await fetchProduct(code);
  if (!data) { scanError.value = `No product for barcode/SKU "${code}"`; adding.value = false; return; }
  const existing = cart.value.find(c => c.product_id === data.id);
  if (existing) existing.qty = Number(existing.qty) + 1;
  else cart.value.push({
    product_id: data.id, product_name: data.name, sku: data.sku, barcode: data.barcode,
    qty: 1, unit_price: Number(data.selling_price), discount_pct: 0, vat_rate: Number(data.vat_rate) || 0, available: data.available,
  });
  lastScanned.value = `${data.name} — ${Number(data.selling_price).toFixed(2)} BDT`;
  barcodeInput.value = '';
  adding.value = false;
  setTimeout(() => barcodeRef.value?.focus(), 50);
}

function remove(i: number) { cart.value.splice(i, 1); }

watch(paymentMethod, (v) => {
  if (v === 'split' && (cashAmt.value === null && bankAmt.value === null)) {
    // default split: 50/50 suggestion
    cashAmt.value = Math.round(grandTotal.value / 2);
    bankAmt.value = Math.round(grandTotal.value - cashAmt.value);
  }
});
watch(grandTotal, (t) => {
  if (paymentMethod.value === 'split' && cart.value.length === 1) {
    // auto sync first time
  }
});

function sell() {
  const payload: any = {
    warehouse_id: warehouseId.value,
    customer_id: customerId.value,
    customer_name: 'Walk-in Customer',
    payment_method: paymentMethod.value,
    bank_id: bankId.value,
    session_id: sessionId.value ?? props.openSession?.id ?? null,
    items: cart.value.map(it => ({
      product_id: it.product_id, variant_id: it.variant_id ?? null,
      qty: Number(it.qty), unit_price: Number(it.unit_price),
      discount_pct: Number(it.discount_pct) || 0, vat_rate: Number(it.vat_rate) || 0,
    })),
  };
  if (paymentMethod.value === 'split') {
    payload.cash_amount = Number(cashAmt.value) || 0;
    payload.bank_amount = Number(bankAmt.value) || 0;
  }
  router.post(route('sales.pos.sell'), payload);
}

function openSessionFn() { router.post(route('sales.pos.sessions.open'), { warehouse_id: warehouseId.value, opening_cash: cashOpening.value }); }
function closeSessionFn() { if (!sessionId.value && props.openSession) sessionId.value = props.openSession.id; if (!sessionId.value) return; router.post(route('sales.pos.sessions.close', sessionId.value), { closing_cash: cashClosing.value }); }

onMounted(() => { if (props.openSession) sessionId.value = props.openSession.id; setTimeout(() => barcodeRef.value?.focus(), 400); });
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales' }, { title: 'POS Terminal' }]">
    <Head title="POS Terminal — Chattogram" />
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
      <div class="lg:col-span-2 space-y-4">
        <div class="bg-card border rounded-xl p-4">
          <h1 class="text-xl font-bold flex items-center gap-2"><ShoppingCart class="size-5 text-primary" /> POS Terminal — Scan, Sell, Print</h1>
          <p class="text-sm text-muted-foreground">Barcode scan → VAT (2110) → bKash/Bank/Split → Stock & ledger posted instantly. Chattogram store ready.</p>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
            <div class="space-y-1"><Label>Warehouse *</Label><SearchableSelect v-model="warehouseId" :options="warehouses" placeholder="Select warehouse" /></div>
            <div class="space-y-1"><Label>Customer</Label><SearchableSelect v-model="customerId" :options="customers" placeholder="Walk-in" clearable /></div>
            <div class="space-y-1">
              <Label>Payment</Label>
              <select v-model="paymentMethod" class="w-full h-9 rounded-md border bg-background px-3 text-sm">
                <option value="cash">Cash</option>
                <option value="bank">Bank</option>
                <option value="bkash">bKash</option>
                <option value="card">Card</option>
                <option value="split">Split (Cash+Bank)</option>
              </select>
            </div>
          </div>
          <div v-if="needsBank" class="mt-3"><Label>Bank / Wallet</Label><SearchableSelect v-model="bankId" :options="banks" placeholder="Select bank (Prime Bank) — optional for bKash" clearable /></div>
          <!-- split amounts -->
          <div v-if="paymentMethod==='split'" class="grid grid-cols-2 gap-3 mt-3 p-3 bg-amber-500/10 border border-amber-500/20 rounded-lg">
            <div class="space-y-1"><Label>Cash amount</Label><Input v-model.number="cashAmt" type="number" step="0.01" placeholder="0.00" /></div>
            <div class="space-y-1"><Label>Bank amount</Label><Input v-model.number="bankAmt" type="number" step="0.01" placeholder="0.00" /></div>
            <p v-if="!splitValid" class="col-span-2 text-xs text-amber-700 flex items-center gap-1"><AlertCircle class="size-3"/> Cash + Bank ({{ ((Number(cashAmt)||0)+(Number(bankAmt)||0)).toFixed(2) }}) must equal total {{ grandTotal.toFixed(2) }} BDT</p>
            <p v-else class="col-span-2 text-xs text-emerald-700 flex items-center gap-1"><CheckCircle class="size-3"/> Split balanced</p>
          </div>
        </div>

        <div class="bg-card border rounded-xl p-4 space-y-3">
          <!-- barcode scanner -->
          <div class="flex gap-2">
            <div class="flex-1 relative">
              <Scan class="absolute left-2.5 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
              <Input ref="barcodeRef" v-model="barcodeInput" placeholder="Scan barcode / SKU → Enter (880…)" class="pl-8 font-mono" @keydown.enter.prevent="scanBarcode" />
            </div>
            <Button variant="outline" @click="scanBarcode" :disabled="!barcodeInput.trim() || adding"><Scan class="size-4" />Scan</Button>
          </div>
          <div class="flex gap-2">
            <div class="flex-1"><SearchableSelect v-model="searchProduct" endpoint="/inventory/api/options/products" placeholder="Or search product name/SKU → Add" /></div>
            <Button @click="addItem" :disabled="!searchProduct"><Package class="size-4" />Add</Button>
          </div>
          <div v-if="lastScanned" class="text-xs text-emerald-700 bg-emerald-500/10 border border-emerald-500/20 rounded px-2 py-1">✓ {{ lastScanned }}</div>
          <div v-if="scanError" class="text-xs text-red-600 bg-red-500/10 border border-red-500/20 rounded px-2 py-1">{{ scanError }}</div>

          <div v-if="!cart.length" class="py-10 text-center text-muted-foreground border-2 border-dashed rounded-xl text-sm">No items — scan barcode or search to add. VAT & totals update live.</div>
          <div v-for="(it, i) in cart" :key="i" class="border rounded-lg p-3 bg-muted/10 space-y-2">
            <div class="flex justify-between items-start gap-2">
              <div class="min-w-0">
                <div class="font-semibold text-sm truncate">{{ it.product_name || `Product #${it.product_id}` }}</div>
                <div class="text-xs text-muted-foreground font-mono">{{ it.sku || '' }} <span v-if="it.barcode">· BC {{ it.barcode }}</span> <span v-if="it.available!==undefined" class="ml-1">· Avl {{ it.available }}</span></div>
              </div>
              <Button variant="ghost" size="icon" class="size-6 shrink-0" @click="remove(i)"><Trash2 class="size-3" /></Button>
            </div>
            <div class="grid grid-cols-5 gap-2">
              <div><Label class="text-xs">Qty</Label><Input v-model.number="it.qty" type="number" step="0.01" min="0.01" class="h-8" /></div>
              <div><Label class="text-xs">Price</Label><Input v-model.number="it.unit_price" type="number" step="0.01" class="h-8" /></div>
              <div><Label class="text-xs">Disc%</Label><Input v-model.number="it.discount_pct" type="number" step="0.01" min="0" max="100" class="h-8" /></div>
              <div><Label class="text-xs">VAT%</Label><Input v-model.number="it.vat_rate" type="number" step="0.01" min="0" max="100" class="h-8" /></div>
              <div><Label class="text-xs">Total</Label><div class="h-8 grid place-items-center font-mono font-bold text-sm bg-card border rounded">{{ lineTotal(it).toFixed(2) }}</div></div>
            </div>
          </div>
        </div>
      </div>

      <div class="space-y-4">
        <div class="bg-card border rounded-xl p-4 space-y-3">
          <h3 class="font-bold flex items-center gap-2"><Wallet class="size-4" /> Session</h3>
          <div v-if="openSession" class="p-2 bg-emerald-500/10 border border-emerald-500/20 rounded text-sm">Open: {{ openSession.session_no }} · {{ openSession.opening_cash }} BDT <button @click="sessionId = openSession.id" class="ml-2 text-xs underline">Use</button></div>
          <div class="flex gap-2"><Input v-model.number="cashOpening" type="number" placeholder="Opening cash" /><Button size="sm" @click="openSessionFn">Open</Button></div>
          <div class="flex gap-2"><Input v-model.number="cashClosing" type="number" placeholder="Closing cash" /><Button size="sm" variant="outline" @click="closeSessionFn">Close</Button></div>
        </div>

        <div class="bg-card border rounded-xl p-4 space-y-2">
          <h3 class="font-bold">Breakdown</h3>
          <div class="space-y-1 text-sm">
            <div class="flex justify-between"><span class="text-muted-foreground">Subtotal (gross)</span><span class="font-mono">{{ subtotal.toFixed(2) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground flex items-center gap-1"><Percent class="size-3"/> Discount</span><span class="font-mono">-{{ discountTotal.toFixed(2) }}</span></div>
            <div class="flex justify-between"><span class="text-muted-foreground flex items-center gap-1"><Percent class="size-3"/> VAT (2110 VAT Payable)</span><span class="font-mono text-amber-700">+{{ vatTotal.toFixed(2) }}</span></div>
            <div class="flex justify-between font-bold border-t pt-1 mt-1"><span>Total</span><span class="font-mono">{{ grandTotal.toFixed(2) }} BDT</span></div>
            <div class="text-xs text-muted-foreground">{{ cart.length }} line(s) · VAT posts to 2110 on invoice</div>
          </div>
        </div>

        <div class="bg-primary text-primary-foreground rounded-xl p-4 space-y-3">
          <h3 class="font-bold text-lg">Total to Pay</h3>
          <p class="text-3xl font-bold font-mono">{{ grandTotal.toFixed(2) }} BDT</p>
          <div class="text-sm opacity-90">{{ cart.length }} item(s) · {{ paymentMethod }}</div>
          <Button class="w-full bg-white text-primary hover:bg-white/90" size="lg" @click="sell" :disabled="!canSell">
            <template v-if="paymentMethod === 'cash'"><Banknote class="size-5" />Cash Sale</template>
            <template v-else-if="paymentMethod === 'bank'"><CreditCard class="size-5" />Bank Sale</template>
            <template v-else-if="paymentMethod === 'bkash'"><Smartphone class="size-5" />bKash Sale</template>
            <template v-else-if="paymentMethod === 'card'"><CreditCard class="size-5" />Card Sale</template>
            <template v-else><Wallet class="size-5" />Sell — Split</template>
          </Button>
          <p class="text-xs opacity-75">Stock deducted & journal posted (SV). Receipt auto-printed. COGS 5001 / Inv 1200.</p>
          <div v-if="page.props?.flash?.error" class="bg-red-600 text-white rounded p-2 text-xs">{{ page.props.flash.error }}</div>
          <div v-if="page.props?.flash?.success" class="bg-white/20 rounded p-2 text-xs">{{ page.props.flash.success }}</div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
