<script setup lang="ts">
/**
 * ProductForm — the single product editor.
 *
 * `Products/Create.vue` (145 lines) and `Products/Edit.vue` (68 lines) were separately maintained
 * copies that had drifted: Create's tab bar had icons and Edit's did not, Create re-seeded a blank
 * repeater row when the last one was removed and Edit did not, Create jumped to the tab holding a
 * validation error and Edit did not, and Edit hardcoded `currency: 'BDT'` instead of reading the
 * product. This is the one implementation; each page is now a thin wrapper.
 */
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    AlertCircle,
    Boxes,
    DollarSign,
    Image as ImageIcon,
    Info,
    Layers,
    Package,
    Plus,
    Trash2,
    Truck,
    X,
} from 'lucide-vue-next';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormField from '@core/Components/FormField.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormActions from '@core/Components/FormActions.vue';
import FormSelect from '@core/Components/FormSelect.vue';
import FormCheckbox from '@core/Components/FormCheckbox.vue';

const props = withDefaults(
    defineProps<{
        mode: 'create' | 'edit';
        product?: any;
        categoryOptions: any[];
        brandOptions: any[];
        uomOptions: any[];
        /** Accepted to keep the controller contract; no section consumes it yet. */
        warehouseOptions?: any[];
        submitUrl: string;
        indexUrl: string;
    }>(),
    {
        product: null,
        warehouseOptions: () => [],
    },
);

const isEdit = computed(() => props.mode === 'edit');
const prod = computed<any>(() => props.product ?? {});

const blankVariant = () => ({
    id: null as number | null,
    name: '',
    sku: '',
    barcode: '',
    cost_price: null as number | null,
    selling_price: null as number | null,
});

const blankSupplier = (primary = false) => ({
    partner_id: null as number | null,
    supplier_sku: '',
    last_purchase_price: null as number | null,
    is_primary: primary,
});

const form = useForm({
    name: prod.value.name ?? '',
    sku: prod.value.sku ?? '',
    barcode: prod.value.barcode ?? '',
    category_id: prod.value.category_id ?? null,
    brand_id: prod.value.brand_id ?? null,
    uom_id: prod.value.uom_id ?? null,
    type: prod.value.type ?? 'stockable',
    description: prod.value.description ?? '',
    hs_code: prod.value.hs_code ?? '',
    weight: prod.value.weight ?? null,
    reorder_level: prod.value.reorder_level ?? 10,
    reorder_qty: prod.value.reorder_qty ?? 50,
    valuation_method: prod.value.valuation_method ?? 'weighted',
    cost_price: prod.value.cost_price ?? null,
    selling_price: prod.value.selling_price ?? null,
    mrp: prod.value.mrp ?? null,
    vat_rate: prod.value.vat_rate ?? 0,
    currency: prod.value.currency ?? 'BDT',
    is_serialized: !!prod.value.is_serialized,
    is_batch_tracked: !!prod.value.is_batch_tracked,
    shelf_life_days: prod.value.shelf_life_days ?? null,
    is_active: prod.value.is_active ?? true,
    status: prod.value.status ?? 'active',
    image: null as File | null,
    variants: prod.value.variants?.length ? prod.value.variants.map((v: any) => ({ ...blankVariant(), ...v })) : [blankVariant()],
    suppliers: prod.value.suppliers?.length
        ? prod.value.suppliers.map((s: any) => ({
              partner_id: s.partner_id ?? null,
              supplier_sku: s.supplier_sku ?? '',
              last_purchase_price: s.last_purchase_price ?? null,
              is_primary: !!s.is_primary,
          }))
        : [blankSupplier(true)],
});

const tabs = [
    { id: 'basic', label: 'Basic', icon: Info },
    { id: 'pricing', label: 'Pricing', icon: DollarSign },
    { id: 'inventory', label: 'Inventory', icon: Boxes },
    { id: 'suppliers', label: 'Suppliers', icon: Truck },
    { id: 'variants', label: 'Variants', icon: Layers },
];

