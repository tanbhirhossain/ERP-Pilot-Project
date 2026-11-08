<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarCheck, Download } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import StaticTable from '../../../../../CORE/Resources/js/Components/StaticTable.vue';

const props = defineProps<{ data: any }>();
const d = props.data;

const totals = (d.trend ?? []).reduce(
    (acc: any, r: any) => ({
        total: acc.total + Number(r.total || 0),
        present: acc.present + Number(r.present || 0),
        late: acc.late + Number(r.late || 0),
        absent: acc.absent + Number(r.absent || 0),
        on_leave: acc.on_leave + Number(r.on_leave || 0),
        missing: acc.missing + Number(r.missing || 0),
    }),
    { total: 0, present: 0, late: 0, absent: 0, on_leave: 0, missing: 0 },
);
function pct(part: number): string {
    return totals.total ? ((part / totals.total) * 100).toFixed(1) + '%' : '0%';
}
const maxLate = Math.max(1, ...(d.lateByDept ?? []).map((r: any) => Number(r.late_cnt || 0)));
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'HR Reports', href: route('hrm.reports.index') }, { title: 'Attendance Trend' }]">
        <Head title="Attendance Trend" />

        <PageHeader title="Attendance Trend" :description="`Reconciled daily attendance over the last ${d.months ?? 6} months.`" :icon="CalendarCheck">
            <template #actions>
                <a :href="route('hrm.reports.export', { type: 'attendance' })" class="inline-flex items-center gap-2 rounded-xl border bg-card px-3 py-2 text-sm font-medium hover:bg-accent">
                <Download class="size-4" /> Export CSV
            </a>
            </template>
        </PageHeader>

        <div class="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div class="rounded-xl border bg-card p-3"><p class="text-xs text-muted-foreground">Records</p><p class="mt-1 text-xl font-bold">{{ totals.total.toLocaleString() }}</p></div>
            <div class="rounded-xl border bg-card p-3"><p class="text-xs text-muted-foreground">Present</p><p class="mt-1 text-xl font-bold text-emerald-600 dark:text-emerald-400">{{ totals.present.toLocaleString() }}</p><p class="text-xs text-muted-foreground">{{ pct(totals.present) }}</p></div>
            <div class="rounded-xl border bg-card p-3"><p class="text-xs text-muted-foreground">Late</p><p class="mt-1 text-xl font-bold text-amber-600 dark:text-amber-400">{{ totals.late.toLocaleString() }}</p><p class="text-xs text-muted-foreground">{{ pct(totals.late) }}</p></div>
            <div class="rounded-xl border bg-card p-3"><p class="text-xs text-muted-foreground">Absent</p><p class="mt-1 text-xl font-bold text-destructive">{{ totals.absent.toLocaleString() }}</p><p class="text-xs text-muted-foreground">{{ pct(totals.absent) }}</p></div>
            <div class="rounded-xl border bg-card p-3"><p class="text-xs text-muted-foreground">On leave</p><p class="mt-1 text-xl font-bold">{{ totals.on_leave.toLocaleString() }}</p><p class="text-xs text-muted-foreground">{{ pct(totals.on_leave) }}</p></div>
            <div class="rounded-xl border bg-card p-3"><p class="text-xs text-muted-foreground">Missing punch</p><p class="mt-1 text-xl font-bold">{{ totals.missing.toLocaleString() }}</p><p class="text-xs text-muted-foreground">{{ pct(totals.missing) }}</p></div>
        </div>

        <section class="mt-4 overflow-x-auto rounded-xl border bg-card">
            <StaticTable
                flush
                :empty="!d?.trend?.length"
                empty-title="No reconciled attendance in this period"
                empty-description="Daily records appear here once attendance is processed and reconciled."
            >
                <thead>
                    <tr class="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                        <th class="px-3 py-2">Month</th>
                        <th class="px-3 py-2 text-right">Total</th>
                        <th class="px-3 py-2 text-right">Present</th>
                        <th class="px-3 py-2 text-right">Late</th>
                        <th class="px-3 py-2 text-right">Early out</th>
                        <th class="px-3 py-2 text-right">Absent</th>
                        <th class="px-3 py-2 text-right">On leave</th>
                        <th class="px-3 py-2 text-right">Missing</th>
                        <th class="px-3 py-2 text-right">Regularized</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in d.trend" :key="r.ym" class="border-b last:border-0 hover:bg-muted/30">
                        <td class="px-3 py-2 font-mono text-xs">{{ r.ym }}</td>
                        <td class="px-3 py-2 text-right font-semibold">{{ Number(r.total).toLocaleString() }}</td>
                        <td class="px-3 py-2 text-right text-emerald-600 dark:text-emerald-400">{{ Number(r.present).toLocaleString() }}</td>
                        <td class="px-3 py-2 text-right text-amber-600 dark:text-amber-400">{{ Number(r.late).toLocaleString() }}</td>
                        <td class="px-3 py-2 text-right">{{ Number(r.early_out).toLocaleString() }}</td>
                        <td class="px-3 py-2 text-right text-destructive">{{ Number(r.absent).toLocaleString() }}</td>
                        <td class="px-3 py-2 text-right">{{ Number(r.on_leave).toLocaleString() }}</td>
                        <td class="px-3 py-2 text-right">{{ Number(r.missing).toLocaleString() }}</td>
                        <td class="px-3 py-2 text-right text-muted-foreground">{{ Number(r.regularized).toLocaleString() }}</td>
                    </tr>
                </tbody>
            </StaticTable>
        </section>

        <section class="mt-4 rounded-xl border bg-card p-4">
            <h2 class="text-sm font-bold">Lateness by department</h2>
            <div class="mt-3 space-y-2">
                <div v-for="r in d.lateByDept" :key="r.dept" class="flex items-center gap-3">
                    <span class="w-44 shrink-0 truncate text-sm" :title="r.dept">{{ r.dept }}</span>
                    <div class="h-2 flex-1 rounded-full bg-muted">
                        <div class="h-2 rounded-full bg-amber-500" :style="{ width: Math.round((Number(r.late_cnt) / maxLate) * 100) + '%' }" />
                    </div>
                    <span class="w-14 shrink-0 text-right text-sm font-semibold">{{ r.late_cnt }}</span>
                </div>
                <p v-if="!d.lateByDept?.length" class="text-sm text-muted-foreground">No late arrivals recorded.</p>
            </div>
        </section>

        <p class="mt-4 text-xs text-muted-foreground">
            <Link :href="route('hrm.reports.index')" class="underline underline-offset-2">Back to HR Reports overview</Link>
        </p>
    </AdminLayout>
</template>
