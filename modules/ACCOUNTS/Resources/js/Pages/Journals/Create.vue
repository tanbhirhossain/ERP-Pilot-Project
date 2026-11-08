<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, nextTick } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { AlertCircle, ArrowLeft, BookOpen, FileText, Plus, Scale, Trash2, Wand2 } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import FormField from '../../../../../CORE/Resources/js/Components/FormField.vue';
import FormSection from '../../../../../CORE/Resources/js/Components/FormSection.vue';
import FormActions from '../../../../../CORE/Resources/js/Components/FormActions.vue';

defineProps<{ accountOptions: any[] }>();

/** Mirrors StoreJournalRequest: lines.*.min = 2, lines.*.max = 50. */
const MIN_LINES = 2;
const MAX_LINES = 50;

const voucherTypes = [
    { code: 'JV', label: 'Journal', hint: 'General / adjusting entry' },
    { code: 'PV', label: 'Payment', hint: 'Cash or bank payment' },
    { code: 'RV', label: 'Receipt', hint: 'Cash or bank receipt' },
    { code: 'CV', label: 'Contra', hint: 'Cash ↔ bank transfer' },
    { code: 'BV', label: 'Bank', hint: 'Bank voucher' },
    { code: 'SV', label: 'Sales', hint: 'Sales voucher' },
];

const typeRefs: Record<string, HTMLElement | null> = {};

function setTypeRef(code: string, el: unknown) {
    typeRefs[code] = (el as HTMLElement | null) ?? null;
}

const form = useForm({
    journal_date: new Date().toISOString().slice(0, 10),
    type: 'JV',
    reference_no: '',
    narration: '',
    lines: [
        { account_id: null as number | null, debit: null as number | null, credit: null as number | null, description: '' },
        { account_id: null as number | null, debit: null as number | null, credit: null as number | null, description: '' },
    ],
});

const round2 = (n: number) => Math.round(n * 100) / 100;

const totalDebit = computed(() => form.lines.reduce((s, l) => s + (Number(l.debit) || 0), 0));
const totalCredit = computed(() => form.lines.reduce((s, l) => s + (Number(l.credit) || 0), 0));
const diff = computed(() => round2(totalDebit.value - totalCredit.value));
const balanced = computed(() => Math.abs(diff.value) < 0.01 && totalDebit.value > 0);

const canAddLine = computed(() => form.lines.length < MAX_LINES);
const canRemoveLine = computed(() => form.lines.length > MIN_LINES);

/**
 * The difference can only be pushed onto the last line when its opposing side is
 * free — a journal line carries a debit *or* a credit, never both.
 */
const canFillDifference = computed(() => {
    if (balanced.value || Math.abs(diff.value) < 0.01 || !form.lines.length) return false;

    const last = form.lines[form.lines.length - 1];

    return diff.value > 0 ? !last.debit : !last.credit;
});

const fillHint = computed(() => {
    if (Math.abs(diff.value) < 0.01) return 'Debit and credit already agree.';
    if (canFillDifference.value) return `Post ${Math.abs(diff.value).toFixed(2)} to the last line.`;

    return 'The last line already uses the opposing side — clear it or add a new line.';
});

function addLine() {
    if (!canAddLine.value) return;
    form.lines.push({ account_id: null, debit: null, credit: null, description: '' });
}

function removeLine(i: number) {
    if (!canRemoveLine.value) return;
    form.lines.splice(i, 1);
}

function fillDifference() {
    if (!canFillDifference.value) return;

    const last = form.lines[form.lines.length - 1];
    const amount = round2(Math.abs(diff.value));

    if (diff.value > 0) {
        last.credit = round2((Number(last.credit) || 0) + amount);
        last.debit = null;
    } else {
        last.debit = round2((Number(last.debit) || 0) + amount);
        last.credit = null;
    }
}

