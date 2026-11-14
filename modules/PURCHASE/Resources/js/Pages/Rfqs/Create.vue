<script setup lang="ts">
/**
 * Raise a quote round (F18).
 *
 * A round may stand alone — buyers sound a market before there is a requisition — but when one is
 * attached, its outstanding lines are carried over rather than retyped, and `requisition_item_id` is what
 * lets the award write back against the request instead of leaving it open forever.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Handshake, Plus, Trash2, AlertCircle } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormField from '@core/Components/FormField.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormActions from '@core/Components/FormActions.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';

const props = defineProps<{
    requisitionId?: number | null;
    requisition?: Record<string, any> | null;
}>();

// The template reads `requisition` for its banner; the form below reads it once, at construction.
const requisition = computed(() => props.requisition ?? null);

const blankLine = () => ({ requisition_item_id: null as number | null, description: '', qty: 1, uom_id: null as number | string | null });

const form = useForm({
    // Bound to the option picker, which posts the requisition id; carried straight through to the service.
    requisition_id: props.requisitionId ?? null,
    subject: props.requisition ? `Quote for ${props.requisition.req_no} — ${props.requisition.title}` : '',
    due_on: '',
    notes: '',
    lines: props.requisition?.lines?.length
        ? props.requisition.lines.map((l: any) => ({ ...blankLine(), ...l }))
        : [blankLine()],
});

function addLine() {
    if (form.lines.length < 100) {
        form.lines.push(blankLine());
    }
}

function dropLine(i: number) {
    if (form.lines.length > 1) {
        form.lines.splice(i, 1);
    }
}

const quoted = computed(() => form.lines.filter((l: any) => String(l.description).trim() !== '').length);
const errorCount = computed(() => Object.keys(form.errors).length);

function submit() {
    form.transform((data: any) => ({
        ...data,
        lines: data.lines.filter((l: any) => String(l.description).trim() !== ''),
    })).post(route('purchase.rfqs.store'), { preserveScroll: true });
}
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Quote Rounds', href: route('purchase.rfqs.index') }, { title: 'New' }]">
        <Head title="New Quote Round" />
        <div class="space-y-5">
            <PageHeader
                title="New Quote Round"
                description="What you want priced. Nothing is committed until a line is awarded."
                :icon="Handshake"
                :actions="[{ label: 'Back to rounds', href: route('purchase.rfqs.index'), variant: 'outline' }]"
            />

            <form class="space-y-5" @submit.prevent="submit">
                <div v-if="errorCount" role="alert" class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                    <AlertCircle class="mt-0.5 size-4 shrink-0" />
                    <div>
                        <p class="font-semibold">{{ errorCount }} {{ errorCount === 1 ? 'field needs' : 'fields need' }} attention</p>
                        <p class="mt-0.5 text-destructive/85">{{ Object.values(form.errors)[0] as string }}</p>
                    </div>
                </div>

                <FormSection title="The ask" description="A subject vendors will still recognise in a month." :icon="Handshake" :cols="4">
                    <FormField label="Subject" required span="2" :error="form.errors.subject as string">
                        <template #default="{ id, invalid }">
                            <Input v-model="form.subject" :id="id" :aria-invalid="invalid" maxlength="180" placeholder="40 pallet racking bays, installed" />
                        </template>
                    </FormField>

                    <FormField label="Quotes due by" help="A soft date: it is shown on the round and on every expiry warning." :error="form.errors.due_on as string">
                        <template #default="{ id }">
                            <Input v-model="form.due_on" :id="id" type="date" class="font-mono" />
                        </template>
                    </FormField>

                    <FormField label="For requisition" help="Optional. Attaching one carries its outstanding lines in and lets the award close the loop." span="2" :error="form.errors.requisition_id as string">
                        <SearchableSelect v-model="form.requisition_id" endpoint="/purchase/api/options/requisitions" placeholder="Market sounding — no requisition" />
                    </FormField>

                    <FormField label="Notes to vendors" span="full" :error="form.errors.notes as string">
                        <template #default="{ id }">
                            <Textarea v-model="form.notes" :id="id" rows="2" class="resize-none" placeholder="Delivery within 21 days, prices in BDT, installation included." />
                        </template>
                    </FormField>
                </FormSection>

                <FormSection
                    title="Lines to price"
                    description="Describe what you need; a product may be named on the quote itself, not here."
                    plain
                    :badge="`${form.lines.length} line${form.lines.length === 1 ? '' : 's'}`"
                >
                    <template #actions>
                        <Button type="button" variant="outline" size="sm" :disabled="form.lines.length >= 100" @click="addLine">
                            <Plus class="size-4" /> Add line
                        </Button>
                    </template>

                    <div v-if="requisition" class="mx-4 mt-3 rounded-lg bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
                        Carried from {{ requisition.req_no }}{{ requisition.warehouse ? ' · delivering to ' + requisition.warehouse : '' }} — quantities are what is still outstanding.
                    </div>

                    <div class="space-y-3">
                        <div class="hidden gap-x-5 xl:grid xl:grid-cols-12 xl:px-1">
                            <p class="col-span-7 text-xs font-semibold uppercase tracking-wider text-muted-foreground">What to price</p>
                            <p class="col-span-2 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Qty</p>
                            <p class="col-span-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Unit</p>
                            <p class="text-xs" />
                        </div>

                        <div
                            v-for="(ln, i) in form.lines"
                            :key="i"
                            class="grid items-end gap-x-5 gap-y-3 rounded-xl border bg-card p-4 xl:grid-cols-12"
                            :class="form.errors[`lines.${i}.description`] ? 'border-destructive/50' : 'border-border'"
                        >
                            <FormField label="What to price" required class="xl:col-span-7" :label-class="'xl:sr-only'" :error="form.errors[`lines.${i}.description`] as string">
                                <template #default="{ id, invalid }">
                                    <Input v-model="ln.description" :id="id" :aria-invalid="invalid" maxlength="180" placeholder="Selective pallet racking, 4 tiers" />
                                </template>
                            </FormField>

                            <FormField label="Qty" required class="xl:col-span-2" :label-class="'xl:sr-only'" :error="form.errors[`lines.${i}.qty`] as string">
                                <template #default="{ id }">
                                    <Input v-model="ln.qty" :id="id" type="number" step="0.001" min="0.001" class="text-right font-mono" />
                                </template>
                            </FormField>

                            <FormField label="Unit" class="xl:col-span-2" :label-class="'xl:sr-only'" :error="form.errors[`lines.${i}.uom_id`] as string">
                                <SearchableSelect v-model="ln.uom_id" endpoint="/inventory/api/options/uoms" placeholder="pcs / set / m" />
                            </FormField>

                            <div class="flex justify-end xl:col-span-1">
                                <Button type="button" variant="ghost" size="icon" class="size-8 text-muted-foreground hover:text-destructive" :aria-label="`Remove line ${i + 1}`" @click="dropLine(i)">
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>
                    </div>
                </FormSection>

                <FormActions submit-label="Create draft round" :cancel-href="route('purchase.rfqs.index')" :processing="form.processing">
                    <template #summary>
                        <p class="text-xs text-muted-foreground">
                            {{ quoted }} of {{ form.lines.length }} line(s) filled · a draft round can still be edited; sending it to vendors cannot be undone.
                        </p>
                    </template>
                </FormActions>
            </form>
        </div>
    </AdminLayout>
</template>
