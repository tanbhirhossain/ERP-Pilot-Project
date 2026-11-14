<script setup lang="ts">
import PageHeader from '@core/Components/PageHeader.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Head, useForm } from '@inertiajs/vue3';
import { CheckCircle2, ClipboardCheck, Settings2, XCircle } from 'lucide-vue-next';
import { ref } from 'vue';

interface ApprovalRow {
    id: number;
    document_type: string;
    document_no: string | null;
    amount: string;
    status: 'pending' | 'approved' | 'rejected';
    policy: { name: string; approver_permission: string } | null;
    requester: string | null;
    decider: string | null;
    decision_note: string | null;
    requested_at: string | null;
    decided_at: string | null;
}
interface Props {
    pending: ApprovalRow[];
    mine: ApprovalRow[];
    recent: ApprovalRow[];
}
const props = defineProps<Props>();

const TYPE_LABEL: Record<string, string> = {
    purchase_order: 'Purchase Order',
    vendor_bill: 'Vendor Bill',
    sales_invoice: 'Sales Invoice',
};
const STATUS_TONE: Record<string, string> = {
    pending: 'bg-amber-500/10 text-amber-600',
    approved: 'bg-emerald-500/10 text-emerald-600',
    rejected: 'bg-rose-500/10 text-rose-600',
};

const money = (v: string): string => Number(v).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

// Decision dialog — approve needs no note, reject requires one.
const deciding = ref<ApprovalRow | null>(null);
const decisionMode = ref<'approve' | 'reject'>('approve');
const form = useForm({ note: '' });

function openDecision(row: ApprovalRow, mode: 'approve' | 'reject'): void {
    deciding.value = row;
    decisionMode.value = mode;
    form.note = '';
}

function submitDecision(): void {
    if (!deciding.value) return;
    const routeName = decisionMode.value === 'approve' ? 'core.approvals.approve' : 'core.approvals.reject';
    form.post(route(routeName, { approval: deciding.value.id }), {
        preserveScroll: true,
        onSuccess: () => {
            deciding.value = null;
            form.reset();
        },
    });
}
</script>

