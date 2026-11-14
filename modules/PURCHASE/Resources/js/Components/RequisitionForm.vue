<script setup lang="ts">
/**
 * RequisitionForm — the one editor for raising and amending an internal demand note (F18).
 *
 * Written once for both modes because the two differ in exactly three lines (url, method, heading) and
 * every divergence between them would be a field the approver sees on one screen and not the other.
 *
 * Two things are deliberate:
 *  - The estimate is computed here and *again* by the service. This preview is arithmetic on what the
 *    user typed; the stored number is arithmetic on what was written. Only the second one is trusted —
 *    which is why the total is shown as a preview, not as an input.
 *  - `estimated_amount`, `req_no` and `status` are never in the payload. A form that could set its own
 *    total is a form that can talk its way past a budget.
 */
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ClipboardList, Plus, Trash2, AlertCircle } from 'lucide-vue-next';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import FormField from '@core/Components/FormField.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormActions from '@core/Components/FormActions.vue';
import { formatCurrency } from '@core/Utils/format';

interface Line {
    product_id: number | string | null;
    description: string;
    uom_id: number | string | null;
    qty: number | string;
    expected_unit_price: number | string;
    notes: string;
}

const props = defineProps<{
    mode: 'create' | 'edit';
    requisition?: any;
    submitUrl: string;
    indexUrl: string;
}>();

const isEdit = computed(() => props.mode === 'edit');
const r = computed<any>(() => props.requisition ?? {});

const blankLine = (): Line => ({ product_id: null, description: '', uom_id: null, qty: 1, expected_unit_price: 0, notes: '' });

const form = useForm({
    title: r.value.title ?? '',
    department: r.value.department ?? '',
    warehouse_id: (r.value.warehouse?.id ?? r.value.warehouse_id ?? null) as number | string | null,
    // Serialised `date` casts are ISO; `<input type="date">` wants the day alone.
    need_by: r.value.need_by ? String(r.value.need_by).slice(0, 10) : '',
    account_id: (r.value.account_id ?? null) as number | string | null,
    justification: r.value.justification ?? '',
    items: isEdit.value && r.value.items?.length
        ? r.value.items.map((i: any): Line => ({
            product_id: i.product_id ?? null,
            description: i.description ?? '',
            uom_id: i.uom_id ?? null,
            qty: Number(i.qty),
            expected_unit_price: Number(i.expected_unit_price),
            notes: i.notes ?? '',
        }))
        : [blankLine()],
});

/**
 * Rounded on whole poisha, the same way the service does it. A preview that rounds differently from the
 * server would be a small, permanent lie about the number the budget is checked against.
 */
function lineTotal(i: number): number {
    const it = form.items[i] as Line;
    const cents = Math.round(Math.round((Number(it.qty) || 0) * 100) * Math.round((Number(it.expected_unit_price) || 0) * 100) / 100);

    return cents / 10000;
}

const total = computed(() => Math.round(form.items.reduce((sum: number, _: Line, i: number) => sum + Math.round(lineTotal(i) * 100), 0)) / 100);
const errorCount = computed(() => Object.keys(form.errors).length);

function addItem() {
    if (form.items.length < 100) {
        form.items.push(blankLine());
    }
}

