<script setup lang="ts">
import PageHeader from '@core/Components/PageHeader.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
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
interface BalanceRow {
    leave_type?: string | null;
    leave_type_id?: number;
    entitlement?: number;
    available?: number;
    approved?: number;
    pending?: number;
}
interface Props {
    requests: Paginator<LeaveRow>;
    types: Array<{ id: number; name: string }>;
    /** Per-type balances for the year — what this screen exists to answer. */
    balances?: BalanceRow[];
    totals?: { available: number; taken: number; pending: number };
    year?: number;
}
const props = defineProps<Props>();

const rows = computed<BalanceRow[]>(() => props.balances ?? []);

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

        <!-- Balances first: "how many days do I have left" is the question this page answers. -->
        <div v-if="rows.length" class="mt-6 rounded-xl border bg-card p-4 shadow-sm">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold">Leave balance{{ props.year ? ' — ' + props.year : '' }}</h2>
                <p v-if="props.totals" class="text-[13px] text-muted-foreground">
                    Available <span class="font-semibold text-foreground">{{ props.totals.available }}</span> ·
                    Taken <span class="font-semibold text-foreground">{{ props.totals.taken }}</span> ·
                    Pending <span class="font-semibold text-foreground">{{ props.totals.pending }}</span>
                </p>
            </div>
            <table class="mt-3 w-full text-sm">
                <thead class="border-b text-left text-[11px] uppercase tracking-wider text-muted-foreground">
                    <tr>
                        <th class="py-2 font-semibold">Type</th>
                        <th class="py-2 text-right font-semibold">Entitlement</th>
                        <th class="py-2 text-right font-semibold">Taken</th>
                        <th class="py-2 text-right font-semibold">Pending</th>
                        <th class="py-2 text-right font-semibold">Available</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, i) in rows" :key="row.leave_type_id ?? i" class="border-b last:border-0">
                        <td class="py-2">{{ row.leave_type ?? '—' }}</td>
                        <td class="py-2 text-right tabular-nums">{{ row.entitlement ?? 0 }}</td>
                        <td class="py-2 text-right tabular-nums">{{ row.approved ?? 0 }}</td>
                        <td class="py-2 text-right tabular-nums">{{ row.pending ?? 0 }}</td>
                        <td class="py-2 text-right font-semibold tabular-nums">{{ row.available ?? 0 }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

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