/** Roving tabindex for the voucher-type radio group. */
function onTypeKeydown(e: KeyboardEvent) {
    const nav = ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp'];
    if (!nav.includes(e.key)) return;

    e.preventDefault();
    const at = voucherTypes.findIndex((t) => t.code === form.type);
    const dir = e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : -1;
    const next = voucherTypes[(at + dir + voucherTypes.length) % voucherTypes.length];

    form.type = next.code;
    nextTick(() => typeRefs[next.code]?.focus());
}

function onLineError(i: number, field: 'account_id' | 'debit' | 'credit' | 'description') {
    return (form.errors[`lines.${i}.${field}`] as string | undefined) ?? null;
}

const errorCount = computed(() => Object.keys(form.errors).length);

function submit() {
    form.post(route('accounts.journals.store'), {
        onError: (errors) => {
            const firstLine = Object.keys(errors).find((k) => k.startsWith('lines.'));
            if (firstLine) {
                const idx = Number(firstLine.split('.')[1]);
                if (!Number.isNaN(idx)) {
                    const el = document.getElementById(`journal-line-${idx}-account`);
                    el?.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        },
    });
}
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Journals', href: route('accounts.journals.index') }, { title: 'Create' }]">
        <Head title="Create Journal" />

        <div class="space-y-5">
            <PageHeader
                title="New Journal Entry"
                description="Double-entry voucher — every line posts to one COA account. Debits must equal credits before the entry can be saved."
                :icon="BookOpen"
                :actions="[{ label: 'Back to journals', href: route('accounts.journals.index'), variant: 'outline', icon: ArrowLeft }]"
            />

            <form
                class="space-y-5"
                @submit.prevent="submit"
                @keydown.ctrl.enter.prevent="addLine"
                @keydown.meta.enter.prevent="addLine"
            >
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
                    title="Voucher details"
                    description="The type drives which printed voucher layout is used."
                    :icon="FileText"
                    :cols="3"
                >
                    <FormField span="full">
                        <div
                            role="radiogroup"
                            aria-label="Voucher type"
                            class="grid grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6"
                            @keydown="onTypeKeydown"
                        >
                            <button
                                v-for="t in voucherTypes"
                                :key="t.code"
                                :ref="(el) => setTypeRef(t.code, el)"
                                type="button"
                                role="radio"
                                :aria-checked="form.type === t.code"
                                :tabindex="form.type === t.code ? 0 : -1"
                                class="rounded-xl border px-3 py-2.5 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                :class="
                                    form.type === t.code
                                        ? 'border-primary bg-primary/10 shadow-sm'
                                        : 'border-border bg-card hover:border-primary/40 hover:bg-muted/40'
                                "
                                @click="form.type = t.code"
                            >
                                <span class="flex items-baseline gap-2">
                                    <span
                                        class="font-mono text-xs font-bold tracking-wider"
                                        :class="form.type === t.code ? 'text-primary' : 'text-muted-foreground'"
                                    >
                                        {{ t.code }}
                                    </span>
                                    <span class="text-sm font-semibold">{{ t.label }}</span>
                                </span>
                                <span class="mt-0.5 block text-xs leading-tight text-muted-foreground">{{ t.hint }}</span>
                            </button>
                        </div>
                        <p v-if="form.errors.type" class="mt-2 text-sm text-destructive">{{ form.errors.type }}</p>
                    </FormField>

                    <FormField label="Journal date" required :error="form.errors.journal_date as string">
                        <template #default="{ id, invalid }">
                            <Input v-model="form.journal_date" type="date" :id="id" :aria-invalid="invalid" />
                        </template>
                    </FormField>

                    <FormField
                        label="Reference"
                        help="Optional — invoice, cheque or bill number."
                        :error="form.errors.reference_no as string"
                    >
                        <template #default="{ id, invalid }">
                            <Input
                                v-model="form.reference_no"
                                :id="id"
                                :aria-invalid="invalid"
                                placeholder="INV-001 / CHQ-123"
                                maxlength="100"
                            />
                        </template>
                    </FormField>

                    <FormField
                        label="Balance status"
                        :help="balanced ? 'Debits equal credits — ready to save.' : 'Enter either a debit or a credit on each line; the other side clears.'"
                    >
                        <div
                            class="flex h-10 items-center gap-2 rounded-md border px-3 font-mono text-sm font-semibold"
                            :class="
                                balanced
                                    ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400'
                                    : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-400'
                            "
                        >
                            <Scale class="size-4 shrink-0" />
                            <span>{{ balanced ? 'Balanced' : `Off by ${Math.abs(diff).toFixed(2)}` }}</span>
                        </div>
                    </FormField>

                    <FormField label="Narration" span="full" :error="form.errors.narration as string">
                        <template #default="{ id, invalid }">
                            <Textarea
                                v-model="form.narration"
                                :id="id"
                                :aria-invalid="invalid"
                                rows="2"
                                maxlength="2000"
                                placeholder="Being … (e.g. salary paid for January 2026)"
                                class="resize-none"
                            />
                        </template>
                    </FormField>
                </FormSection>

                <FormSection
                    title="Journal lines"
                    description="Each line posts to one COA account. Tip: press Ctrl + Enter anywhere to append a line."
                    :icon="Scale"
                    plain
                    :badge="`${form.lines.length} / ${MAX_LINES}`"
                >
                    <template #actions>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            :disabled="!canAddLine"
                            :title="canAddLine ? 'Add another line' : `A journal holds at most ${MAX_LINES} lines`"
                            @click="addLine"
                        >
                            <Plus class="size-4" /> Add line
                        </Button>
                    </template>

                    <div class="space-y-3">
                        <p v-if="form.errors.lines" role="alert" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm font-medium text-destructive">
                            {{ form.errors.lines }}
                        </p>

                        <!-- Column headings line up with the row grid below at xl and up. -->
                        <div class="hidden gap-x-5 xl:grid xl:grid-cols-4 xl:px-1">
                            <p class="xl:col-span-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Account</p>
                            <p class="text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Debit</p>
                            <p class="text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Credit</p>
                        </div>

                        <div
                            v-for="(line, i) in form.lines"
                            :key="i"
                            class="group relative rounded-lg border bg-card p-4 transition hover:border-primary/30 hover:shadow-sm"
                            :class="onLineError(i, 'account_id') ? 'border-destructive/50' : 'border-border'"
                        >
                            <div class="mb-3 flex items-center gap-2">
                                <span class="font-mono text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                    Line {{ i + 1 }}
                                </span>

                                <span
                                    v-if="line.debit || line.credit"
                                    class="rounded-md px-1.5 py-0.5 font-mono text-[11px] font-bold"
                                    :class="
                                        Number(line.debit) > 0
                                            ? 'bg-sky-500/15 text-sky-700 dark:text-sky-400'
                                            : 'bg-violet-500/15 text-violet-700 dark:text-violet-400'
                                    "
                                >
                                    {{ Number(line.debit) > 0 ? 'DR' : 'CR' }}
                                    {{ (Number(line.debit) || Number(line.credit) || 0).toFixed(2) }}
                                </span>

                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    class="ml-auto size-7 text-muted-foreground hover:text-destructive"
                                    :disabled="!canRemoveLine"
                                    :aria-label="`Remove line ${i + 1}`"
                                    :title="canRemoveLine ? 'Remove this line' : `A journal needs at least ${MIN_LINES} lines`"
                                    @click="removeLine(i)"
                                >
                                    <Trash2 class="size-3.5" />
                                </Button>
                            </div>

                            <div class="grid gap-x-5 gap-y-3 sm:grid-cols-2 xl:grid-cols-4">
                                <FormField
                                    label="Account"
                                    required
                                    span="full"
                                    class="xl:col-span-2"
                                    :error="onLineError(i, 'account_id')"
                                >
                                    <SearchableSelect
                                        v-model="line.account_id"
                                        :id="`journal-line-${i}-account`"
                                        :options="accountOptions"
                                        endpoint="/accounts/api/options/accounts"
                                        placeholder="Search the chart of accounts…"
                                    />
                                </FormField>

                                <FormField label="Debit" :error="onLineError(i, 'debit')" label-class="xl:sr-only">
                                    <template #default="{ id, invalid }">
                                        <Input
                                            v-model.number="line.debit"
                                            :id="id"
                                            :aria-invalid="invalid"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            placeholder="0.00"
                                            class="text-right font-mono"
                                            @update:model-value="(v) => v && (line.credit = null)"
                                        />
                                    </template>
                                </FormField>

                                <FormField label="Credit" :error="onLineError(i, 'credit')" label-class="xl:sr-only">
                                    <template #default="{ id, invalid }">
                                        <Input
                                            v-model.number="line.credit"
                                            :id="id"
                                            :aria-invalid="invalid"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            placeholder="0.00"
                                            class="text-right font-mono"
                                            @update:model-value="(v) => v && (line.debit = null)"
                                        />
                                    </template>
                                </FormField>

                                <FormField label="Memo" span="full" :error="onLineError(i, 'description')">
                                    <template #default="{ id, invalid }">
                                        <Input
                                            v-model="line.description"
                                            :id="id"
                                            :aria-invalid="invalid"
                                            maxlength="500"
                                            placeholder="Line note — printed per line on the voucher"
                                        />
                                    </template>
                                </FormField>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2 pt-1">
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="!canAddLine"
                                @click="addLine"
                            >
                                <Plus class="size-4" /> Add line
                            </Button>

                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                class="text-muted-foreground"
                                :disabled="!canFillDifference"
                                :title="fillHint"
                                @click="fillDifference"
                            >
                                <Wand2 class="size-4" />
                                Post {{ Math.abs(diff).toFixed(2) }} difference
                            </Button>
                        </div>
                    </div>

                    <template #footer>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="rounded-lg bg-sky-500/10 px-3 py-2">
                                <p class="text-xs font-medium text-muted-foreground">Total debit</p>
                                <p class="font-mono text-base font-bold text-sky-700 dark:text-sky-400">
                                    {{ totalDebit.toFixed(2) }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-violet-500/10 px-3 py-2">
                                <p class="text-xs font-medium text-muted-foreground">Total credit</p>
                                <p class="font-mono text-base font-bold text-violet-700 dark:text-violet-400">
                                    {{ totalCredit.toFixed(2) }}
                                </p>
                            </div>
                            <div
                                class="rounded-lg px-3 py-2"
                                :class="balanced ? 'bg-emerald-500/10' : 'bg-amber-500/10'"
                            >
                                <p class="text-xs font-medium text-muted-foreground">Difference</p>
                                <p
                                    class="font-mono text-base font-bold"
                                    :class="balanced ? 'text-emerald-700 dark:text-emerald-400' : 'text-amber-700 dark:text-amber-400'"
                                >
                                    {{ balanced ? '0.00 — balanced' : diff.toFixed(2) }}
                                </p>
                            </div>
                        </div>
                    </template>
                </FormSection>

                <FormActions
                    submit-label="Save draft journal"
                    :cancel-href="route('accounts.journals.index')"
                    :processing="form.processing"
                    :disabled="!balanced"
                >
                    <template #summary>
                        <div class="min-w-0">
                            <p class="flex items-center gap-1.5 font-mono text-sm font-bold" :class="balanced ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">
                                <Scale class="size-3.5 shrink-0" />
                                Dr {{ totalDebit.toFixed(2) }} · Cr {{ totalCredit.toFixed(2) }}
                            </p>
                            <p v-if="!balanced" class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                <AlertCircle class="size-3 shrink-0" />
                                {{ fillHint }}
                            </p>
                            <p v-else class="mt-0.5 text-xs text-muted-foreground">
                                {{ form.lines.length }} {{ form.lines.length === 1 ? 'line' : 'lines' }} · saved as a draft, then post it from the journal list
                            </p>
                        </div>
                    </template>
                </FormActions>
            </form>
        </div>
    </AdminLayout>
</template>
