<script setup lang="ts">
/**
 * One POS sale: what was rung up, what was taken, what is still owed — and where the money went.
 *
 * The collection panel is the reason this page exists beyond a printout. A credit sale at the till is
 * settled later, at whichever drawer the customer walks up to, and that payment has to be recorded
 * against the sale *and* land in the collecting cashier's day (report item 39). Without a place to
 * take it, the due would sit in the receivables ledger with no counter-facing way to clear it.
 *
 * Voiding is refused by the service once the drawer has closed, so the button is hidden then and the
 * page points at a sales return instead: the day is already in the ledger, and undoing one sale in it
 * would put the stock back and leave the money booked.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, Ban, Banknote, CreditCard, FileText, HandCoins, Printer, Receipt, Smartphone, Wallet } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { confirmDialog } from '@core/Composables/useConfirm';
import { formatCurrency, formatDate } from '@core/Utils/format';

const props = defineProps<{
  sale: any;
  banks?: { value: number; label: string }[];
  openSession?: { id: number; session_no: string; cashier?: string } | null;
}>();

const money = (value: unknown): number => Number(value ?? 0);

const due = computed(() => money(props.sale.due_amount));
const collected = computed(() => money(props.sale.collected_amount));
const outstanding = computed(() => Math.max(0, Math.round((due.value - collected.value) * 100) / 100));
const isVoid = computed(() => props.sale.status === 'void');
const drawerClosed = computed(() => props.sale.session?.status === 'closed');
const canCollect = computed(() => !isVoid.value && outstanding.value > 0.005 && !!props.openSession);

const methods = [
  { value: 'cash', label: 'Cash', icon: Banknote },
  { value: 'bank', label: 'Bank', icon: CreditCard },
  { value: 'bkash', label: 'bKash', icon: Smartphone },
  { value: 'card', label: 'Card', icon: CreditCard },
] as const;

const form = useForm({
  amount: outstanding.value > 0 ? outstanding.value.toFixed(2) : '',
  method: 'cash' as string,
  bank_id: null as number | null,
  notes: '',
});

const methodNeedsBank = computed(() => form.method !== 'cash');

function collect() {
  if (!canCollect.value) return;

  form.post(route('sales.pos.collect', props.sale.id), {
    preserveScroll: true,
    onSuccess: () => {
      form.amount = '';
      form.notes = '';
      form.bank_id = null;
      form.method = 'cash';
    },
  });
}

async function voidSale() {
  const confirmed = await confirmDialog({
    title: `Void ${props.sale.pos_no}?`,
    description: drawerClosed.value
      ? 'This drawer has closed and its day is already in the ledger, so the sale cannot be voided. Raise a sales return instead.'
      : 'The stock goes back on the shelf and this sale drops out of the day. Nothing is reversed in the ledger, because the day has not posted yet — it posts once, when this drawer closes.',
    confirmLabel: drawerClosed.value ? 'Cannot void' : 'Void sale',
    cancelLabel: 'Keep it',
    danger: true,
  });

  if (!confirmed || drawerClosed.value) return;

  router.post(route('sales.pos.void', props.sale.id));
}

const payLabels: Record<string, string> = {
  cash: 'Cash', bank: 'Bank', bkash: 'bKash', card: 'Card', split: 'Split (cash + bank)', due: 'Due — credit sale',
};
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'POS History', href: route('sales.pos.history') }, { title: sale.pos_no }]">
    <Head :title="sale.pos_no" />

    <div class="mb-3 flex flex-wrap gap-2">
      <Link :href="route('sales.pos.history')"><Button variant="outline" size="sm"><ArrowLeft class="size-4" />History</Button></Link>
      <Link :href="route('sales.pos.receipt', sale.id)"><Button variant="outline" size="sm"><Printer class="size-4" />Receipt</Button></Link>
      <Link v-if="sale.session" :href="route('sales.pos.sessions.dayEnd', { session: sale.session.id })">
        <Button variant="outline" size="sm"><FileText class="size-4" />Day-end {{ sale.session.session_no }}</Button>
      </Link>
      <Button v-if="!isVoid && !drawerClosed" variant="destructive" size="sm" @click="voidSale"><Ban class="size-4" />Void</Button>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <div class="space-y-4 lg:col-span-2">
        <div class="rounded-xl border bg-card p-4">
          <div class="flex flex-wrap items-center gap-2">
            <h1 class="flex items-center gap-2 text-xl font-bold"><Receipt class="size-5 text-primary" />{{ sale.pos_no }}</h1>
            <StatusBadge :label="sale.status" :tone="sale.status === 'completed' ? 'success' : 'danger'" />
            <span class="rounded bg-muted px-1.5 py-0.5 text-xs">{{ payLabels[sale.payment_method] ?? sale.payment_method }}</span>
          </div>
          <p class="mt-1 text-sm text-muted-foreground">
            {{ sale.customer?.name ?? sale.customer_name ?? 'Walk-in Customer' }} ·
            {{ formatDate(sale.sale_date) }} ·
            {{ sale.warehouse?.name ?? '—' }}
          </p>
          <p v-if="sale.session" class="mt-1 text-xs text-muted-foreground">
            Drawer {{ sale.session.session_no }}
            <span v-if="sale.session.journal?.journal_no"> · posted in {{ sale.session.journal.journal_no }}</span>
            <span v-else-if="sale.session.status === 'open'"> · open, posts at the close</span>
          </p>

          <div class="mt-3 divide-y text-sm">
            <div class="flex justify-between py-1"><span class="text-muted-foreground">Subtotal</span><span class="font-mono">{{ formatCurrency(money(sale.subtotal)) }}</span></div>
            <div v-if="money(sale.discount_amount) > 0" class="flex justify-between py-1"><span class="text-muted-foreground">Discount</span><span class="font-mono">-{{ formatCurrency(money(sale.discount_amount)) }}</span></div>
            <div v-if="money(sale.vat_amount) > 0" class="flex justify-between py-1"><span class="text-muted-foreground">VAT</span><span class="font-mono">{{ formatCurrency(money(sale.vat_amount)) }}</span></div>
            <div class="flex justify-between py-1 font-bold"><span>Total</span><span class="font-mono">{{ formatCurrency(money(sale.total_amount)) }}</span></div>
            <div class="flex justify-between py-1"><span class="text-muted-foreground">Paid at the counter</span><span class="font-mono">{{ formatCurrency(money(sale.paid_amount)) }}</span></div>
            <template v-if="due > 0">
              <div class="flex justify-between py-1"><span class="text-muted-foreground">Sold on credit</span><span class="font-mono">{{ formatCurrency(due) }}</span></div>
              <div class="flex justify-between py-1"><span class="text-muted-foreground">Collected since</span><span class="font-mono">{{ formatCurrency(collected) }}</span></div>
              <div class="flex justify-between py-1 font-bold" :class="outstanding > 0 ? 'text-amber-600' : 'text-emerald-600'">
                <span>Outstanding</span><span class="font-mono">{{ formatCurrency(outstanding) }}</span>
              </div>
            </template>
          </div>
        </div>

        <div class="rounded-xl border bg-card p-4">
          <h3 class="mb-2 font-semibold">Items</h3>
          <div v-for="it in sale.items" :key="it.id" class="flex justify-between border-b py-1.5 text-sm last:border-0">
            <span>
              <span class="font-medium">{{ it.product_name }}</span>
              <span class="text-xs text-muted-foreground"> × {{ it.qty }} @ {{ formatCurrency(money(it.unit_price)) }}</span>
              <span v-if="money(it.discount_pct) > 0" class="text-xs text-muted-foreground"> · {{ it.discount_pct }}% off</span>
            </span>
            <span class="font-mono">{{ formatCurrency(money(it.line_total)) }}</span>
          </div>
          <p class="mt-2 text-xs text-muted-foreground">
            Cost of goods {{ formatCurrency(money(sale.cost_total)) }} — snapshotted when the stock left the shelf, and posted to COGS 5001 at the close.
          </p>
        </div>

        <div v-if="sale.collections?.length" class="rounded-xl border bg-card p-4">
          <h3 class="mb-2 font-semibold">Collections against this sale</h3>
          <div v-for="c in sale.collections" :key="c.id" class="flex flex-wrap justify-between gap-1 border-b py-1.5 text-sm last:border-0">
            <span class="font-mono text-xs">{{ c.collection_no }}</span>
            <span class="text-xs text-muted-foreground">{{ formatDate(c.collected_at) }} · {{ payLabels[c.method] ?? c.method }} · drawer {{ c.session?.session_no ?? c.session_id }}</span>
            <span class="font-mono font-medium">{{ formatCurrency(money(c.amount)) }}</span>
          </div>
        </div>
      </div>

      <div class="space-y-4">
        <div class="rounded-xl border bg-card p-4" :class="canCollect ? 'border-primary/40' : ''">
          <h3 class="flex items-center gap-2 font-semibold"><HandCoins class="size-4 text-primary" />Collect a due</h3>

          <template v-if="canCollect">
            <p class="mt-1 text-xs text-muted-foreground">
              Into drawer {{ openSession?.session_no }}<span v-if="openSession?.cashier"> ({{ openSession.cashier }})</span> — so the money joins that cashier's day and that day's journal.
            </p>

            <div class="mt-3 space-y-3">
              <div class="space-y-1">
                <Label for="collect-amount">Amount</Label>
                <Input id="collect-amount" v-model="form.amount" type="number" step="0.01" min="0.01" :max="outstanding" />
                <p class="text-xs text-muted-foreground">Outstanding {{ formatCurrency(outstanding) }}</p>
                <p v-if="form.errors.amount" class="text-xs text-destructive">{{ form.errors.amount }}</p>
              </div>

              <div class="space-y-1">
                <Label>Taken as</Label>
                <div class="grid grid-cols-2 gap-2">
                  <button
                    v-for="m in methods"
                    :key="m.value"
                    type="button"
                    class="flex items-center justify-center gap-1.5 rounded-md border px-2 py-2 text-sm transition"
                    :class="form.method === m.value ? 'border-primary bg-primary/10 font-semibold text-primary' : 'hover:bg-muted'"
                    @click="form.method = m.value"
                  >
                    <component :is="m.icon" class="size-4" />{{ m.label }}
                  </button>
                </div>
              </div>

              <div v-if="methodNeedsBank" class="space-y-1">
                <Label>Settlement account</Label>
                <SearchableSelect v-model="form.bank_id" :options="banks ?? []" placeholder="Which bank or wallet settled it" clearable />
                <p v-if="form.errors.bank_id" class="text-xs text-destructive">{{ form.errors.bank_id }}</p>
                <p v-else class="text-xs text-muted-foreground">Left empty, it settles into Cash in Hand (1001).</p>
              </div>

              <div class="space-y-1">
                <Label for="collect-notes">Note</Label>
                <Input id="collect-notes" v-model="form.notes" placeholder="Optional" />
              </div>

              <Button class="w-full" :disabled="form.processing" @click="collect">
                <Wallet class="size-4" />{{ form.processing ? 'Recording…' : `Collect ${form.amount || '0.00'}` }}
              </Button>
            </div>
          </template>

          <p v-else-if="due <= 0" class="mt-1 text-sm text-muted-foreground">This sale was settled at the counter — there is nothing to collect.</p>
          <p v-else-if="outstanding <= 0" class="mt-1 text-sm text-emerald-600">Fully collected.</p>
          <p v-else-if="isVoid" class="mt-1 text-sm text-muted-foreground">This sale was voided.</p>
          <p v-else class="mt-1 text-sm text-amber-600">
            No drawer is open for {{ sale.warehouse?.name ?? 'this warehouse' }}. Open a session at the till first: a collection has to join a day that has not posted yet.
          </p>
        </div>

        <div v-if="drawerClosed || isVoid" class="rounded-xl border bg-card p-4">
          <h3 class="font-semibold">Voiding</h3>
          <p class="mt-1 text-xs text-muted-foreground">
            {{ isVoid
              ? 'This sale is void: the stock went back on the shelf and it counts as a void on the day, not as a transaction.'
              : 'This drawer has closed and its day is posted, so the sale cannot be voided — undoing it here would return the stock and leave the revenue, VAT and cash in the books. Raise a sales return in the current session instead.' }}
          </p>
          <Link v-if="!isVoid" :href="route('sales.returns.create')" class="mt-2 inline-block">
            <Button variant="outline" size="sm"><FileText class="size-4" />Sales return</Button>
          </Link>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
