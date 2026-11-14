<script setup lang="ts">
/**
 * The requisition record: what was asked, what it costs, who decided, and what the decision allows next.
 *
 * The action panel is rendered from the status the server sent, never from a click — a button that leads
 * to a DomainException is a bug wearing a label. Where a grant is missing the button is absent, because
 * the route would 403 and the reader deserves to know why the list looks short.
 */
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { ClipboardList, Send, Check, X, Trash2, FileText, Calculator, Handshake, ExternalLink } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { confirmDialog, promptDialog } from '@core/Composables/useConfirm';
import { formatCurrency } from '@core/Utils/format';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import DetailItem from '@core/Components/DetailItem.vue';
import StaticTable from '@core/Components/StaticTable.vue';
import ActivityTimeline from '@core/Components/ActivityTimeline.vue';
import AttachmentPanel from '@core/Components/AttachmentPanel.vue';

const props = defineProps<{
    requisition: any;
    budget?: Record<string, any> | null;
    activities?: any[];
    attachments?: any[];
    canManage?: boolean;
    canApprove?: boolean;
    canQuote?: boolean;
}>();

const req = computed(() => props.requisition);
const lines = computed<any[]>(() => req.value.items ?? []);
const isDraft = computed(() => req.value.status === 'draft');
/** A budget block makes "submit" impossible; the approver's way round it is a recorded override. */
const blockedByBudget = computed(() => Boolean(props.budget?.over));

const tone = computed(() => {
    const s = req.value.status;

    if (['approved', 'converted'].includes(s)) return 'success';
    if (s === 'submitted') return 'info';
    if (['rejected', 'cancelled'].includes(s)) return 'danger';

    return 'warning';
});

/**
 * Serialised date casts are ISO strings (`2026-11-20T00:00:00.000000Z`); a date on a document is a day,
 * and `<input type="date">` only accepts `YYYY-MM-DD` — so both the label and the form go through here.
 */
function day(value: unknown): string | null {
    return value ? String(value).slice(0, 10) : null;
}

function remaining(line: any) {
    return Math.max(0, Math.round((Number(line.qty) - Number(line.qty_ordered ?? 0)) * 100) / 100);
}

async function submit() {
    if (! (await confirmDialog({ title: 'Submit for approval?', description: 'Once submitted the lines are fixed — the approver decides on what you wrote.', confirmLabel: 'Submit' }))) {
        return;
    }

    router.post(route('purchase.requisitions.submit', req.value.id));
}

async function approve() {
    const override = blockedByBudget.value;

    if (override) {
        // No free-text field here on purpose: a requisition carries one reason column —
        // `rejection_reason` — and inventing an approval note the server would discard is a worse promise
        // than a confirmation that names the numbers being waived.
        if (! (await confirmDialog({
            title: 'Over budget — approve anyway?',
            description: `${formatCurrency(Number(props.budget?.requested))} requested against ${formatCurrency(Number(props.budget?.headroom))} of headroom on ${props.budget?.account ?? 'the budget line'}. The override is recorded as your decision: your name, today's date, on this requisition.`,
            confirmLabel: 'Approve with override',
            danger: true,
        }))) {
            return;
        }

        router.post(route('purchase.requisitions.approve', req.value.id), { override_budget: 1 });

        return;
    }

    if (! (await confirmDialog({ title: 'Approve this requisition?', description: 'It becomes quotable and orderable. Nothing is committed to a vendor yet.', confirmLabel: 'Approve' }))) {
        return;
    }

    router.post(route('purchase.requisitions.approve', req.value.id));
}

async function reject() {
    const reason = await promptDialog({
        title: 'Reject this requisition?',
        description: 'The requester sees this text on their record, so write it to them, not about them.',
        inputLabel: 'Reason',
        inputType: 'textarea',
        confirmLabel: 'Reject',
        danger: true,
    });

    if (! reason) {
        return;
    }

    router.post(route('purchase.requisitions.reject', req.value.id), { reason });
}

async function cancel() {
    if (await confirmDialog({ title: 'Cancel this requisition?', description: 'It stops being actionable but stays on the register.', confirmLabel: 'Cancel requisition', danger: true })) {
        router.post(route('purchase.requisitions.cancel', req.value.id));
    }
}

async function remove() {
    if (await confirmDialog({ title: `Delete ${req.value.req_no}?`, description: 'Only a draft can be deleted, and a deleted draft leaves no trace on the register.', confirmLabel: 'Delete', danger: true })) {
        router.delete(route('purchase.requisitions.destroy', req.value.id));
    }
}

function requestQuotes() {
    router.post(route('purchase.requisitions.requestQuotes', req.value.id));
}

const headroomPct = computed(() => {
    const plan = Number(props.budget?.plan ?? 0);

    if (plan <= 0) {
        return null;
    }

    return Math.min(100, Math.max(0, Math.round((Number(props.budget?.actual ?? 0) / plan) * 100)));
});

