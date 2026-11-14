<script setup lang="ts">
/**
 * The round: what was asked, who answered, the comparison, and the award.
 *
 * Three panels, in the order the work happens, and they are deliberately not one giant table:
 *  - answers, so a missing vendor is visible as a missing vendor;
 *  - the comparison, computed by the service — a grid assembled in the browser could not be the record of
 *    a decision years later;
 *  - the award, which is the only place a number becomes a purchase order.
 *
 * Cheapest-per-line and cheapest-complete are offered side by side on purpose. They are different
 * decisions — splitting a round across vendors saves money and costs you one delivery — and the person
 * making it should see both numbers, not have to work out that the second one exists.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';
import { Handshake, Send, Check, Lock, X, Trash2, Award } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { confirmDialog } from '@core/Composables/useConfirm';
import { formatCurrency } from '@core/Utils/format';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import DetailItem from '@core/Components/DetailItem.vue';
import StaticTable from '@core/Components/StaticTable.vue';
import FormField from '@core/Components/FormField.vue';
import ActivityTimeline from '@core/Components/ActivityTimeline.vue';
import AttachmentPanel from '@core/Components/AttachmentPanel.vue';

const props = defineProps<{
    rfq: any;
    compare: Record<string, any>;
    activities?: any[];
    attachments?: any[];
    canManage?: boolean;
    canAward?: boolean;
}>();

const rfq = computed(() => props.rfq);
const lines = computed<any[]>(() => props.compare.lines ?? []);
const quotes = computed<any[]>(() => props.compare.quotes ?? []);
const recommendation = computed<any>(() => props.compare.recommendation ?? null);
const awarded = computed(() => rfq.value.awarded_quote_id !== null && rfq.value.awarded_quote_id !== undefined);

/** A serialised date cast is an ISO string; a date on a round is a day. */
function day(value: unknown): string | null {
    return value ? String(value).slice(0, 10) : null;
}

function tone(s: string) {
    if (s === 'closed') return 'success';
    if (s === 'sent') return 'info';
    if (s === 'cancelled') return 'danger';

    return 'warning';
}

// ── Recording an answer ────────────────────────────────────────────────────────────────────────────
const quoting = ref(false);
const quoteForm = useForm({
    vendor_id: null as number | string | null,
    quote_ref: '',
    quoted_on: new Date().toISOString().slice(0, 10),
    valid_until: '',
    lead_time_days: '',
    notes: '',
    items: {} as Record<string, number | string>,
});

function openQuote(vendorId?: number) {
    quoteForm.items = {};
    for (const l of lines.value) {
        quoteForm.items[String(l.id)] = '';
    }

    if (vendorId) {
        quoteForm.vendor_id = vendorId;
    }

    quoting.value = true;
}

const quoteTotal = computed(() =>
    Math.round(
        lines.value.reduce((sum: number, l: any) => {
            const price = Number(quoteForm.items[String(l.id)] ?? 0);

            return sum + Math.round((Number(l.qty) || 0) * 100) * price;
        }, 0),
    ) / 10000,
);

/** Vendors with an answer on this round already — `recordQuote` replaces one whole, so say so first. */
const answeredBy = computed(() => new Set(quotes.value.map((q: any) => Number(q.vendor_id))));
const overwriting = computed(() => quoteForm.vendor_id !== null && answeredBy.value.has(Number(quoteForm.vendor_id)));

function saveQuote() {
    const payload = {
        ...quoteForm.data(),
        items: Object.entries(quoteForm.items)
            .filter(([, price]) => String(price) !== '')
            .map(([lineId, price]) => ({ rfq_line_id: Number(lineId), unit_price: Number(price) })),
    };

    quoteForm.transform(() => payload).post(route('purchase.rfqs.quotes.store', rfq.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            quoting.value = false;
        },
    });
}

async function withdraw(quote: any) {
    if (await confirmDialog({ title: `Withdraw ${quote.vendor}'s answer?`, description: 'It stops being an offer on the table. The record stays, marked withdrawn.', confirmLabel: 'Withdraw', danger: true })) {
        router.post(route('purchase.rfqs.quotes.withdraw', quote.id));
    }
}

// ── Choosing a winner ──────────────────────────────────────────────────────────────────────────────
const picks = reactive<Record<string, number | null>>({});
const warehouseId = ref<number | string | null>(null);

