<script setup lang="ts">
/**
 * InvoiceForm — the single sales invoice editor.
 *
 * `Invoices/Edit.vue` was a 14-line stub: it rendered only Customer, Product and Qty, so invoice
 * date, warehouse, due date, unit price, discount and VAT were uneditable, there was no way to
 * add or remove a line, and nothing reported validation errors. `Invoices/Create.vue` was a
 * fuller but separate copy that read the order-prefill customer from
 * `(globalThis as any).__orderCustomer` — a global that nothing in the codebase ever sets, so
 * "Invoice this order" opened a blank customer every time. Both are now thin wrappers.
 */
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { AlertCircle, CreditCard, FileText, Lock, Plus, Trash2 } from 'lucide-vue-next';
import SearchableSelect from '../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import PageHeader from '../../../../CORE/Resources/js/Components/PageHeader.vue';
import StatusBadge from '../../../../CORE/Resources/js/Components/StatusBadge.vue';
import FormField from '../../../../CORE/Resources/js/Components/FormField.vue';
import FormSection from '../../../../CORE/Resources/js/Components/FormSection.vue';
import FormActions from '../../../../CORE/Resources/js/Components/FormActions.vue';
import FormSelect from '../../../../CORE/Resources/js/Components/FormSelect.vue';

const props = withDefaults(
    defineProps<{
        mode: 'create' | 'edit';
        invoice?: any;
        /** Set when the invoice is raised from a sales order. */
        order?: any;
        submitUrl: string;
        indexUrl: string;
    }>(),
    { invoice: null, order: null },
);

const isEdit = computed(() => props.mode === 'edit');
const inv = computed<any>(() => props.invoice ?? {});
const ord = computed<any>(() => props.order ?? null);

const status = computed(() => String(inv.value.status ?? 'draft'));
/** `InvoiceRepository::update()` refuses anything but a draft. */
const locked = computed(() => isEdit.value && status.value !== 'draft');

const statusTone = computed(() => {
    if (status.value === 'paid') return 'success' as const;
    if (status.value === 'posted') return 'info' as const;
    if (status.value === 'cancelled') return 'danger' as const;

    return 'warning' as const;
});

const PAYMENT_METHODS = ['credit', 'cash', 'bank', 'cheque'];

const blankItem = () => ({
    product_id: null as number | null,
    qty: 1 as number | null,
    unit_price: null as number | null,
    discount_pct: 0 as number | null,
    vat_rate: 0 as number | null,
});

const mapItems = (rows: any[]) =>
    rows.map((it: any) => ({
        product_id: it.product_id ?? null,
        qty: Number(it.qty ?? 1),
        unit_price: Number(it.unit_price ?? 0),
        discount_pct: Number(it.discount_pct ?? 0),
        vat_rate: Number(it.vat_rate ?? 0),
    }));

const initialItems = computed(() => {
    if (isEdit.value) return inv.value.items?.length ? mapItems(inv.value.items) : [blankItem()];
    if (ord.value?.items?.length) return mapItems(ord.value.items);

    return [blankItem()];
});

const form = useForm({
    order_id: isEdit.value ? (inv.value.order_id ?? null) : (ord.value?.id ?? null),
    customer_id: isEdit.value ? (inv.value.customer_id ?? null) : (ord.value?.customer_id ?? null),
    warehouse_id: isEdit.value ? (inv.value.warehouse_id ?? null) : (ord.value?.warehouse_id ?? null),
    invoice_date: isEdit.value ? String(inv.value.invoice_date ?? '').slice(0, 10) : new Date().toISOString().slice(0, 10),
    due_date: isEdit.value
        ? inv.value.due_date
            ? String(inv.value.due_date).slice(0, 10)
            : ''
        : ord.value?.delivery_date
          ? String(ord.value.delivery_date).slice(0, 10)
          : '',
    currency: isEdit.value ? (inv.value.currency ?? 'BDT') : 'BDT',
    payment_method: isEdit.value ? (inv.value.payment_method ?? 'credit') : 'credit',
    notes: isEdit.value
        ? (inv.value.notes ?? '')
        : ord.value
          ? `Invoice for order ${ord.value.order_no ?? ''}`.trim()
          : '',
    items: initialItems.value,
});

