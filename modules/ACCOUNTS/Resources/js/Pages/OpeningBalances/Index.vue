<script setup lang="ts">
import PageHeader from '@core/Components/PageHeader.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Head, useForm } from '@inertiajs/vue3';
import { Boxes, Landmark, Plus, Receipt, Trash2, Wallet } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Account { id: number; code: string; name: string; account_type: string; nature: string }
interface Partner { id: number; partner_code: string; name: string }
interface Product { id: number; sku: string; name: string }
interface Warehouse { id: number; code: string; name: string }
interface Batch { id: number; reference: string; type: string; as_of_date: string; total_amount: string; journal_id: number | null; created_at: string }
interface Props {
    accounts: Account[];
    customers: Partner[];
    vendors: Partner[];
    products: Product[];
    warehouses: Warehouse[];
    batches: Batch[];
}
const props = defineProps<Props>();

type Tab = 'coa' | 'ar' | 'ap' | 'stock';
const tab = ref<Tab>('coa');
const TABS: Array<{ id: Tab; label: string; icon: any }> = [
    { id: 'coa', label: 'Chart of Accounts', icon: Landmark },
    { id: 'ar', label: 'Receivables (AR)', icon: Receipt },
    { id: 'ap', label: 'Payables (AP)', icon: Wallet },
    { id: 'stock', label: 'Opening Stock', icon: Boxes },
];

const today = new Date().toISOString().slice(0, 10);
const refPrefix: Record<Tab, string> = { coa: 'OB-COA', ar: 'OB-AR', ap: 'OB-AP', stock: 'OB-STOCK' };
const defaultRef = (t: Tab): string => `${refPrefix[t]}-${today.replace(/-/g, '')}`;

// ---- COA form ----
const coaForm = useForm({
    reference: defaultRef('coa'),
    as_of_date: today,
    lines: [{ account_id: '', debit: '', credit: '' }] as Array<{ account_id: number | string; debit: string; credit: string }>,
});
const coaTotalDr = computed(() => coaForm.lines.reduce((s, l) => s + (Number(l.debit) || 0), 0));
const coaTotalCr = computed(() => coaForm.lines.reduce((s, l) => s + (Number(l.credit) || 0), 0));

// ---- AR form ----
const arForm = useForm({
    reference: defaultRef('ar'),
    as_of_date: today,
    rows: [{ partner_id: '', invoice_no: '', invoice_date: today, due_date: '', amount: '' }] as Array<Record<string, string | number>>,
});
const arTotal = computed(() => arForm.rows.reduce((s, r) => s + (Number(r.amount) || 0), 0));

// ---- AP form ----
const apForm = useForm({
    reference: defaultRef('ap'),
    as_of_date: today,
    rows: [{ partner_id: '', bill_no: '', bill_date: today, due_date: '', amount: '' }] as Array<Record<string, string | number>>,
});
const apTotal = computed(() => apForm.rows.reduce((s, r) => s + (Number(r.amount) || 0), 0));

// ---- Stock form ----
const stockForm = useForm({
    reference: defaultRef('stock'),
    as_of_date: today,
    rows: [{ product_id: '', warehouse_id: '', qty: '', unit_cost: '' }] as Array<Record<string, string | number>>,
});
const stockTotal = computed(() => stockForm.rows.reduce((s, r) => s + (Number(r.qty) || 0) * (Number(r.unit_cost) || 0), 0));

const money = (v: number): string => v.toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function submit(tabId: Tab): void {
    const map = { coa: coaForm, ar: arForm, ap: apForm, stock: stockForm };
    const routeMap = { coa: 'accounts.opening-balances.coa', ar: 'accounts.opening-balances.ar', ap: 'accounts.opening-balances.ap', stock: 'accounts.opening-balances.stock' };
    map[tabId].post(route(routeMap[tabId]), { preserveScroll: true });
}

const TYPE_LABEL: Record<string, string> = { coa: 'COA', ar: 'Receivables', ap: 'Payables', stock: 'Stock' };
</script>

