<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { HandCoins, Plus, Landmark, BadgeCheck, FileCheck2, Percent } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { formatCurrency } from '@core/Utils/format';
import { promptDialog } from '@core/Composables/useConfirm';
import { useLocale } from '@core/Composables/useLocale';
import type { SelectOption } from '@core/types';
import type { Withholding, WithholdingRule, TaxDeposit } from '@accounts/types';

const props = defineProps<{
    tab: string;
    pending: { tds: number; vds: number; count: number };
    rules: WithholdingRule[];
    inward: Withholding[];
    outward: Withholding[];
    deposits: TaxDeposit[];
    ruleOptions: SelectOption[];
    billOptions: SelectOption[];
    invoiceOptions: SelectOption[];
    bankOptions: SelectOption[];
}>();

const { t: tr } = useLocale();

const tab = ref(props.tab || 'inward');
const tabs = computed(() => [
    { id: 'inward', label: tr('tax.tabInward') },
    { id: 'outward', label: tr('tax.tabOutward') },
    { id: 'deposits', label: tr('tax.tabDeposits') },
    { id: 'rules', label: tr('tax.tabRules') },
]);

/* Apply a rule to a posted bill */
const apply = useForm({ bill_id: '' as number | string, rule_id: '' as number | string });
function applyToBill() { apply.post(route('accounts.tax.withholding.apply'), { onSuccess: () => apply.reset() }); }

/* Record outward VDS */
const outwardForm = useForm({ invoice_id: '' as number | string, rate: 15, base: 0, certificate_no: '' });
function recordOutward() { outwardForm.post(route('accounts.tax.withholding.outward'), { onSuccess: () => outwardForm.reset() }); }
const certifyForm = useForm({ certificate_no: '' });
async function certify(w: Withholding) {
    // promptDialog, not window.prompt: the native box cannot be themed or translated, it
    // blocks the main thread, and this repo's ratchet (NativeDialogTest) only counts down.
    // The form is also hoisted to setup scope — `useForm()` inside a handler builds a new
    // form on every click, so Inertia never sees a busy state and errors never stick.
    const no = await promptDialog({
        title: 'Mushak 6.6 certificate',
        description: `Record the certificate number for ${w.invoice?.invoice_no ?? 'this sale'} — the buyer's withheld tax is credited against it.`,
        inputLabel: 'Certificate number',
        inputPlaceholder: 'e.g. 6.6-2026-000123',
        confirmLabel: 'Record certificate',
    });
    if (!no) return;

    certifyForm.certificate_no = no;
    certifyForm.post(route('accounts.tax.withholding.certify', w.id), { preserveScroll: true, onSuccess: () => certifyForm.reset() });
}

/* Deposit selected inward withholdings */
const selected = ref<number[]>([]);
function toggle(id: number) {
    selected.value = selected.value.includes(id) ? selected.value.filter((x) => x !== id) : [...selected.value, id];
}
const deposit = useForm({
    withholding_ids: [] as number[],
    period: new Date().toISOString().slice(0, 7) + '-01',
    deposit_date: new Date().toISOString().slice(0, 10),
    bank_id: '' as number | string,
    challan_no: '',
    treasury_code: '',
    notes: '',
});
function submitDeposit() {
    deposit.withholding_ids = selected.value;
    deposit.post(route('accounts.tax.withholding.deposit'), { onSuccess: () => { selected.value = []; deposit.reset(); } });
}

/* Add a rule */
const rule = useForm({ name: '', type: 'tds', section: '', rate: 10, threshold: 0, effective_from: '', notes: '' });
function addRule() { rule.post(route('accounts.tax.withholding.rules.store'), { onSuccess: () => rule.reset() }); }
function toggleRule(r: WithholdingRule) { useForm({}).post(route('accounts.tax.withholding.rules.toggle', r.id)); }