const n = (v: unknown) => Number(v) || 0;

function lineGross(i: number) {
    return n(form.items[i]?.qty) * n(form.items[i]?.unit_price);
}
function lineDiscount(i: number) {
    return lineGross(i) * (n(form.items[i]?.discount_pct) / 100);
}
function lineVat(i: number) {
    return (lineGross(i) - lineDiscount(i)) * (n(form.items[i]?.vat_rate) / 100);
}
function lineTotal(i: number) {
    return lineGross(i) - lineDiscount(i) + lineVat(i);
}

const subtotal = computed(() => form.items.reduce((s, _it, i) => s + lineGross(i), 0));
const discountTotal = computed(() => form.items.reduce((s, _it, i) => s + lineDiscount(i), 0));
const vatTotal = computed(() => form.items.reduce((s, _it, i) => s + lineVat(i), 0));
const total = computed(() => subtotal.value - discountTotal.value + vatTotal.value);

const money = (v: number) => v.toFixed(2);

/** `due_date` is validated `after_or_equal:invoice_date`; warn before the server does. */
const dueTooEarly = computed(
    () => Boolean(form.due_date) && Boolean(form.invoice_date) && form.due_date < form.invoice_date,
);

function addItem() {
    form.items.push(blankItem());
}
function removeItem(i: number) {
    form.items.splice(i, 1);
    if (!form.items.length) addItem();
}

const errorCount = computed(() => Object.keys(form.errors).length);

function submit() {
    if (locked.value) return;

    if (isEdit.value) {
        form.put(props.submitUrl, { preserveScroll: true });

        return;
    }

    form.post(props.submitUrl);
}
</script>

