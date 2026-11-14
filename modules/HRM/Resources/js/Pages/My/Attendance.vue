<script setup lang="ts">
import PageHeader from '@core/Components/PageHeader.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Input } from '@/components/ui/input';
import { Head, router } from '@inertiajs/vue3';
import { Clock } from 'lucide-vue-next';
import { computed } from 'vue';

interface AttendanceRow {
    id: number;
    duty_date: string;
    status: string;
    primary_punch_in: string | null;
    primary_punch_out: string | null;
    primary_worked_hours: string | null;
    total_overtime_hours: string | null;
}
interface Props {
    rows: AttendanceRow[];
    month: string;
}
const props = defineProps<Props>();

const STATUS_TONE: Record<string, string> = {
    Present: 'bg-emerald-500/10 text-emerald-600',
    Late: 'bg-amber-500/10 text-amber-600',
    Absent: 'bg-rose-500/10 text-rose-600',
    'On Leave': 'bg-sky-500/10 text-sky-600',
    Holiday: 'bg-violet-500/10 text-violet-600',
    'Off Day': 'bg-muted text-muted-foreground',
};

const summary = computed(() => ({
    present: props.rows.filter((r) => ['Present', 'Late'].includes(r.status)).length,
    late: props.rows.filter((r) => r.status === 'Late').length,
    absent: props.rows.filter((r) => r.status === 'Absent').length,
    leave: props.rows.filter((r) => r.status === 'On Leave').length,
}));

function changeMonth(e: Event): void {
    const value = (e.target as HTMLInputElement).value;
    if (value) router.get(route('hrm.my.attendance'), { month: value }, { preserveState: true });
}

const time = (iso: string | null): string => (iso ? new Date(iso).toLocaleTimeString('en-BD', { hour: '2-digit', minute: '2-digit' }) : '—');
const dayName = (d: string): string => new Date(d).toLocaleDateString('en-BD', { weekday: 'short' });
</script>

<template>
    <Head title="My Attendance" />
    <AdminLayout>
        <PageHeader title="My Attendance" description="Your daily attendance, punch times and overtime for the selected month." :icon="Clock" :actions="[{ label: 'My dashboard', route: 'hrm.my.dashboard', variant: 'outline' }]" />

        <div class="mt-6 flex flex-wrap items-center gap-4">
            <div class="w-44"><Input type="month" :model-value="props.month" @change="changeMonth" /></div>
            <div class="flex flex-wrap gap-2 text-[12px]">
                <span class="rounded-full bg-emerald-500/10 px-2.5 py-1 font-semibold text-emerald-600">{{ summary.present }} present</span>
                <span class="rounded-full bg-amber-500/10 px-2.5 py-1 font-semibold text-amber-600">{{ summary.late }} late</span>
                <span class="rounded-full bg-rose-500/10 px-2.5 py-1 font-semibold text-rose-600">{{ summary.absent }} absent</span>
                <span class="rounded-full bg-sky-500/10 px-2.5 py-1 font-semibold text-sky-600">{{ summary.leave }} on leave</span>
            </div>
        </div>

        <div class="mt-5 overflow-hidden rounded-xl border bg-card shadow-sm">
            <div v-if="props.rows.length === 0" class="px-6 py-14 text-center">
                <Clock class="mx-auto size-8 text-muted-foreground/40" />
                <p class="mt-2 text-sm font-medium">No attendance records</p>
                <p class="text-[13px] text-muted-foreground">Nothing recorded for this month yet.</p>
            </div>
            <table v-else class="w-full text-sm">
                <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Date</th>
                        <th class="px-4 py-2.5 font-semibold">Day</th>
                        <th class="px-4 py-2.5 font-semibold">Status</th>
                        <th class="px-4 py-2.5 font-semibold">Punch in</th>
                        <th class="px-4 py-2.5 font-semibold">Punch out</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Worked</th>
                        <th class="px-4 py-2.5 text-right font-semibold">OT</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in props.rows" :key="r.id" class="hover:bg-accent/50">
                        <td class="px-4 py-2.5 font-medium tabular-nums">{{ r.duty_date }}</td>
                        <td class="px-4 py-2.5 text-[13px] text-muted-foreground">{{ dayName(r.duty_date) }}</td>
                        <td class="px-4 py-2.5"><span class="rounded-full px-2 py-0.5 text-[11px] font-bold" :class="STATUS_TONE[r.status] ?? 'bg-muted text-muted-foreground'">{{ r.status }}</span></td>
                        <td class="px-4 py-2.5 text-[13px] tabular-nums">{{ time(r.primary_punch_in) }}</td>
                        <td class="px-4 py-2.5 text-[13px] tabular-nums">{{ time(r.primary_punch_out) }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums">{{ r.primary_worked_hours ? `${Number(r.primary_worked_hours).toFixed(1)}h` : '—' }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums text-muted-foreground">{{ r.total_overtime_hours && Number(r.total_overtime_hours) > 0 ? `${Number(r.total_overtime_hours).toFixed(1)}h` : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminLayout>
</template>
