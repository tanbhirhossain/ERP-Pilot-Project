<script setup lang="ts">
/**
 * VendorBillForm — the single vendor bill editor.
 *
 * `Bills/Create.vue` and `Bills/Edit.vue` were near-identical copies (four template lines apart)
 * that shared three omissions: no Discount % input even though the request validates it and
 * `enrichTotals()` computes with it, no per-field errors on the item rows, and no totals
 * breakdown — just one number in a heading. This is the one implementation.
 *
 * A line is either goods or a fixed asset. Asset lines capitalise on posting — one register row
 * per unit, straight into the category's fixed-asset account — so they carry the class and the
 * depreciation overrides. Blank overrides inherit the category defaults; the picker shows them
 * so "blank" is an informed choice, not a shrug.
 *
 * Purchase shows two doors over this one form: Inventory Purchase locks every line to goods,
 * Fixed Asset Purchase locks every line to assets. The lock only narrows the page — the bill,
 * the posting and the register below never know which door was used.
 */
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { AlertCircle, FileText, Landmark, Lock, Plus, Receipt, Trash2 } from 'lucide-vue-next';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import FormField from '@core/Components/FormField.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormActions from '@core/Components/FormActions.vue';
import FormSelect from '@core/Components/FormSelect.vue';

const props = withDefaults(
    defineProps<{
        mode: 'create' | 'edit';
        bill?: any;
        /** Set when the bill is raised from a purchase order. */
        order?: any;
        /** Set when the bill is raised from a goods receipt; wins over `order`. */
        receipt?: any;
        submitUrl: string;
        indexUrl: string;
        /**
         * Which door the clerk walked through. `goods` is the Inventory Purchase page, where the
         * asset controls never appear; `fixed_asset` is the Fixed Asset Purchase page, where every
         * line is an asset. Null keeps the per-line toggle (mixed bills in edit).
         */
        lockLineType?: 'goods' | 'fixed_asset' | null;
        /** Page heading — the two create pages name themselves. */
        formTitle?: string;
        /** One-line explainer under the heading. */
        formDescription?: string;
    }>(),
    { bill: null, order: null, receipt: null, lockLineType: null, formTitle: '', formDescription: '' },
);

const lock = computed(() => props.lockLineType ?? null);

const isEdit = computed(() => props.mode === 'edit');
const src = computed<any>(() => props.receipt ?? props.order ?? null);
const b = computed<any>(() => props.bill ?? {});

const status = computed(() => String(b.value.status ?? 'draft'));
/** `VendorBillRepository::update()` refuses anything but a draft, so say so up front. */
const locked = computed(() => isEdit.value && status.value !== 'draft');

const statusTone = computed(() => {
    if (status.value === 'paid') return 'success' as const;
    if (status.value === 'posted') return 'info' as const;
    if (status.value === 'cancelled') return 'danger' as const;

    return 'warning' as const;
});

const blankItem = () => ({
    product_id: null as number | null,
    qty: 1 as number | null,
    unit_price: null as number | null,
    discount_pct: 0 as number | null,
    vat_rate: 0 as number | null,
    line_type: (lock.value ?? 'goods') as 'goods' | 'fixed_asset',
    asset_category_id: null as number | null,
    asset_name: '' as string,
    asset_method: '' as '' | 'straight_line' | 'declining_balance',
    asset_useful_life_years: null as number | null,
    asset_salvage_value: null as number | null,
    asset_db_factor: null as number | null,
    asset_in_service_date: '' as string,
    asset_location: '' as string,
});

/** Prefilled lines inherit the asset marking from the source product — a PO line for an asset product is an asset line. */
const prefillItem = (it: any) => ({
    product_id: it.product_id ?? null,
    qty: Number(it.qty ?? 1),
    unit_price: Number(it.unit_price ?? 0),
    discount_pct: Number(it.discount_pct ?? 0),
    vat_rate: Number(it.vat_rate ?? 0),
    line_type: (lock.value ?? (it.line_type === 'fixed_asset' || (!it.line_type && it.product?.type === 'asset') ? 'fixed_asset' : 'goods')) as 'goods' | 'fixed_asset',
    asset_category_id: it.asset_category_id ?? null,
    asset_name: it.asset_name ?? '',
    asset_method: (it.asset_method ?? '') as '' | 'straight_line' | 'declining_balance',
    asset_useful_life_years: it.asset_useful_life_years ?? null,
    asset_salvage_value: it.asset_salvage_value != null ? Number(it.asset_salvage_value) : null,
    asset_db_factor: it.asset_db_factor != null ? Number(it.asset_db_factor) : null,
    asset_in_service_date: it.asset_in_service_date ? String(it.asset_in_service_date).slice(0, 10) : '',
    asset_location: it.asset_location ?? '',
});