<template>
    <Head title="Approvals" />
    <AdminLayout>
        <PageHeader
            title="Approvals"
            description="Documents waiting for a decision, requests you raised, and the recent decision trail."
            :icon="ClipboardCheck"
            :actions="[{ label: 'Policies', route: 'core.approvals.policies', icon: Settings2, variant: 'outline' }]"
        />

        <!-- Pending — the action queue -->
        <section class="mt-6">
            <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold">
                Awaiting your decision
                <span v-if="props.pending.length" class="rounded-full bg-amber-500/10 px-2 py-0.5 text-[11px] font-bold text-amber-600">{{ props.pending.length }}</span>
            </h2>
            <div v-if="props.pending.length === 0" class="rounded-xl border border-dashed bg-card px-6 py-10 text-center">
                <CheckCircle2 class="mx-auto size-8 text-emerald-500/60" />
                <p class="mt-2 text-sm font-medium">Nothing waiting on you</p>
                <p class="text-[13px] text-muted-foreground">Requests you're authorised to decide will appear here.</p>
            </div>
            <div v-else class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-semibold">Document</th>
                            <th class="px-4 py-2.5 font-semibold">Policy</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Amount</th>
                            <th class="px-4 py-2.5 font-semibold">Raised by</th>
                            <th class="px-4 py-2.5 font-semibold">When</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Decision</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in props.pending" :key="row.id" class="hover:bg-accent/50">
                            <td class="px-4 py-3">
                                <span class="block font-medium">{{ TYPE_LABEL[row.document_type] ?? row.document_type }}</span>
                                <span class="text-[12px] text-muted-foreground">{{ row.document_no ?? `#${row.id}` }}</span>
                            </td>
                            <td class="px-4 py-3 text-[13px] text-muted-foreground">{{ row.policy?.name ?? '—' }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(row.amount) }}</td>
                            <td class="px-4 py-3 text-[13px]">{{ row.requester ?? '—' }}</td>
                            <td class="px-4 py-3 text-[12px] text-muted-foreground">{{ row.requested_at }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-1.5">
                                    <Button size="sm" class="gap-1 bg-success text-background hover:bg-success/90" @click="openDecision(row, 'approve')"><CheckCircle2 class="size-3.5" /> Approve</Button>
                                    <Button size="sm" variant="outline" class="gap-1 text-rose-600 hover:bg-rose-50" @click="openDecision(row, 'reject')"><XCircle class="size-3.5" /> Reject</Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- My requests -->
        <section class="mt-8">
            <h2 class="mb-3 text-sm font-semibold">Requests you raised</h2>
            <div v-if="props.mine.length === 0" class="rounded-xl border border-dashed bg-card px-6 py-8 text-center text-[13px] text-muted-foreground">
                You haven't raised any approval requests. They're created automatically when a document crosses a policy threshold.
            </div>
            <div v-else class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-semibold">Document</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Amount</th>
                            <th class="px-4 py-2.5 font-semibold">Status</th>
                            <th class="px-4 py-2.5 font-semibold">Decided by</th>
                            <th class="px-4 py-2.5 font-semibold">Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in props.mine" :key="row.id">
                            <td class="px-4 py-3">
                                <span class="block font-medium">{{ TYPE_LABEL[row.document_type] ?? row.document_type }}</span>
                                <span class="text-[12px] text-muted-foreground">{{ row.document_no ?? `#${row.id}` }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(row.amount) }}</td>
                            <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-[11px] font-bold capitalize" :class="STATUS_TONE[row.status]">{{ row.status }}</span></td>
                            <td class="px-4 py-3 text-[13px]">{{ row.decider ?? '—' }}</td>
                            <td class="px-4 py-3 text-[12px] text-muted-foreground">{{ row.decision_note ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Recent decisions -->
        <section class="mt-8">
            <h2 class="mb-3 text-sm font-semibold">Recent decisions</h2>
            <div v-if="props.recent.length === 0" class="rounded-xl border border-dashed bg-card px-6 py-8 text-center text-[13px] text-muted-foreground">No decisions yet.</div>
            <div v-else class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                        <tr>
                            <th class="px-4 py-2.5 font-semibold">Document</th>
                            <th class="px-4 py-2.5 text-right font-semibold">Amount</th>
                            <th class="px-4 py-2.5 font-semibold">Status</th>
                            <th class="px-4 py-2.5 font-semibold">Raised by</th>
                            <th class="px-4 py-2.5 font-semibold">Decided by</th>
                            <th class="px-4 py-2.5 font-semibold">When</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="row in props.recent" :key="row.id">
                            <td class="px-4 py-3">
                                <span class="block font-medium">{{ TYPE_LABEL[row.document_type] ?? row.document_type }}</span>
                                <span class="text-[12px] text-muted-foreground">{{ row.document_no ?? `#${row.id}` }}</span>
                            </td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(row.amount) }}</td>
                            <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-[11px] font-bold capitalize" :class="STATUS_TONE[row.status]">{{ row.status }}</span></td>
                            <td class="px-4 py-3 text-[13px]">{{ row.requester ?? '—' }}</td>
                            <td class="px-4 py-3 text-[13px]">{{ row.decider ?? '—' }}</td>
                            <td class="px-4 py-3 text-[12px] text-muted-foreground">{{ row.decided_at }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Decision dialog -->
        <Dialog :open="deciding !== null" @update:open="(v: boolean) => !v && (deciding = null)">
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{{ decisionMode === 'approve' ? 'Approve request' : 'Reject request' }}</DialogTitle>
                </DialogHeader>
                <div v-if="deciding" class="space-y-3 py-2">
                    <p class="text-[13px] text-muted-foreground">
                        {{ TYPE_LABEL[deciding.document_type] }} {{ deciding.document_no }} · <span class="font-semibold text-foreground">{{ money(deciding.amount) }} BDT</span>
                    </p>
                    <div>
                        <Label>{{ decisionMode === 'approve' ? 'Note (optional)' : 'Reason (required)' }}</Label>
                        <Textarea v-model="form.note" rows="3" :placeholder="decisionMode === 'approve' ? 'Add a note for the audit trail…' : 'Why is this being rejected?'" class="mt-1.5" />
                        <p v-if="form.errors.note" class="mt-1 text-[12px] text-rose-600">{{ form.errors.note }}</p>
                    </div>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="deciding = null">Cancel</Button>
                    <Button :class="decisionMode === 'approve' ? 'bg-emerald-600 hover:bg-emerald-700' : 'bg-rose-600 hover:bg-rose-700'" :disabled="form.processing || (decisionMode === 'reject' && !form.note.trim())" @click="submitDecision">
                        {{ decisionMode === 'approve' ? 'Approve' : 'Reject' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
