<script setup lang="ts">
import PageHeader from '@core/Components/PageHeader.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Plus, Settings2, ShieldCheck, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

import { useConfirm } from '@core/Composables/useConfirm';
const { confirm } = useConfirm();
interface Policy {
    id: number;
    name: string;
    document_type: string;
    min_amount: string;
    approver_permission: string;
    require_segregation: boolean;
    priority: number;
    is_active: boolean;
    description: string | null;
}
interface Props {
    policies: Policy[];
    documentTypes: Array<{ value: string; label: string }>;
}
const props = defineProps<Props>();

const TYPE_LABEL: Record<string, string> = {
    purchase_order: 'Purchase Order',
    vendor_bill: 'Vendor Bill',
    sales_invoice: 'Sales Invoice',
};

const creating = ref(false);
const form = useForm({
    name: '',
    document_type: 'purchase_order',
    min_amount: '50000',
    approver_permission: 'purchase.orders.approve',
    require_segregation: true,
    priority: 10,
    description: '',
});

async function submit(): void {
    form.post(route('core.approvals.policies.store'), {
        preserveScroll: true,
        onSuccess: () => {
            creating.value = false;
            form.reset();
        },
    });
}

async function toggle(p: Policy): void {
    router.post(route('core.approvals.policies.toggle', { policy: p.id }), {}, { preserveScroll: true });
}

async function destroy(p: Policy): void {
    if (await confirm({ description: `Delete policy "${p.name}"? Existing requests are kept.`, danger: true })) {
        router.delete(route('core.approvals.policies.destroy', { policy: p.id }), { preserveScroll: true });
    }
}

const money = (v: string): string => Number(v).toLocaleString('en-BD', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
</script>

<template>
    <Head title="Approval Policies" />
    <AdminLayout>
        <PageHeader
            title="Approval Policies"
            description="Define which documents need a second pair of eyes, above which amount, and who may decide. Inactive policies are ignored."
            :icon="ShieldCheck"
            :actions="[{ label: 'Back to inbox', route: 'core.approvals.index', variant: 'outline' }]"
        />

        <div class="mt-4 rounded-lg border border-sky-200 bg-sky-50 px-4 py-3 text-[13px] text-sky-800 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-300">
            <strong>Segregation of duties:</strong> when enabled, the person who raised a request cannot approve it — a different authorised user must decide. Policies ship inactive; activate one to enforce it.
        </div>

        <div class="mt-6 overflow-hidden rounded-xl border bg-card shadow-sm">
            <div class="flex items-center justify-between border-b px-4 py-3">
                <p class="text-sm font-semibold">Policy matrix</p>
                <Button size="sm" class="gap-1.5" @click="creating = true"><Plus class="size-4" /> New policy</Button>
            </div>
            <div v-if="props.policies.length === 0" class="px-6 py-12 text-center">
                <Settings2 class="mx-auto size-8 text-muted-foreground/40" />
                <p class="mt-2 text-sm font-medium">No policies yet</p>
                <p class="text-[13px] text-muted-foreground">Create a policy to require approval on high-value documents.</p>
            </div>
            <table v-else class="w-full text-sm">
                <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Policy</th>
                        <th class="px-4 py-2.5 font-semibold">Document</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Min amount</th>
                        <th class="px-4 py-2.5 font-semibold">Approver permission</th>
                        <th class="px-4 py-2.5 text-center font-semibold">SoD</th>
                        <th class="px-4 py-2.5 text-center font-semibold">Active</th>
                        <th class="px-4 py-2.5 text-right font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="p in props.policies" :key="p.id" class="hover:bg-accent/50" :class="p.is_active ? '' : 'opacity-60'">
                        <td class="px-4 py-3">
                            <span class="block font-medium">{{ p.name }}</span>
                            <span v-if="p.description" class="line-clamp-1 text-[12px] text-muted-foreground">{{ p.description }}</span>
                        </td>
                        <td class="px-4 py-3 text-[13px]">{{ TYPE_LABEL[p.document_type] ?? p.document_type }}</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(p.min_amount) }}</td>
                        <td class="px-4 py-3"><code class="rounded bg-muted px-1.5 py-0.5 text-[11px]">{{ p.approver_permission }}</code></td>
                        <td class="px-4 py-3 text-center"><span class="text-[12px]" :class="p.require_segregation ? 'text-emerald-600' : 'text-muted-foreground'">{{ p.require_segregation ? 'Yes' : 'No' }}</span></td>
                        <td class="px-4 py-3 text-center">
                            <button type="button" class="relative inline-flex h-5 w-9 items-center rounded-full transition" :class="p.is_active ? 'bg-emerald-500' : 'bg-muted-foreground/30'" :aria-label="p.is_active ? 'Deactivate' : 'Activate'" @click="toggle(p)">
                                <span class="inline-block size-4 transform rounded-full bg-background shadow transition" :class="p.is_active ? 'translate-x-4.5' : 'translate-x-0.5'" />
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <Button variant="ghost" size="icon" class="size-8 text-muted-foreground hover:text-rose-600" aria-label="Delete" @click="destroy(p)"><Trash2 class="size-4" /></Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Create dialog -->
        <Dialog :open="creating" @update:open="(v: boolean) => (creating = v)">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader><DialogTitle>New approval policy</DialogTitle></DialogHeader>
                <form class="space-y-3 py-2" @submit.prevent="submit">
                    <div>
                        <Label>Name</Label>
                        <Input v-model="form.name" class="mt-1.5" placeholder="High-value purchase orders" />
                        <p v-if="form.errors.name" class="mt-1 text-[12px] text-rose-600">{{ form.errors.name }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label>Document type</Label>
                            <select v-model="form.document_type" class="mt-1.5 h-9 w-full rounded-md border bg-background px-2 text-sm">
                                <option v-for="t in props.documentTypes" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                        <div>
                            <Label>Min amount (BDT)</Label>
                            <Input v-model="form.min_amount" type="number" min="0" step="0.01" class="mt-1.5" />
                            <p v-if="form.errors.min_amount" class="mt-1 text-[12px] text-rose-600">{{ form.errors.min_amount }}</p>
                        </div>
                    </div>
                    <div>
                        <Label>Approver permission</Label>
                        <Input v-model="form.approver_permission" class="mt-1.5" placeholder="purchase.orders.approve" />
                        <p class="mt-1 text-[11px] text-muted-foreground">Users holding this permission may decide requests under this policy.</p>
                        <p v-if="form.errors.approver_permission" class="mt-1 text-[12px] text-rose-600">{{ form.errors.approver_permission }}</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <Label>Priority</Label>
                            <Input v-model="form.priority" type="number" min="0" class="mt-1.5" />
                            <p class="mt-1 text-[11px] text-muted-foreground">Higher wins when several match.</p>
                        </div>
                        <div class="flex items-end pb-1">
                            <label class="flex items-center gap-2 text-sm">
                                <input v-model="form.require_segregation" type="checkbox" class="size-4 rounded border" />
                                Require segregation of duties
                            </label>
                        </div>
                    </div>
                    <div>
                        <Label>Description (optional)</Label>
                        <Textarea v-model="form.description" rows="2" class="mt-1.5" />
                    </div>
                    <DialogFooter>
                        <Button type="button" variant="outline" @click="creating = false">Cancel</Button>
                        <Button type="submit" :disabled="form.processing">Create policy</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