<template>
    <Head title="Opening Balances" />
    <AdminLayout>
        <PageHeader title="Opening Balances" description="Take on your existing balances at go-live. Each tab posts a balanced, idempotent batch to the ledger — a reference can only be posted once." :icon="Landmark" />

        <!-- Tabs -->
        <div class="mt-6 flex flex-wrap gap-1.5 rounded-xl border bg-card p-1.5 shadow-sm">
            <button
                v-for="t in TABS"
                :key="t.id"
                type="button"
                class="flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-medium transition"
                :class="tab === t.id ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:bg-accent'"
                @click="tab = t.id"
            >
                <component :is="t.icon" class="size-4" /> {{ t.label }}
            </button>
        </div>

        <!-- COA -->
        <div v-if="tab === 'coa'" class="mt-5 rounded-xl border bg-card p-5 shadow-sm">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><Label>Batch reference</Label><Input v-model="coaForm.reference" class="mt-1.5" /><p v-if="coaForm.errors.reference" class="mt-1 text-[12px] text-rose-600">{{ coaForm.errors.reference }}</p></div>
                <div><Label>As of date</Label><Input v-model="coaForm.as_of_date" type="date" class="mt-1.5" /></div>
            </div>
            <div class="mt-4 overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                        <tr><th class="px-3 py-2 font-semibold">Posting account</th><th class="w-36 px-3 py-2 text-right font-semibold">Debit</th><th class="w-36 px-3 py-2 text-right font-semibold">Credit</th><th class="w-12"></th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(line, i) in coaForm.lines" :key="i">
                            <td class="px-3 py-2">
                                <select v-model="line.account_id" class="h-9 w-full rounded-md border bg-background px-2 text-sm">
                                    <option value="" disabled>Select account…</option>
                                    <option v-for="a in props.accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                                </select>
                            </td>
                            <td class="px-3 py-2"><Input v-model="line.debit" type="number" min="0" step="0.01" class="text-right" placeholder="0.00" /></td>
                            <td class="px-3 py-2"><Input v-model="line.credit" type="number" min="0" step="0.01" class="text-right" placeholder="0.00" /></td>
                            <td class="px-3 py-2 text-center"><Button variant="ghost" size="icon" class="size-8 text-muted-foreground hover:text-rose-600" :disabled="coaForm.lines.length === 1" @click="coaForm.lines.splice(i, 1)"><Trash2 class="size-4" /></Button></td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-muted/30 font-semibold">
                        <tr><td class="px-3 py-2 text-right">Totals</td><td class="px-3 py-2 text-right tabular-nums">{{ money(coaTotalDr) }}</td><td class="px-3 py-2 text-right tabular-nums">{{ money(coaTotalCr) }}</td><td></td></tr>
                    </tfoot>
                </table>
            </div>
            <div class="mt-3 flex items-center justify-between">
                <Button variant="outline" size="sm" class="gap-1.5" @click="coaForm.lines.push({ account_id: '', debit: '', credit: '' })"><Plus class="size-4" /> Add line</Button>
                <p class="text-[12px]" :class="Math.abs(coaTotalDr - coaTotalCr) < 0.005 ? 'text-emerald-600' : 'text-amber-600'">
                    {{ Math.abs(coaTotalDr - coaTotalCr) < 0.005 ? 'Balanced — the net posts to Opening Balance Equity (3900).' : `Out of balance by ${money(Math.abs(coaTotalDr - coaTotalCr))} — the difference posts to 3900.` }}
                </p>
            </div>
            <div class="mt-4 flex justify-end"><Button :disabled="coaForm.processing" @click="submit('coa')">{{ coaForm.processing ? 'Posting…' : 'Post COA opening balances' }}</Button></div>
        </div>

        <!-- AR -->
        <div v-if="tab === 'ar'" class="mt-5 rounded-xl border bg-card p-5 shadow-sm">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><Label>Batch reference</Label><Input v-model="arForm.reference" class="mt-1.5" /></div>
                <div><Label>As of date</Label><Input v-model="arForm.as_of_date" type="date" class="mt-1.5" /></div>
            </div>
            <div class="mt-4 overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                        <tr><th class="px-3 py-2 font-semibold">Customer</th><th class="px-3 py-2 font-semibold">Invoice no</th><th class="px-3 py-2 font-semibold">Invoice date</th><th class="px-3 py-2 font-semibold">Due date</th><th class="w-32 px-3 py-2 text-right font-semibold">Amount</th><th class="w-12"></th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(row, i) in arForm.rows" :key="i">
                            <td class="px-3 py-2"><select v-model="row.partner_id" class="h-9 w-full rounded-md border bg-background px-2 text-sm"><option value="" disabled>Select…</option><option v-for="p in props.customers" :key="p.id" :value="p.id">{{ p.name }}</option></select></td>
                            <td class="px-3 py-2"><Input v-model="row.invoice_no" placeholder="INV-001" /></td>
                            <td class="px-3 py-2"><Input v-model="row.invoice_date" type="date" /></td>
                            <td class="px-3 py-2"><Input v-model="row.due_date" type="date" /></td>
                            <td class="px-3 py-2"><Input v-model="row.amount" type="number" min="0.01" step="0.01" class="text-right" placeholder="0.00" /></td>
                            <td class="px-3 py-2 text-center"><Button variant="ghost" size="icon" class="size-8 text-muted-foreground hover:text-rose-600" :disabled="arForm.rows.length === 1" @click="arForm.rows.splice(i, 1)"><Trash2 class="size-4" /></Button></td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-muted/30 font-semibold"><tr><td colspan="4" class="px-3 py-2 text-right">Total receivable</td><td class="px-3 py-2 text-right tabular-nums">{{ money(arTotal) }}</td><td></td></tr></tfoot>
                </table>
            </div>
            <div class="mt-3 flex justify-between"><Button variant="outline" size="sm" class="gap-1.5" @click="arForm.rows.push({ partner_id: '', invoice_no: '', invoice_date: today, due_date: '', amount: '' })"><Plus class="size-4" /> Add invoice</Button><p class="text-[12px] text-muted-foreground">Dr Accounts Receivable (1100) / Cr Opening Balance Equity (3900).</p></div>
            <div class="mt-4 flex justify-end"><Button :disabled="arForm.processing" @click="submit('ar')">{{ arForm.processing ? 'Posting…' : 'Post opening receivables' }}</Button></div>
        </div>

        <!-- AP -->
        <div v-if="tab === 'ap'" class="mt-5 rounded-xl border bg-card p-5 shadow-sm">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><Label>Batch reference</Label><Input v-model="apForm.reference" class="mt-1.5" /></div>
                <div><Label>As of date</Label><Input v-model="apForm.as_of_date" type="date" class="mt-1.5" /></div>
            </div>
            <div class="mt-4 overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                        <tr><th class="px-3 py-2 font-semibold">Vendor</th><th class="px-3 py-2 font-semibold">Bill no</th><th class="px-3 py-2 font-semibold">Bill date</th><th class="px-3 py-2 font-semibold">Due date</th><th class="w-32 px-3 py-2 text-right font-semibold">Amount</th><th class="w-12"></th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(row, i) in apForm.rows" :key="i">
                            <td class="px-3 py-2"><select v-model="row.partner_id" class="h-9 w-full rounded-md border bg-background px-2 text-sm"><option value="" disabled>Select…</option><option v-for="p in props.vendors" :key="p.id" :value="p.id">{{ p.name }}</option></select></td>
                            <td class="px-3 py-2"><Input v-model="row.bill_no" placeholder="BILL-001" /></td>
                            <td class="px-3 py-2"><Input v-model="row.bill_date" type="date" /></td>
                            <td class="px-3 py-2"><Input v-model="row.due_date" type="date" /></td>
                            <td class="px-3 py-2"><Input v-model="row.amount" type="number" min="0.01" step="0.01" class="text-right" placeholder="0.00" /></td>
                            <td class="px-3 py-2 text-center"><Button variant="ghost" size="icon" class="size-8 text-muted-foreground hover:text-rose-600" :disabled="apForm.rows.length === 1" @click="apForm.rows.splice(i, 1)"><Trash2 class="size-4" /></Button></td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-muted/30 font-semibold"><tr><td colspan="4" class="px-3 py-2 text-right">Total payable</td><td class="px-3 py-2 text-right tabular-nums">{{ money(apTotal) }}</td><td></td></tr></tfoot>
                </table>
            </div>
            <div class="mt-3 flex justify-between"><Button variant="outline" size="sm" class="gap-1.5" @click="apForm.rows.push({ partner_id: '', bill_no: '', bill_date: today, due_date: '', amount: '' })"><Plus class="size-4" /> Add bill</Button><p class="text-[12px] text-muted-foreground">Dr Opening Balance Equity (3900) / Cr Accounts Payable (2100).</p></div>
            <div class="mt-4 flex justify-end"><Button :disabled="apForm.processing" @click="submit('ap')">{{ apForm.processing ? 'Posting…' : 'Post opening payables' }}</Button></div>
        </div>

        <!-- Stock -->
        <div v-if="tab === 'stock'" class="mt-5 rounded-xl border bg-card p-5 shadow-sm">
            <div class="grid gap-4 sm:grid-cols-2">
                <div><Label>Batch reference</Label><Input v-model="stockForm.reference" class="mt-1.5" /></div>
                <div><Label>As of date</Label><Input v-model="stockForm.as_of_date" type="date" class="mt-1.5" /></div>
            </div>
            <div class="mt-4 overflow-x-auto rounded-lg border">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                        <tr><th class="px-3 py-2 font-semibold">Product</th><th class="px-3 py-2 font-semibold">Warehouse</th><th class="w-28 px-3 py-2 text-right font-semibold">Qty</th><th class="w-32 px-3 py-2 text-right font-semibold">Unit cost</th><th class="w-32 px-3 py-2 text-right font-semibold">Value</th><th class="w-12"></th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="(row, i) in stockForm.rows" :key="i">
                            <td class="px-3 py-2"><select v-model="row.product_id" class="h-9 w-full rounded-md border bg-background px-2 text-sm"><option value="" disabled>Select…</option><option v-for="p in props.products" :key="p.id" :value="p.id">{{ p.sku }} — {{ p.name }}</option></select></td>
                            <td class="px-3 py-2"><select v-model="row.warehouse_id" class="h-9 w-full rounded-md border bg-background px-2 text-sm"><option value="" disabled>Select…</option><option v-for="w in props.warehouses" :key="w.id" :value="w.id">{{ w.code }} — {{ w.name }}</option></select></td>
                            <td class="px-3 py-2"><Input v-model="row.qty" type="number" min="0.001" step="0.001" class="text-right" placeholder="0" /></td>
                            <td class="px-3 py-2"><Input v-model="row.unit_cost" type="number" min="0" step="0.01" class="text-right" placeholder="0.00" /></td>
                            <td class="px-3 py-2 text-right tabular-nums text-muted-foreground">{{ money((Number(row.qty) || 0) * (Number(row.unit_cost) || 0)) }}</td>
                            <td class="px-3 py-2 text-center"><Button variant="ghost" size="icon" class="size-8 text-muted-foreground hover:text-rose-600" :disabled="stockForm.rows.length === 1" @click="stockForm.rows.splice(i, 1)"><Trash2 class="size-4" /></Button></td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t bg-muted/30 font-semibold"><tr><td colspan="4" class="px-3 py-2 text-right">Total stock value</td><td class="px-3 py-2 text-right tabular-nums">{{ money(stockTotal) }}</td><td></td></tr></tfoot>
                </table>
            </div>
            <div class="mt-3 flex justify-between"><Button variant="outline" size="sm" class="gap-1.5" @click="stockForm.rows.push({ product_id: '', warehouse_id: '', qty: '', unit_cost: '' })"><Plus class="size-4" /> Add item</Button><p class="text-[12px] text-muted-foreground">Creates stock moves + Dr Inventory (1200) / Cr Opening Balance Equity (3900).</p></div>
            <div class="mt-4 flex justify-end"><Button :disabled="stockForm.processing" @click="submit('stock')">{{ stockForm.processing ? 'Posting…' : 'Post opening stock' }}</Button></div>
        </div>

        <!-- Posted batches -->
        <section class="mt-8">
            <h2 class="mb-3 text-sm font-semibold">Posted batches</h2>
            <div v-if="props.batches.length === 0" class="rounded-xl border border-dashed bg-card px-6 py-8 text-center text-[13px] text-muted-foreground">No opening balances posted yet.</div>
            <div v-else class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                        <tr><th class="px-4 py-2.5 font-semibold">Reference</th><th class="px-4 py-2.5 font-semibold">Type</th><th class="px-4 py-2.5 font-semibold">As of</th><th class="px-4 py-2.5 text-right font-semibold">Total</th><th class="px-4 py-2.5 font-semibold">Journal</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="b in props.batches" :key="b.id">
                            <td class="px-4 py-3 font-medium">{{ b.reference }}</td>
                            <td class="px-4 py-3"><span class="rounded-full bg-muted px-2 py-0.5 text-[11px] font-semibold">{{ TYPE_LABEL[b.type] ?? b.type }}</span></td>
                            <td class="px-4 py-3 text-[13px] text-muted-foreground">{{ b.as_of_date }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(Number(b.total_amount)) }}</td>
                            <td class="px-4 py-3 text-[13px] text-muted-foreground">{{ b.journal_id ? `#${b.journal_id}` : '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
