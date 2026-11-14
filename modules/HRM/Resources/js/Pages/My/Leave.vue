<script setup lang="ts">
import PageHeader from '@core/Components/PageHeader.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { CalendarDays } from 'lucide-vue-next';

interface LeaveRow {
    id: number;
    leave_type?: { name: string } | null;
    from_date: string;
    to_date: string;
    days: number;
    reason: string | null;
    status: string;
}
interface Paginator<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
}
interface Props {
    requests: Paginator<LeaveRow>;
    types: Array<{ id: number; name: string }>;
}
const props = defineProps<Props>();

const STATUS_TONE: Record<string, string> = {
    Approved: 'bg-emerald-500/10 text-emerald-600',
    Pending: 'bg-amber-500/10 text-amber-600',
    Rejected: 'bg-rose-500/10 text-rose-600',
    Cancelled: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <Head title="My Leave" />
    <AdminLayout>
        <PageHeader title="My Leave" description="Your leave requests and their status. To request new leave, contact HR or use the leave application." :icon="CalendarDays" :actions="[{ label: 'My dashboard', route: 'hrm.my.dashboard', variant: 'outline' }]" />

        <div class="mt-6 overflow-hidden rounded-xl border bg-card shadow-sm">
            <div v-if="props.requests.data.length === 0" class="px-6 py-14 text-center">
                <CalendarDays class="mx-auto size-8 text-muted-foreground/40" />
                <p class="mt-2 text-sm font-medium">No leave requests</p>
                <p class="text-[13px] text-muted-foreground">Your leave applications will appear here.</p>
            </div>
            <table v-else class="w-full text-sm">
                <thead class="border-b bg-muted/40 text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                    <tr>
                        <th class="px-4 py-2.5 font-semibold">Type</th>
                        <th class="px-4 py-2.5 font-semibold">From</th>
                        <th class="px-4 py-2.5 font-semibold">To</th>
                        <th class="px-4 py-2.5 text-right font-semibold">Days</th>
                        <th class="px-4 py-2.5 font-semibold">Reason</th>
                        <th class="px-4 py-2.5 font-semibold">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr v-for="r in props.requests.data" :key="r.id" class="hover:bg-accent/50">
                        <td class="px-4 py-3 font-medium">{{ r.leave_type?.name ?? 'Leave' }}</td>
                        <td class="px-4 py-3 text-[13px] tabular-nums">{{ r.from_date }}</td>
                        <td class="px-4 py-3 text-[13px] tabular-nums">{{ r.to_date }}</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ r.days }}</td>
                        <td class="px-4 py-3 text-[13px] text-muted-foreground">{{ r.reason ?? '—' }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-[11px] font-bold" :class="STATUS_TONE[r.status] ?? 'bg-muted text-muted-foreground'">{{ r.status }}</span></td>
                    </tr>
                </tbody>
            </table>
            <div v-if="props.requests.last_page > 1" class="flex items-center justify-between border-t px-4 py-3">
                <p class="text-[12px] text-muted-foreground">Page {{ props.requests.current_page }} of {{ props.requests.last_page }}</p>
                <div class="flex gap-1">
                    <template v-for="(link, i) in props.requests.links" :key="i">
                        <Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-md border px-2.5 py-1 text-[12px] transition hover:bg-accent" :class="link.active ? 'border-primary bg-primary text-primary-foreground' : ''"><span v-html="link.label" /></Link>
                        <span v-else class="rounded-md border px-2.5 py-1 text-[12px] opacity-40" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