// Seeded with an explicit "no award" rather than left undefined: a <select> whose model is undefined
// renders empty, and an empty box reads like a missing option instead of a decision not yet made.
for (const l of lines.value) {
    picks[String(l.id)] = null;
}

function resetPicks(mode: 'recommended' | 'cheapest') {
    for (const l of lines.value) {
        picks[String(l.id)] = mode === 'recommended' ? (recommendation.value?.quote_id ?? null) : (l.best_quote_id ?? null);
    }
}

const chosen = computed(() =>
    lines.value
        .filter((l: any) => picks[String(l.id)])
        .map((l: any) => ({ rfq_line_id: l.id, quote_id: Number(picks[String(l.id)]) })),
);

const chosenTotal = computed(() =>
    Math.round(
        chosen.value.reduce((sum: number, pick) => {
            const line = lines.value.find((l: any) => l.id === pick.rfq_line_id);
            const cell = line?.cells?.[String(pick.quote_id)] ?? line?.cells?.[pick.quote_id];

            return sum + Math.round(Number(cell?.total ?? 0) * 100);
        }, 0),
    ) / 10000,
);

const vendorsChosen = computed(() => new Set(chosen.value.map((p) => p.quote_id)));

async function award() {
    const split = vendorsChosen.value.size > 1;

    if (! (await confirmDialog({
        title: 'Award this round?',
        description: split
            ? `This creates ${vendorsChosen.value.size} draft purchase orders — one per vendor. Nothing is confirmed until each order is approved.`
            : 'This creates a draft purchase order. Nothing is confirmed until the order is approved.',
        confirmLabel: 'Award',
    }))) {
        return;
    }

    router.post(route('purchase.rfqs.award', rfq.value.id), { picks: chosen.value, warehouse_id: warehouseId.value }, { preserveScroll: true });
}

async function act(name: 'send' | 'close' | 'cancel', label: string, description: string) {
    if (! (await confirmDialog({ title: label, description, confirmLabel: label, danger: name === 'cancel' }))) {
        return;
    }

    router.post(route(`purchase.rfqs.${name}`, rfq.value.id));
}

function cellFor(line: any, quoteId: number) {
    return line.cells?.[String(quoteId)] ?? line.cells?.[quoteId] ?? null;
}