const activeTab = ref('basic');

const pricingFields = ['cost_price', 'selling_price', 'mrp', 'vat_rate', 'currency'];
const inventoryFields = [
    'reorder_level',
    'reorder_qty',
    'valuation_method',
    'shelf_life_days',
    'is_serialized',
    'is_batch_tracked',
    'is_active',
];

function tabForError(field: string) {
    if (field.startsWith('variants')) return 'variants';
    if (field.startsWith('suppliers')) return 'suppliers';
    if (pricingFields.includes(field)) return 'pricing';
    if (inventoryFields.includes(field)) return 'inventory';

    return 'basic';
}

const errorTabs = computed(() => new Set(Object.keys(form.errors).map(tabForError)));
const errorCount = computed(() => Object.keys(form.errors).length);

function addVariant() {
    form.variants.push(blankVariant());
}
function removeVariant(i: number) {
    form.variants.splice(i, 1);
    if (!form.variants.length) addVariant();
}
function addSupplier() {
    form.suppliers.push(blankSupplier());
}
function removeSupplier(i: number) {
    form.suppliers.splice(i, 1);
    if (!form.suppliers.length) addSupplier();
}

/** "Primary supplier" drives the purchase-order default, so only one row can hold it. */
function promoteSupplier(index: number, value: boolean) {
    if (!value) return;
    form.suppliers.forEach((row, i) => {
        row.is_primary = i === index;
    });
}

const imagePreview = computed(() => {
    if (form.image) return URL.createObjectURL(form.image);

    const stored = prod.value.image;
    if (!stored || typeof stored !== 'string') return null;

    // store() keeps the disk-relative path; the public disk is served from /storage.
    return stored.startsWith('http') || stored.startsWith('/') ? stored : `/storage/${stored}`;
});