const showOverrideHelp = ref(false);
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Requisitions', href: route('purchase.requisitions.index') }, { title: req.req_no }]">
        <Head :title="req.req_no" />

        <PageHeader
            :title="req.title"
            :description="`Raised by ${req.requester?.name ?? '—'}${req.department ? ' · ' + req.department : ''}`"
            :icon="ClipboardList"
            :actions="[{ label: 'All requisitions', href: route('purchase.requisitions.index'), variant: 'outline' }]"
        >
            <template #badge>
                <StatusBadge :label="req.status" :tone="tone" dot />
                <StatusBadge v-if="blockedByBudget" label="over budget" tone="danger" />
            </template>
        </PageHeader>

        <div class="grid gap-4 lg:grid-cols-3">
            <EnterpriseCard class="lg:col-span-2" title="What is wanted">
                <div class="grid gap-3 sm:grid-cols-4">
                    <DetailItem label="Number" :value="req.req_no" mono />
                    <DetailItem label="Deliver to" :value="req.warehouse?.name ?? '—'" />
                    <DetailItem label="Needed by" :value="day(req.need_by) ?? 'not stated'" />
                    <DetailItem label="Estimate" :value="formatCurrency(Number(req.estimated_amount))" mono />
                </div>
                <p v-if="req.justification" class="mt-3 rounded-lg bg-muted/40 px-3 py-2 text-sm">
                    <span class="font-semibold text-muted-foreground">Why: </span>{{ req.justification }}
                </p>
                <p v-if="req.rejection_reason" class="mt-2 text-sm">
                    <span class="font-semibold text-muted-foreground">Rejection reason: </span>{{ req.rejection_reason }}
                </p>
            </EnterpriseCard>

            <EnterpriseCard title="Decision">
                <div class="space-y-2 text-sm">
                    <DetailItem label="Decided by" :value="req.reviewer?.name ?? 'not yet'" />
                    <DetailItem label="Decided on" :value="day(req.reviewed_at) ?? '—'" />
                    <DetailItem v-if="req.converted_order" label="Became order" :value="req.converted_order.order_no ?? String(req.converted_order_id)" />

                    <div v-if="canManage && isDraft" class="flex flex-wrap gap-2 pt-2">
                        <Button size="sm" :disabled="blockedByBudget" @click="submit">
                            <Send class="size-4" /> Submit
                        </Button>
                        <Button size="sm" variant="outline" class="text-destructive" @click="remove">
                            <Trash2 class="size-4" /> Delete
                        </Button>
                        <Link
                            v-if="!blockedByBudget"
                            :href="route('purchase.requisitions.edit', req.id)"
                            class="inline-flex h-9 items-center gap-2 rounded-md border border-input bg-transparent px-3 text-sm font-medium hover:bg-accent"
                        >Edit draft</Link>
                    </div>

                    <div v-if="canApprove && (req.status === 'submitted' || blockedByBudget)" class="flex flex-wrap gap-2 pt-2">
                        <Button size="sm" @click="approve">
                            <Check class="size-4" /> {{ blockedByBudget ? 'Approve with override' : 'Approve' }}
                        </Button>
                        <Button size="sm" variant="outline" class="text-destructive" @click="reject">
                            <X class="size-4" /> Reject
                        </Button>
                        <p v-if="blockedByBudget" class="w-full text-xs text-muted-foreground">
                            Submitting is blocked while this is over plan, so approval here carries the override —
                            one decision, with your name and today's date on it.
                            <button type="button" class="underline" @click="showOverrideHelp = !showOverrideHelp">Why is it not on the submit button?</button>
                        </p>
                        <p v-if="showOverrideHelp" class="w-full rounded bg-muted/40 p-2 text-xs">
                            Because a waiver is not a clerical step. The person who wants the goods may not waive
                            the budget, so the flag is read only from the approve action, where the permission is
                            checked again — and it is the approve action that stamps who decided.
                        </p>
                    </div>

                    <div v-if="canQuote && req.status === 'approved'" class="pt-2">
                        <Button size="sm" variant="outline" @click="requestQuotes">
                            <Handshake class="size-4" /> Request quotes
                        </Button>
                        <p class="pt-1 text-xs text-muted-foreground">Opens a round carrying every line that still has quantity outstanding.</p>
                    </div>

                    <div v-if="canManage && ['submitted', 'approved'].includes(req.status)" class="pt-2">
                        <Button size="sm" variant="ghost" class="text-muted-foreground" @click="cancel">Withdraw this request</Button>
                    </div>

                    <p v-if="req.status === 'converted' && req.converted_order" class="pt-2">
                        <Link :href="route('purchase.orders.show', req.converted_order.id)" class="inline-flex items-center gap-1 text-sm text-primary hover:underline">
                            <ExternalLink class="size-3.5" /> Open the purchase order
                        </Link>
                    </p>
                </div>
            </EnterpriseCard>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <StaticTable
                    :empty="!lines.length"
                    empty-title="No lines"
                    empty-description="A requisition with nothing in it is a memo."
                >
                    <template #head>
                        <thead>
                        <tr class="text-xs text-muted-foreground">
                            <th class="text-left">Item</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Expected</th>
                            <th class="text-right">Estimate</th>
                            <th class="text-right">Ordered</th>
                        </tr>
                        </thead>
                    </template>
                    <tbody>
                    <tr v-for="line in lines" :key="line.id" class="border-t">
                        <td>
                            <div class="text-sm">{{ line.description }}</div>
                            <div v-if="line.product" class="text-xs text-muted-foreground">{{ line.product.sku }} · {{ line.product.name }}</div>
                        </td>
                        <td class="text-right font-mono text-sm">{{ Number(line.qty) }}</td>
                        <td class="text-right font-mono text-sm">{{ formatCurrency(Number(line.expected_unit_price)) }}</td>
                        <td class="text-right font-mono text-sm font-semibold">{{ formatCurrency(Number(line.line_estimate)) }}</td>
                        <td class="text-right font-mono text-sm">
                            {{ Number(line.qty_ordered ?? 0) }}
                            <span v-if="remaining(line) > 0 && remaining(line) !== Number(line.qty)" class="text-xs text-muted-foreground">· {{ remaining(line) }} left</span>
                        </td>
                    </tr>
                    </tbody>
                </StaticTable>
            </div>

            <EnterpriseCard title="Budget" :icon="Calculator">
                <div v-if="!budget" class="text-sm text-muted-foreground">
                    No budget line governs this requisition — either it names no account, or the month
                    {{ req.need_by ?? 'of need' }} has no approved plan. That is not a violation; it is an
                    uncontrolled spend, and the difference matters at reporting time.
                </div>
                <div v-else class="space-y-2 text-sm">
                    <DetailItem label="Account" :value="budget.account ?? '—'" />
                    <DetailItem label="Period" :value="budget.period" />
                    <div class="grid grid-cols-2 gap-2">
                        <div class="rounded-lg bg-muted/40 px-3 py-2">
                            <p class="text-xs text-muted-foreground">Plan</p>
                            <p class="font-mono text-sm font-semibold">{{ formatCurrency(Number(budget.plan)) }}</p>
                        </div>
                        <div class="rounded-lg bg-muted/40 px-3 py-2">
                            <p class="text-xs text-muted-foreground">Posted</p>
                            <p class="font-mono text-sm font-semibold">{{ formatCurrency(Number(budget.actual)) }}</p>
                        </div>
                        <div class="rounded-lg bg-muted/40 px-3 py-2">
                            <p class="text-xs text-muted-foreground">Headroom</p>
                            <p class="font-mono text-sm font-semibold">{{ formatCurrency(Number(budget.headroom)) }}</p>
                        </div>
                        <div class="rounded-lg px-3 py-2" :class="budget.over ? 'bg-destructive/10 text-destructive' : 'bg-muted/40'">
                            <p class="text-xs text-muted-foreground">This request</p>
                            <p class="font-mono text-sm font-semibold">{{ formatCurrency(Number(budget.requested)) }}</p>
                        </div>
                    </div>
                    <div v-if="headroomPct !== null" class="h-1.5 w-full overflow-hidden rounded-full bg-muted">
                        <div class="h-full rounded-full" :class="budget.over ? 'bg-destructive' : 'bg-primary'" :style="{ width: headroomPct + '%' }" />
                    </div>
                    <p class="text-xs text-muted-foreground">{{ headroomPct ?? 0 }}% of the plan is already posted.</p>
                </div>
            </EnterpriseCard>
        </div>

        <div v-if="(req.rfqs ?? []).length" class="grid gap-4 md:grid-cols-2">
            <EnterpriseCard v-for="round in req.rfqs" :key="round.id" :title="round.rfq_no" :icon="Handshake">
                <div class="flex items-center justify-between gap-2">
                    <div class="text-sm">
                        <p>{{ round.subject }}</p>
                        <p class="text-xs text-muted-foreground">{{ (round.quotes ?? []).length }} quote(s) received</p>
                    </div>
                    <Link
                        :href="route('purchase.rfqs.show', round.id)"
                        class="inline-flex h-9 shrink-0 items-center gap-2 rounded-md border border-input bg-transparent px-3 text-sm font-medium hover:bg-accent"
                    ><FileText class="size-4" /> Open round</Link>
                </div>
            </EnterpriseCard>
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <AttachmentPanel type="purchase-requisition" :record-id="req.id" :attachments="attachments" :can-manage="Boolean(canManage) && isDraft" />
            <ActivityTimeline :activities="activities" />
        </div>
    </AdminLayout>
</template>
