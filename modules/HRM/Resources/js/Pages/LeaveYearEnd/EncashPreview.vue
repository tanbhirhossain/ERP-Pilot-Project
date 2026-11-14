<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { PiggyBank, ArrowLeft } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import StaticTable from '@core/Components/StaticTable.vue';

const props = defineProps<{ employee_id: string; preview: any[] }>();

const totalDays = (props.preview ?? []).reduce((s: number, r: any) => s + Number(r.encash_days || 0), 0);
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Leave Year-End', href: route('hrm.leave-year-end.index') }, { title: 'Encashment Preview' }]">
        <Head :title="`Encashment Preview — ${employee_id}`" />

        <div>
            <h1 class="flex items-center gap-2 text-xl font-bold"><PiggyBank class="size-5 text-primary" /> Leave Encashment Preview</h1>
            <p class="text-sm text-muted-foreground">
                Employee <Link :href="route('hrm.employees.show', employee_id)" class="font-mono underline underline-offset-2">{{ employee_id }}</Link>
                — what the final settlement would pay out. Nothing is written until the settlement is processed.
            </p>
        </div>

        <div class="mt-4 grid gap-3 sm:grid-cols-2">
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Encashable leave types</p>
                <p class="mt-1 text-2xl font-bold">{{ (preview ?? []).length }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4">
                <p class="text-xs text-muted-foreground">Total encashable days</p>
                <p class="mt-1 text-2xl font-bold">{{ totalDays.toFixed(1) }}</p>
            </div>
        </div>

        <section class="mt-4 overflow-x-auto rounded-xl border bg-card">
            <StaticTable
                flush
                :empty="!preview?.length"
                empty-title="Nothing to encash"
                empty-description="This employee has no encashable balance — either every leave type they hold is non-encashable, or the available balance is already exhausted."
            >
                <thead>
                    <tr class="border-b bg-muted/40 text-left text-xs text-muted-foreground">
                        <th class="px-3 py-2">Leave type</th>
                        <th class="px-3 py-2 text-right">Encash days</th>
                        <th class="px-3 py-2">Salary component</th>
                        <th class="px-3 py-2 text-right">Year</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in preview" :key="r.leave_type_id" class="border-b last:border-0 hover:bg-muted/30">
                        <td class="px-3 py-2 font-medium">{{ r.leave_type }}</td>
                        <td class="px-3 py-2 text-right font-semibold">{{ Number(r.encash_days).toFixed(1) }}</td>
                        <td class="px-3 py-2 text-muted-foreground">{{ r.component }}</td>
                        <td class="px-3 py-2 text-right text-muted-foreground">{{ r.year ?? '—' }}</td>
                    </tr>
                </tbody>
            </StaticTable>
        </section>

        <p class="mt-4 text-xs text-muted-foreground">
            Days are capped per leave type by its encashment policy (<code>encash_max_days</code>). The monetary value is
            calculated at settlement time from the chosen salary component.
        </p>

        <div class="mt-4">
            <Link :href="route('hrm.leave-year-end.index')" class="inline-flex items-center gap-2 rounded-lg border bg-card px-3 py-2 text-sm font-medium hover:bg-accent">
                <ArrowLeft class="size-4" /> Back to Leave Year-End
            </Link>
        </div>
    </AdminLayout>
</template>