const draft = computed(() => rfq.value.status === 'draft');
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Quote Rounds', href: route('purchase.rfqs.index') }, { title: rfq.rfq_no }]">
        <Head :title="rfq.rfq_no" />

        <PageHeader
            :title="rfq.subject"
            :description="`Round ${rfq.rfq_no}${rfq.requisition ? ' · raised from ' + rfq.requisition.req_no : ' · a market sounding'}`"
            :icon="Handshake"
            :actions="[{ label: 'All rounds', href: route('purchase.rfqs.index'), variant: 'outline' }]"
        >
            <template #badge>
                <StatusBadge :label="rfq.status" :tone="tone(rfq.status)" dot />
                <StatusBadge v-if="awarded" label="awarded" tone="success" />
                <StatusBadge v-else-if="rfq.due_on && new Date(String(rfq.due_on).slice(0, 10)) < new Date()" label="past due" tone="danger" />
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-4">
            <EnterpriseCard class="lg:col-span-3" title="What vendors were asked">
                <StaticTable
                    :empty="!lines.length"
                    empty-title="No lines yet"
                    empty-description="A round with nothing on it is not a question anyone can answer."
                >
                    <template #head>
                        <thead>
                            <tr class="text-xs text-muted-foreground">
                                <th class="text-left">Line</th>
                                <th class="text-right">Qty</th>
                                <th class="text-left">From requisition</th>
                            </tr>
                        </thead>
                    </template>
                    <tbody>
                        <tr v-for="l in lines" :key="l.id" class="border-t">
                            <td class="text-sm">{{ l.description }}</td>
                            <td class="text-right font-mono text-sm">{{ Number(l.qty) }}</td>
                            <td class="text-xs text-muted-foreground">{{ l.requisition_item_id ? `item #${l.requisition_item_id}` : '—' }}</td>
                        </tr>
                    </tbody>
                </StaticTable>
            </EnterpriseCard>

            <EnterpriseCard title="Round">
                <div class="space-y-2 text-sm">
                    <DetailItem label="Due" :value="day(rfq.due_on) ?? 'not set'" />
                    <DetailItem label="Answers" :value="`${quotes.length} of ${lines.length} line(s) each`" />
                    <DetailItem label="Awarded to" :value="awarded ? (rfq.awards?.[0]?.quote?.vendor?.name ?? 'see awards') : 'not yet'" />
                    <DetailItem v-if="rfq.requisition?.warehouse?.name" label="Delivers to" :value="rfq.requisition.warehouse.name" />
                    <p v-if="rfq.notes" class="rounded-lg bg-muted/40 px-3 py-2 text-sm">{{ rfq.notes }}</p>

                    <div v-if="canManage" class="flex flex-wrap gap-2 pt-2">
                        <Button v-if="draft" size="sm" @click="act('send', 'Send this round?', 'Vendors can be quoted against from now; the lines stop being editable.')">
                            <Send class="size-4" /> Send
                        </Button>
                        <Link v-if="draft" :href="route('purchase.rfqs.index')"><span class="inline-flex h-9 items-center rounded-md border border-input px-3 text-sm font-medium hover:bg-accent">Edit lines</span></Link>
                        <Button v-if="rfq.status === 'sent' && !awarded" size="sm" variant="outline" @click="act('close', 'Close this round?', 'No further answers will be taken. Award first if you have a winner.')">
                            <Lock class="size-4" /> Close
                        </Button>
                        <Button v-if="['draft', 'sent'].includes(rfq.status)" size="sm" variant="ghost" class="text-muted-foreground" @click="act('cancel', 'Cancel this round?', 'It stays on file, marked cancelled.')">
                            <X class="size-4" /> Cancel
                        </Button>
                    </div>
                </div>
            </EnterpriseCard>
        </div>

        <EnterpriseCard title="Answers" :icon="Handshake">
            <template #actions>
                <Button v-if="canManage && rfq.status === 'sent' && !awarded" size="sm" variant="outline" @click="openQuote()">
                    <Send class="size-4" /> Record an answer
                </Button>
            </template>

            <StaticTable
                :empty="!quotes.length"
                empty-title="No answers yet"
                empty-description="Record one as it arrives — a quote is a price with a name and a date on it."
            >
                <template #head>
                    <thead>
                        <tr class="text-xs text-muted-foreground">
                            <th class="text-left">Vendor</th>
                            <th class="text-left">Their ref</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Coverage</th>
                            <th class="text-right">Lead time</th>
                            <th class="text-right">Valid until</th>
                            <th class="text-left" />
                        </tr>
                    </thead>
                </template>
                <tbody>
                    <tr v-for="q in quotes" :key="q.id" class="border-t" :class="q.expired ? 'opacity-60' : ''">
                        <td class="text-sm font-medium">{{ q.vendor }}</td>
                        <td class="font-mono text-xs">{{ q.quote_ref ?? '—' }}</td>
                        <td class="text-right font-mono text-sm font-semibold">{{ formatCurrency(Number(q.total)) }}</td>
                        <td class="text-right text-xs" :class="q.complete ? 'text-muted-foreground' : 'font-semibold text-destructive'">
                            {{ q.lines_quoted }}/{{ q.lines_requested }}
                            <span v-if="!q.complete" class="ml-1">(partial)</span>
                        </td>
                        <td class="text-right text-xs">{{ q.lead_time_days ? q.lead_time_days + ' days' : '—' }}</td>
                        <td class="text-right text-xs" :class="q.expired ? 'font-semibold text-destructive' : ''">
                            {{ q.valid_until ?? 'open-ended' }}
                            <span v-if="q.expired">· expired</span>
                        </td>
                        <td class="text-right">
                            <div v-if="canManage && rfq.status === 'sent' && !awarded" class="flex justify-end gap-1">
                                <Button size="sm" variant="ghost" class="h-7 px-2" @click="openQuote(q.vendor_id)">Update</Button>
                                <Button size="sm" variant="ghost" class="h-7 px-2 text-muted-foreground hover:text-destructive" @click="withdraw(q)">
                                    <Trash2 class="size-3.5" />
                                </Button>
                            </div>
                            <span v-else-if="awarded && recommendation?.quote_id === q.id" class="text-xs font-semibold text-primary">winner</span>
                        </td>
                    </tr>
                </tbody>
            </StaticTable>

            <form v-if="quoting" class="mt-4 space-y-3 rounded-xl border bg-muted/20 p-4" @submit.prevent="saveQuote">
                <div class="grid gap-3 sm:grid-cols-4">
                    <FormField label="Vendor" required :error="quoteForm.errors.vendor_id as string">
                        <SearchableSelect v-model="quoteForm.vendor_id" endpoint="/partner/api/options/vendors" placeholder="Who answered?" />
                    </FormField>
                    <FormField label="Their reference" :error="quoteForm.errors.quote_ref as string">
                        <template #default="{ id }"><Input v-model="quoteForm.quote_ref" :id="id" maxlength="80" placeholder="QT-4471" /></template>
                    </FormField>
                    <FormField label="Quoted on" :error="quoteForm.errors.quoted_on as string">
                        <template #default="{ id }"><Input v-model="quoteForm.quoted_on" :id="id" type="date" class="font-mono" /></template>
                    </FormField>
                    <FormField label="Valid until" help="An expired answer cannot be awarded, and the comparison stops calling it the cheapest." :error="quoteForm.errors.valid_until as string">
                        <template #default="{ id }"><Input v-model="quoteForm.valid_until" :id="id" type="date" class="font-mono" /></template>
                    </FormField>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField v-for="l in lines" :key="l.id" :label="`${l.description} · ${Number(l.qty)}`">
                        <template #default="{ id }">
                            <Input v-model="quoteForm.items[String(l.id)]" :id="id" type="number" step="0.01" min="0" class="text-right font-mono" placeholder="0.00" />
                        </template>
                    </FormField>
                </div>

                <p v-if="overwriting" class="rounded-lg bg-muted/50 px-3 py-2 text-xs text-muted-foreground">
                    This vendor has an answer on the round already. Saving replaces that answer line for
                    line — the old prices are not kept for anything it does not re-quote — and its total is
                    recomputed from what you type here.
                </p>

                <p v-if="quoteForm.errors.items || quoteForm.errors.vendor_id" role="alert" class="text-xs font-medium text-destructive">
                    {{ (quoteForm.errors.vendor_id ?? Object.values(quoteForm.errors)[0]) as string }}
                </p>

                <div class="flex items-center justify-between gap-3">
                    <p class="text-xs text-muted-foreground">
                        Leave a line empty to answer only part of the round — a partial quote is never recommended as the winner.
                    </p>
                    <div class="flex items-center gap-2">
                        <p class="font-mono text-sm font-bold">{{ formatCurrency(quoteTotal) }}</p>
                        <Button type="button" size="sm" variant="ghost" @click="quoting = false">Cancel</Button>
                        <Button size="sm" :disabled="quoteForm.processing" @click="saveQuote">Save answer</Button>
                    </div>
                </div>
            </form>
        </EnterpriseCard>

        <EnterpriseCard title="Comparison" :icon="Award">
            <p v-if="!quotes.length" class="text-sm text-muted-foreground">Nothing to compare until an answer is recorded.</p>
            <template v-else>
                <StaticTable flush label="Quote comparison" class="text-sm">
                        <template #head>
                        <thead>
                            <tr class="text-xs text-muted-foreground">
                                <th class="sticky left-0 bg-card py-2 pr-3 text-left">Line</th>
                                <th v-for="q in quotes" :key="q.id" class="px-3 py-2 text-right">
                                    {{ q.vendor }}
                                    <span v-if="!q.complete" class="block text-[10px] font-normal text-destructive">partial</span>
                                    <span v-else-if="q.expired" class="block text-[10px] font-normal text-destructive">expired</span>
                                    <span v-else-if="recommendation?.quote_id === q.id" class="block text-[10px] font-normal text-primary">lowest total</span>
                                </th>
                            </tr>
                        </thead>
                        </template>
                        <tbody>
                            <tr v-for="l in lines" :key="l.id" class="border-t">
                                <th scope="row" class="sticky left-0 bg-card py-2 pr-3 text-left font-normal">{{ l.description }} <span class="text-xs text-muted-foreground">× {{ Number(l.qty) }}</span></th>
                                <td v-for="q in quotes" :key="q.id" class="px-3 py-2 text-right font-mono" :class="cellFor(l, q.id) && l.best_quote_id === q.id ? 'font-bold text-primary' : ''">
                                    <template v-if="cellFor(l, q.id)">{{ formatCurrency(Number(cellFor(l, q.id).total)) }}</template>
                                    <span v-else class="text-xs text-muted-foreground">—</span>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t">
                                <th scope="row" class="sticky left-0 bg-card py-2 pr-3 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">Round total</th>
                                <td v-for="q in quotes" :key="q.id" class="px-3 py-2 text-right font-mono text-sm font-bold">
                                    {{ formatCurrency(Number(q.total)) }}
                                    <span v-if="!q.complete" class="block text-[10px] font-normal text-muted-foreground">of {{ q.lines_quoted }} line(s)</span>
                                </td>
                            </tr>
                        </tfoot>
                </StaticTable>
                <p v-if="recommendation" class="pt-2 text-xs text-muted-foreground">{{ recommendation.reason }}</p>
                <p v-else class="pt-2 text-xs text-muted-foreground">
                    No vendor has answered every line within its validity, so there is no single winner to recommend — compare the lines yourself, or ask for the missing prices.
                </p>
            </template>
        </EnterpriseCard>

        <EnterpriseCard v-if="rfq.status === 'sent' && canAward && !awarded" title="Award" :icon="Award">
            <div class="space-y-3">
                <div class="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" :disabled="!recommendation" @click="resetPicks('recommended')">Take the recommended vendor</Button>
                    <Button size="sm" variant="outline" @click="resetPicks('cheapest')">Cheapest line by line</Button>
                    <Button size="sm" variant="ghost" @click="lines.forEach((l: any) => { picks[String(l.id)] = null; })">Clear</Button>
                </div>

                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    <label v-for="l in lines" :key="l.id" class="flex items-center justify-between gap-2 rounded-lg border bg-card px-3 py-2 text-sm">
                        <span class="truncate">{{ l.description }}</span>
                        <select v-model="picks[String(l.id)]" class="h-8 rounded-md border bg-background px-2 text-xs" :aria-label="`Which answer wins ${l.description}`">
                            <option :value="null">— no award —</option>
                            <option v-for="q in quotes.filter((x: any) => cellFor(l, x.id) && !x.expired)" :key="q.id" :value="q.id">
                                {{ q.vendor }} · {{ formatCurrency(Number(cellFor(l, q.id).total)) }}
                            </option>
                        </select>
                    </label>
                </div>

                <div v-if="!rfq.requisition" class="max-w-sm">
                    <FormField label="Receiving warehouse" help="This round has no requisition to inherit one from, so an order cannot be written without it.">
                        <SearchableSelect v-model="warehouseId" endpoint="/inventory/api/options/warehouses" placeholder="Where does it land?" />
                    </FormField>
                </div>

                <div class="flex flex-wrap items-end justify-between gap-3">
                    <p class="text-xs text-muted-foreground">
                        {{ chosen.length }} of {{ lines.length }} line(s) picked · {{ vendorsChosen.size }} vendor(s)
                        <template v-if="vendorsChosen.size > 1"> → one draft order each</template>
                        <span class="ml-1 font-mono font-semibold">{{ formatCurrency(chosenTotal) }}</span>
                    </p>
                    <Button :disabled="!chosen.length" @click="award">
                        <Check class="size-4" /> Award {{ chosen.length }} line(s)
                    </Button>
                </div>
                <p class="text-xs text-muted-foreground">
                    An award writes draft purchase orders — never a confirmed one. Confirming, receiving and paying stay separate decisions with their own permissions.
                </p>
            </div>
        </EnterpriseCard>

        <div v-else-if="awarded && (rfq.awards ?? []).length" class="rounded-xl border bg-card p-4">
            <h3 class="mb-2 text-sm font-semibold">Awarded</h3>
            <ul class="space-y-1 text-sm">
                <li v-for="a in rfq.awards" :key="a.id" class="flex flex-wrap items-center gap-2">
                    <span class="font-mono text-xs">{{ a.quote?.vendor?.name ?? 'vendor #' + a.quote_id }}</span>
                    <span>{{ a.description ?? 'line #' + a.rfq_line_id }}</span>
                    <span class="font-mono text-xs">{{ Number(a.qty) }} × {{ formatCurrency(Number(a.unit_price)) }}</span>
                    <Link v-if="a.purchase_order_id" :href="route('purchase.orders.show', a.purchase_order_id)" class="text-primary hover:underline">
                        PO #{{ a.purchase_order_id }}
                    </Link>
                </li>
            </ul>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <AttachmentPanel type="purchase-rfq" :record-id="rfq.id" :attachments="attachments" :can-manage="Boolean(canManage) && draft" />
            <ActivityTimeline :activities="activities" />
        </div>
    </AdminLayout>
</template>