function statusTone(s: string) {
    return s === 'deposited' ? 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20'
        : s === 'certified' ? 'bg-sky-500/10 text-sky-700 border-sky-500/20'
        : 'bg-amber-500/10 text-amber-700 border-amber-500/20';
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'TDS / VDS Withholding' }]">
    <Head :title="tr('tax.withholding')" />
    <PageHeader
      :title="tr('tax.withholding')"
      description="Deduct tax and VAT at source on vendor bills, track VDS suffered on your sales, and deposit to NBR via treasury challans. Certified VDS credits your Mushak 9.1."
      :icon="HandCoins"
      :stats="[
        { label: 'TDS pending deposit', value: formatCurrency(pending.tds), sub: 'Liability to NBR' },
        { label: 'VDS pending deposit', value: formatCurrency(pending.vds), sub: 'Liability to NBR' },
        { label: 'Pending deductions', value: String(pending.count), sub: 'Awaiting challan' },
      ]"
    />

    <!-- Tabs -->
    <div class="flex flex-wrap gap-1 rounded-xl border bg-card p-1 shadow-sm">
      <button v-for="t in tabs" :key="t.id" type="button" class="rounded-lg px-4 py-2 text-sm font-medium transition" :class="tab === t.id ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent'" @click="tab = t.id">{{ t.label }}</button>
    </div>

    <!-- INWARD -->
    <div v-if="tab === 'inward'" class="space-y-5">
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <h2 class="flex items-center gap-2 font-semibold"><Percent class="size-4 text-primary" /> Deduct on a posted bill</h2>
        <form class="mt-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto]" @submit.prevent="applyToBill">
          <div><Label>Posted bill</Label><SearchableSelect v-model="apply.bill_id" :options="billOptions" placeholder="Select bill" /></div>
          <div><Label>Withholding rule</Label><SearchableSelect v-model="apply.rule_id" :options="ruleOptions" placeholder="Select rule" /></div>
          <div class="flex items-end"><Button :disabled="apply.processing || !apply.bill_id || !apply.rule_id"><Plus class="size-4" /> Deduct</Button></div>
        </form>
      </div>

      <div class="rounded-xl border bg-card shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3">
          <h3 class="font-semibold">{{ tr('tax.inward') }}</h3>
          <Button v-if="selected.length" size="sm" class="rounded-full" @click="tab = 'deposits'"><Landmark class="size-4" /> Deposit {{ selected.length }} selected</Button>
        </div>
        <div class="overflow-auto">
          <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2"></th><th class="px-4 py-2">Period</th><th class="px-4 py-2">Vendor / Bill</th><th class="px-4 py-2">Type</th><th class="px-4 py-2 text-right">Base</th><th class="px-4 py-2 text-right">Rate</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2">Status</th></tr></thead>
            <tbody class="divide-y">
              <tr v-for="w in inward" :key="w.id" class="hover:bg-accent/40">
                <td class="px-4 py-2.5"><input v-if="w.status === 'pending'" type="checkbox" class="size-4 accent-primary" :checked="selected.includes(w.id)" @change="toggle(w.id)" /></td>
                <td class="px-4 py-2.5 whitespace-nowrap">{{ w.period }}</td>
                <td class="px-4 py-2.5"><span class="font-medium">{{ w.partner?.name }}</span><span class="block text-xs text-muted-foreground">{{ w.bill?.bill_no }}</span></td>
                <td class="px-4 py-2.5 uppercase text-xs font-semibold">{{ w.type }}</td>
                <td class="px-4 py-2.5 text-right font-mono">{{ formatCurrency(w.base_amount) }}</td>
                <td class="px-4 py-2.5 text-right font-mono">{{ w.rate }}%</td>
                <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ formatCurrency(w.amount) }}</td>
                <td class="px-4 py-2.5"><span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-medium capitalize" :class="statusTone(w.status)">{{ w.status }}</span></td>
              </tr>
              <tr v-if="!inward.length"><td colspan="8" class="px-4 py-8 text-center text-muted-foreground">No deductions yet. Apply a rule to a posted bill above.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- OUTWARD -->
    <div v-if="tab === 'outward'" class="space-y-5">
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <h2 class="flex items-center gap-2 font-semibold"><FileCheck2 class="size-4 text-primary" /> Record VDS a customer withheld on our sale</h2>
        <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="recordOutward">
          <div class="lg:col-span-2"><Label>Our invoice</Label><SearchableSelect v-model="outwardForm.invoice_id" :options="invoiceOptions" placeholder="Select invoice" /></div>
          <div><Label>Base (BDT)</Label><Input v-model.number="outwardForm.base" type="number" min="0" step="0.01" /></div>
          <div><Label>Rate %</Label><Input v-model.number="outwardForm.rate" type="number" min="0" max="100" step="0.01" /></div>
          <div class="flex items-end"><Button :disabled="outwardForm.processing || !outwardForm.invoice_id"><Plus class="size-4" /> Record</Button></div>
          <div class="sm:col-span-2 lg:col-span-5"><Label>Mushak 6.6 certificate no. (optional — add later to claim)</Label><Input v-model="outwardForm.certificate_no" placeholder="e.g. VDS-2026-00123" /></div>
        </form>
      </div>
      <div class="rounded-xl border bg-card shadow-sm">
        <div class="border-b px-5 py-3"><h3 class="font-semibold">{{ tr('tax.outward') }}</h3></div>
        <div class="overflow-auto">
          <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2">Period</th><th class="px-4 py-2">Customer / Invoice</th><th class="px-4 py-2 text-right">Amount</th><th class="px-4 py-2">Certificate</th><th class="px-4 py-2">Status</th><th class="px-4 py-2"></th></tr></thead>
            <tbody class="divide-y">
              <tr v-for="w in outward" :key="w.id" class="hover:bg-accent/40">
                <td class="px-4 py-2.5 whitespace-nowrap">{{ w.period }}</td>
                <td class="px-4 py-2.5"><span class="font-medium">{{ w.partner?.name }}</span><span class="block text-xs text-muted-foreground">{{ w.invoice?.invoice_no }}</span></td>
                <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ formatCurrency(w.amount) }}</td>
                <td class="px-4 py-2.5 font-mono text-xs">{{ w.certificate_no || '—' }}</td>
                <td class="px-4 py-2.5"><span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-medium capitalize" :class="statusTone(w.status)">{{ w.status }}</span></td>
                <td class="px-4 py-2.5"><Button v-if="w.status !== 'certified'" variant="outline" size="sm" class="rounded-full" @click="certify(w)"><BadgeCheck class="size-4" /> Certify</Button></td>
              </tr>
              <tr v-if="!outward.length"><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">No outward VDS recorded.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- DEPOSITS -->
    <div v-if="tab === 'deposits'" class="space-y-5">
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <h2 class="flex items-center gap-2 font-semibold"><Landmark class="size-4 text-primary" /> Deposit selected deductions to NBR</h2>
        <p class="mt-1 text-sm text-muted-foreground">{{ selected.length }} deduction(s) selected on the “Deducted on bills” tab. A challan deposits one tax type.</p>
        <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="submitDeposit">
          <div><Label>Tax period</Label><Input v-model="deposit.period" type="date" /></div>
          <div><Label>Deposit date</Label><Input v-model="deposit.deposit_date" type="date" /></div>
          <div><Label>Bank</Label><SearchableSelect v-model="deposit.bank_id" :options="bankOptions" placeholder="Select bank" clearable /></div>
          <div><Label>Challan no.</Label><Input v-model="deposit.challan_no" /></div>
          <div><Label>Treasury code</Label><Input v-model="deposit.treasury_code" /></div>
          <div class="sm:col-span-2 lg:col-span-3"><Label>Notes</Label><Input v-model="deposit.notes" /></div>
          <div class="flex items-end lg:col-span-4"><Button :disabled="deposit.processing || !selected.length"><Landmark class="size-4" /> Record deposit</Button></div>
        </form>
        <p v-if="deposit.errors.withholding_ids" class="mt-2 text-sm text-destructive">{{ deposit.errors.withholding_ids }}</p>
      </div>
      <div class="rounded-xl border bg-card shadow-sm">
        <div class="border-b px-5 py-3"><h3 class="font-semibold">Deposit history</h3></div>
        <div class="overflow-auto">
          <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2">Deposit no.</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Date</th><th class="px-4 py-2">Challan</th><th class="px-4 py-2">Bank</th><th class="px-4 py-2 text-right">Amount</th></tr></thead>
            <tbody class="divide-y">
              <tr v-for="d in deposits" :key="d.id" class="hover:bg-accent/40">
                <td class="px-4 py-2.5 font-mono">{{ d.deposit_no }}</td>
                <td class="px-4 py-2.5 uppercase text-xs font-semibold">{{ d.type }}</td>
                <td class="px-4 py-2.5 whitespace-nowrap">{{ d.deposit_date }}</td>
                <td class="px-4 py-2.5 font-mono text-xs">{{ d.challan_no || '—' }}</td>
                <td class="px-4 py-2.5">{{ d.bank?.name || '—' }}</td>
                <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ formatCurrency(d.amount) }}</td>
              </tr>
              <tr v-if="!deposits.length"><td colspan="6" class="px-4 py-8 text-center text-muted-foreground">No deposits recorded yet.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- RULES -->
    <div v-if="tab === 'rules'" class="space-y-5">
      <div class="rounded-xl border bg-card p-5 shadow-sm">
        <h2 class="flex items-center gap-2 font-semibold"><Plus class="size-4 text-primary" /> Add a withholding rate</h2>
        <form class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6" @submit.prevent="addRule">
          <div class="lg:col-span-2"><Label>Name</Label><Input v-model="rule.name" placeholder="e.g. TDS on contractor services" /></div>
          <div><Label>Type</Label><select v-model="rule.type" class="h-10 w-full rounded-md border bg-background px-3"><option value="tds">TDS (income tax)</option><option value="vds">VDS (VAT)</option></select></div>
          <div><Label>Rate %</Label><Input v-model.number="rule.rate" type="number" min="0" max="100" step="0.01" /></div>
          <div><Label>Threshold (BDT)</Label><Input v-model.number="rule.threshold" type="number" min="0" step="0.01" /></div>
          <div class="flex items-end"><Button :disabled="rule.processing || !rule.name"><Plus class="size-4" /> Add</Button></div>
          <div class="lg:col-span-3"><Label>Legal section (optional)</Label><Input v-model="rule.section" placeholder="e.g. u/s 52, Income Tax Act 2023" /></div>
          <div class="lg:col-span-3"><Label>Effective from (optional)</Label><Input v-model="rule.effective_from" type="date" /></div>
        </form>
      </div>
      <div class="rounded-xl border bg-card shadow-sm">
        <div class="border-b px-5 py-3"><h3 class="font-semibold">Configured rates</h3></div>
        <div class="overflow-auto">
          <table class="w-full text-sm">
            <thead class="bg-muted/50 text-left text-xs text-muted-foreground"><tr><th class="px-4 py-2">Name</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Section</th><th class="px-4 py-2 text-right">Rate</th><th class="px-4 py-2 text-right">Threshold</th><th class="px-4 py-2">Active</th><th class="px-4 py-2"></th></tr></thead>
            <tbody class="divide-y">
              <tr v-for="r in rules" :key="r.id" class="hover:bg-accent/40">
                <td class="px-4 py-2.5 font-medium">{{ r.name }}</td>
                <td class="px-4 py-2.5 uppercase text-xs font-semibold">{{ r.type }}</td>
                <td class="px-4 py-2.5 text-xs text-muted-foreground">{{ r.section || '—' }}</td>
                <td class="px-4 py-2.5 text-right font-mono">{{ r.rate }}%</td>
                <td class="px-4 py-2.5 text-right font-mono">{{ formatCurrency(r.threshold) }}</td>
                <td class="px-4 py-2.5"><span class="inline-flex rounded-full border px-2 py-0.5 text-xs font-medium" :class="r.is_active ? 'bg-emerald-500/10 text-emerald-700 border-emerald-500/20' : 'bg-muted text-muted-foreground'">{{ r.is_active ? 'Active' : 'Off' }}</span></td>
                <td class="px-4 py-2.5"><Button variant="outline" size="sm" class="rounded-full" @click="toggleRule(r)">{{ r.is_active ? 'Deactivate' : 'Activate' }}</Button></td>
              </tr>
              <tr v-if="!rules.length"><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">No rates configured. Add one above.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