const initialItems = computed(() => {
    if (isEdit.value) {
        return b.value.items?.length ? b.value.items.map(prefillItem) : [blankItem()];
    }

    return src.value?.items?.length ? src.value.items.map(prefillItem) : [blankItem()];
});

const form = useForm({
    order_id: isEdit.value ? (b.value.order_id ?? null) : (src.value?.order_id ?? props.order?.id ?? null),
    receipt_id: isEdit.value ? (b.value.receipt_id ?? null) : (props.receipt?.id ?? null),
    vendor_id: isEdit.value ? (b.value.vendor_id ?? null) : (src.value?.vendor_id ?? null),
    warehouse_id: isEdit.value ? (b.value.warehouse_id ?? null) : (src.value?.warehouse_id ?? null),
    bill_date: isEdit.value ? String(b.value.bill_date ?? '').slice(0, 10) : new Date().toISOString().slice(0, 10),
    due_date: isEdit.value ? (b.value.due_date ? String(b.value.due_date).slice(0, 10) : '') : '',
    vendor_reference: isEdit.value ? (b.value.vendor_reference ?? '') : '',
    currency: isEdit.value ? (b.value.currency ?? 'BDT') : 'BDT',
    vat_recoverable: isEdit.value ? b.value.vat_recoverable !== false : true,
    notes: isEdit.value
        ? (b.value.notes ?? '')
        : src.value
          ? `Bill for ${src.value.order_no ?? src.value.receipt_no ?? ''}`.trim()
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

function addItem() {
    form.items.push(blankItem());
}
function removeItem(i: number) {
    form.items.splice(i, 1);
    if (!form.items.length) addItem();
    // Line numbers shift up — the tracked product types shift with them.
    const next: Record<number, string> = {};
    Object.entries(lineProductTypes.value).forEach(([k, v]) => {
        const n = Number(k);
        if (n !== i) next[n > i ? n - 1 : n] = v;
    });
    lineProductTypes.value = next;
}

/** Each page shops its own catalogue — the picker never offers what the page cannot bill. */
const productEndpoint = computed(() =>
    lock.value === 'fixed_asset'
        ? '/inventory/api/options/products?type=asset'
        : lock.value === 'goods'
          ? '/inventory/api/options/products?exclude_type=asset'
          : '/inventory/api/options/products',
);

/**
 * What each line's product turned out to be. Prefill knows it when the source embeds the
 * product; manual picks report it through the select event. A locked page uses it to spot the
 * line that wandered in from the other catalogue (usually prefill from a mixed order).
 */
const lineProductTypes = ref<Record<number, string>>({});
function seedLineTypes(items: any[]) {
    items.forEach((it: any, i: number) => {
        if (it.product_id && it.product?.type) lineProductTypes.value[i] = it.product.type;
    });
}

function lineMismatch(i: number): boolean {
    if (!lock.value) return false;
    const t = lineProductTypes.value[i];
    if (!t) return false;

    return lock.value === 'fixed_asset' ? t !== 'asset' : t === 'asset';
}
const mismatchCount = computed(() => form.items.filter((_it, i) => lineMismatch(i)).length);

/**
 * Picking an asset product marks the line; picking anything else unmarks it. The marking stays
 * a conscious control — the clerk can still flip it by hand — but the common case needs no
 * second click, and a forgotten marking is what the posting guard shouts about.
 */
function onProductSelect(i: number, option: any) {
    if (option?.type) lineProductTypes.value[i] = option.type;
    if (lock.value) return;
    const isAsset = option?.type === 'asset';
    form.items[i].line_type = isAsset ? 'fixed_asset' : 'goods';
    if (isAsset && form.items[i].unit_price == null && option?.price != null) {
        form.items[i].unit_price = Number(option.price);
    }
}

/** Remember what the chosen class would lend a blank field, so the form can say so. */
const categoryDefaults = ref<Record<number, any>>({});
function onCategorySelect(i: number, option: any) {
    if (option?.value) categoryDefaults.value[i] = option;
}
function inheritedHint(i: number): string {
    const d = categoryDefaults.value[i];
    if (!d?.default_method) return 'Blank inherits the class defaults.';
    const method = d.default_method === 'declining_balance' ? `DB ×${d.default_db_factor ?? 2}` : 'SL';
    const life = d.default_useful_life_years ? ` · ${d.default_useful_life_years}y` : '';
    const salvage = d.default_salvage_pct ? ` · ${d.default_salvage_pct}% salvage` : '';

    return `Blank inherits: ${method}${life}${salvage}.`;
}

const assetLineCount = computed(() => form.items.filter((it) => it.line_type === 'fixed_asset').length);

seedLineTypes(src.value?.items ?? []);

/** What the prefilled source is made of — guides the clerk to the right door. */
const srcKind = computed<'asset' | 'goods' | 'mixed' | null>(() => {
    const items = src.value?.items ?? [];
    if (!items.length) return null;
    const kinds = new Set(items.map((it: any) => (it.product?.type === 'asset' ? 'asset' : 'goods')));

    return kinds.size > 1 ? 'mixed' : (kinds.has('asset') ? 'asset' : 'goods');
});
const showAssetRedirect = computed(() => lock.value === 'goods' && !isEdit.value && props.order && srcKind.value === 'asset');
const showGoodsRedirect = computed(
    () => lock.value === 'fixed_asset' && !isEdit.value && (props.receipt || (props.order && srcKind.value === 'goods')),
);
const showMixedHint = computed(() => lock.value !== null && !isEdit.value && props.order && srcKind.value === 'mixed');

const blocked = ref('');

function submit() {
    if (locked.value) return;

    // A locked page never submits a line from the other catalogue: the picker filters, but
    // prefill from a mixed order can still smuggle one in. Deleting the line unblocks.
    if (lock.value && mismatchCount.value > 0) {
        blocked.value =
            lock.value === 'fixed_asset'
                ? `${mismatchCount.value} line${mismatchCount.value === 1 ? ' is' : 's are'} not asset products — remove them here and bill them under Inventory Purchase.`
                : `${mismatchCount.value} line${mismatchCount.value === 1 ? ' is' : 's are'} asset products — remove them here and bill them under Fixed Asset Purchase.`;

        return;
    }
    blocked.value = '';

    if (isEdit.value) {
        form.put(props.submitUrl, { preserveScroll: true });

        return;
    }

    form.post(props.submitUrl);
}

const errorCount = computed(() => Object.keys(form.errors).length);
</script>

<template>
    <div class="space-y-5">
        <PageHeader
            :title="formTitle || (isEdit ? `Edit ${b.bill_no ?? 'bill'}` : 'New Vendor Bill')"
            :description="formDescription || 'Posting writes Inventory 1200 / VAT input 1330 → Accounts Payable 2100. Link a GRN or PO to inherit its lines.'"
            :icon="Receipt"
            :actions="[{ label: 'Back to bills', href: indexUrl, variant: 'outline' }]"
        >
            <template #badge>
                <StatusBadge v-if="isEdit" :label="status" :tone="statusTone" dot />
                <StatusBadge v-if="src" :label="`From ${src.receipt_no ?? src.order_no}`" tone="primary" />
            </template>
        </PageHeader>

        <div
            v-if="locked"
            role="alert"
            class="flex items-start gap-3 rounded-xl border border-amber-500/40 bg-amber-500/10 px-4 py-3 text-sm"
        >
            <Lock class="mt-0.5 size-4 shrink-0 text-amber-600 dark:text-amber-400" />
            <div class="text-amber-900 dark:text-amber-200">
                <p class="font-semibold">This bill is {{ status }} — editing is locked.</p>
                <p class="mt-0.5 text-amber-800/90 dark:text-amber-200/80">
                    Only draft bills can be edited. Void it and raise a new one to make a correction.
                </p>
            </div>
        </div>

        <div
            v-if="showAssetRedirect"
            role="alert"
            class="flex items-start gap-3 rounded-xl border border-primary/30 bg-primary/5 px-4 py-3 text-sm"
        >
            <AlertCircle class="mt-0.5 size-4 shrink-0 text-primary" />
            <div>
                <p class="font-semibold">This order buys fixed assets — it belongs on the other page.</p>
                <p class="mt-0.5 text-muted-foreground">
                    Inventory Purchase cannot bill asset products.
                    <Link :href="route('purchase.bills.create_asset', { order_id: props.order.id })" class="font-medium text-primary hover:underline">
                        Continue in Fixed Asset Purchase →
                    </Link>
                </p>
            </div>
        </div>

        <div
            v-if="showGoodsRedirect"
            role="alert"
            class="flex items-start gap-3 rounded-xl border border-primary/30 bg-primary/5 px-4 py-3 text-sm"
        >
            <AlertCircle class="mt-0.5 size-4 shrink-0 text-primary" />
            <div>
                <p class="font-semibold">This {{ props.receipt ? 'receipt' : 'order' }} carries goods — it belongs on the other page.</p>
                <p class="mt-0.5 text-muted-foreground">
                    Fixed Asset Purchase cannot bill stock products{{ props.receipt ? ', and assets never arrive through a GRN' : '' }}.
                    <Link
                        :href="props.receipt ? route('purchase.bills.create', { receipt_id: props.receipt.id }) : route('purchase.bills.create', { order_id: props.order.id })"
                        class="font-medium text-primary hover:underline"
                    >
                        Continue in Inventory Purchase →
                    </Link>
                </p>
            </div>
        </div>

        <div
            v-if="showMixedHint"
            role="alert"
            class="flex items-start gap-3 rounded-xl border border-sky-500/30 bg-sky-500/5 px-4 py-3 text-sm"
        >
            <AlertCircle class="mt-0.5 size-4 shrink-0 text-sky-600" />
            <div>
                <p class="font-semibold">This order mixes goods and assets — bill each part on its own page.</p>
                <p class="mt-0.5 text-muted-foreground">
                    Delete the lines that do not belong here, post this half, then raise the other half{{
                        lock === 'fixed_asset' ? ' in Inventory Purchase' : ' in Fixed Asset Purchase'
                    }}
                    with the same vendor reference.
                </p>
            </div>
        </div>

        <div
            v-if="blocked"
            role="alert"
            class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive"
        >
            <AlertCircle class="mt-0.5 size-4 shrink-0" />
            <div>
                <p class="font-semibold">This bill cannot be saved yet</p>
                <p class="mt-0.5 text-destructive/85">{{ blocked }}</p>
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

                <FormSection
                    title="Bill details"
                    description="Vendor, warehouse and dates."
                    :icon="FileText"
                    :cols="4"
                >
                    <FormField label="Vendor" required span="2" :error="form.errors.vendor_id as string">
                        <SearchableSelect
                            v-model="form.vendor_id"
                            endpoint="/partner/api/options/vendors"
                            placeholder="Search vendors…"
                        />
                    </FormField>

                    <FormField label="Warehouse" required :error="form.errors.warehouse_id as string">
                        <SearchableSelect
                            v-model="form.warehouse_id"
                            endpoint="/inventory/api/options/warehouses"
                            placeholder="Select a warehouse"
                        />
                    </FormField>

                    <FormField label="Bill date" required :error="form.errors.bill_date as string">
                        <template #default="{ id, invalid }">
                            <Input v-model="form.bill_date" :id="id" :aria-invalid="invalid" type="date" />
                        </template>
                    </FormField>

                    <FormField label="Due date" :error="form.errors.due_date as string">
                        <template #default="{ id, invalid }">
                            <Input v-model="form.due_date" :id="id" :aria-invalid="invalid" type="date" />
                        </template>
                    </FormField>

                    <FormField label="Vendor reference" help="Their invoice number." :error="form.errors.vendor_reference as string">
                        <template #default="{ id, invalid }">
                            <Input v-model="form.vendor_reference" :id="id" :aria-invalid="invalid" placeholder="SUP-INV-001" />
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

                    <FormField
                        label="VAT recoverable"
                        help="Untick only when the VAT cannot be claimed — it then becomes part of the cost. Direct bills only."
                        :error="form.errors.vat_recoverable as string"
                    >
                        <template #default="{ id, invalid }">
                            <label class="flex h-10 cursor-pointer items-center gap-2 text-sm">
                                <input
                                    :id="id"
                                    v-model="form.vat_recoverable"
                                    type="checkbox"
                                    :aria-invalid="invalid"
                                    class="size-4 rounded border-input accent-primary"
                                />
                                <span class="font-medium">{{ form.vat_recoverable ? 'Claim input VAT' : 'VAT is part of cost' }}</span>
                            </label>
                        </template>
                    </FormField>

                    <FormField label="Notes" span="full" :error="form.errors.notes as string">
                        <template #default="{ id, invalid }">
                            <Textarea v-model="form.notes" :id="id" :aria-invalid="invalid" rows="2" class="resize-none" />
                        </template>
                    </FormField>
                </FormSection>

                <FormSection
                    title="Bill lines"
                    :description="
                        lock === 'fixed_asset'
                            ? 'Every line capitalises — one register row per unit — when the bill posts.'
                            : 'Discount is taken off the gross, then VAT is applied to the net.'
                    "
                    :icon="Landmark"
                    plain
                    :badge="`${form.items.length} line${form.items.length === 1 ? '' : 's'}${!lock && assetLineCount ? ` · ${assetLineCount} asset` : ''}`"
                >
                    <template #actions>
                        <Button type="button" variant="outline" size="sm" :disabled="form.items.length >= 100" @click="addItem">
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
                            <div class="mb-3 flex items-center justify-between gap-2">
                                <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Line {{ i + 1 }}</p>
                                <span
                                    v-if="lock"
                                    class="rounded-full px-2.5 py-1 text-xs font-semibold"
                                    :class="lock === 'fixed_asset' ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground'"
                                >
                                    {{ lock === 'fixed_asset' ? 'Fixed asset' : 'Goods' }}
                                </span>
                                <div
                                    v-else
                                    class="flex rounded-lg border bg-muted/40 p-0.5 text-xs font-semibold"
                                    role="radiogroup"
                                    :aria-label="`Line ${i + 1} type`"
                                >
                                    <button
                                        type="button"
                                        role="radio"
                                        :aria-checked="it.line_type !== 'fixed_asset'"
                                        class="rounded-md px-2.5 py-1 transition"
                                        :class="it.line_type !== 'fixed_asset' ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                                        @click="it.line_type = 'goods'"
                                    >
                                        Goods
                                    </button>
                                    <button
                                        type="button"
                                        role="radio"
                                        :aria-checked="it.line_type === 'fixed_asset'"
                                        class="rounded-md px-2.5 py-1 transition"
                                        :class="it.line_type === 'fixed_asset' ? 'bg-primary text-primary-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground'"
                                        @click="it.line_type = 'fixed_asset'"
                                    >
                                        Fixed asset
                                    </button>
                                </div>
                            </div>
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
                                        :endpoint="productEndpoint"
                                        :placeholder="lock === 'fixed_asset' ? 'Search asset products…' : 'Search products…'"
                                        @select="(opt) => onProductSelect(i, opt)"
                                    />
                                    <p v-if="lineMismatch(i)" class="mt-1 text-xs font-medium text-destructive">
                                        {{
                                            lock === 'fixed_asset'
                                                ? 'Not an asset product — bill it under Inventory Purchase.'
                                                : 'An asset product — bill it under Fixed Asset Purchase.'
                                        }}
                                    </p>
                                </FormField>

                                <FormField
                                    label="Qty"
                                    required
                                    class="xl:col-span-2"
                                    :label-class="'xl:sr-only'"
                                    :help="it.line_type === 'fixed_asset' ? 'Whole units — one register row each.' : ''"
                                    :error="form.errors[`items.${i}.qty`] as string"
                                >
                                    <Input
                                        v-model.number="it.qty"
                                        type="number"
                                        :step="it.line_type === 'fixed_asset' ? 1 : 0.01"
                                        min="0.01"
                                        class="text-right font-mono"
                                    />
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
                                    <p class="w-full text-right font-mono text-sm font-semibold xl:sr-only">Line total</p>
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

                            <div
                                v-if="it.line_type === 'fixed_asset'"
                                class="mt-4 rounded-lg border border-primary/20 bg-primary/[0.04] p-4"
                            >
                                <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                    Capitalise as · {{ inheritedHint(i) }}
                                </p>
                                <div class="grid items-end gap-x-5 gap-y-3 sm:grid-cols-2 xl:grid-cols-4">
                                    <FormField
                                        label="Asset class"
                                        required
                                        class="sm:col-span-2 xl:col-span-1"
                                        :error="form.errors[`items.${i}.asset_category_id`] as string"
                                    >
                                        <SearchableSelect
                                            v-model="it.asset_category_id"
                                            endpoint="/accounts/api/options/asset-categories"
                                            placeholder="Pick a class…"
                                            @select="(opt) => onCategorySelect(i, opt)"
                                        />
                                    </FormField>

                                    <FormField label="Asset name" :help="'Blank uses the product name.'" :error="form.errors[`items.${i}.asset_name`] as string">
                                        <template #default="{ id, invalid }">
                                            <Input v-model="it.asset_name" :id="id" :aria-invalid="invalid" placeholder="Optional tag name" />
                                        </template>
                                    </FormField>

                                    <FormField label="Method" :error="form.errors[`items.${i}.asset_method`] as string">
                                        <template #default="{ id, invalid }">
                                            <FormSelect v-model="it.asset_method" :id="id" :invalid="invalid">
                                                <option value="">Inherit from class</option>
                                                <option value="straight_line">Straight line</option>
                                                <option value="declining_balance">Declining balance</option>
                                            </FormSelect>
                                        </template>
                                    </FormField>

                                    <FormField
                                        label="Useful life (yrs)"
                                        :error="form.errors[`items.${i}.asset_useful_life_years`] as string"
                                    >
                                        <template #default="{ id, invalid }">
                                            <Input v-model.number="it.asset_useful_life_years" :id="id" :aria-invalid="invalid" type="number" min="1" max="100" placeholder="Class default" class="font-mono" />
                                        </template>
                                    </FormField>

                                    <FormField label="Salvage value" :help="'Per line, split across units.'" :error="form.errors[`items.${i}.asset_salvage_value`] as string">
                                        <template #default="{ id, invalid }">
                                            <Input v-model.number="it.asset_salvage_value" :id="id" :aria-invalid="invalid" type="number" step="0.01" min="0" placeholder="Class default" class="text-right font-mono" />
                                        </template>
                                    </FormField>

                                    <FormField
                                        v-if="it.asset_method === 'declining_balance'"
                                        label="DB factor"
                                        :error="form.errors[`items.${i}.asset_db_factor`] as string"
                                    >
                                        <template #default="{ id, invalid }">
                                            <Input v-model.number="it.asset_db_factor" :id="id" :aria-invalid="invalid" type="number" step="0.01" min="0.01" max="10" placeholder="Class default" class="font-mono" />
                                        </template>
                                    </FormField>

                                    <FormField label="In-service date" :help="'Blank uses the bill date.'" :error="form.errors[`items.${i}.asset_in_service_date`] as string">
                                        <template #default="{ id, invalid }">
                                            <Input v-model="it.asset_in_service_date" :id="id" :aria-invalid="invalid" type="date" />
                                        </template>
                                    </FormField>

                                    <FormField label="Location" :error="form.errors[`items.${i}.asset_location`] as string">
                                        <template #default="{ id, invalid }">
                                            <Input v-model="it.asset_location" :id="id" :aria-invalid="invalid" placeholder="e.g. Head office, 3rd floor" />
                                        </template>
                                    </FormField>
                                </div>
                                <p class="mt-3 text-xs text-muted-foreground">
                                    Posting creates {{ Math.max(1, Math.floor(n(it.qty))) }} register row{{ Math.floor(n(it.qty)) > 1 ? 's' : '' }} at
                                    {{ money(lineGross(i) - lineDiscount(i) + (form.vat_recoverable ? 0 : lineVat(i))) }} total cost —
                                    {{ form.vat_recoverable ? 'net of VAT' : 'VAT included' }}.
                                </p>
                            </div>

                            <p class="mt-2 text-right font-mono text-xs text-muted-foreground xl:hidden">
                                Line total {{ money(lineTotal(i)) }}
                            </p>
                        </div>

                        <Button type="button" variant="outline" size="sm" class="xl:hidden" :disabled="form.items.length >= 100" @click="addItem">
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
                :submit-label="isEdit ? 'Save changes' : 'Create bill'"
                :cancel-href="indexUrl"
                :processing="form.processing"
                :disabled="locked"
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
