<script setup lang="ts">
/**
 * PartnerForm — the single customer/vendor editor.
 *
 * Customers and Vendors are the same `partners` table with a different `type` default, and the
 * four pages (Customers/Vendors × Create/Edit) had drifted into four hand-maintained copies with
 * different formatting and different error handling. This component is the one implementation;
 * each page is now a thin wrapper that supplies `mode`, `partner`, `submitUrl` and `entityLabel`.
 */
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    AlertCircle,
    Banknote,
    Building2,
    ChevronLeft,
    ChevronRight,
    Coins,
    Landmark,
    MapPin,
    Phone,
    Plus,
    Trash2,
    User,
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
        partner?: any;
        defaultType?: string;
        groupOptions: any[];
        priceListOptions: any[];
        userOptions: any[];
        submitUrl: string;
        indexUrl: string;
        entityLabel: string;
    }>(),
    {
        partner: null,
        defaultType: 'customer',
    },
);

const isEdit = computed(() => props.mode === 'edit');
const p = computed<any>(() => props.partner ?? {});

const blankContact = (primary = false) => ({
    id: null as number | null,
    name: '',
    designation: '',
    phone: '',
    email: '',
    is_primary: primary,
    can_receive_statement: false,
});

const blankAddress = (primary = false) => ({
    id: null as number | null,
    type: 'billing',
    label: primary ? 'Head Office' : '',
    address_line: '',
    area: '',
    city: '',
    district: '',
    division: '',
    country: 'Bangladesh',
    postal_code: '',
    is_default: primary,
});

const blankBank = (primary = false) => ({
    id: null as number | null,
    bank_name: '',
    branch: '',
    account_name: '',
    account_no: '',
    routing_no: '',
    swift_code: '',
    is_primary: primary,
});

const form = useForm({
    type: isEdit.value ? p.value.type : props.defaultType || 'customer',
    name: p.value.name ?? '',
    legal_name: p.value.legal_name ?? '',
    partner_code: p.value.partner_code ?? '',
    group_id: p.value.group_id ?? null,
    price_list_id: p.value.price_list_id ?? null,
    trade_license: p.value.trade_license ?? '',
    tin: p.value.tin ?? '',
    bin: p.value.bin ?? '',
    vat_reg_no: p.value.vat_reg_no ?? '',
    nid: p.value.nid ?? '',
    phone: p.value.phone ?? '',
    email: p.value.email ?? '',
    website: p.value.website ?? '',
    credit_limit: p.value.credit_limit ?? 0,
    payment_terms_days: p.value.payment_terms_days ?? 30,
    payment_terms: p.value.payment_terms ?? (isEdit.value ? '' : 'Net 30'),
    opening_balance: p.value.opening_balance ?? 0,
    currency: p.value.currency ?? 'BDT',
    assigned_to: p.value.assigned_to ?? null,
    notes: p.value.notes ?? '',
    internal_notes: p.value.internal_notes ?? '',
    contacts: p.value.contacts?.length ? p.value.contacts.map((c: any) => ({ ...blankContact(), ...c })) : [blankContact(true)],
    addresses: p.value.addresses?.length ? p.value.addresses.map((a: any) => ({ ...blankAddress(), ...a })) : [blankAddress(true)],
    banks: p.value.banks?.length ? p.value.banks.map((b: any) => ({ ...blankBank(), ...b })) : [blankBank(true)],
});

const tabs = [
    { id: 'basic', label: 'Basic info', icon: Building2 },
    { id: 'commercial', label: 'Commercial', icon: Coins },
    { id: 'contacts', label: 'Contacts', icon: Phone },
    { id: 'addresses', label: 'Addresses', icon: MapPin },
    { id: 'banks', label: 'Banks', icon: Landmark },
];

const activeTab = ref('basic');

/**
 * Section traversal.
 *
 * Five tabs made this form a mouse hunt: the only way forward was to reach back up to the nav,
 * and the submit button sat below whatever section you were on, so finishing the Banks tab meant
 * scrolling past four empty ones. Back/Next live in the sticky action bar beside Submit, so the
 * way forward is always under the operator's hands, and changing section scrolls to the top of
 * the panel — otherwise the next section opens already scrolled past its first field.
 */
