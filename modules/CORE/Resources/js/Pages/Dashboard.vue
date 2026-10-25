<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Activity,
    ArrowRight,
    FileClock,
    KeyRound,
    LogIn,
    LogOut,
    PencilLine,
    Plus,
    ShieldAlert,
    ShieldCheck,
    Trash2,
    UserPlus,
    Users,
    UsersRound,
} from 'lucide-vue-next';
import AdminLayout from '../Layouts/AdminLayout.vue';
import { useAcl } from '../Composables/useAcl';
import type { ActivityRow } from '../types';
import { eventTone, formatNumber, timeAgo } from '../Utils/format';
import { computed, onBeforeUnmount, onMounted, ref, type Component } from 'vue';

interface Props {
    stats: {
        users: number;
        verified_users: number;
        roles: number;
        permissions: number;
        audit_events_24h: number;
    };
    recentActivities: ActivityRow[];
    roleDistribution: { name: string; users_count: number }[];
}

const props = defineProps<Props>();
const { can } = useAcl();

/* ----------------------------- entrance + count-up ----------------------- */

const progress = ref(0);
let progressFrame = 0;

onMounted(() => {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    if (reduced) {
        progress.value = 1;
    } else {
        const start = performance.now();
        const duration = 950;

        const tick = (now: number) => {
            const t = Math.min((now - start) / duration, 1);
            progress.value = 1 - Math.pow(1 - t, 3);
            if (t < 1) progressFrame = requestAnimationFrame(tick);
        };

        progressFrame = requestAnimationFrame(tick);
    }
});

onBeforeUnmount(() => cancelAnimationFrame(progressFrame));

function display(value: number): number {
    return Math.round(value * progress.value);
}

/* --------------------------------- greeting ------------------------------ */

const now = new Date();
const greeting = computed(() => {
    const hour = now.getHours();
    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
});

const today = now.toLocaleDateString(undefined, {
    weekday: 'long',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
});

/* -------------------------------- stat cards ----------------------------- */

const cards = computed(() => [
    { key: 'users', label: 'Total users', icon: Users, tint: 'from-indigo-500 to-violet-500', glow: 'group-hover:shadow-indigo-500/20', hint: 'Registered accounts', routeName: 'core.users.index', permission: 'users.view', value: props.stats.users },
    { key: 'roles', label: 'Roles', icon: ShieldCheck, tint: 'from-sky-500 to-cyan-500', glow: 'group-hover:shadow-sky-500/20', hint: 'Access profiles', routeName: 'core.roles.index', permission: 'roles.view', value: props.stats.roles },
    { key: 'permissions', label: 'Permissions', icon: KeyRound, tint: 'from-amber-500 to-orange-500', glow: 'group-hover:shadow-amber-500/20', hint: 'ACL capabilities', routeName: 'core.permissions.index', permission: 'permissions.view', value: props.stats.permissions },
    { key: 'audit_24h', label: 'Audit events', icon: Activity, tint: 'from-emerald-500 to-teal-500', glow: 'group-hover:shadow-emerald-500/20', hint: 'Recorded in the last 24h', routeName: 'core.activities.index', permission: 'audit.view', value: props.stats.audit_events_24h },
]);

const verifiedPct = computed(() =>
    props.stats.users > 0 ? Math.round((props.stats.verified_users / props.stats.users) * 100) : 0,
);

/* ------------------------------ activity feed ---------------------------- */

const EVENT_ICONS: Record<string, Component> = {
    created: UserPlus,
    updated: PencilLine,
    deleted: Trash2,
    permissions_synced: ShieldCheck,
    roles_synced: UsersRound,
    login: LogIn,
    logout: LogOut,
    login_failed: ShieldAlert,
};

function iconFor(event: string | null): Component {
    return (event && EVENT_ICONS[event]) || FileClock;
}

/* --------------------------- role distribution --------------------------- */

const rolePalette = ['bg-indigo-500', 'bg-sky-500', 'bg-amber-500', 'bg-rose-500', 'bg-emerald-500', 'bg-violet-500'];
const roleStroke = ['#6366f1', '#0ea5e9', '#f59e0b', '#f43f5e', '#10b981', '#8b5cf6'];

