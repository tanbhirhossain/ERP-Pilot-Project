<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Building2, Check, Monitor, Moon, Palette, Printer, RotateCcw, Save, Sun, User } from 'lucide-vue-next';
import { ref, watch } from 'vue';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';

import AdminLayout from '@core/Layouts/AdminLayout.vue';
import EnterpriseCard from '@core/Components/EnterpriseCard.vue';
import EnterpriseStat from '@core/Components/EnterpriseStat.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SchemePicker from '@core/Components/SchemePicker.vue';
import StatusBadge from '@core/Components/StatusBadge.vue';
import { useUiTheme, type UiMode, type UiProps } from '@core/Composables/useUiTheme';
import StaticTable from '../../Components/StaticTable.vue';

/**
 * UI Settings — colour scheme and mode (item 42).
 *
 * The page is a controller, not a stylesheet: every colour it paints comes from a token, so the
 * preview below is a genuine preview. Switching scheme repaints this page, the shell and all 400
 * others through the same two attributes on `<html>`.
 *
 * Two sections write to two different places. The user's own choice applies the moment it is
 * clicked — waiting for a save button on a preference makes the interface feel broken — and the
 * company default is a deliberate, explicit save, because it changes what everybody else sees.
 */
const props = defineProps<{ ui: UiProps }>();

const {
    scheme,
    mode,
    source,
    companyScheme,
    companyMode,
    canManageCompany,
    isOverridden,
    labelFor,
    setScheme,
    setMode,
    resetToCompany,
    saveCompany,
} = useUiTheme();

const modes: Array<{ id: UiMode; label: string; icon: typeof Sun; hint: string }> = [
    { id: 'light', label: 'Light', icon: Sun, hint: 'Always the light token set.' },
    { id: 'dark', label: 'Dark', icon: Moon, hint: 'Always the dark token set.' },
    { id: 'system', label: 'System', icon: Monitor, hint: 'Follows the operating system, live.' },
];

/** The company form keeps a draft so browsing schemes does not change anyone's screen. */
const draftScheme = ref<string>(companyScheme.value);
const draftMode = ref<UiMode>(companyMode.value);

watch(companyScheme, (next) => (draftScheme.value = next));
watch(companyMode, (next) => (draftMode.value = next));

const companyDirty = ref(false);

function chooseCompanyScheme(id: string): void {
    draftScheme.value = id;
    companyDirty.value = true;
}

function chooseCompanyMode(next: UiMode): void {
    draftMode.value = next;
    companyDirty.value = true;
}

function commitCompany(): void {
    companyDirty.value = false;
    saveCompany(draftScheme.value, draftMode.value);
}