const tabIndex = computed(() => Math.max(0, tabs.findIndex((t) => t.id === activeTab.value)));
const isFirstTab = computed(() => tabIndex.value === 0);
const isLastTab = computed(() => tabIndex.value === tabs.length - 1);

function goToTab(index: number) {
    const next = tabs[index];
    if (!next || next.id === activeTab.value) return;
    activeTab.value = next.id;
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
function nextTab() {
    goToTab(tabIndex.value + 1);
}
function prevTab() {
    goToTab(tabIndex.value - 1);
}

/** Arrow keys walk the tablist, which is what role="tablist" promises a keyboard user. */
function onTabKeydown(event: KeyboardEvent) {
    if (event.key === 'ArrowRight') {
        event.preventDefault();
        goToTab(Math.min(tabs.length - 1, tabIndex.value + 1));
    } else if (event.key === 'ArrowLeft') {
        event.preventDefault();
        goToTab(Math.max(0, tabIndex.value - 1));
    }
}

const commercialFields = [
    'credit_limit',
    'payment_terms_days',
    'payment_terms',
    'opening_balance',
    'currency',
    'notes',
    'internal_notes',
];

function tabForError(field: string) {
    if (field.startsWith('contacts')) return 'contacts';
    if (field.startsWith('addresses')) return 'addresses';
    if (field.startsWith('banks')) return 'banks';
    if (commercialFields.some((key) => field === key || field.startsWith(`${key}.`))) return 'commercial';

    return 'basic';
}

/** Tabs carrying at least one validation error, so the badge can flag an unseen section. */
const errorTabs = computed(() => new Set(Object.keys(form.errors).map(tabForError)));

const errorCount = computed(() => Object.keys(form.errors).length);

function addContact() {
    form.contacts.push(blankContact());
}
function removeContact(i: number) {
    form.contacts.splice(i, 1);
    if (!form.contacts.length) addContact();
}
function addAddress() {
    form.addresses.push(blankAddress());
}
function removeAddress(i: number) {
    form.addresses.splice(i, 1);
    if (!form.addresses.length) addAddress();
}
function addBank() {
    form.banks.push(blankBank());
}
function removeBank(i: number) {
    form.banks.splice(i, 1);
    if (!form.banks.length) addBank();
}

/**
 * "Primary" and "Default" are exclusive flags, but three separate checkboxes would let the user
 * tick two rows at once. Promoting one row clears the others.
 */
function promote<T extends Record<string, any>>(rows: T[], index: number, key: string, value: boolean) {
    if (!value) return;
    rows.forEach((row, i) => {
        row[key] = i === index;
    });
}

const sectionSummary = computed(() => {
    const at = tabs.findIndex((t) => t.id === activeTab.value) + 1;

    return `Section ${at} of ${tabs.length} · ${tabs[at - 1]?.label ?? ''}`;
});

function submit() {
    const options = {
        onError: (errors: Record<string, string>) => {
            const first = Object.keys(errors)[0];
            if (first) activeTab.value = tabForError(first);
        },
    };

    if (isEdit.value) form.put(props.submitUrl, options);
    else form.post(props.submitUrl, options);
}
</script>

<template>
    <div class="space-y-5">
        <PageHeader
            :title="isEdit ? `Edit ${p.name || entityLabel}` : `Add ${entityLabel}`"
            :description="
                isEdit
                    ? `${p.partner_code ?? ''}${p.group?.name ? ' · ' + p.group.name : ''}`
                    : 'The partner code is generated automatically if you leave it blank.'
            "
            :icon="Building2"
            :actions="[{ label: `Back to ${entityLabel.toLowerCase()}s`, href: indexUrl, variant: 'outline' }]"
        />

        <form class="space-y-5" @submit.prevent="submit">
            <!-- ── Section navigation ───────────────────────────────────── -->
            <nav
                class="sticky top-2 z-20 -mx-1 flex gap-1.5 overflow-x-auto rounded-2xl border bg-card/95 p-2 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-card/80"
                role="tablist"
                :aria-label="`${entityLabel} form sections`"
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
                    @click="goToTab(tabs.indexOf(t))"
                    @keydown="onTabKeydown"
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

            <!-- ── Basic information ────────────────────────────────────── -->
            <FormSection
                v-if="activeTab === 'basic'"
                role="tabpanel"
                title="Basic information"
                description="Identity, grouping and how to reach them."
                :icon="Building2"
                :cols="4"
            >
                <FormField label="Partner type" required :error="form.errors.type as string">
                    <template #default="{ id, invalid }">
                        <FormSelect v-model="form.type" :id="id" :invalid="invalid">
                            <option value="customer">Customer</option>
                            <option value="vendor">Vendor</option>
                            <option value="both">Both — buys and sells</option>
                        </FormSelect>
                    </template>
                </FormField>

                <FormField label="Name" required :error="form.errors.name as string" span="2">
                    <template #default="{ id, invalid }">
                        <Input
                            v-model="form.name"
                            :id="id"
                            :aria-invalid="invalid"
                            placeholder="Apex Retail Chain"
                            autocomplete="organization"
                        />
                    </template>
                </FormField>

                <FormField
                    label="Partner code"
                    help="Leave blank to auto-generate."
                    :error="form.errors.partner_code as string"
                >
                    <template #default="{ id, invalid }">
                        <Input v-model="form.partner_code" :id="id" :aria-invalid="invalid" placeholder="CUS-0001" class="font-mono" />
                    </template>
                </FormField>

                <FormField label="Legal name" :error="form.errors.legal_name as string" span="2">
                    <template #default="{ id, invalid }">
                        <Input v-model="form.legal_name" :id="id" :aria-invalid="invalid" placeholder="Apex Retail Chain Ltd." />
                    </template>
                </FormField>

                <FormField label="Group" :error="form.errors.group_id as string">
                    <SearchableSelect v-model="form.group_id" :options="groupOptions" placeholder="Select a group" clearable />
                </FormField>

                <FormField label="Price list" :error="form.errors.price_list_id as string">
                    <SearchableSelect
                        v-model="form.price_list_id"
                        :options="priceListOptions"
                        placeholder="Select a price list"
                        clearable
                    />
                </FormField>

                <FormField label="Phone" :error="form.errors.phone as string">
                    <template #default="{ id, invalid }">
                        <Input v-model="form.phone" :id="id" :aria-invalid="invalid" type="tel" placeholder="+880 1XXX-XXXXXX" />
                    </template>
                </FormField>

                <FormField label="Email" :error="form.errors.email as string">
                    <template #default="{ id, invalid }">
                        <Input v-model="form.email" :id="id" :aria-invalid="invalid" type="email" placeholder="accounts@apex.com" />
                    </template>
                </FormField>

                <FormField label="Website" :error="form.errors.website as string">
                    <template #default="{ id, invalid }">
                        <Input v-model="form.website" :id="id" :aria-invalid="invalid" type="url" placeholder="https://apex.com" />
                    </template>
                </FormField>

                <FormField label="Assigned to" help="The owner of this relationship." :error="form.errors.assigned_to as string">
                    <SearchableSelect v-model="form.assigned_to" :options="userOptions" placeholder="Select an owner" clearable />
                </FormField>
            </FormSection>

            <!-- ── Commercial ───────────────────────────────────────────── -->
            <FormSection
                v-if="activeTab === 'commercial'"
                role="tabpanel"
                title="Commercial terms"
                description="Credit exposure, payment terms and opening position."
                :icon="Coins"
                :cols="4"
            >
                <FormField label="Credit limit" help="Maximum outstanding balance." :error="form.errors.credit_limit as string">
                    <template #default="{ id, invalid }">
                        <Input
                            v-model.number="form.credit_limit"
                            :id="id"
                            :aria-invalid="invalid"
                            type="number"
                            step="0.01"
                            min="0"
                            class="text-right font-mono"
                        />
                    </template>
                </FormField>

                <FormField label="Payment days" :error="form.errors.payment_terms_days as string">
                    <template #default="{ id, invalid }">
                        <Input
                            v-model.number="form.payment_terms_days"
                            :id="id"
                            :aria-invalid="invalid"
                            type="number"
                            min="0"
                            class="text-right font-mono"
                        />
                    </template>
                </FormField>

                <FormField label="Payment terms" :error="form.errors.payment_terms as string">
                    <template #default="{ id, invalid }">
                        <FormSelect v-model="form.payment_terms" :id="id" :invalid="invalid" placeholder="Select terms">
                            <option>Net 7</option>
                            <option>Net 15</option>
                            <option>Net 30</option>
                            <option>Net 45</option>
                            <option>Net 60</option>
                            <option>Cash on delivery</option>
                            <option>Advance</option>
                        </FormSelect>
                    </template>
                </FormField>

                <FormField label="Opening balance" :error="form.errors.opening_balance as string">
                    <template #default="{ id, invalid }">
                        <Input
                            v-model.number="form.opening_balance"
                            :id="id"
                            :aria-invalid="invalid"
                            type="number"
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

                <FormField label="Notes" help="Visible on the partner record and printed documents." span="full">
                    <template #default="{ id, invalid }">
                        <Textarea v-model="form.notes" :id="id" :aria-invalid="invalid" rows="2" class="resize-none" />
                    </template>
                </FormField>

                <FormField label="Internal notes" help="Never printed or shared with the partner." span="full">
                    <template #default="{ id, invalid }">
                        <Textarea v-model="form.internal_notes" :id="id" :aria-invalid="invalid" rows="2" class="resize-none" />
                    </template>
                </FormField>
            </FormSection>

            <!-- ── Contacts ─────────────────────────────────────────────── -->
            <FormSection
                v-if="activeTab === 'contacts'"
                role="tabpanel"
                title="Contacts"
                description="The people you actually deal with. One should be marked primary."
                :icon="Phone"
                plain
                :badge="String(form.contacts.length)"
            >
                <template #actions>
                    <Button type="button" variant="outline" size="sm" @click="addContact">
                        <Plus class="size-4" /> Add contact
                    </Button>
                </template>

                <div class="space-y-3">
                    <div
                        v-for="(c, i) in form.contacts"
                        :key="i"
                        class="rounded-xl border bg-muted/[0.25] p-4 transition hover:border-primary/30 hover:bg-card"
                        :class="form.errors[`contacts.${i}.name`] ? 'border-destructive/50' : 'border-border'"
                    >
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <p class="font-mono text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                Contact {{ i + 1 }}
                            </p>
                            <span
                                v-if="c.is_primary"
                                class="rounded-md bg-primary/10 px-1.5 py-0.5 text-[11px] font-bold text-primary"
                            >
                                PRIMARY
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="ml-auto size-7 text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove contact ${i + 1}`"
                                @click="removeContact(i)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>

                        <div class="grid gap-x-5 gap-y-3 sm:grid-cols-2 xl:grid-cols-4">
                            <FormField label="Name" :error="form.errors[`contacts.${i}.name`] as string">
                                <Input v-model="c.name" placeholder="Full name" />
                            </FormField>

                            <FormField label="Designation" :error="form.errors[`contacts.${i}.designation`] as string">
                                <Input v-model="c.designation" placeholder="Accounts Manager" />
                            </FormField>

                            <FormField label="Phone" :error="form.errors[`contacts.${i}.phone`] as string">
                                <Input v-model="c.phone" type="tel" placeholder="+880 1XXX-XXXXXX" />
                            </FormField>

                            <FormField label="Email" :error="form.errors[`contacts.${i}.email`] as string">
                                <Input v-model="c.email" type="email" placeholder="name@company.com" />
                            </FormField>

                            <FormField span="2" hide-label label="Flags">
                                <div class="grid gap-2 sm:grid-cols-2">
                                    <FormCheckbox
                                        :model-value="!!c.is_primary"
                                        label="Primary contact"
                                        description="Listed first on the record"
                                        @update:model-value="(v) => promote(form.contacts, i, 'is_primary', !!v)"
                                    />
                                    <FormCheckbox
                                        v-model="c.can_receive_statement"
                                        label="Can receive statements"
                                        description="Gets emailed account statements"
                                    />
                                </div>
                            </FormField>
                        </div>
                    </div>
                </div>
            </FormSection>

            <!-- ── Addresses ────────────────────────────────────────────── -->
            <FormSection
                v-if="activeTab === 'addresses'"
                role="tabpanel"
                title="Addresses"
                description="Billing and shipping addresses. One should be marked default."
                :icon="MapPin"
                plain
                :badge="String(form.addresses.length)"
            >
                <template #actions>
                    <Button type="button" variant="outline" size="sm" @click="addAddress">
                        <Plus class="size-4" /> Add address
                    </Button>
                </template>

                <div class="space-y-3">
                    <div
                        v-for="(a, i) in form.addresses"
                        :key="i"
                        class="rounded-xl border bg-muted/[0.25] p-4 transition hover:border-primary/30 hover:bg-card"
                    >
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <p class="font-mono text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                Address {{ i + 1 }}
                            </p>
                            <span
                                v-if="a.is_default"
                                class="rounded-md bg-primary/10 px-1.5 py-0.5 text-[11px] font-bold text-primary"
                            >
                                DEFAULT
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="ml-auto size-7 text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove address ${i + 1}`"
                                @click="removeAddress(i)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>

                        <div class="grid gap-x-5 gap-y-3 sm:grid-cols-2 xl:grid-cols-4">
                            <FormField label="Type" :error="form.errors[`addresses.${i}.type`] as string">
                                <FormSelect v-model="a.type">
                                    <option value="billing">Billing</option>
                                    <option value="shipping">Shipping</option>
                                    <option value="both">Billing &amp; shipping</option>
                                </FormSelect>
                            </FormField>

                            <FormField label="Label" :error="form.errors[`addresses.${i}.label`] as string">
                                <Input v-model="a.label" placeholder="Head Office / Warehouse 2" />
                            </FormField>

                            <FormField label="Address line" span="2" help="Optional — leave the whole row blank to skip it." :error="form.errors[`addresses.${i}.address_line`] as string">
                                <Input v-model="a.address_line" placeholder="House, road, block" />
                            </FormField>

                            <FormField label="Area" :error="form.errors[`addresses.${i}.area`] as string">
                                <Input v-model="a.area" placeholder="Gulshan 2" />
                            </FormField>

                            <FormField label="City" :error="form.errors[`addresses.${i}.city`] as string">
                                <Input v-model="a.city" placeholder="Dhaka" />
                            </FormField>

                            <FormField label="District" :error="form.errors[`addresses.${i}.district`] as string">
                                <Input v-model="a.district" placeholder="Dhaka" />
                            </FormField>

                            <FormField label="Division" :error="form.errors[`addresses.${i}.division`] as string">
                                <Input v-model="a.division" placeholder="Dhaka" />
                            </FormField>

                            <FormField label="Country" :error="form.errors[`addresses.${i}.country`] as string">
                                <Input v-model="a.country" placeholder="Bangladesh" />
                            </FormField>

                            <FormField label="Postal code" :error="form.errors[`addresses.${i}.postal_code`] as string">
                                <Input v-model="a.postal_code" placeholder="1212" class="font-mono" />
                            </FormField>

                            <FormField span="2" hide-label label="Default address">
                                <FormCheckbox
                                    :model-value="!!a.is_default"
                                    label="Default address"
                                    description="Pre-selected on new documents"
                                    @update:model-value="(v) => promote(form.addresses, i, 'is_default', !!v)"
                                />
                            </FormField>
                        </div>
                    </div>
                </div>
            </FormSection>

            <!-- ── Banks ────────────────────────────────────────────────── -->
            <FormSection
                v-if="activeTab === 'banks'"
                role="tabpanel"
                title="Bank accounts"
                description="Used for payment advice, receipts and reconciliation."
                :icon="Landmark"
                plain
                :badge="String(form.banks.length)"
            >
                <template #actions>
                    <Button type="button" variant="outline" size="sm" @click="addBank">
                        <Plus class="size-4" /> Add account
                    </Button>
                </template>

                <div class="space-y-3">
                    <div
                        v-for="(b, i) in form.banks"
                        :key="i"
                        class="rounded-xl border bg-muted/[0.25] p-4 transition hover:border-primary/30 hover:bg-card"
                    >
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <p class="flex items-center gap-1.5 font-mono text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                <Banknote class="size-3.5" /> Account {{ i + 1 }}
                            </p>
                            <span
                                v-if="b.is_primary"
                                class="rounded-md bg-primary/10 px-1.5 py-0.5 text-[11px] font-bold text-primary"
                            >
                                PRIMARY
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="ml-auto size-7 text-muted-foreground hover:text-destructive"
                                :aria-label="`Remove bank account ${i + 1}`"
                                @click="removeBank(i)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>
                        </div>

                        <div class="grid gap-x-5 gap-y-3 sm:grid-cols-2 xl:grid-cols-4">
                            <FormField label="Bank name" help="Optional." :error="form.errors[`banks.${i}.bank_name`] as string">
                                <Input v-model="b.bank_name" placeholder="Dutch-Bangla Bank" />
                            </FormField>

                            <FormField label="Branch" :error="form.errors[`banks.${i}.branch`] as string">
                                <Input v-model="b.branch" placeholder="Gulshan Branch" />
                            </FormField>

                            <FormField label="Account name" :error="form.errors[`banks.${i}.account_name`] as string">
                                <Input v-model="b.account_name" placeholder="Apex Retail Chain Ltd." />
                            </FormField>

                            <FormField label="Account no" help="Optional." :error="form.errors[`banks.${i}.account_no`] as string">
                                <Input v-model="b.account_no" placeholder="Account number" class="font-mono" />
                            </FormField>

                            <FormField label="Routing no" :error="form.errors[`banks.${i}.routing_no`] as string">
                                <Input v-model="b.routing_no" placeholder="9-digit routing number" class="font-mono" />
                            </FormField>

                            <FormField label="SWIFT / IBAN" :error="form.errors[`banks.${i}.swift_code`] as string">
                                <Input v-model="b.swift_code" placeholder="For foreign transfers" class="font-mono uppercase" />
                            </FormField>

                            <FormField span="2" hide-label label="Primary account">
                                <FormCheckbox
                                    :model-value="!!b.is_primary"
                                    label="Primary account"
                                    description="Default for payment advice"
                                    @update:model-value="(v) => promote(form.banks, i, 'is_primary', !!v)"
                                />
                            </FormField>
                        </div>
                    </div>
                </div>
            </FormSection>

            <FormActions
                :submit-label="isEdit ? 'Save changes' : `Create ${entityLabel}`"
                :cancel-href="indexUrl"
                :processing="form.processing"
                :dirty="form.isDirty"
            >
                <template #summary>
                    <p class="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <User class="size-3.5 shrink-0" />
                        {{ sectionSummary }}
                    </p>
                    <span
                        class="h-1.5 w-24 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        :aria-valuenow="tabIndex + 1"
                        :aria-valuemin="1"
                        :aria-valuemax="tabs.length"
                        :aria-label="`${entityLabel} form progress`"
                    >
                        <span
                            class="block h-full rounded-full bg-primary transition-all duration-300"
                            :style="{ width: `${((tabIndex + 1) / tabs.length) * 100}%` }"
                        />
                    </span>
                </template>

                <template #actions>
                    <Button type="button" variant="outline" :disabled="isFirstTab || form.processing" @click="prevTab">
                        <ChevronLeft class="size-4" />Back
                    </Button>
                    <Button v-if="!isLastTab" type="button" variant="secondary" :disabled="form.processing" @click="nextTab">
                        Next: {{ tabs[tabIndex + 1]?.label }}<ChevronRight class="size-4" />
                    </Button>
                </template>
            </FormActions>
        </form>
    </div>
</template>
