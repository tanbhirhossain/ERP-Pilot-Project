<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import type { SelectOption } from '@core/types';
import { computed, nextTick } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { AlertCircle, ArrowLeft, ArrowRight, ArrowUpRight, ArrowDownRight, ArrowLeftRight, BookOpen, FileText, Landmark, Plus, Scale, Tag, Trash2, Wand2, Clock } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormField from '@core/Components/FormField.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormActions from '@core/Components/FormActions.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';

import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
defineProps<{ accountOptions: SelectOption[]; todaysJournals?: { id: number; journal_no: string; type: string; status: string; total_debit: string | number; narration: string | null }[] }>();

/** Mirrors StoreJournalRequest: lines.*.min = 2, lines.*.max = 50. */
const MIN_LINES = 2;
const MAX_LINES = 50;

const voucherTypes = [
    { code: 'JV', label: 'Journal', hint: 'General / adjusting entry', icon: BookOpen },
    { code: 'PV', label: 'Payment', hint: 'Cash or bank payment', icon: ArrowUpRight },
    { code: 'RV', label: 'Receipt', hint: 'Cash or bank receipt', icon: ArrowDownRight },
    { code: 'CV', label: 'Contra', hint: 'Cash ↔ bank transfer', icon: ArrowLeftRight },
    { code: 'BV', label: 'Bank', hint: 'Bank voucher', icon: Landmark },
    { code: 'SV', label: 'Sales', hint: 'Sales voucher', icon: Tag },
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
useUnsavedGuard(() => form.isDirty);

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
    if (totalDebit.value === 0 && totalCredit.value === 0) return 'Enter debit and credit amounts to begin.';
    if (Math.abs(diff.value) < 0.01) return 'Debit and credit already agree.';
    if (canFillDifference.value) return `Post ${Math.abs(diff.value).toFixed(2)} to the last line.`;

    return 'The last line already uses the opposing side — clear it or add a new line.';
});

