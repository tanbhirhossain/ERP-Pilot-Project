<script setup lang="ts">
import PageHeader from '@core/Components/PageHeader.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { FileText } from 'lucide-vue-next';

interface Slip {
    id: number;
    salary_sheet?: { pay_year: number; pay_month: number; status: string } | null;
    basic_salary: string;
    gross_salary: string;
    total_deductions: string;
    net_salary: string;
    payment_status: string;
    paid_at: string | null;
}
interface Paginator<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
}
interface Props {
    slips: Paginator<Slip>;
}
const props = defineProps<Props>();

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const money = (v: string): string => Number(v).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const period = (s: Slip): string => (s.salary_sheet ? `${MONTHS[s.salary_sheet.pay_month - 1]} ${s.salary_sheet.pay_year}` : `Slip #${s.id}`);
const STATUS_TONE: Record<string, string> = {
    paid: 'bg-emerald-500/10 text-emerald-600',
    hold: 'bg-amber-500/10 text-amber-600',
    pending: 'bg-sky-500/10 text-sky-600',
};
</script>

<template>
    <Head title="My Payslips" />
    <AdminLayout>
        <PageHeader title="My Payslips" description="Your salary slips, newest first. Open one to see the full breakdown." :icon="FileText" :actions="[{ label: 'My dashboard', route: 'hrm.my.dashboard', variant: 'outline' }]" />

        <div class="mt-6 overflow-hidden rounded-xl border bg-card shadow-sm">
            <div v-if="props.slips.data.length === 0" class="px-6 py-14 text-center">
                <FileText class="mx-auto size-8 text-muted-foreground/40" />
                <p class="mt-2 text-sm font-medium">No payslips yet</p>
                <p class="text-[13px] text-muted-foreground">They'll appear here once payroll is processed for you.</p>
            </div>
            <table v-else class="w-full text-sm">
                <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Period</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Basic</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Gross</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Deductions</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Net pay</th>
                        <th class="px-4 py-2.5 font-semibold">Status</th>
                        <th class="px-4 py-2.5 text-right font-semibold"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="s in props.slips.data" :key="s.id" class="hover:bg-accent/50">
                        <td class="px-4 py-3 font-medium">{{ period(s) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-muted-foreground">{{ money(s.basic_salary) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ money(s.gross_salary) }}</td>
                        <td class="px-4 py-3 text-right tabular-nums text-rose-600">{{ money(s.total_deductions) }}</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(s.net_salary) }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-[11px] font-bold capitalize" :class="STATUS_TONE[s.payment_status] ?? 'bg-muted text-muted-foreground'">{{ s.payment_status }}</span></td>
                        <td class="px-4 py-3 text-right"><Link :href="route('hrm.my.payslip', { id: s.id })" class="text-[12px] font-medium text-primary hover:underline">View →</Link></td>
                    </tr>
                </tbody>
            </table>
            <div v-if="props.slips.last_page > 1" class="flex items-center justify-between border-t px-4 py-3">
                <p class="text-[12px] text-muted-foreground">Page {{ props.slips.current_page }} of {{ props.slips.last_page }} · {{ props.slips.total }} slips</p>
                <div class="flex gap-1">
                    <template v-for="(link, i) in props.slips.links" :key="i">
                        <Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-md border px-2.5 py-1 text-[12px] transition hover:bg-accent" :class="link.active ? 'border-primary bg-primary text-primary-foreground' : ''"><span v-html="link.label" /></Link>
                        <span v-else class="rounded-md border px-2.5 py-1 text-[12px] opacity-40" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
