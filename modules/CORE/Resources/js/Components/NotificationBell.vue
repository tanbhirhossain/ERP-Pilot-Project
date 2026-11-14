<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Link, router } from '@inertiajs/vue3';
import { Bell, CheckCheck, Inbox } from 'lucide-vue-next';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The header bell (Phase 1 — F2 / U10).
 *
 * Polls the feed endpoint every 45s while the tab is visible, so approvals and
 * system events surface without a websocket server. Clicking an item marks it
 * read and follows its deep link. "Mark all read" clears the badge.
 */
interface FeedItem {
    id: string;
    title: string;
    body: string;
    url: string | null;
    level: 'info' | 'success' | 'warning' | 'danger';
    read: boolean;
    at: string;
}

const items = ref<FeedItem[]>([]);
const unread = ref(0);
const open = ref(false);
let timer: number | undefined;

const LEVEL_DOT: Record<string, string> = {
    info: 'bg-sky-500',
    success: 'bg-emerald-500',
    warning: 'bg-amber-500',
    danger: 'bg-rose-500',
};

async function load(): Promise<void> {
    try {
        const res = await fetch(route('core.notifications.feed'), { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const data = await res.json();
        items.value = data.items ?? [];
        unread.value = data.unread ?? 0;
    } catch {
        /* network hiccup — the next poll retries */
    }
}

async function markRead(item: FeedItem): Promise<void> {
    if (!item.read) {
        try {
            await fetch(route('core.notifications.read', { id: item.id }), {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '', Accept: 'application/json' },
            });
            item.read = true;
            unread.value = Math.max(0, unread.value - 1);
        } catch {
            /* ignore */
        }
    }
    if (item.url) {
        open.value = false;
        router.visit(item.url);
    }
}

async function markAll(): Promise<void> {
    try {
        await fetch(route('core.notifications.read-all'), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null)?.content ?? '', Accept: 'application/json' },
        });
        items.value = items.value.map((i) => ({ ...i, read: true }));
        unread.value = 0;
    } catch {
        /* ignore */
    }
}

function onVisibility(): void {
    if (document.visibilityState === 'visible') void load();
}

onMounted(() => {
    void load();
    timer = window.setInterval(() => {
        if (document.visibilityState === 'visible') void load();
    }, 45000);
    document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
    if (timer) window.clearInterval(timer);
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <DropdownMenu v-model:open="open">
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" class="relative size-8 rounded-full text-muted-foreground hover:bg-accent" aria-label="Notifications">
                <Bell class="size-4" />
                <span
                    v-if="unread > 0"
                    class="absolute -right-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full bg-destructive px-1 text-[9px] font-bold leading-4 text-destructive-foreground ring-2 ring-background"
                >
                    {{ unread > 9 ? '9+' : unread }}
                </span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80 rounded-xl p-0 shadow-xl">
            <div class="flex items-center justify-between border-b px-3 py-2">
                <p class="text-[13px] font-semibold">Notifications</p>
                <button v-if="unread > 0" type="button" class="flex items-center gap-1 text-[11px] font-medium text-muted-foreground hover:text-foreground" @click="markAll">
                    <CheckCheck class="size-3" /> Mark all read
                </button>
            </div>
            <div class="max-h-80 overflow-y-auto premium-scrollbar">
                <div v-if="items.length === 0" class="grid place-items-center gap-2 px-4 py-8 text-center">
                    <Inbox class="size-6 text-muted-foreground/50" />
                    <p class="text-[13px] text-muted-foreground">You're all caught up</p>
                </div>
                <button
                    v-for="item in items"
                    :key="item.id"
                    type="button"
                    class="flex w-full items-start gap-2.5 border-b border-border/50 px-3 py-2.5 text-left transition last:border-0 hover:bg-accent"
                    :class="item.read ? 'opacity-60' : ''"
                    @click="markRead(item)"
                >
                    <span class="mt-1.5 size-2 shrink-0 rounded-full" :class="item.read ? 'bg-muted-foreground/30' : LEVEL_DOT[item.level]" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13px] font-medium">{{ item.title }}</span>
                        <span v-if="item.body" class="mt-0.5 block line-clamp-2 text-[12px] text-muted-foreground">{{ item.body }}</span>
                        <span class="mt-1 block text-[11px] text-muted-foreground/70">{{ item.at }}</span>
                    </span>
                </button>
            </div>
            <Link :href="route('core.notifications.index')" class="block border-t px-3 py-2 text-center text-[12px] font-medium text-primary hover:bg-accent">
                View all notifications
            </Link>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