function submit() {
    form.transform((data: any) => ({
        ...data,
        items: data.items
            .filter((it: Line) => it.description.trim() !== '' || it.product_id !== null)
            .map((it: Line) => ({ ...it, qty: Number(it.qty) || 0, expected_unit_price: Number(it.expected_unit_price) || 0 })),
    })).submit(isEdit.value ? 'put' : 'post', props.submitUrl, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-5">
        <PageHeader
            :title="isEdit ? `Edit ${r.req_no ?? 'requisition'}` : 'New Purchase Requisition'"
            description="What you need, for which department, by when. This asks; it does not buy."
            :icon="ClipboardList"
            :actions="[{ label: 'Back to requisitions', href: indexUrl, variant: 'outline' }]"
        >
            <template #badge>
                <StatusBadge v-if="isEdit" :label="r.status ?? 'draft'" :tone="r.status === 'draft' ? 'warning' : 'info'" dot />
            </template>
        </PageHeader>

        <p
            v-if="isEdit && r.status !== 'draft'"
            role="alert"
            class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
            <AlertCircle class="mt-0.5 size-4 shrink-0" />
            <span>This requisition is {{ r.status }}. Only a draft can be edited — the server will refuse this form.</span>
        </p>

        <form class="space-y-5" @submit.prevent="submit">
            <div v-if="errorCount" role="alert" class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                <AlertCircle class="mt-0.5 size-4 shrink-0" />
                <div>
                    <p class="font-semibold">{{ errorCount }} {{ errorCount === 1 ? 'field needs' : 'fields need' }} attention</p>
                    <p class="mt-0.5 text-destructive/85">{{ Object.values(form.errors)[0] as string }}</p>
                </div>
            </div>

            <FormSection title="What is wanted" description="Where it is going and when it is needed." :icon="ClipboardList" :cols="4">
                <FormField label="Title" required span="2" :error="form.errors.title as string">
                    <template #default="{ id, invalid }">
                        <Input v-model="form.title" :id="id" :aria-invalid="invalid" maxlength="180" placeholder="Reagents for the lab, Q4 restock" />
                    </template>
                </FormField>

                <FormField label="Department" :error="form.errors.department as string">
                    <template #default="{ id }">
                        <Input v-model="form.department" :id="id" maxlength="120" placeholder="Manufacturing" />
                    </template>
                </FormField>

                <FormField label="Deliver to" required :error="form.errors.warehouse_id as string">
                    <SearchableSelect v-model="form.warehouse_id" endpoint="/inventory/api/options/warehouses" placeholder="Receiving warehouse" />
                </FormField>

                <FormField label="Needed by" help="Drives the month the budget is checked against." :error="form.errors.need_by as string">
                    <template #default="{ id }">
                        <Input v-model="form.need_by" :id="id" type="date" class="font-mono" />
                    </template>
                </FormField>

                <FormField label="Budget account" help="Optional. With one set, an approved plan is enforced when this is submitted." span="2" :error="form.errors.account_id as string">
                    <SearchableSelect v-model="form.account_id" endpoint="/accounts/api/options/accounts" placeholder="None — no budget control" />
                </FormField>

                <FormField label="Why" help="The sentence an approver reads first." span="full" :error="form.errors.justification as string">
                    <template #default="{ id }">
                        <Textarea v-model="form.justification" :id="id" rows="2" class="resize-none" placeholder="Line 3 stops on the 20th without these." />
                    </template>
                </FormField>
            </FormSection>

            <FormSection
                title="Lines"
                description="Quantity and an expected price. A line with no price is a wish, not a budget figure."
                plain
                :badge="`${form.items.length} line${form.items.length === 1 ? '' : 's'}`"
            >
                <template #actions>
                    <Button type="button" variant="outline" size="sm" :disabled="form.items.length >= 100" @click="addItem">
                        <Plus class="size-4" /> Add line
                    </Button>
                </template>

                <div class="space-y-3">
                    <div class="hidden gap-x-5 xl:grid xl:grid-cols-12 xl:px-1">
                        <p class="col-span-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Product</p>
                        <p class="col-span-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Description</p>
                        <p class="col-span-1 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Qty</p>
                        <p class="col-span-2 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Unit</p>
                        <p class="text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Expected</p>
                        <p class="text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Estimate</p>
                    </div>

                    <div
                        v-for="(it, i) in form.items"
                        :key="i"
                        class="rounded-xl border bg-card p-4 transition hover:border-primary/30"
                        :class="form.errors[`items.${i}.description`] ? 'border-destructive/50' : 'border-border'"
                    >
                        <div class="grid items-end gap-x-5 gap-y-3 xl:grid-cols-12">
                            <FormField label="Product" class="xl:col-span-3" :label-class="'xl:sr-only'" :error="form.errors[`items.${i}.product_id`] as string">
                                <SearchableSelect v-model="it.product_id" endpoint="/inventory/api/options/products" placeholder="Off-catalogue?" />
                            </FormField>

                            <FormField label="Description" required class="xl:col-span-3" :label-class="'xl:sr-only'" :error="form.errors[`items.${i}.description`] as string">
                                <template #default="{ id, invalid }">
                                    <Input v-model="it.description" :id="id" :aria-invalid="invalid" maxlength="180" placeholder="What is being asked for" />
                                </template>
                            </FormField>

                            <FormField label="Qty" required class="xl:col-span-1" :label-class="'xl:sr-only'" :error="form.errors[`items.${i}.qty`] as string">
                                <template #default="{ id }">
                                    <Input v-model="it.qty" :id="id" type="number" step="0.001" min="0.001" class="text-right font-mono" />
                                </template>
                            </FormField>

                            <FormField label="Unit" class="xl:col-span-2" :label-class="'xl:sr-only'" :error="form.errors[`items.${i}.uom_id`] as string">
                                <SearchableSelect v-model="it.uom_id" endpoint="/inventory/api/options/uoms" placeholder="pcs / kg / box" />
                            </FormField>

                            <FormField label="Expected unit price" class="xl:col-span-2" :label-class="'xl:sr-only'" :error="form.errors[`items.${i}.expected_unit_price`] as string">
                                <template #default="{ id }">
                                    <Input v-model="it.expected_unit_price" :id="id" type="number" step="0.01" min="0" class="text-right font-mono" />
                                </template>
                            </FormField>

                            <div class="flex items-center justify-end gap-2 xl:col-span-1">
                                <p class="text-right font-mono text-sm font-semibold">{{ formatCurrency(lineTotal(i)) }}</p>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                    :aria-label="`Remove line ${i + 1}`"
                                    :disabled="form.items.length === 1"
                                    @click="form.items.splice(i, 1)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </FormSection>

            <FormActions
                :submit-label="isEdit ? 'Save changes' : 'Save draft'"
                :cancel-href="indexUrl"
                :processing="form.processing"
            >
                <template #summary>
                    <p class="font-mono text-sm font-bold">
                        {{ formatCurrency(total) }}
                        <span class="ml-1 font-sans text-xs font-normal text-muted-foreground">
                            · estimate only — approval and any budget check use the stored figure
                        </span>
                    </p>
                </template>
            </FormActions>
        </form>
    </div>
</template>
