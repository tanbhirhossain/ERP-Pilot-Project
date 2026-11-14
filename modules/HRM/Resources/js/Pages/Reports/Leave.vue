<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plane, Download } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import StaticTable from '@core/Components/StaticTable.vue';

const props = defineProps<{ data: any }>();
const d = props.data;

const maxType = Math.max(1, ...(d.byType ?? []).map((r: any) => Number(r.approved || 0) + Number(r.pending || 0)));
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'HR Reports', href: route('hrm.reports.index') }, { title: 'Leave Liability' }]">
        <Head :title="`Leave Liability ${d.year}`" />

        <PageHeader :title="`Leave Liability — ${d.year}`" description="Approved and pending leave days, carry-forward and lapse positions." :icon="Plane">
            <template #actions>
                <a :href="route('hrm.reports.export', { type: 'leave' })" class="inline-flex items-center gap-2 rounded-xl border bg-card px-3 py-2 text-sm font-medium hover:bg-accent">
                <Download class="size-4" /> Export CSV
            </a>
            </template>
        </PageHeader>

        <div class="mt-4 grid gap-3 sm:grid-cols-4">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Approved days</p>
                <p class="mt-1 text-2xl font-bold">{{ Number(d.approved ?? 0).toLocaleString() }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Pending days</p>
                <p class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">{{ Number(d.pending ?? 0).toLocaleString() }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Carried forward</p>
                <p class="mt-1 text-2xl font-bold">{{ Number(d.carry ?? 0).toLocaleString() }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Lapsed</p>
                <p class="mt-1 text-2xl font-bold text-destructive">{{ Number(d.lapsed ?? 0).toLocaleString() }}</p>
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border bg-card p-4">
                <h2 class="text-sm font-bold">By leave type</h2>
                <div class="mt-3 space-y-3">
                    <div v-for="r in d.byType" :key="r.type">
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="truncate" :title="r.type">{{ r.type }}</span>
                            <span class="shrink-0 font-semibold">{{ Number(r.approved).toFixed(1) }} approved · {{ Number(r.pending).toFixed(1) }} pending</span>
                        </div>
                        <div class="mt-1 flex h-2 overflow-hidden rounded-full bg-muted">
                            <div class="h-2 bg-primary" :style="{ width: ((Number(r.approved) / maxType) * 100) + '%' }" />
                            <div class="h-2 bg-amber-500" :style="{ width: ((Number(r.pending) / maxType) * 100) + '%' }" />
                        </div>
                    </div>
                    <p v-if="!d.byType?.length" class="text-sm text-muted-foreground">No leave requests in {{ d.year }}.</p>
                </div>
            </section>

            <section class="rounded-xl border bg-card p-4">
                <h2 class="text-sm font-bold">Top leave takers</h2>
                <StaticTable
                    class="mt-3"
                    :empty="!d?.topTakers?.length"
                    :empty-title="`No approved leave in ${d?.year ?? 'this year'}`"
                    empty-description="The ten employees with the most approved leave days appear here."
                >
                    <thead><tr class="border-b text-left text-xs text-muted-foreground"><th class="py-1.5">Employee</th><th class="py-1.5 text-right">Approved days</th></tr></thead>
                    <tbody>
                        <tr v-for="r in d.topTakers" :key="r.employee_id" class="border-b last:border-0">
                            <td class="py-1.5 font-mono text-xs">
                                <Link :href="route('hrm.employees.show', r.employee_id)" class="underline underline-offset-2">{{ r.employee_id }}</Link>
                            </td>
                            <td class="py-1.5 text-right font-semibold">{{ Number(r.total_days).toFixed(1) }}</td>
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
