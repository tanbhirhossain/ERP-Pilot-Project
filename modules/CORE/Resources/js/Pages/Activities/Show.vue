<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Fingerprint, Globe, Monitor, ScrollText } from 'lucide-vue-next';
import { computed } from 'vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { eventTone, formatDateTime, timeAgo } from '../../Utils/format';

interface Props {
    activity: {
        id: number;
        log_name: string | null;
        description: string;
        event: string | null;
        created_at: string | null;
        ip: string | null;
        user_agent: string | null;
        causer: { id: number; name: string; email: string } | null;
        subject_type: string | null;
        subject_id: number | null;
        subject: { type: string; id: number; label: string } | null;
        changes: { old: Record<string, unknown>; new: Record<string, unknown> };
        properties: Record<string, unknown>;
    };
}

const props = defineProps<Props>();

const changeRows = computed(() => {
    const oldValues = props.activity.changes.old ?? {};
    const newValues = props.activity.changes.new ?? {};

    const keys = [...new Set([...Object.keys(oldValues), ...Object.keys(newValues)])];

    return keys.map((key) => ({
        key,
        before: oldValues[key],
        after: newValues[key],
        changed: JSON.stringify(oldValues[key] ?? null) !== JSON.stringify(newValues[key] ?? null),
    }));
});

function display(value: unknown): string {
    if (value === null || value === undefined) return '—';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (typeof value === 'object') return JSON.stringify(value);
    return String(value);
}

const extraProperties = computed(() => {
    const properties = (props.activity.properties ?? {}) as Record<string, unknown>;
    return Object.fromEntries(Object.entries(properties).filter(([key]) => key !== 'old' && key !== 'attributes'));
});
</script>

<template>
    <AdminLayout
        :breadcrumbs="[
            { title: 'Audit & Compliance', href: route('core.activities.index') },
            { title: 'Audit Trail', href: route('core.activities.index') },
            { title: `Entry #${activity.id}` },
        ]"
    >
        <Head :title="`Audit entry #${activity.id}`" />

        <div class="flex items-center gap-3 print:hidden">
            <Link
                :href="route('core.activities.index')"
                class="inline-flex size-9 items-center justify-center rounded-lg border text-muted-foreground transition hover:bg-accent hover:text-accent-foreground"
                aria-label="Back to audit trail"
            >
                <ArrowLeft class="size-4" />
            </Link>
            <div class="min-w-0">
                <h1 class="flex flex-wrap items-center gap-2 text-xl font-bold tracking-tight">
                    Entry
                    <span class="font-mono text-lg">#{{ activity.id }}</span>
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium capitalize"
                        :class="eventTone(activity.event).chip"
                    >
                        <span class="size-1.5 rounded-full" :class="eventTone(activity.event).dot" />
                        {{ (activity.event ?? 'unknown').replace('_', ' ') }}
                    </span>
                </h1>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    {{ activity.description }}
                </p>
            </div>
        </div>

        <!-- Overview -->
        <div class="grid gap-4 lg:grid-cols-3">
            <Card>
                <CardHeader class="pb-3">
                    <CardDescription>Recorded</CardDescription>
                    <CardTitle class="text-base">{{ formatDateTime(activity.created_at) }}</CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">{{ timeAgo(activity.created_at) }}</CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-3">
                    <CardDescription>Performed by</CardDescription>
                    <CardTitle class="text-base">
                        {{ activity.causer?.name ?? 'System / anonymous' }}
                    </CardTitle>
                </CardHeader>
                <CardContent class="text-sm text-muted-foreground">
                    {{ activity.causer?.email ?? 'No authenticated causer' }}
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="pb-3">
                    <CardDescription>Subject</CardDescription>
                    <CardTitle class="text-base">
                        <template v-if="activity.subject">
                            {{ activity.subject.type }} <span class="font-mono">#{{ activity.subject.id }}</span>
                        </template>
                        <template v-else>None</template>
                    </CardTitle>
                </CardHeader>
                <CardContent class="truncate text-sm text-muted-foreground">
                    {{ activity.subject?.label ?? 'Entry has no subject model' }}
                </CardContent>
            </Card>
        </div>

        <!-- Change log -->
        <Card v-if="changeRows.length">
            <CardHeader>
                <div class="flex items-center gap-2">
                    <Fingerprint class="size-4 text-primary" />
                    <CardTitle class="text-base">Attribute changes</CardTitle>
                </div>
                <CardDescription>Before and after values captured at the moment of the action.</CardDescription>
            </CardHeader>
            <CardContent class="overflow-x-auto p-0">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            <th class="px-5 py-2.5">Attribute</th>
                            <th class="px-5 py-2.5">Before</th>
                            <th class="px-5 py-2.5">After</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/70">
                        <tr v-for="row in changeRows" :key="row.key" :class="row.changed && 'bg-amber-500/[0.04]'">
                            <td class="px-5 py-2.5 font-mono text-[13px] font-medium">{{ row.key }}</td>
                            <td class="px-5 py-2.5 text-muted-foreground line-through decoration-rose-400/60">
                                {{ display(row.before) }}
                            </td>
                            <td class="px-5 py-2.5 font-medium text-foreground">{{ display(row.after) }}</td>
                        </tr>
                    </tbody>
                </table>
            </CardContent>
        </Card>

        <!-- Request context -->
        <div class="grid gap-4 lg:grid-cols-2">
            <Card>
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <Globe class="size-4 text-primary" />
                        <CardTitle class="text-base">Request context</CardTitle>
                    </div>
                    <CardDescription>Where the action came from (JCI source requirement).</CardDescription>
                </CardHeader>
                <CardContent class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-4 rounded-lg border bg-muted/30 px-3.5 py-2.5">
                        <span class="text-muted-foreground">IP address</span>
                        <span class="font-mono font-medium">{{ activity.ip ?? '—' }}</span>
                    </div>
                    <div class="flex items-start justify-between gap-4 rounded-lg border bg-muted/30 px-3.5 py-2.5">
                        <span class="flex shrink-0 items-center gap-1.5 text-muted-foreground">
                            <Monitor class="size-3.5" />
                            User agent
                        </span>
                        <span class="break-all text-right text-xs">{{ activity.user_agent ?? '—' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-4 rounded-lg border bg-muted/30 px-3.5 py-2.5">
                        <span class="text-muted-foreground">Log</span>
                        <span class="rounded-md border bg-background px-2 py-0.5 text-xs font-medium">
                            {{ activity.log_name ?? 'default' }}
                        </span>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <div class="flex items-center gap-2">
                        <ScrollText class="size-4 text-primary" />
                        <CardTitle class="text-base">Raw properties</CardTitle>
                    </div>
                    <CardDescription>Additional structured metadata stored with the entry.</CardDescription>
                </CardHeader>
                <CardContent>
                    <pre
                        class="max-h-64 overflow-auto rounded-lg border bg-muted/40 p-3.5 font-mono text-xs leading-relaxed"
                    >{{ JSON.stringify(extraProperties, null, 2) }}</pre>
                </CardContent>
            </Card>
        </div>
    </AdminLayout>
</template>
