<script setup lang="ts">
import PageHeader from '@core/Components/PageHeader.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays, Clock, FileText, UserRound, Wallet } from 'lucide-vue-next';

interface Slip {
    id: number;
    salary_sheet?: { pay_year: number; pay_month: number; status: string } | null;
    net_salary: string;
    payment_status: string;
    paid_at: string | null;
}
interface Props {
    employee: {
        employee_id: string;
        full_name: string;
        department: string | null;
        designation: string | null;
        joining_date: string | null;
        photo: string | null;
    };
    slips: Slip[];
    leave: { taken: number; pending: number };
    attendance: { present: number; late: number; absent: number; days: number };
}
const props = defineProps<Props>();

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const money = (v: string): string => Number(v).toLocaleString('en-BD', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const period = (s: Slip): string => (s.salary_sheet ? `${MONTHS[s.salary_sheet.pay_month - 1]} ${s.salary_sheet.pay_year}` : `Slip #${s.id}`);

const STATUS_TONE: Record<string, string> = {
    paid: 'bg-emerald-500/10 text-emerald-600',
    hold: 'bg-amber-500/10 text-amber-600',
    pending: 'bg-sky-500/10 text-sky-600',
};
</script>

<template>
    <Head title="My Dashboard" />
    <AdminLayout>
        <PageHeader title="My Dashboard" description="Your profile, payslips, leave and attendance at a glance." :icon="UserRound" />

        <!-- Profile card -->
        <div class="mt-6 flex flex-wrap items-center gap-4 rounded-xl border bg-card p-5 shadow-sm">
            <span class="grid size-16 shrink-0 place-items-center overflow-hidden rounded-2xl bg-gradient-to-br from-hero-from to-hero-to text-xl font-bold text-hero-ink">
                <img v-if="props.employee.photo" :src="props.employee.photo" :alt="props.employee.full_name" class="size-full object-cover" />
                <span v-else>{{ props.employee.full_name.split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase() }}</span>
            </span>
            <div class="min-w-0 flex-1">
                <h2 class="text-lg font-semibold">{{ props.employee.full_name }}</h2>
                <p class="text-[13px] text-muted-foreground">
                    {{ props.employee.designation ?? 'Employee' }}<span v-if="props.employee.department"> · {{ props.employee.department }}</span>
                </p>
                <p class="mt-0.5 text-[12px] text-muted-foreground/70">ID {{ props.employee.employee_id }}<span v-if="props.employee.joining_date"> · Joined {{ props.employee.joining_date }}</span></p>
            </div>
            <div class="flex gap-2">
                <Link :href="route('hrm.my.payslips')"><Button variant="outline" size="sm" class="gap-1.5"><FileText class="size-4" /> Payslips</Button></Link>
                <Link :href="route('hrm.my.leave')"><Button variant="outline" size="sm" class="gap-1.5"><CalendarDays class="size-4" /> Leave</Button></Link>
                <Link :href="route('hrm.my.attendance')"><Button variant="outline" size="sm" class="gap-1.5"><Clock class="size-4" /> Attendance</Button></Link>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="mt-5 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground"><Wallet class="size-3.5" /> Latest net pay</p>
                <p class="mt-1.5 text-2xl font-bold tabular-nums">{{ props.slips.length ? money(props.slips[0].net_salary) : '—' }}</p>
                <p class="text-[11px] text-muted-foreground/70">{{ props.slips.length ? period(props.slips[0]) : 'No payslips yet' }}</p>
            </div>
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground"><CalendarDays class="size-3.5" /> Leave taken ({{ new Date().getFullYear() }})</p>
                <p class="mt-1.5 text-2xl font-bold tabular-nums">{{ props.leave.taken }} <span class="text-sm font-medium text-muted-foreground">days</span></p>
                <p class="text-[11px] text-muted-foreground/70">{{ props.leave.pending }} request(s) pending</p>
            </div>
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground"><Clock class="size-3.5" /> Present this month</p>
                <p class="mt-1.5 text-2xl font-bold tabular-nums">{{ props.attendance.present }} <span class="text-sm font-medium text-muted-foreground">/ {{ props.attendance.days }}</span></p>
                <p class="text-[11px] text-muted-foreground/70">{{ props.attendance.late }} late · {{ props.attendance.absent }} absent</p>
            </div>
            <div class="rounded-xl border bg-card p-4 shadow-sm">
                <p class="flex items-center gap-1.5 text-[12px] font-medium text-muted-foreground"><FileText class="size-3.5" /> Payslips on file</p>
                <p class="mt-1.5 text-2xl font-bold tabular-nums">{{ props.slips.length }}</p>
                <p class="text-[11px] text-muted-foreground/70">Most recent six shown below</p>
            </div>
        </div>

        <!-- Recent payslips -->
        <section class="mt-8">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold">Recent payslips</h2>
                <Link :href="route('hrm.my.payslips')" class="text-[12px] font-medium text-primary hover:underline">View all →</Link>
            </div>
            <div v-if="props.slips.length === 0" class="rounded-xl border border-dashed bg-card px-6 py-10 text-center text-[13px] text-muted-foreground">
                No payslips yet. They'll appear here once payroll is processed.
            </div>
            <div v-else class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <table class="w-full text-sm">
                    <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                        <tr><th class="px-4 py-2.5 font-semibold">Period</th><th class="px-4 py-2.5 text-right font-semibold">Net pay</th><th class="px-4 py-2.5 font-semibold">Status</th><th class="px-4 py-2.5 text-right font-semibold"></th></tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="s in props.slips" :key="s.id" class="hover:bg-accent/50">
                            <td class="px-4 py-3 font-medium">{{ period(s) }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ money(s.net_salary) }}</td>
                            <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-[11px] font-bold capitalize" :class="STATUS_TONE[s.payment_status] ?? 'bg-muted text-muted-foreground'">{{ s.payment_status }}</span></td>
                            <td class="px-4 py-3 text-right"><Link :href="route('hrm.my.payslip', { id: s.id })" class="text-[12px] font-medium text-primary hover:underline">View →</Link></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </AdminLayout>
</template>