const totalMembers = computed(() => props.roleDistribution.reduce((sum, role) => sum + role.users_count, 0));

interface DonutSegment {
    name: string;
    count: number;
    pct: number;
    color: string;
    dash: string;
    offset: number;
}

const donutReady = ref(false);

const donutSegments = computed<DonutSegment[]>(() => {
    const total = Math.max(1, totalMembers.value);
    const radius = 52;
    const circumference = 2 * Math.PI * radius;
    let cumulative = 0;

    return props.roleDistribution.map((role, index) => {
        const fraction = role.users_count / total;
        const segment: DonutSegment = {
            name: role.name,
            count: role.users_count,
            pct: Math.round(fraction * 100),
            color: roleStroke[index % roleStroke.length],
            dash: donutReady.value ? `${Math.max(fraction * circumference, 3)} ${circumference}` : `0 ${circumference}`,
            offset: -cumulative * circumference,
        };

        cumulative += fraction;

        return segment;
    });
});

const donutTotal = computed(() =>
    donutReady.value ? totalMembers.value : 0,
);

onMounted(() => {
    requestAnimationFrame(() => {
        requestAnimationFrame(() => {
            donutReady.value = true;
        });
    });
});
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Dashboard' }]">
        <Head title="Dashboard" />

        <!-- Hero -->
        <div
            class="d-rise relative overflow-hidden rounded-2xl border bg-gradient-to-br from-card via-card to-primary/[0.06] p-6 shadow-sm md:p-7"
        >
            <div class="d-grid pointer-events-none absolute inset-0 opacity-[0.5]" aria-hidden="true" />
            <div class="pointer-events-none absolute -right-20 -top-24 size-64 rounded-full bg-primary/12 blur-3xl" />
            <div class="pointer-events-none absolute -bottom-24 right-24 size-44 rounded-full bg-violet-500/10 blur-3xl" />

            <div class="relative flex flex-col gap-5 md:flex-row md:items-end md:justify-between">
                <div>
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-xs font-semibold uppercase tracking-[0.18em] text-primary">Command Center</p>
                        <span class="rounded-full border bg-card/80 px-2.5 py-0.5 text-[11px] font-medium text-muted-foreground shadow-sm">
                            {{ today }}
                        </span>
                    </div>
                    <h1 class="mt-2.5 text-2xl font-bold tracking-tight md:text-3xl">
                        {{ greeting }}<span v-if="$page.props.auth.user">, {{ $page.props.auth.user.name.split(' ')[0] }}</span>
                    </h1>
                    <p class="mt-1.5 max-w-2xl text-sm leading-relaxed text-muted-foreground">
                        Your access, audit and compliance surface — every action in this console is recorded in the
                        JCI-grade audit trail.
                    </p>
                </div>

                <div class="flex shrink-0 flex-wrap items-center gap-2">
                    <Link
                        v-if="can('users.create')"
                        :href="route('core.users.create')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary px-3.5 text-sm font-medium text-primary-foreground shadow-sm shadow-primary/30 transition hover:bg-primary/90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    >
                        <Plus class="size-4" />
                        New user
                    </Link>
                    <Link
                        v-if="can('audit.view')"
                        :href="route('core.activities.index')"
                        class="inline-flex h-9 items-center gap-1.5 rounded-lg border bg-card px-3.5 text-sm font-medium shadow-sm transition hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <Activity class="size-4 text-muted-foreground" />
                        Audit trail
                    </Link>
                </div>
            </div>
        </div>

        <!-- Stat cards -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <component
                :is="can(card.permission) ? Link : 'div'"
                v-for="(card, index) in cards"
                :key="card.key"
                :href="can(card.permission) ? route(card.routeName) : undefined"
                class="d-rise group relative overflow-hidden rounded-xl border bg-card p-5 shadow-sm transition duration-300 hover:-translate-y-1 hover:shadow-xl"
                :class="[card.glow, !can(card.permission) && 'pointer-events-none opacity-60']"
                :style="{ 'animation-delay': `${90 + index * 70}ms` }"
            >
                <!-- accent ribbon -->
                <span
                    class="absolute inset-x-0 top-0 h-0.5 origin-left scale-x-0 bg-gradient-to-r transition-transform duration-500 group-hover:scale-x-100"
                    :class="card.tint"
                    aria-hidden="true"
                />

                <div class="flex items-start justify-between">
                    <span
                        class="grid size-11 place-items-center rounded-xl bg-gradient-to-br text-white shadow-md transition-transform duration-300 group-hover:scale-105"
                        :class="card.tint"
                    >
                        <component :is="card.icon" class="size-5" />
                    </span>
                    <ArrowRight
                        class="size-4 translate-x-1 text-muted-foreground opacity-0 transition group-hover:translate-x-0 group-hover:opacity-100"
                    />
                </div>

                <p class="mt-4 text-3xl font-bold tabular-nums tracking-tight">
                    {{ display(card.value) }}
                </p>
                <p class="mt-1 text-sm font-medium">{{ card.label }}</p>
                <p class="text-xs text-muted-foreground">{{ card.hint }}</p>

                <!-- users card carries the verified sub-metric -->
                <div v-if="card.key === 'users'" class="mt-3 flex items-center gap-2">
                    <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 transition-[width] duration-1000 ease-out"
                            :style="{ width: `${verifiedPct * progress}%` }"
                        />
                    </div>
                    <span class="whitespace-nowrap text-[11px] font-medium text-muted-foreground">
                        {{ formatNumber(stats.verified_users) }} verified
                    </span>
                </div>
            </component>
        </div>

        <div class="grid gap-4 lg:grid-cols-5">
            <!-- Recent audit -->
            <section
                class="d-rise overflow-hidden rounded-xl border bg-card shadow-sm lg:col-span-3"
                style="animation-delay: 380ms"
            >
                <header class="flex items-center justify-between border-b px-5 py-4">
                    <div>
                        <h2 class="text-sm font-semibold">Recent activity</h2>
                        <p class="text-xs text-muted-foreground">Live feed from the audit trail</p>
                    </div>
                    <Link
                        v-if="can('audit.view')"
                        :href="route('core.activities.index')"
                        class="inline-flex items-center gap-1 rounded-md px-1.5 py-1 text-xs font-medium text-primary transition hover:bg-primary/10 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        View all
                        <ArrowRight class="size-3.5" />
                    </Link>
                </header>

                <ul v-if="recentActivities.length" class="relative px-5 py-4">
                    <span
                        class="pointer-events-none absolute bottom-6 left-[38px] top-6 w-px bg-gradient-to-b from-primary/40 via-border to-transparent"
                        aria-hidden="true"
                    />
                    <li
                        v-for="(activity, index) in recentActivities"
                        :key="activity.id"
                        class="relative flex items-start gap-3.5 py-2.5"
                        :style="{ 'animation-delay': `${420 + index * 50}ms` }"
                    >
                        <span
                            class="d-badge relative z-[1] grid size-9 shrink-0 place-items-center rounded-xl border shadow-sm"
                            :class="eventTone(activity.event).chip"
                        >
                            <component :is="iconFor(activity.event)" class="size-4" />
                        </span>

                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm leading-snug">
                                <span class="font-semibold">{{ activity.causer?.name ?? 'System' }}</span>
                                <span class="text-muted-foreground"> — </span>
                                {{ activity.description }}
                            </p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                                <span>{{ activity.log_name }}</span>
                                <template v-if="activity.subject">
                                    <span aria-hidden="true">·</span>
                                    <span>{{ activity.subject.type }} #{{ activity.subject.id }}</span>
                                </template>
                            </p>
                        </div>

                        <span
                            class="shrink-0 whitespace-nowrap rounded-md bg-muted/60 px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground"
                            :title="activity.created_at"
                        >
                            {{ timeAgo(activity.created_at) }}
                        </span>
                    </li>
                </ul>

                <div v-else class="px-5 py-12 text-center">
                    <div class="mx-auto grid size-12 place-items-center rounded-2xl border border-dashed bg-muted/40">
                        <Activity class="size-5 text-muted-foreground/70" />
                    </div>
                    <p class="mt-3 text-sm font-medium">No audit entries yet</p>
                    <p class="mt-1 text-sm text-muted-foreground">Actions you take will appear here instantly.</p>
                </div>
            </section>

            <!-- Role distribution -->
            <section
                class="d-rise overflow-hidden rounded-xl border bg-card shadow-sm lg:col-span-2"
                style="animation-delay: 460ms"
            >
                <header class="border-b px-5 py-4">
                    <h2 class="text-sm font-semibold">Members per role</h2>
                    <p class="text-xs text-muted-foreground">Where your people sit in the ACL</p>
                </header>

                <div class="flex flex-col items-center gap-5 px-5 py-5 sm:flex-row sm:justify-center">
                    <!-- Donut -->
                    <div class="relative shrink-0">
                        <svg viewBox="0 0 120 120" class="size-36 -rotate-90" role="img" aria-label="Role distribution chart">
                            <circle
                                cx="60"
                                cy="60"
                                r="52"
                                fill="none"
                                stroke="hsl(var(--muted))"
                                stroke-width="12"
                            />
                            <circle
                                v-for="segment in donutSegments"
                                :key="segment.name"
                                cx="60"
                                cy="60"
                                r="52"
                                fill="none"
                                :stroke="segment.color"
                                stroke-width="12"
                                stroke-linecap="butt"
                                class="transition-[stroke-dasharray] duration-1000 ease-out"
                                :stroke-dasharray="segment.dash"
                                :stroke-dashoffset="segment.offset"
                            />
                        </svg>
                        <div class="absolute inset-0 grid place-content-center text-center">
                            <span class="text-2xl font-bold tabular-nums leading-none">{{ formatNumber(donutTotal) }}</span>
                            <span class="mt-1 text-[11px] uppercase tracking-wider text-muted-foreground">members</span>
                        </div>
                    </div>

                    <!-- Legend -->
                    <ul class="w-full min-w-0 space-y-2.5">
                        <li
                            v-for="(segment, index) in donutSegments"
                            :key="segment.name"
                            class="flex items-center gap-2.5 text-sm"
                        >
                            <span
                                class="size-2.5 shrink-0 rounded-full"
                                :class="rolePalette[index % rolePalette.length]"
                                aria-hidden="true"
                            />
                            <span class="min-w-0 flex-1 truncate font-medium">{{ segment.name }}</span>
                            <span class="tabular-nums text-muted-foreground">{{ formatNumber(segment.count) }}</span>
                            <span class="w-9 text-right text-xs tabular-nums text-muted-foreground/80">{{ segment.pct }}%</span>
                        </li>
                    </ul>
                </div>

                <div class="space-y-2 border-t px-5 py-4">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-medium text-muted-foreground">Verified accounts</span>
                        <span class="font-semibold tabular-nums">
                            {{ formatNumber(stats.verified_users) }} / {{ formatNumber(stats.users) }}
                        </span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full rounded-full bg-gradient-to-r from-emerald-500 to-teal-500 transition-[width] duration-1000 ease-out"
                            :style="{ width: `${verifiedPct * progress}%` }"
                        />
                    </div>
                </div>
            </section>
        </div>
    </AdminLayout>
</template>

<style scoped>
/* Entrance choreography */
.d-rise {
    animation: d-rise 0.55s cubic-bezier(0.21, 1.02, 0.73, 1) both;
    animation-delay: var(--animation-delay, 0ms);
}

@keyframes d-rise {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.d-badge {
    animation: d-badge 0.4s cubic-bezier(0.21, 1.02, 0.73, 1) both;
    animation-delay: var(--animation-delay, 0ms);
}

@keyframes d-badge {
    from {
        opacity: 0;
        transform: scale(0.7);
    }
    to {
        opacity: 1;
        transform: scale(1);
    }
}

/* Subtle blueprint grid over the hero */
.d-grid {
    background-image:
        linear-gradient(to right, hsl(var(--border) / 0.5) 1px, transparent 1px),
        linear-gradient(to bottom, hsl(var(--border) / 0.5) 1px, transparent 1px);
    background-size: 36px 36px;
    mask-image: radial-gradient(ellipse 80% 90% at 70% 0%, black 30%, transparent 75%);
}

@media (prefers-reduced-motion: reduce) {
    .d-rise,
    .d-badge {
        animation: none !important;
    }
}
</style>