const sourceLabel = {
    user: 'Your own choice',
    company: 'Company default',
    default: 'Built-in default',
} as const;
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Administration' }, { title: 'Payroll Settings', href: route('payroll.settings.index') }, { title: 'UI Settings' }]">
        <Head title="UI Settings" />

        <PageHeader
            title="UI Settings"
            description="Colour scheme and light/dark mode. Every surface in the application paints from tokens, so a change here reaches all of it — including the shell, the charts and the printed sheet header."
            :icon="Palette"
            :stats="[
                { label: 'Active scheme', value: labelFor(scheme) },
                { label: 'Mode', value: mode },
                { label: 'Decided by', value: sourceLabel[source] },
                { label: 'Palettes', value: props.ui.schemes.length },
            ]"
        />

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="space-y-6">
                <EnterpriseCard
                    title="Your interface"
                    description="Stored against your account, so it follows you to any device."
                    :icon="User"
                >
                    <template #actions>
                        <Button v-if="isOverridden" variant="ghost" size="sm" class="rounded-full" @click="resetToCompany">
                            <RotateCcw class="size-4" /> Follow company default
                        </Button>
                        <StatusBadge v-else :label="`Following ${labelFor(companyScheme)}`" tone="info" />
                    </template>

                    <SchemePicker :model-value="scheme" :schemes="props.ui.schemes" @select="setScheme" />

                    <Separator class="my-5" />

                    <div>
                        <p class="text-sm font-semibold text-foreground">Mode</p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Dark mode is a token set, not an inverted stylesheet — the same palette, re-tuned for a dark surface.
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button
                                v-for="option in modes"
                                :key="option.id"
                                type="button"
                                class="flex items-center gap-2 rounded-full border px-3.5 py-2 text-[13px] font-medium transition"
                                :class="
                                    mode === option.id
                                        ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                                        : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground'
                                "
                                :title="option.hint"
                                @click="setMode(option.id)"
                            >
                                <component :is="option.icon" class="size-4" />
                                {{ option.label }}
                                <Check v-if="mode === option.id" class="size-3.5" />
                            </button>
                        </div>
                    </div>
                </EnterpriseCard>

                <EnterpriseCard
                    v-if="canManageCompany"
                    title="Company default"
                    description="Applies to everyone who has not chosen for themselves, and to a fresh account's first login."
                    :icon="Building2"
                >
                    <template #actions>
                        <StatusBadge v-if="companyDirty" label="Unsaved" tone="warning" dot />
                        <StatusBadge v-else :label="labelFor(companyScheme)" tone="success" dot />
                    </template>

                    <SchemePicker :model-value="draftScheme" :schemes="props.ui.schemes" compact @select="chooseCompanyScheme" />

                    <div class="mt-4 flex flex-wrap items-center gap-2">
                        <button
                            v-for="option in modes"
                            :key="option.id"
                            type="button"
                            class="rounded-full border px-3 py-1.5 text-xs font-medium transition"
                            :class="
                                draftMode === option.id
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border bg-card text-muted-foreground hover:text-foreground'
                            "
                            @click="chooseCompanyMode(option.id)"
                        >
                            {{ option.label }}
                        </button>
                    </div>

                    <div class="mt-5 flex flex-wrap items-center gap-3">
                        <Button :disabled="!companyDirty" class="rounded-full" @click="commitCompany">
                            <Save class="size-4" /> Save company default
                        </Button>
                        <p class="text-xs text-muted-foreground">
                            Users who picked their own scheme keep it — a company default is a starting point, not an override.
                        </p>
                    </div>
                </EnterpriseCard>

                <EnterpriseCard v-else title="Company default" :icon="Building2">
                    <p class="text-sm text-muted-foreground">
                        This installation uses <strong class="text-foreground">{{ labelFor(companyScheme) }}</strong> in
                        {{ companyMode }} mode. An administrator with <code class="rounded bg-muted px-1 py-0.5 font-mono text-xs">settings.appearance.manage</code>
                        can change it; your own choice above always wins for you.
                    </p>
                </EnterpriseCard>

                <EnterpriseCard
                    title="Live preview"
                    description="Not a picture of the theme — the real components, painted by the tokens you just chose."
                    :icon="Palette"
                >
                    <div class="space-y-5">
                        <div class="overflow-hidden rounded-xl bg-gradient-to-br from-hero-from via-hero-via to-hero-to p-5 text-hero-ink">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] opacity-70">Hero gradient</p>
                            <p class="mt-1 text-xl font-bold tracking-tight">{{ labelFor(scheme) }}</p>
                            <p class="mt-1 text-sm opacity-75">
                                Page headers, KPI bands and the sign-in shell use this ramp. It is three tokens, so every palette
                                supplies its own.
                            </p>
                        </div>

                        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <EnterpriseStat label="Primary" value="Button" :icon="Check" />
                            <EnterpriseStat label="Success" value="Posted" tone="success" :icon="Check" />
                            <EnterpriseStat label="Warning" value="Pending" tone="warning" :icon="Sun" />
                            <EnterpriseStat label="Danger" value="Overdue" tone="danger" :icon="Moon" />
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <Button>Primary action</Button>
                            <Button variant="secondary">Secondary</Button>
                            <Button variant="outline">Outline</Button>
                            <Button variant="ghost">Ghost</Button>
                            <Button variant="destructive">Destructive</Button>
                            <Button disabled>Disabled</Button>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <StatusBadge label="Neutral" tone="neutral" dot />
                            <StatusBadge label="Info" tone="info" dot />
                            <StatusBadge label="Success" tone="success" dot />
                            <StatusBadge label="Warning" tone="warning" dot />
                            <StatusBadge label="Danger" tone="danger" dot />
                            <StatusBadge label="Primary" tone="primary" dot />
                        </div>

                        <div>
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">Chart series</p>
                            <div class="flex gap-1.5">
                                <span class="h-8 flex-1 rounded-md bg-chart-1" title="chart-1" />
                                <span class="h-8 flex-1 rounded-md bg-chart-2" title="chart-2" />
                                <span class="h-8 flex-1 rounded-md bg-chart-3" title="chart-3" />
                                <span class="h-8 flex-1 rounded-md bg-chart-4" title="chart-4" />
                                <span class="h-8 flex-1 rounded-md bg-chart-5" title="chart-5" />
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-1.5">
                                <Label for="ui-preview-input">Text input</Label>
                                <Input id="ui-preview-input" placeholder="Focus me to see the ring token" />
                            </div>
                            <div class="space-y-1.5">
                                <Label>Selection</Label>
                                <div class="rounded-xl border border-border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                                    Muted surface · <span class="text-brand-tint-ink">brand wash ink</span>
                                </div>
                            </div>
                        </div>

                        <StaticTable>
                                <thead class="bg-muted/50 text-xs uppercase tracking-wider text-muted-foreground">
                                    <tr>
                                        <th class="px-3 py-2 text-left font-semibold">Document</th>
                                        <th class="px-3 py-2 text-right font-semibold">Amount</th>
                                        <th class="px-3 py-2 text-left font-semibold">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr class="bg-card">
                                        <td class="px-3 py-2 font-medium">INV-00042</td>
                                        <td class="px-3 py-2 text-right font-mono">18,400.00</td>
                                        <td class="px-3 py-2"><StatusBadge label="Paid" tone="success" dot /></td>
                                    </tr>
                                    <tr class="bg-card">
                                        <td class="px-3 py-2 font-medium">INV-00043</td>
                                        <td class="px-3 py-2 text-right font-mono">7,250.50</td>
                                        <td class="px-3 py-2"><StatusBadge label="Partial" tone="warning" dot /></td>
                                    </tr>
                                </tbody>
                            </StaticTable>
                    </div>
                </EnterpriseCard>
            </div>

            <aside class="space-y-6">
                <EnterpriseCard title="How colour works here" :icon="Palette">
                    <ul class="space-y-3 text-[13px] leading-relaxed text-muted-foreground">
                        <li>
                            A palette is a set of CSS custom properties under
                            <code class="rounded bg-muted px-1 py-0.5 font-mono text-[11px]">data-theme</code>. Components never
                            name a colour; they name a role — <code class="rounded bg-muted px-1 py-0.5 font-mono text-[11px]">primary</code>,
                            <code class="rounded bg-muted px-1 py-0.5 font-mono text-[11px]">muted</code>,
                            <code class="rounded bg-muted px-1 py-0.5 font-mono text-[11px]">hero-from</code>.
                        </li>
                        <li>
                            Status colours — success, warning, danger, info — are the same in every palette. Green means the same
                            thing in Emerald as it does in Rose, or a status badge would change meaning with the decor.
                        </li>
                        <li>
                            Your choice is stored twice: in this browser, so the next paint is instant, and on your account, so it
                            follows you to another machine.
                        </li>
                    </ul>
                </EnterpriseCard>

                <EnterpriseCard title="Printing" :icon="Printer">
                    <p class="text-[13px] leading-relaxed text-muted-foreground">
                        Printed output ignores the palette on purpose. Paper is white in both modes and in every scheme, the
                        tokens are reset to the light set, and the sheet carries a letterhead with the document title, the time and
                        who printed it — so a colour choice can never make a payslip or a ledger unreadable, or expensive to
                        produce.
                    </p>
                    <p class="mt-3 text-[13px] leading-relaxed text-muted-foreground">
                        Colour that carries meaning still prints exactly: barcode bars and status dots opt into
                        <code class="rounded bg-muted px-1 py-0.5 font-mono text-[11px]">print-color-adjust: exact</code>.
                    </p>
                </EnterpriseCard>
            </aside>
        </div>
    </AdminLayout>
</template>