<template>
    <div class="space-y-5">
        <PageHeader
            :title="isEdit ? `Edit ${inv.invoice_no ?? 'invoice'}` : 'New Invoice'"
            description="Posting deducts stock and writes Accounts Receivable (Dr) → Sales (Cr) with output VAT."
            :icon="FileText"
            :actions="[{ label: 'Back to invoices', href: indexUrl, variant: 'outline' }]"
        >
            <template #badge>
                <StatusBadge v-if="isEdit" :label="status" :tone="statusTone" dot />
                <StatusBadge v-if="ord" :label="`From ${ord.order_no}`" tone="primary" />
            </template>
        </PageHeader>

        <div
            v-if="locked"
            role="alert"
            class="flex items-start gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm"
        >
            <Lock class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <div class="text-amber-900 dark:text-amber-200">
                <p class="font-semibold">This invoice is {{ status }} — editing is locked.</p>
                <p class="mt-0.5 text-amber-800/90 dark:text-amber-200/80">
                    Only draft invoices can be edited. Raise a credit note instead.
                </p>
            </div>
        </div>

        <form class="space-y-5" @submit.prevent="submit">
            <fieldset :disabled="locked" class="m-0 space-y-5 border-0 p-0">
                <div
                    v-if="errorCount"
                    role="alert"
                    class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
                >
                    <AlertCircle class="mt-0.5 size-4 shrink-0" />
                    <div>
                        <p class="font-semibold">
                            {{ errorCount }} {{ errorCount === 1 ? 'field needs' : 'fields need' }} attention
                        </p>
                        <p class="mt-0.5 text-destructive/85">{{ Object.values(form.errors)[0] as string }}</p>
                    </div>
                </div>

                <FormSection title="Invoice details" description="Customer, warehouse and dates." :icon="FileText" :cols="4">
                    <FormField label="Customer" required span="2" :error="form.errors.customer_id as string">
                        <SearchableSelect
                            v-model="form.customer_id"
                            endpoint="/partner/api/options/customers"
                            placeholder="Search customers…"
                        />
                    </FormField>

                    <FormField label="Warehouse" required :error="form.errors.warehouse_id as string">
                        <SearchableSelect
                            v-model="form.warehouse_id"
                            endpoint="/inventory/api/options/warehouses"
                            placeholder="Select a warehouse"
                        />
                    </FormField>

                    <FormField label="Invoice date" required :error="form.errors.invoice_date as string">
                        <template #default="{ id, invalid }">
                            <Input v-model="form.invoice_date" :id="id" :aria-invalid="invalid" type="date" />
                        </template>
                    </FormField>

                    <FormField
                        label="Due date"
                        :help="dueTooEarly ? '' : 'Must be on or after the invoice date.'"
                        :error="dueTooEarly ? 'Due date is before the invoice date.' : (form.errors.due_date as string)"
                    >
                        <template #default="{ id, invalid }">
                            <Input
                                v-model="form.due_date"
                                :id="id"
                                :aria-invalid="invalid || dueTooEarly"
                                type="date"
                                :min="form.invoice_date || undefined"
                            />
                        </template>
                    </FormField>

                    <FormField label="Currency" :error="form.errors.currency as string">
                        <template #default="{ id, invalid }">
                            <FormSelect v-model="form.currency" :id="id" :invalid="invalid">
                                <option>BDT</option>
                                <option>USD</option>
                                <option>EUR</option>
                                <option>GBP</option>
                                <option>INR</option>
                                <option>CNY</option>
                            </FormSelect>
                        </template>
                    </FormField>

                    <FormField label="Payment method" :error="form.errors.payment_method as string">
                        <template #default="{ id, invalid }">
                            <FormSelect v-model="form.payment_method" :id="id" :invalid="invalid">
                                <option v-for="m in PAYMENT_METHODS" :key="m" :value="m">{{ m[0].toUpperCase() + m.slice(1) }}</option>
                            </FormSelect>
                        </template>
                    </FormField>

                    <FormField label="Notes" span="full" :error="form.errors.notes as string">
                        <template #default="{ id, invalid }">
                            <Textarea v-model="form.notes" :id="id" :aria-invalid="invalid" rows="2" class="resize-none" />
                        </template>
                    </FormField>
                </FormSection>

                <FormSection
                    title="Invoice lines"
                    description="Discount is taken off the gross, then VAT is applied to the net."
                    :icon="CreditCard"
                    plain
                    :badge="`${form.items.length} line${form.items.length === 1 ? '' : 's'}`"
                >
                    <template #actions>
                        <Button type="button" variant="outline" size="sm" :disabled="form.items.length >= 50" @click="addItem">
                            <Plus class="size-4" /> Add line
                        </Button>
                    </template>

                    <div class="space-y-3">
                        <div class="hidden gap-x-5 xl:grid xl:grid-cols-12 xl:px-1">
                            <p class="col-span-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Product</p>
                            <p class="col-span-2 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Qty</p>
                            <p class="col-span-2 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Price</p>
                            <p class="text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Disc %</p>
                            <p class="text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">VAT %</p>
                            <p class="col-span-2 text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Line total</p>
                        </div>

                        <div
                            v-for="(it, i) in form.items"
                            :key="i"
                            class="rounded-xl border bg-card p-4 transition hover:border-primary/30 hover:shadow-sm"
                            :class="form.errors[`items.${i}.product_id`] ? 'border-destructive/50' : 'border-border'"
                        >
                            <div class="grid items-end gap-x-5 gap-y-3 xl:grid-cols-12">
                                <FormField
                                    label="Product"
                                    required
                                    span="full"
                                    class="xl:col-span-4"
                                    :label-class="'xl:sr-only'"
                                    :error="form.errors[`items.${i}.product_id`] as string"
                                >
                                    <SearchableSelect
                                        v-model="it.product_id"
                                        endpoint="/inventory/api/options/products"
                                        placeholder="Search products…"
                                    />
                                </FormField>

                                <FormField label="Qty" required class="xl:col-span-2" :label-class="'xl:sr-only'" :error="form.errors[`items.${i}.qty`] as string">
                                    <Input v-model.number="it.qty" type="number" step="0.01" min="0.01" class="text-right font-mono" />
                                </FormField>

                                <FormField label="Unit price" required class="xl:col-span-2" :label-class="'xl:sr-only'" :error="form.errors[`items.${i}.unit_price`] as string">
                                    <Input v-model.number="it.unit_price" type="number" step="0.01" min="0" class="text-right font-mono" />
                                </FormField>

                                <FormField label="Discount %" class="xl:col-span-1" :label-class="'xl:sr-only'" :error="form.errors[`items.${i}.discount_pct`] as string">
                                    <Input v-model.number="it.discount_pct" type="number" step="0.01" min="0" max="100" class="text-right font-mono" />
                                </FormField>

                                <FormField label="VAT %" class="xl:col-span-1" :label-class="'xl:sr-only'" :error="form.errors[`items.${i}.vat_rate`] as string">
                                    <Input v-model.number="it.vat_rate" type="number" step="0.01" min="0" max="100" class="text-right font-mono" />
                                </FormField>

                                <div class="flex items-center gap-2 xl:col-span-2">
                                    <p aria-hidden="true" class="hidden w-full text-right font-mono text-sm font-semibold xl:block">
                                        {{ money(lineTotal(i)) }}
                                    </p>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        class="size-8 shrink-0 text-muted-foreground hover:text-destructive"
                                        :aria-label="`Remove line ${i + 1}`"
                                        @click="removeItem(i)"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </div>

                            <p class="mt-2 text-right font-mono text-xs text-muted-foreground xl:hidden">
                                Line total {{ money(lineTotal(i)) }}
                            </p>
                        </div>

                        <Button type="button" variant="outline" size="sm" class="xl:hidden" :disabled="form.items.length >= 50" @click="addItem">
                            <Plus class="size-4" /> Add line
                        </Button>
                    </div>

                    <template #footer>
                        <div class="grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-lg bg-muted/40 px-3 py-2">
                                <p class="text-xs font-medium text-muted-foreground">Subtotal</p>
                                <p class="font-mono text-base font-bold">{{ money(subtotal) }}</p>
                            </div>
                            <div class="rounded-lg bg-muted/40 px-3 py-2">
                                <p class="text-xs font-medium text-muted-foreground">Discount</p>
                                <p class="font-mono text-base font-bold text-amber-600 dark:text-amber-400">−{{ money(discountTotal) }}</p>
                            </div>
                            <div class="rounded-lg bg-muted/40 px-3 py-2">
                                <p class="text-xs font-medium text-muted-foreground">VAT</p>
                                <p class="font-mono text-base font-bold">{{ money(vatTotal) }}</p>
                            </div>
                            <div class="rounded-lg bg-primary/10 px-3 py-2">
                                <p class="text-xs font-medium text-muted-foreground">Total</p>
                                <p class="font-mono text-base font-bold text-primary">{{ money(total) }}</p>
                            </div>
                        </div>
                    </template>
                </FormSection>
            </fieldset>

            <FormActions
                :submit-label="isEdit ? 'Save changes' : 'Create invoice'"
                :cancel-href="indexUrl"
                :processing="form.processing"
                :disabled="locked || dueTooEarly"
            >
                <template #summary>
                    <p class="font-mono text-sm font-bold">
                        {{ form.currency }} {{ money(total) }}
                        <span class="ml-1 font-sans text-xs font-normal text-muted-foreground">
                            · {{ form.items.length }} {{ form.items.length === 1 ? 'line' : 'lines' }}
                            <template v-if="isEdit && !locked">· still a draft until posted</template>
                        </span>
                    </p>
                </template>
            </FormActions>
        </form>
    </div>
</template>