function onImageChange(e: Event) {
    form.image = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function clearImage() {
    form.image = null;
    const input = document.getElementById('product-image-input') as HTMLInputElement | null;
    if (input) input.value = '';
}

/** Margin, so the pricing tab says something useful rather than just collecting numbers. */
const margin = computed(() => {
    const cost = Number(form.cost_price) || 0;
    const sell = Number(form.selling_price) || 0;

    if (!sell || !cost) return null;

    return ((sell - cost) / sell) * 100;
});

const marginTone = computed(() => {
    if (margin.value === null) return '';
    if (margin.value >= 20) return 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400';
    if (margin.value >= 0) return 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400';

    return 'border-destructive/40 bg-destructive/10 text-destructive';
});

const marginNote = computed(() => {
    if (margin.value === null) return '';
    if (margin.value < 0) return 'selling below cost';

    return `\u09F3 ${(Number(form.selling_price) - Number(form.cost_price)).toFixed(2)} per unit`;
});

const sectionSummary = computed(() => {
    const at = tabs.findIndex((t) => t.id === activeTab.value) + 1;

    return `Section ${at} of ${tabs.length} · ${tabs[at - 1]?.label ?? ''}`;
});

function submit() {
    const onError = (errors: Record<string, string>) => {
        const first = Object.keys(errors)[0];
        if (first) activeTab.value = tabForError(first);
    };

    // The update route is PUT, but a multipart body cannot travel on PUT from the browser, so the
    // page posts with a method-override header — verified by ProductFormContractTest.
    form.post(props.submitUrl, {
        forceFormData: true,
        ...(isEdit.value ? { headers: { 'X-HTTP-Method-Override': 'PUT' } } : {}),
        onError,
    });
}
</script>

<template>
    <div class="space-y-5">
        <PageHeader
            :title="isEdit ? `Edit ${prod.name || 'product'}` : 'New Product'"
            :description="
                isEdit
                    ? `${prod.sku ?? ''}${prod.category?.name ? ' · ' + prod.category.name : ''}`
                    : 'A catalog item — stockable, consumable, service or bundle. The SKU is generated if you leave it blank.'
            "
            :icon="Package"
            :actions="[{ label: 'Back to products', href: indexUrl, variant: 'outline' }]"
        />

        <form class="space-y-5" @submit.prevent="submit">
            <nav
                class="sticky top-2 z-20 -mx-1 flex gap-1.5 overflow-x-auto rounded-2xl border bg-card/95 p-2 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-card/80"
                role="tablist"
                aria-label="Product form sections"
            >
                <button
                    v-for="t in tabs"
                    :key="t.id"
                    type="button"
                    role="tab"
                    :aria-selected="activeTab === t.id"
                    class="inline-flex shrink-0 items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-medium transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    :class="
                        activeTab === t.id
                            ? 'bg-primary text-primary-foreground shadow-sm'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                    "
                    @click="activeTab = t.id"
                >
                    <component :is="t.icon" class="size-4" />
                    {{ t.label }}
                    <span
                        v-if="errorTabs.has(t.id)"
                        class="grid size-4 place-items-center rounded-full bg-destructive text-[10px] font-bold text-destructive-foreground"
                        :aria-label="`${t.label} has validation errors`"
                    >
                        !
                    </span>
                </button>
            </nav>

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

            <!-- ── Basic ─────────────────────────────────────────────────── -->
            <FormSection
                v-if="activeTab === 'basic'"
                role="tabpanel"
                title="Basic information"
                description="What the item is and how it is filed."
                :icon="Info"
                :cols="4"
            >
                <FormField label="Name" required span="2" :error="form.errors.name as string">
                    <template #default="{ id, invalid }">
                        <Input v-model="form.name" :id="id" :aria-invalid="invalid" placeholder="Cotton Fabric — Premium" />
                    </template>
                </FormField>

                <FormField label="SKU" help="Generated automatically if left blank." :error="form.errors.sku as string">
                    <template #default="{ id, invalid }">
                        <Input v-model="form.sku" :id="id" :aria-invalid="invalid" placeholder="SKU-00001" class="font-mono" />
                    </template>
                </FormField>

                <FormField label="Barcode" :error="form.errors.barcode as string">
                    <template #default="{ id, invalid }">
                        <Input v-model="form.barcode" :id="id" :aria-invalid="invalid" placeholder="880…" class="font-mono" />
                    </template>
                </FormField>

                <FormField label="Category" :error="form.errors.category_id as string">
                    <SearchableSelect v-model="form.category_id" :options="categoryOptions" placeholder="Select a category" clearable />
                </FormField>

                <FormField label="Brand" :error="form.errors.brand_id as string">
                    <SearchableSelect v-model="form.brand_id" :options="brandOptions" placeholder="Select a brand" clearable />
                </FormField>

                <FormField label="Unit of measure" :error="form.errors.uom_id as string">
                    <SearchableSelect v-model="form.uom_id" :options="uomOptions" placeholder="Piece / Kg" clearable />
                </FormField>

                <FormField label="Type" required :error="form.errors.type as string">
                    <template #default="{ id, invalid }">
                        <FormSelect v-model="form.type" :id="id" :invalid="invalid">
                            <option value="stockable">Stockable — tracked in inventory</option>
                            <option value="consumable">Consumable — not tracked</option>
                            <option value="service">Service — no stock</option>
                            <option value="bundle">Bundle — kit of products</option>
                        </FormSelect>
                    </template>
                </FormField>

                <FormField label="Status" required :error="form.errors.status as string">
                    <template #default="{ id, invalid }">
                        <FormSelect v-model="form.status" :id="id" :invalid="invalid">
                            <option value="active">Active</option>
                            <option value="draft">Draft</option>
                            <option value="discontinued">Discontinued</option>
                        </FormSelect>
                    </template>
                </FormField>

                <FormField label="HS code" help="Customs tariff heading." :error="form.errors.hs_code as string">
                    <template #default="{ id, invalid }">
                        <Input v-model="form.hs_code" :id="id" :aria-invalid="invalid" class="font-mono" />
                    </template>
                </FormField>

                <FormField label="Weight (kg)" :error="form.errors.weight as string">
                    <template #default="{ id, invalid }">
                        <Input
                            v-model.number="form.weight"
                            :id="id"
                            :aria-invalid="invalid"
                            type="number"
                            step="0.001"
                            min="0"
                            class="text-right font-mono"
                        />
                    </template>
                </FormField>

                <FormField label="Description" span="full" :error="form.errors.description as string">
                    <template #default="{ id, invalid }">
                        <Textarea
                            v-model="form.description"
                            :id="id"
                            :aria-invalid="invalid"
                            rows="3"
                            placeholder="Detailed description"
                            class="resize-none"
                        />
                    </template>
                </FormField>

                <FormField
                    label="Image"
                    span="full"
                    help="JPG, PNG or WebP, up to 5 MB."
                    :error="form.errors.image as string"
                >
                    <div class="flex flex-wrap items-center gap-4 rounded-xl border border-dashed p-3">
                        <img
                            v-if="imagePreview"
                            :src="imagePreview"
                            alt="Product image preview"
                            class="size-16 rounded-lg border object-cover"
                        />
                        <div
                            v-else
                            class="grid size-16 place-items-center rounded-lg border border-dashed bg-muted/40 text-muted-foreground"
                        >
                            <ImageIcon class="size-5" />
                        </div>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ form.image ? form.image.name : imagePreview ? 'Current image' : 'No image selected' }}
                            </p>
                            <p v-if="form.image" class="text-xs text-muted-foreground">
                                {{ (form.image.size / 1024).toFixed(0) }} KB — saved when you submit
                            </p>
                        </div>

                        <label
                            class="inline-flex cursor-pointer items-center gap-2 rounded-md border bg-background px-3 py-2 text-sm font-medium transition hover:bg-muted/50"
                        >
                            <ImageIcon class="size-4" />
                            {{ imagePreview ? 'Replace image' : 'Choose image' }}
                            <input id="product-image-input" type="file" accept="image/*" class="sr-only" @change="onImageChange" />
                        </label>

                        <Button v-if="form.image" type="button" variant="ghost" size="sm" @click="clearImage">
                            <X class="size-4" /> Remove
                        </Button>
                    </div>
                </FormField>
            </FormSection>

            <!-- ── Pricing ───────────────────────────────────────────────── -->
            <FormSection
                v-if="activeTab === 'pricing'"
                role="tabpanel"
                title="Pricing & VAT"
                description="Cost and selling price are required; everything else is optional."
                :icon="DollarSign"
                :cols="4"
            >
                <FormField label="Cost price" required :error="form.errors.cost_price as string">
                    <template #default="{ id, invalid }">
                        <Input
                            v-model.number="form.cost_price"
                            :id="id"
                            :aria-invalid="invalid"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="120.00"
                            class="text-right font-mono"
                        />
                    </template>
                </FormField>

                <FormField label="Selling price" required :error="form.errors.selling_price as string">
                    <template #default="{ id, invalid }">
                        <Input
                            v-model.number="form.selling_price"
                            :id="id"
                            :aria-invalid="invalid"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="150.00"
                            class="text-right font-mono"
                        />
                    </template>
                </FormField>

                <FormField label="MRP" help="List price, for printed discount lines." :error="form.errors.mrp as string">
                    <template #default="{ id, invalid }">
                        <Input
                            v-model.number="form.mrp"
                            :id="id"
                            :aria-invalid="invalid"
                            type="number"
                            step="0.01"
                            min="0"
                            class="text-right font-mono"
                        />
                    </template>
                </FormField>

                <FormField label="VAT rate %" :error="form.errors.vat_rate as string">
                    <template #default="{ id, invalid }">
                        <Input
                            v-model.number="form.vat_rate"
                            :id="id"
                            :aria-invalid="invalid"
                            type="number"
                            min="0"
                            max="100"
                            step="0.01"
                            class="text-right font-mono"
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

                <FormField v-if="margin !== null" label="Gross margin" span="3">
                    <div
                        class="flex h-10 items-center gap-2 rounded-md border px-3 font-mono text-sm font-semibold"
                        :class="marginTone"
                    >
                        {{ margin.toFixed(1) }}%
                        <span class="font-sans text-xs font-normal text-muted-foreground">{{ marginNote }}</span>
                    </div>
                </FormField>
            </FormSection>

            <!-- ── Inventory ─────────────────────────────────────────────── -->
            <FormSection
                v-if="activeTab === 'inventory'"
                role="tabpanel"
                title="Inventory settings"
                description="Replenishment triggers and tracking behaviour."
                :icon="Boxes"
                :cols="4"
            >
                <FormField label="Reorder level" help="Stock at which a reorder is suggested." :error="form.errors.reorder_level as string">
                    <template #default="{ id, invalid }">
                        <Input
                            v-model.number="form.reorder_level"
                            :id="id"
                            :aria-invalid="invalid"
                            type="number"
                            min="0"
                            step="0.01"
                            class="text-right font-mono"
                        />
                    </template>
                </FormField>

                <FormField label="Reorder quantity" :error="form.errors.reorder_qty as string">
                    <template #default="{ id, invalid }">
                        <Input
                            v-model.number="form.reorder_qty"
                            :id="id"
                            :aria-invalid="invalid"
                            type="number"
                            min="0"
                            step="0.01"
                            class="text-right font-mono"
                        />
                    </template>
                </FormField>

                <FormField label="Valuation method" required :error="form.errors.valuation_method as string">
                    <template #default="{ id, invalid }">
                        <FormSelect v-model="form.valuation_method" :id="id" :invalid="invalid">
                            <option value="weighted">Weighted average</option>
                            <option value="fifo">FIFO</option>
                        </FormSelect>
                    </template>
                </FormField>

                <FormField label="Shelf life (days)" :error="form.errors.shelf_life_days as string">
                    <template #default="{ id, invalid }">
                        <Input
                            v-model.number="form.shelf_life_days"
                            :id="id"
                            :aria-invalid="invalid"
                            type="number"
                            min="0"
                            max="3650"
                            class="text-right font-mono"
                        />
                    </template>
                </FormField>

                <FormField span="full" hide-label label="Tracking flags">
                    <div class="grid gap-2 sm:grid-cols-3">
                        <FormCheckbox v-model="form.is_serialized" label="Serialized" description="Track individual serial numbers" />
                        <FormCheckbox v-model="form.is_batch_tracked" label="Batch tracked" description="Track by batch or lot" />
                        <FormCheckbox v-model="form.is_active" label="Active" description="Available to sell and purchase" />
                    </div>
                </FormField>
            </FormSection>

            <!-- ── Suppliers ─────────────────────────────────────────────── -->
            <FormSection
                v-if="activeTab === 'suppliers'"
                role="tabpanel"
                title="Suppliers"
                description="Vendor partners who supply this item. The primary one becomes the purchase-order default."
                :icon="Truck"
                plain
                :badge="String(form.suppliers.length)"
            >
                <template #actions>
                    <Button type="button" variant="outline" size="sm" @click="addSupplier">
                        <Plus class="size-4" /> Add supplier
                    </Button>
                </template>

                <div class="space-y-3">
                    <div
                        v-for="(s, i) in form.suppliers"
                        :key="i"
                        class="rounded-xl border bg-muted/[0.25] p-4 transition hover:border-primary/30 hover:bg-card"
                        :class="form.errors[`suppliers.${i}.partner_id`] ? 'border-destructive/50' : 'border-border'"
                    >
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <p class="font-mono text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                Supplier {{ i + 1 }}
                            </p>
                            <span v-if="s.is_primary" class="rounded-md bg-primary/10 px-1.5 py-0.5 text-[11px] font-bold text-primary">
                                PRIMARY
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="ml-auto size-7 text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove supplier ${i + 1}`"
                                @click="removeSupplier(i)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>

                        <div class="grid gap-x-5 gap-y-3 sm:grid-cols-2 xl:grid-cols-4">
                            <FormField label="Vendor" required span="2" :error="form.errors[`suppliers.${i}.partner_id`] as string">
                                <SearchableSelect
                                    v-model="s.partner_id"
                                    endpoint="/partner/api/options/vendors"
                                    placeholder="Search vendors…"
                                    clearable
                                />
                            </FormField>

                            <FormField label="Supplier SKU" :error="form.errors[`suppliers.${i}.supplier_sku`] as string">
                                <Input v-model="s.supplier_sku" placeholder="Their code for this item" class="font-mono" />
                            </FormField>

                            <FormField label="Last purchase price" :error="form.errors[`suppliers.${i}.last_purchase_price`] as string">
                                <Input
                                    v-model.number="s.last_purchase_price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="text-right font-mono"
                                />
                            </FormField>

                            <FormField span="full" hide-label label="Primary supplier">
                                <FormCheckbox
                                    :model-value="!!s.is_primary"
                                    label="Primary supplier"
                                    description="Used as the default on new purchase orders"
                                    @update:model-value="(v) => promoteSupplier(i, !!v)"
                                />
                            </FormField>
                        </div>
                    </div>
                </div>
            </FormSection>

            <!-- ── Variants ──────────────────────────────────────────────── -->
            <FormSection
                v-if="activeTab === 'variants'"
                role="tabpanel"
                title="Variants"
                description="Size, colour or pack variations. A variant code is generated from the SKU if you leave it out."
                :icon="Layers"
                plain
                :badge="String(form.variants.length)"
            >
                <template #actions>
                    <Button type="button" variant="outline" size="sm" @click="addVariant">
                        <Plus class="size-4" /> Add variant
                    </Button>
                </template>

                <div class="space-y-3">
                    <div
                        v-for="(v, i) in form.variants"
                        :key="i"
                        class="rounded-xl border bg-muted/[0.25] p-4 transition hover:border-primary/30 hover:bg-card"
                        :class="form.errors[`variants.${i}.name`] ? 'border-destructive/50' : 'border-border'"
                    >
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <p class="font-mono text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                Variant {{ i + 1 }}
                            </p>
                            <span v-if="v.sku" class="rounded-md bg-muted px-1.5 py-0.5 font-mono text-[11px] text-muted-foreground">
                                {{ v.sku }}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="ml-auto size-7 text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove variant ${i + 1}`"
                                @click="removeVariant(i)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>

                        <div class="grid gap-x-5 gap-y-3 sm:grid-cols-2 xl:grid-cols-4">
                            <FormField label="Variant name" required span="2" :error="form.errors[`variants.${i}.name`] as string">
                                <Input v-model="v.name" placeholder="Red — XL" />
                            </FormField>

                            <FormField label="SKU" :error="form.errors[`variants.${i}.sku`] as string">
                                <Input v-model="v.sku" placeholder="SKU-00001-RED-XL" class="font-mono" />
                            </FormField>

                            <FormField label="Barcode" :error="form.errors[`variants.${i}.barcode`] as string">
                                <Input v-model="v.barcode" class="font-mono" />
                            </FormField>

                            <FormField label="Cost price" :error="form.errors[`variants.${i}.cost_price`] as string">
                                <Input v-model.number="v.cost_price" type="number" step="0.01" min="0" class="text-right font-mono" />
                            </FormField>

                            <FormField label="Selling price" :error="form.errors[`variants.${i}.selling_price`] as string">
                                <Input
                                    v-model.number="v.selling_price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    class="text-right font-mono"
                                />
                            </FormField>
                        </div>
                    </div>
                </div>
            </FormSection>

            <FormActions
                :submit-label="isEdit ? 'Save changes' : 'Create product'"
                :cancel-href="indexUrl"
                :processing="form.processing"
                :dirty="form.isDirty"
            >
                <template #summary>
                    <p class="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Package class="size-3.5 shrink-0" />
                        {{ sectionSummary }}
                    </p>
                </template>
            </FormActions>
        </form>
    </div>
</template>
