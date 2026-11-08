<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Users, Building2, BadgeCent, Activity, Download } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';

const props = defineProps<{ data: any }>();
const d = props.data;

function barW(val: number, max: number): number {
    return max ? Math.round((val / max) * 100) : 0;
}
function pct(part: number, total: number): number {
    return total ? Math.round((part / total) * 100) : 0;
}

const maxDept = Math.max(1, ...(d.byDept ?? []).map((r: any) => Number(r.cnt)));
const maxDesig = Math.max(1, ...(d.byDesig ?? []).map((r: any) => Number(r.cnt)));
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'HR Reports', href: route('hrm.reports.index') }, { title: 'Headcount' }]">
        <Head title="Headcount Report" />

        <PageHeader title="Headcount" description="Establishment split by department, designation, status and employment type." :icon="Users">
            <template #actions>
                <a :href="route('hrm.reports.export', { type: 'headcount' })" class="inline-flex items-center gap-2 rounded-xl border bg-card px-3 py-2 text-sm font-medium hover:bg-accent">
                <Download class="size-4" /> Export CSV
            </a>
            </template>
        </PageHeader>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Total employees</p>
                <p class="mt-1 text-2xl font-bold">{{ d.total ?? 0 }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Active</p>
                <p class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">{{ d.active ?? 0 }}</p>
                <p class="text-xs text-muted-foreground">{{ pct(d.active ?? 0, d.total ?? 0) }}% of establishment</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Inactive</p>
                <p class="mt-1 text-2xl font-bold">{{ (d.total ?? 0) - (d.active ?? 0) }}</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border bg-card p-4">
                <h2 class="flex items-center gap-2 text-sm font-bold"><Building2 class="size-4 text-primary" /> By department</h2>
                <div class="mt-3 space-y-2">
                    <div v-for="r in d.byDept" :key="r.dept" class="flex items-center gap-3">
                        <span class="w-40 shrink-0 truncate text-sm" :title="r.dept">{{ r.dept }}</span>
                        <div class="h-2 flex-1 rounded-full bg-muted">
                            <div class="h-2 rounded-full bg-primary" :style="{ width: barW(Number(r.cnt), maxDept) + '%' }" />
                        </div>
                        <span class="w-20 shrink-0 text-right text-sm font-semibold">{{ r.cnt }} <span class="text-xs font-normal text-muted-foreground">({{ r.active ?? 0 }} active)</span></span>
                    </div>
                    <p v-if="!d.byDept?.length" class="text-sm text-muted-foreground">No department data.</p>
                </div>
            </section>

            <section class="rounded-xl border bg-card p-4">
                <h2 class="flex items-center gap-2 text-sm font-bold"><BadgeCent class="size-4 text-primary" /> By designation</h2>
                <div class="mt-3 space-y-2">
                    <div v-for="r in d.byDesig" :key="r.desig" class="flex items-center gap-3">
                        <span class="w-40 shrink-0 truncate text-sm" :title="r.desig">{{ r.desig }}</span>
                        <div class="h-2 flex-1 rounded-full bg-muted">
                            <div class="h-2 rounded-full bg-primary/70" :style="{ width: barW(Number(r.cnt), maxDesig) + '%' }" />
                        </div>
                        <span class="w-10 shrink-0 text-right text-sm font-semibold">{{ r.cnt }}</span>
                    </div>
                    <p v-if="!d.byDesig?.length" class="text-sm text-muted-foreground">No designation data.</p>
                </div>
            </section>

            <section class="rounded-xl border bg-card p-4">
                <h2 class="flex items-center gap-2 text-sm font-bold"><Activity class="size-4 text-primary" /> By status</h2>
                <StaticTable
                    class="mt-3"
                    :empty="!d?.byStatus?.length"
                    empty-title="No headcount by status yet"
                    empty-description="Employees are counted here by their current status."
                >
                    <thead><tr class="border-b text-left text-xs text-muted-foreground"><th class="py-1.5">Status</th><th class="py-1.5 text-right">Count</th><th class="py-1.5 text-right">Share</th></tr></thead>
                    <tbody>
                        <tr v-for="r in d.byStatus" :key="r.status" class="border-b last:border-0">
                            <td class="py-1.5">{{ r.status ?? '—' }}</td>
                            <td class="py-1.5 text-right font-semibold">{{ r.cnt }}</td>
                            <td class="py-1.5 text-right text-muted-foreground">{{ pct(Number(r.cnt), d.total ?? 0) }}%</td>
                        </tr>
                    </tbody>
                </StaticTable>
            </section>

            <section class="rounded-xl border bg-card p-4">
                <h2 class="flex items-center gap-2 text-sm font-bold"><Users class="size-4 text-primary" /> By employment type</h2>
                <StaticTable
                    class="mt-3"
                    :empty="!d?.byType?.length"
                    empty-title="No headcount by employment type yet"
                    empty-description="Employees are counted here by their employment type."
                >
                    <thead><tr class="border-b text-left text-xs text-muted-foreground"><th class="py-1.5">Type</th><th class="py-1.5 text-right">Count</th><th class="py-1.5 text-right">Share</th></tr></thead>
                    <tbody>
                        <tr v-for="r in d.byType" :key="r.employment_type" class="border-b last:border-0">
                            <td class="py-1.5">{{ r.employment_type ?? '—' }}</td>
                            <td class="py-1.5 text-right font-semibold">{{ r.cnt }}</td>
                            <td class="py-1.5 text-right text-muted-foreground">{{ pct(Number(r.cnt), d.total ?? 0) }}%</td>
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
