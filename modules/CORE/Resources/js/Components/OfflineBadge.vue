<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { CloudOff, Inbox } from 'lucide-vue-next';
import { useLocale } from '../Composables/useLocale';

/**
 * The header's offline-till signal (F22 / U21).
 *
 * Why this reads a storage key instead of importing the queue: the queue belongs to SALES, and CORE
 * importing a module it is supposed to know nothing about would put a hole in the modular monolith the
 * rest of this app is built on — SALES must be removable without CORE noticing. So the contract is one
 * versioned JSON blob written by the till (`sales.offline.pending.v1` in
 * `SALES/Resources/js/offline/queue.ts`), and this component re-implements nothing but the eleven lines
 * needed to read it. `OfflineBadgeTest` in `Phase3OfflinePwaTest` pins the key, the version and the
 * field names on both sides, because a contract that is not checked is a contract that drifts.
 *
 * It also stays quiet when there is nothing to report. A permanent "0" badge is noise that teaches
 * cashiers to ignore the one thing on screen that would tell them the drawer and the ledger disagree.
 */
const { t: tr } = useLocale();

const SIGNAL_KEY = 'sales.offline.pending.v1';
const SIGNAL_VERSION = 1;
/** Same-tab notification; `storage` only fires in *other* tabs, and a till is mostly one tab. */
const SIGNAL_EVENT = 'sales.offline.signal';

interface Signal {
    waiting: number;
    attention: number;
    value: string;
}

const signal = ref<Signal | null>(null);
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine !== false);

function read(): Signal | null {
    try {
        const raw = window.localStorage.getItem(SIGNAL_KEY);

        if (!raw) return null;

        const parsed = JSON.parse(raw) as Partial<Signal> & { v?: number };

        // A foreign or half-written blob is treated as no signal. This badge's only job is to be
        // believed, and a number invented from a partial write is how that belief ends.
        if (!parsed || parsed.v !== SIGNAL_VERSION || typeof parsed.waiting !== 'number') return null;

        return { waiting: parsed.waiting, attention: Number(parsed.attention) || 0, value: String(parsed.value ?? '0.00') };
    } catch {
        return null;
    }
}

const href = computed<string | null>(() => {
    const ziggy = (window as { route?: { has?: (name: string) => boolean } } & ((name: string) => string)).route;

    if (typeof ziggy !== 'function') return null;

    // The link is offered only if the module that owns the page is installed, so removing SALES does
    // not leave CORE rendering a route that throws.
    try {
        return ziggy.has?.('sales.pos.offline') ? ziggy('sales.pos.offline') : null;
    } catch {
        return null;
    }
});

const visible = computed(() => online.value === false || (signal.value?.waiting ?? 0) > 0);
const needsAttention = computed(() => (signal.value?.attention ?? 0) > 0);

const label = computed(() => {
    if (!visible.value) return '';

    if (!online.value && !signal.value?.waiting) return tr('Offline');

    const count = signal.value?.waiting ?? 0;

    return tr('pos.offlineBadgeWaiting')
        .replace(':n', String(count))
        .replace(':nPlural', count === 1 ? '' : 's')
        .replace(':v', signal.value?.value ?? '0.00');
});

function resync() {
    signal.value = read();
}

function onVisibility() {
    if (document.visibilityState === 'visible') resync();
}

onMounted(() => {
    resync();

    window.addEventListener('storage', resync);
    window.addEventListener(SIGNAL_EVENT, resync);
    window.addEventListener('online', resync);
    window.addEventListener('offline', resync);
    document.addEventListener('visibilitychange', onVisibility);
});

onBeforeUnmount(() => {
    window.removeEventListener('storage', resync);
    window.removeEventListener(SIGNAL_EVENT, resync);
    window.removeEventListener('online', resync);
    window.removeEventListener('offline', resync);
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
  <a
    v-if="visible"
    :href="href ?? undefined"
    role="status"
    aria-live="polite"
    class="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-full px-2 text-[11px] font-semibold ring-1 transition"
    :class="needsAttention
      ? 'bg-destructive/10 text-destructive ring-destructive/25 hover:bg-destructive/15'
      : 'bg-warning/10 text-warning ring-warning/25 hover:bg-warning/15'"
    :title="needsAttention ? 'A queued sale was refused and needs a decision' : 'Sales held on this device until the ledger accepts them'"
  >
    <component :is="needsAttention ? Inbox : CloudOff" class="size-3.5" :aria-hidden="true" />
    <span class="tabular-nums">{{ label }}</span>
  </a>
</template>
