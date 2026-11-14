<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { TrendingUp, ArrowLeftRight, Download } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StaticTable from '@core/Components/StaticTable.vue';

const props = defineProps<{ data: any }>();
const d = props.data;

const totalResignations = (d.resignations ?? []).reduce((s: number, r: any) => s + Number(r.cnt || 0), 0);
const totalMovements = (d.movements ?? []).reduce((s: number, r: any) => s + Number(r.cnt || 0), 0);
const maxRate = Math.max(0.01, ...(d.monthlyRate ?? []).map((r: any) => Number(r.rate || 0)));
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'HR Reports', href: route('hrm.reports.index') }, { title: 'Attrition' }]">
        <Head title="Attrition Report" />

        <PageHeader title="Attrition" :description="`Resignations and internal movements over the last ${d.periodMonths ?? 12} months.`" :icon="TrendingUp">
            <template #actions>
                <a :href="route('hrm.reports.export', { type: 'attrition' })" class="inline-flex items-center gap-2 rounded-xl border bg-card px-3 py-2 text-sm font-medium hover:bg-accent">
                <Download class="size-4" /> Export CSV
            </a>
            </template>
        </PageHeader>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Resignations</p>
                <p class="mt-1 text-2xl font-bold">{{ totalResignations }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Internal movements</p>
                <p class="mt-1 text-2xl font-bold">{{ totalMovements }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Peak monthly attrition</p>
                <p class="mt-1 text-2xl font-bold">{{ maxRate.toFixed(2) }}%</p>
            </div>
        </div>

        <section class="mt-4 rounded-xl border bg-card p-4">
            <h2 class="text-sm font-bold">Monthly attrition rate</h2>
            <div class="mt-3 space-y-2">
                <div v-for="r in d.monthlyRate" :key="r.ym" class="flex items-center gap-3">
                    <span class="w-24 shrink-0 font-mono text-xs text-muted-foreground">{{ r.ym }}</span>
                    <div class="h-2 flex-1 rounded-full bg-muted">
                        <div class="h-2 rounded-full bg-destructive/70" :style="{ width: Math.round((Number(r.rate) / maxRate) * 100) + '%' }" />
                    </div>
                    <span class="w-16 shrink-0 text-right text-sm font-semibold">{{ Number(r.rate).toFixed(2) }}%</span>
                </div>
                <p v-if="!d.monthlyRate?.length" class="text-sm text-muted-foreground">No resignations recorded in this period.</p>
            </div>
        </section>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border bg-card p-4">
                <h2 class="text-sm font-bold">Resignations by month</h2>
                <StaticTable
                    class="mt-3"
                    :empty="!d?.resignations?.length"
                    empty-title="No resignations in this period"
                    empty-description="Resignations appear here by the month of the last working day."
                >
                    <thead><tr class="border-b text-left text-xs text-muted-foreground"><th class="py-1.5">Month</th><th class="py-1.5 text-right">Filed</th><th class="py-1.5 text-right">Approved</th></tr></thead>
                    <tbody>
                        <tr v-for="r in d.resignations" :key="r.ym" class="border-b last:border-0">
                            <td class="py-1.5 font-mono text-xs">{{ r.ym }}</td>
                            <td class="py-1.5 text-right font-semibold">{{ r.cnt }}</td>
                            <td class="py-1.5 text-right text-muted-foreground">{{ r.approved }}</td>
                        </tr>
                    </tbody>
                </StaticTable>
            </section>

            <section class="rounded-xl border bg-card p-4">
                <h2 class="flex items-center gap-2 text-sm font-bold"><ArrowLeftRight class="size-4 text-primary" /> Movements by month</h2>
                <StaticTable
                    class="mt-3"
                    :empty="!d?.movements?.length"
                    empty-title="No movements recorded in this period"
                    empty-description="Transfers, promotions and other movements appear here by month."
                >
                    <thead><tr class="border-b text-left text-xs text-muted-foreground"><th class="py-1.5">Month</th><th class="py-1.5 text-right">Movements</th></tr></thead>
                    <tbody>
                        <tr v-for="r in d.movements" :key="r.ym" class="border-b last:border-0">
                            <td class="py-1.5 font-mono text-xs">{{ r.ym }}</td>
                            <td class="py-1.5 text-right font-semibold">{{ r.cnt }}</td>
                        </tr>
                    </tbody>
                </StaticTable>
            </section>
        </div>

        <p class="mt-4 text-xs text-muted-foreground">
            <Link :href="route('hrm.reports.index')" class="underline underline-offset-2">Back to HR Reports overview</Link>
        </p>
    </AdminLayout>
</template>
