<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle, Save, Shield } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import { formatDate } from '../../Utils/format';

interface Props {
    permission: {
        id: number;
        name: string;
        group: string;
        guard_name: string;
        roles: { id: number; name: string }[];
        created_at: string | null;
    };
}

const props = defineProps<Props>();

const form = useForm({
    name: props.permission.name,
});

function submit() {
    form.put(route('core.permissions.update', props.permission.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <AdminLayout
        :breadcrumbs="[
            { title: 'Access Control', href: route('core.permissions.index') },
            { title: 'Permissions', href: route('core.permissions.index') },
            { title: permission.name },
        ]"
    >
        <Head :title="`Edit ${permission.name}`" />

        <div class="flex items-center gap-3 print:hidden">
            <Link
                :href="route('core.permissions.index')"
                class="inline-flex size-9 items-center justify-center rounded-lg border text-muted-foreground transition hover:bg-accent hover:text-accent-foreground"
                aria-label="Back to permissions"
            >
                <ArrowLeft class="size-4" />
            </Link>
            <div class="min-w-0">
                <h1 class="flex flex-wrap items-center gap-2 text-xl font-bold tracking-tight">
                    <code class="rounded-md bg-muted px-2 py-1 font-mono text-base">{{ permission.name }}</code>
                    <span class="rounded-full border bg-muted/60 px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                        {{ permission.guard_name }}
                    </span>
                </h1>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    Group: {{ permission.group }} · created {{ formatDate(permission.created_at) }}
                </p>
            </div>
        </div>

        <form class="max-w-3xl space-y-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>Permission</CardTitle>
                    <CardDescription>
                        Renaming keeps existing role / user assignments (pivots reference ids).
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-2">
                    <Label for="name">Name</Label>
                    <Input
                        id="name"
                        v-model="form.name"
                        class="font-mono"
                        autofocus
                        required
                    />
                    <InputError :message="form.errors.name" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Assigned roles</CardTitle>
                    <CardDescription>Roles that currently include this capability.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="permission.roles.length" class="flex flex-wrap gap-2">
                        <span
                            v-for="role in permission.roles"
                            :key="role.id"
                            class="inline-flex items-center gap-1.5 rounded-md border border-violet-500/30 bg-violet-500/10 px-2.5 py-1 text-xs font-medium text-violet-600 dark:text-violet-400"
                        >
                            <Shield class="size-3" />
                            {{ role.name }}
                        </span>
                    </div>
                    <p v-else class="text-sm text-muted-foreground">
                        Not assigned to any role yet — it grants nothing until linked.
                    </p>
                </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3 print:hidden">
                <Link :href="route('core.permissions.index')">
                    <Button type="button" variant="outline">Cancel</Button>
                </Link>
                <Button type="submit" :disabled="form.processing" class="shadow-md shadow-primary/20">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    <Save v-else class="size-4" />
                    Save changes
                </Button>
            </div>
        </form>
    </AdminLayout>
</template>