/** Status → badge tone for the "today's entries" column. */
function journalStatusTone(status: string): 'success' | 'warning' | 'danger' | 'neutral' {
    if (status === 'posted') return 'success';
    if (status === 'draft') return 'warning';
    if (status === 'void') return 'danger';

    return 'neutral';
}

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

        <div class="space-y-4">
            <PageHeader
                title="New Journal Entry"
                description="Double-entry voucher — every line posts to one COA account. Debits must equal credits before the entry can be saved."
                :icon="BookOpen"
                :actions="[{ label: 'Back to journals', href: route('accounts.journals.index'), variant: 'outline', icon: ArrowLeft }]"
            />

            <!--
                Three columns on a wide desk, packed rather than padded: the voucher itself, a
                one-glance summary of its balance, and — the column asked for — today's journal
                entries, so the cashier can see what the day has already produced without leaving the
                form. The middle column only exists where there is room for it; the entries column is
                always beside the form.
            -->
            <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_19rem] 2xl:grid-cols-[minmax(0,1fr)_21rem]">
            <form
                class="min-w-0 space-y-3"
                @submit.prevent="submit"
                @keydown.ctrl.enter.prevent="addLine"
                @keydown.meta.enter.prevent="addLine"
            >
                <div
                    v-if="errorCount"
                    role="alert"
                    class="flex items-start gap-3 rounded-xl border border-destructive/30 bg-destructive/5 px-3.5 py-2.5 text-sm text-destructive"
                >
                    <AlertCircle class="mt-px size-4 shrink-0" />
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
                    dense
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
                                class="group flex flex-col items-center gap-1.5 rounded-xl border px-2 py-2.5 transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                                :class="
                                    form.type === t.code
                                        ? 'border-primary bg-primary/[0.06] ring-1 ring-inset ring-primary/20 shadow-sm'
                                        : 'border-border bg-card hover:border-primary/40 hover:bg-muted/40'
                                "
                                @click="form.type = t.code"
                            >
                                <span
                                    class="grid size-8 place-items-center rounded-lg transition"
                                    :class="form.type === t.code ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground group-hover:bg-primary/10 group-hover:text-primary'"
                                >
                                    <component :is="t.icon" class="size-4" />
                                </span>
                                <span class="flex items-center gap-1.5">
                                    <span
                                        class="font-mono text-[11px] font-bold tracking-wider"
                                        :class="form.type === t.code ? 'text-primary' : 'text-muted-foreground'"
                                    >{{ t.code }}</span>
                                    <span class="text-[11px] font-semibold">{{ t.label }}</span>
                                </span>
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
                            <span>{{ balanced ? 'Balanced' : totalDebit === 0 && totalCredit === 0 ? 'Enter amounts' : `Off by ${Math.abs(diff).toFixed(2)}` }}</span>
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
                    dense
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

                    <!-- `@container` is what makes the row's inline layout answer to the width it is
                         actually given, not to the width of the window. See the note under the headings. -->
                    <div class="@container space-y-3">
                        <p v-if="form.errors.lines" role="alert" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm font-medium text-destructive">
                            {{ form.errors.lines }}
                        </p>

                        <!--
                            One journal line, one row: Account · Memo · Debit · Credit, with the line number
                            and its DR/CR side in a gutter and the remove button pinned to the card's edge.

                            The switch is a *container* query (`@3xl`), not a viewport one, and that is the
                            point. The form sits inside a shell whose sidebar collapses to an icon rail, which
                            widens the content area by 200px without the window changing size — a viewport
                            breakpoint would keep the lines stacked on exactly the screens that just got room
                            for them. The heading row above repeats this same column template, so labels stay
                            over their fields at every width.
                        -->
                        <div class="hidden @3xl:grid @3xl:gap-x-3 @3xl:px-3 @3xl:pr-11 @3xl:grid-cols-[1.9rem_minmax(0,1.15fr)_minmax(0,1.1fr)_minmax(0,0.7fr)_minmax(0,0.7fr)]">
                            <span aria-hidden="true" />
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Account</p>
                            <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">Memo</p>
                            <p class="text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Debit</p>
                            <p class="text-right text-xs font-semibold uppercase tracking-wider text-muted-foreground">Credit</p>
                        </div>

                        <div
                            v-for="(line, i) in form.lines"
                            :key="i"
                            class="group relative rounded-lg border bg-card p-3 transition hover:border-primary/30 hover:shadow-sm @3xl:py-2 @3xl:pr-11"
                            :class="onLineError(i, 'account_id') ? 'border-destructive/50' : 'border-border'"
                        >
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                class="absolute right-1.5 top-1.5 z-10 size-7 text-muted-foreground hover:text-destructive"
                                :disabled="!canRemoveLine"
                                :aria-label="`Remove line ${i + 1}`"
                                :title="canRemoveLine ? 'Remove this line' : `A journal needs at least ${MIN_LINES} lines`"
                                @click="removeLine(i)"
                            >
                                <Trash2 class="size-3.5" />
                            </Button>

                            <div class="grid grid-cols-1 gap-x-3 gap-y-2 @3xl:grid-cols-[1.9rem_minmax(0,1.15fr)_minmax(0,1.1fr)_minmax(0,0.7fr)_minmax(0,0.7fr)] @3xl:items-start">
                                <!-- Gutter: the line number, and which side it posts to. The amount is left
                                     out once the row is inline — it is in the Debit or Credit field right
                                     beside it, and a second copy would only compete for width. -->
                                <div class="flex items-center gap-2 pr-9 @3xl:flex-col @3xl:items-center @3xl:gap-1 @3xl:pr-0 @3xl:pt-1.5">
                                    <span class="font-mono text-xs font-bold uppercase tracking-widest text-muted-foreground">
                                        <span class="@3xl:hidden">Line </span>{{ i + 1 }}
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
                                        {{ Number(line.debit) > 0 ? 'DR' : 'CR' }}<span class="@3xl:hidden">
                                            {{ (Number(line.debit) || Number(line.credit) || 0).toFixed(2) }}
                                        </span>
                                    </span>
                                </div>


                                <FormField
                                    label="Account"
                                    required
                                    label-class="@3xl:sr-only"
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
                                <FormField label="Memo" label-class="@3xl:sr-only" :error="onLineError(i, 'description')">
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

                                <FormField label="Debit" :error="onLineError(i, 'debit')" label-class="@3xl:sr-only">
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

                                <FormField label="Credit" :error="onLineError(i, 'credit')" label-class="@3xl:sr-only">
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
                        <div class="grid gap-2 sm:grid-cols-3">
                            <div class="rounded-lg bg-sky-500/10 px-3 py-1.5">
                                <p class="text-[11px] font-medium text-muted-foreground">Total debit</p>
                                <p class="font-mono text-sm font-bold text-sky-700 dark:text-sky-400">
                                    {{ totalDebit.toFixed(2) }}
                                </p>
                            </div>
                            <div class="rounded-lg bg-violet-500/10 px-3 py-1.5">
                                <p class="text-[11px] font-medium text-muted-foreground">Total credit</p>
                                <p class="font-mono text-sm font-bold text-violet-700 dark:text-violet-400">
                                    {{ totalCredit.toFixed(2) }}
                                </p>
                            </div>
                            <div
                                class="rounded-lg px-3 py-1.5"
                                :class="balanced ? 'bg-emerald-500/10' : 'bg-amber-500/10'"
                            >
                                <p class="text-[11px] font-medium text-muted-foreground">Difference</p>
                                <p
                                    class="font-mono text-sm font-bold"
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

            <!-- The requested column: today's journal entries, always beside the form, dense and
                 scannable — number, type, status and amount on one row. -->
            <aside
                class="rounded-xl border bg-card shadow-sm xl:col-start-2"
                aria-label="Today's journal entries"
            >
                <div class="flex items-center gap-2 border-b bg-muted/25 px-4 py-3">
                    <Clock class="size-4 text-primary" />
                    <h2 class="text-sm font-semibold tracking-tight">Today's journal entries</h2>
                    <span class="ml-auto rounded-full border bg-background px-2 py-0.5 text-[11px] font-medium tabular-nums text-muted-foreground">
                        {{ todaysJournals?.length ?? 0 }}
                    </span>
                </div>
                <p v-if="!todaysJournals?.length" class="px-4 py-8 text-center text-sm text-muted-foreground">
                    No journal entries today.
                </p>
                <ul v-else class="max-h-[26rem] divide-y overflow-y-auto">
                    <li v-for="entry in todaysJournals" :key="entry.id" class="px-4 py-3 transition-colors hover:bg-muted/30">
                        <div class="flex items-center justify-between gap-2">
                            <Link :href="route('accounts.journals.show', entry.id)" class="truncate font-mono text-[13px] font-semibold text-primary hover:underline">{{ entry.journal_no }}</Link>
                            <span class="shrink-0 font-mono text-[13px] font-semibold tabular-nums">{{ Number(entry.total_debit).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }}</span>
                        </div>
                        <p v-if="entry.narration" class="mt-1 truncate text-xs text-muted-foreground" :title="entry.narration">{{ entry.narration }}</p>
                        <div class="mt-2 flex items-center gap-2">
                            <span class="rounded-md bg-muted px-1.5 py-0.5 font-mono text-[10px] font-semibold text-muted-foreground">{{ entry.type }}</span>
                            <StatusBadge :label="entry.status" :tone="journalStatusTone(entry.status)" />
                        </div>
                    </li>
                </ul>
                <div class="border-t px-4 py-2">
                    <Link
                        :href="route('accounts.journals.index')"
                        class="inline-flex items-center gap-1 text-[11px] font-semibold text-primary hover:underline"
                    >
                        View all journals <ArrowRight class="size-3" />
                    </Link>
                </div>
            </aside>
            </div>
        </div>
    </AdminLayout>
</template>
