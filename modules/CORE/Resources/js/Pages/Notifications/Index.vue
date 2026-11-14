<script setup lang="ts">
import PageHeader from '@core/Components/PageHeader.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Button } from '@/components/ui/button';
import { Head, Link, router } from '@inertiajs/vue3';
import { Bell, CheckCheck, Inbox } from 'lucide-vue-next';
import { computed } from 'vue';

interface NotificationRow {
    id: string;
    data: { title?: string; body?: string; url?: string | null; level?: string };
    read_at: string | null;
    created_at: string;
}
interface Paginator<T> {
    data: T[];
    links: Array<{ url: string | null; label: string; active: boolean }>;
    current_page: number;
    last_page: number;
    total: number;
}
interface Props {
    notifications: Paginator<NotificationRow>;
    unreadCount: number;
}
const props = defineProps<Props>();

const LEVEL_DOT: Record<string, string> = {
    info: 'bg-sky-500',
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    danger: 'bg-rose-500',
};

const rows = computed(() => props.notifications.data);

function markRead(n: NotificationRow): void {
    if (!n.read_at) {
        router.post(route('core.notifications.read', { id: n.id }), {}, { preserveScroll: true });
    }
    if (n.data.url) router.visit(n.data.url);
}

function markAll(): void {
    router.post(route('core.notifications.read-all'), {}, { preserveScroll: true });
}

function timeAgo(iso: string): string {
    const diff = Date.now() - new Date(iso).getTime();
    const mins = Math.floor(diff / 60000);
    if (mins < 1) return 'just now';
    if (mins < 60) return `${mins}m ago`;
    const hrs = Math.floor(mins / 60);
    if (hrs < 24) return `${hrs}h ago`;
    return `${Math.floor(hrs / 24)}d ago`;
}
</script>

<template>
    <Head title="Notifications" />
    <AdminLayout>
        <PageHeader title="Notifications" description="Approvals, alerts and system events addressed to you." :icon="Bell" />

        <div class="mt-6 rounded-xl border bg-card shadow-sm">
            <div class="flex items-center justify-between border-b px-4 py-3">
                <p class="text-sm font-semibold">
                    Inbox
                    <span v-if="unreadCount > 0" class="ml-2 rounded-full bg-rose-500/10 px-2 py-0.5 text-[11px] font-bold text-rose-600">{{ unreadCount }} unread</span>
                </p>
                <Button v-if="unreadCount > 0" variant="ghost" size="sm" class="gap-1.5 text-muted-foreground" @click="markAll">
                    <CheckCheck class="size-4" /> Mark all read
                </Button>
            </div>

            <div v-if="rows.length === 0" class="grid place-items-center gap-3 px-6 py-16 text-center">
                <span class="grid size-14 place-items-center rounded-2xl bg-muted"><Inbox class="size-7 text-muted-foreground/50" /></span>
                <p class="text-sm font-medium">You're all caught up</p>
                <p class="max-w-sm text-[13px] text-muted-foreground">When an approval is requested, a credit limit is hit, or the system needs your attention, it will appear here.</p>
            </div>

            <ul v-else class="divide-y">
                <li v-for="n in rows" :key="n.id">
                    <button type="button" class="flex w-full items-start gap-3 px-4 py-3.5 text-left transition hover:bg-accent" :class="n.read_at ? 'opacity-60' : ''" @click="markRead(n)">
                        <span class="mt-1.5 size-2.5 shrink-0 rounded-full" :class="n.read_at ? 'bg-muted-foreground/25' : LEVEL_DOT[n.data.level ?? 'info']" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium">{{ n.data.title ?? 'Notification' }}</span>
                            <span v-if="n.data.body" class="mt-0.5 block text-[13px] text-muted-foreground">{{ n.data.body }}</span>
                            <span class="mt-1 block text-[11px] text-muted-foreground/70">{{ timeAgo(n.created_at) }}</span>
                        </span>
                        <span v-if="!n.read_at" class="mt-1 shrink-0 rounded-full bg-primary/10 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wide text-primary">New</span>
                    </button>
                </li>
            </ul>

            <div v-if="notifications.last_page > 1" class="flex items-center justify-between border-t px-4 py-3">
                <p class="text-[12px] text-muted-foreground">Page {{ notifications.current_page }} of {{ notifications.last_page }} · {{ notifications.total }} total</p>
                <div class="flex gap-1">
                    <template v-for="(link, i) in notifications.links" :key="i">
                        <Link v-if="link.url" :href="link.url" preserve-scroll class="rounded-md border px-2.5 py-1 text-[12px] transition hover:bg-accent" :class="link.active ? 'border-primary bg-primary text-primary-foreground' : ''"><span v-html="link.label" /></Link>
                        <span v-else class="rounded-md border px-2.5 py-1 text-[12px] opacity-40" v-html="link.label" />
                    </template>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>
