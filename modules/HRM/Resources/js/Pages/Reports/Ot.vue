<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Timer, Download } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';

const props = defineProps<{ data: any }>();
const d = props.data;

const approved = (d.ot ?? []).find((r: any) => r.status === 'Approved');
const approvedHours = Number(approved?.hrs ?? 0);
const approvedCost = Number(approved?.cost ?? 0);
// The backend reports whether a cost figure is actually computable. There is no
// OT rate or per-employee wage in the schema, so showing ৳0 would read as "no
// overtime was owed" rather than "unknown" — render a dash instead.
const costAvailable = d.costAvailable === true;
const maxDept = Math.max(0.01, ...(d.byDept ?? []).map((r: any) => Number(r.hrs || 0)));
const maxTop = Math.max(0.01, ...(d.topOt ?? []).map((r: any) => Number(r.hrs || 0)));

function money(v: number): string {
    return new Intl.NumberFormat('en-BD', { maximumFractionDigits: 0 }).format(Math.round(v));
}
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'HR Reports', href: route('hrm.reports.index') }, { title: 'Overtime Cost' }]">
        <Head title="Overtime Cost" />

        <PageHeader title="Overtime Cost" :description="`${d.from} → ${d.to}`" :icon="Timer">
            <template #actions>
                <a :href="route('hrm.reports.export', { type: 'ot' })" class="inline-flex items-center gap-2 rounded-xl border bg-card px-3 py-2 text-sm font-medium hover:bg-accent">
                <Download class="size-4" /> Export CSV
            </a>
            </template>
        </PageHeader>

        <div class="mt-3 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3 text-xs text-amber-900 dark:text-amber-200">
            Cost is an estimate: approved hours valued at gross monthly salary ÷ 208 hours (26 days × 8). No hourly-rate
            column exists in the schema, so the basis is fixed in <code>HrmReportsService::OT_MONTHLY_HOURS</code>.
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-3">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Approved hours</p>
                <p class="mt-1 text-2xl font-bold">{{ approvedHours.toFixed(1) }}h</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Estimated OT cost</p>
                <p v-if="costAvailable" class="mt-1 text-2xl font-bold">৳ {{ money(approvedCost) }}</p>
                <template v-else>
                    <p class="mt-1 text-2xl font-bold text-muted-foreground" aria-label="Not available">—</p>
                    <p class="mt-0.5 text-[11px] text-muted-foreground">
                        No OT rate configured
                    </p>
                </template>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Requests by status</p>
                <div class="mt-1 flex flex-wrap gap-1.5">
                    <span v-for="r in d.ot" :key="r.status" class="rounded-full border px-2 py-0.5 text-xs">
                        {{ r.status }}: {{ r.cnt }}
                    </span>
                    <span v-if="!d.ot?.length" class="text-sm text-muted-foreground">None</span>
                </div>
            </div>
        </div>

        <div class="mt-4 grid gap-4 lg:grid-cols-2">
            <section class="rounded-xl border bg-card p-4">
                <h2 class="text-sm font-bold">Approved OT by department</h2>
                <div class="mt-3 space-y-2">
                    <div v-for="r in d.byDept" :key="r.dept" class="flex items-center gap-3">
                        <span class="w-44 shrink-0 truncate text-sm" :title="r.dept">{{ r.dept }}</span>
                        <div class="h-2 flex-1 rounded-full bg-muted">
                            <div class="h-2 rounded-full bg-primary" :style="{ width: Math.round((Number(r.hrs) / maxDept) * 100) + '%' }" />
                        </div>
                        <span class="w-20 shrink-0 text-right text-sm font-semibold">{{ Number(r.hrs).toFixed(1) }}h <span class="text-xs font-normal text-muted-foreground">({{ r.cnt }})</span></span>
                    </div>
                    <p v-if="!d.byDept?.length" class="text-sm text-muted-foreground">No approved overtime in this period.</p>
                </div>
            </section>

            <section class="rounded-xl border bg-card p-4">
                <h2 class="text-sm font-bold">Top overtime earners</h2>
                <div class="mt-3 space-y-2">
                    <div v-for="r in d.topOt" :key="r.employee_id" class="flex items-center gap-3">
                        <Link :href="route('hrm.employees.show', r.employee_id)" class="w-32 shrink-0 truncate font-mono text-xs underline underline-offset-2">{{ r.employee_id }}</Link>
                        <div class="h-2 flex-1 rounded-full bg-muted">
                            <div class="h-2 rounded-full bg-primary/70" :style="{ width: Math.round((Number(r.hrs) / maxTop) * 100) + '%' }" />
                        </div>
                        <span class="w-16 shrink-0 text-right text-sm font-semibold">{{ Number(r.hrs).toFixed(1) }}h</span>
                    </div>
                    <p v-if="!d.topOt?.length" class="text-sm text-muted-foreground">No approved overtime in this period.</p>
                </div>
            </section>
        </div>

        <p class="mt-4 text-xs text-muted-foreground">
            <Link :href="route('hrm.reports.index')" class="underline underline-offset-2">Back to HR Reports overview</Link>
        </p>
    </AdminLayout>
</template>
