<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Plus, Trash2, ReceiptText, Percent, Coins } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { computed } from 'vue';

import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const form = useForm({
  vendor_id: null as number | null,
  warehouse_id: null as number | null,
  order_date: new Date().toISOString().slice(0, 10),
  expected_date: '',
  notes: '',
  items: [{ product_id: null as number | null, qty: 1, unit_price: null as number | null, discount_pct: 0, vat_rate: 0 }],
});
useUnsavedGuard(() => form.isDirty);

/*
 * Money is worked out per line and summed, in the same order the ledger will read it: gross, then
 * the discount off that gross, then VAT on the discounted figure. The page used to show one number
 * in a heading and a `text-xs` line under each row, so an operator raising a PO could not see what
 * a line cost, what the order came to before VAT, or how much of it was VAT.
 */
const num = (v: unknown) => Number(v) || 0;
const lineGross = (it: any) => num(it.qty) * num(it.unit_price);
const lineDisc = (it: any) => lineGross(it) * (num(it.discount_pct) / 100);
const lineNet = (it: any) => lineGross(it) - lineDisc(it);
const lineVat = (it: any) => lineNet(it) * (num(it.vat_rate) / 100);
const lineTotal = (it: any) => lineNet(it) + lineVat(it);

const grossTotal = computed(() => form.items.reduce((s, it) => s + lineGross(it), 0));
const discountTotal = computed(() => form.items.reduce((s, it) => s + lineDisc(it), 0));
const netTotal = computed(() => grossTotal.value - discountTotal.value);
const vatTotal = computed(() => form.items.reduce((s, it) => s + lineVat(it), 0));
const grandTotal = computed(() => netTotal.value + vatTotal.value);
const money = (v: number) => v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function addItem() { form.items.push({ product_id: null, qty: 1, unit_price: null, discount_pct: 0, vat_rate: 0 }); }
function removeItem(i: number) { form.items.splice(i, 1); if (!form.items.length) addItem(); }
function submit() { form.post(route('purchase.orders.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'New PO' }]">
    <Head title="New Purchase Order" />
    <div class="space-y-4">
      <PageHeader title="New Purchase Order" />
      <p class="text-sm text-muted-foreground">Vendor → confirm → GRN (stock IN 1200) → Bill (AP 2100). VAT posts to 1330 (input) / 2110 (payable).</p>
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] as string }}</p>

        <div class="bg-card border rounded-xl p-4 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-3 2xl:grid-cols-4 gap-4">
            <div class="space-y-2"><Label>Vendor *</Label><SearchableSelect v-model="form.vendor_id" endpoint="/partner/api/options/vendors" placeholder="Search vendor" /></div>
            <div class="space-y-2"><Label>Warehouse *</Label><SearchableSelect v-model="form.warehouse_id" endpoint="/inventory/api/options/warehouses" placeholder="Receiving warehouse" /></div>
            <div class="space-y-2"><Label>Order Date *</Label><Input v-model="form.order_date" type="date" /></div>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
            <div class="space-y-2"><Label>Expected Date</Label><Input v-model="form.expected_date" type="date" /></div>
            <div class="space-y-2"><Label>Notes</Label><Textarea v-model="form.notes" rows="2" placeholder="Delivery terms..." /></div>
          </div>
        </div>

        <!-- ── Lines ───────────────────────────────────────────────────── -->
        <div class="bg-card border rounded-xl overflow-hidden">
          <div class="flex flex-wrap items-center gap-2 border-b bg-muted/30 px-4 py-3">
            <ReceiptText class="size-4 text-primary" aria-hidden="true" />
            <h3 class="font-semibold text-sm">Items</h3>
            <span class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold text-muted-foreground">
              {{ form.items.length }} line{{ form.items.length === 1 ? '' : 's' }}
            </span>
            <p class="ml-auto font-mono text-sm font-bold tabular-nums">{{ money(grandTotal) }} BDT</p>
          </div>

          <!-- Column headings, only where the row is wide enough to be a table. -->
          <div class="hidden lg:grid grid-cols-[minmax(0,2.2fr)_5.5rem_7.5rem_6rem_6rem_9rem_2.25rem] gap-3 border-b bg-muted/20 px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
            <span>Product</span><span class="text-right">Qty</span><span class="text-right">Unit price</span>
            <span class="text-right">Disc %</span><span class="text-right">VAT %</span>
            <span class="text-right">Line total</span><span aria-hidden="true" />
          </div>

          <div class="divide-y">
            <div
              v-for="(it, i) in form.items"
              :key="i"
              class="relative grid grid-cols-2 gap-3 p-4 transition-colors hover:bg-muted/20 sm:grid-cols-4 lg:grid-cols-[minmax(0,2.2fr)_5.5rem_7.5rem_6rem_6rem_9rem_2.25rem] lg:items-end lg:py-3"
            >
              <div class="col-span-2 space-y-1 sm:col-span-3 lg:col-span-1">
                <Label class="lg:hidden">Product *</Label>
                <SearchableSelect v-model="it.product_id" endpoint="/inventory/api/options/products" placeholder="Search SKU/barcode" />
              </div>
              <div class="space-y-1">
                <Label class="lg:hidden">Qty *</Label>
                <Input v-model.number="it.qty" type="number" step="0.01" min="0.01" class="text-right font-mono" />
              </div>
              <div class="space-y-1">
                <Label class="lg:hidden">Unit price *</Label>
                <Input v-model.number="it.unit_price" type="number" step="0.01" min="0" placeholder="0.00" class="text-right font-mono" />
              </div>
              <div class="space-y-1">
                <Label class="lg:hidden">Disc %</Label>
                <Input v-model.number="it.discount_pct" type="number" step="0.01" min="0" max="100" class="text-right font-mono" />
              </div>
              <div class="space-y-1">
                <Label class="lg:hidden">VAT %</Label>
                <Input v-model.number="it.vat_rate" type="number" step="0.01" min="0" max="100" class="text-right font-mono" />
              </div>

              <!-- The line's own total, so a PO can be read without a calculator. -->
              <div class="space-y-1">
                <Label class="lg:hidden">Line total</Label>
                <div class="grid h-9 place-items-end rounded-md border bg-muted/40 px-2.5 font-mono text-sm font-bold tabular-nums">
                  {{ money(lineTotal(it)) }}
                </div>
              </div>

              <Button
                type="button" variant="ghost" size="icon"
                class="absolute right-2 top-2 size-7 text-muted-foreground hover:text-destructive lg:static lg:mb-0.5 lg:hover:bg-destructive/10"
                :aria-label="`Remove line ${i + 1}`" @click="removeItem(i)"
              ><Trash2 class="size-3.5" /></Button>
            </div>
          </div>

          <div class="border-t bg-muted/20 px-4 py-3">
            <Button type="button" variant="outline" size="sm" @click="addItem"><Plus class="size-4" /> Add Item</Button>
          </div>
        </div>

        <!-- ── Totals ──────────────────────────────────────────────────── -->
        <div class="bg-card border rounded-xl p-4">
          <div class="ml-auto w-full max-w-sm space-y-2 text-sm">
            <div class="flex items-baseline justify-between gap-3">
              <span class="text-muted-foreground">Subtotal (gross)</span>
              <span class="font-mono tabular-nums">{{ money(grossTotal) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-3">
              <span class="flex items-center gap-1 text-muted-foreground"><Percent class="size-3" aria-hidden="true" /> Discount</span>
              <span class="font-mono tabular-nums text-emerald-700 dark:text-emerald-400">−{{ money(discountTotal) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-3">
              <span class="flex items-center gap-1 text-muted-foreground"><Coins class="size-3" aria-hidden="true" /> VAT</span>
              <span class="font-mono tabular-nums text-amber-700 dark:text-amber-400">+{{ money(vatTotal) }}</span>
            </div>
            <div class="mt-1 flex items-baseline justify-between gap-3 border-t pt-2.5">
              <span class="font-semibold">Total</span>
              <span class="font-mono text-xl font-bold tabular-nums">{{ money(grandTotal) }} <span class="text-xs font-semibold text-muted-foreground">BDT</span></span>
            </div>
            <p class="text-xs text-muted-foreground">Net of discount {{ money(netTotal) }} · VAT posts to 1330 (input) and 2110 (payable) when the bill is created.</p>
          </div>
        </div>

        <Button type="submit" size="lg" :disabled="form.processing">
          Create PO — {{ money(grandTotal) }} BDT
        </Button>
      </form>
    </div>
  </AdminLayout>
</template>
