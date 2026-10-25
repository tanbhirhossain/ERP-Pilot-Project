<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle, Save, Users } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import PermissionGrid from '../../Components/PermissionGrid.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import type { SelectOption } from '../../types';
import { formatNumber } from '../../Utils/format';

interface Props {
    role: {
        id: number;
        name: string;
        users_count: number;
        permissions: number[];
        created_at: string | null;
    };
    permissionOptions: SelectOption[];
}

const props = defineProps<Props>();

const form = useForm({
    name: props.role.name,
    permissions: [...props.role.permissions] as number[],
});

function submit() {
    form.put(route('core.roles.update', props.role.id), {
        preserveScroll: true,
    });
}
</script>

<template>
    <AdminLayout
        :breadcrumbs="[
            { title: 'Access Control', href: route('core.roles.index') },
            { title: 'Roles', href: route('core.roles.index') },
            { title: role.name },
        ]"
    >
        <Head :title="`Edit ${role.name}`" />

        <div class="flex items-center gap-3 print:hidden">
            <Link
                :href="route('core.roles.index')"
                class="inline-flex size-9 items-center justify-center rounded-lg border text-muted-foreground transition hover:bg-accent hover:text-accent-foreground"
                aria-label="Back to roles"
            >
                <ArrowLeft class="size-4" />
            </Link>
            <div class="min-w-0">
                <h1 class="flex flex-wrap items-center gap-2 text-xl font-bold tracking-tight">
                    {{ role.name }}
                    <span class="inline-flex items-center gap-1 rounded-full border bg-muted/60 px-2 py-0.5 text-[11px] font-medium text-muted-foreground">
                        <Users class="size-3" />
                        {{ formatNumber(role.users_count) }} member{{ role.users_count === 1 ? '' : 's' }}
                    </span>
                </h1>
                <p class="mt-0.5 text-sm text-muted-foreground">Adjust the permission matrix for this profile.</p>
            </div>
        </div>

        <form class="max-w-5xl space-y-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>Role details</CardTitle>
                    <CardDescription>Renames are recorded in the audit trail with before/after values.</CardDescription>
                </CardHeader>
                <CardContent class="max-w-md space-y-2">
                    <Label for="name">Role name</Label>
                    <Input id="name" v-model="form.name" required autofocus />
                    <InputError :message="form.errors.name" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Permissions</CardTitle>
                    <CardDescription>Changes to this matrix are logged as a dedicated audit event.</CardDescription>
                </CardHeader>
                <CardContent>
                    <PermissionGrid v-model="form.permissions" :options="permissionOptions" />
                    <InputError :message="form.errors.permissions" class="mt-2" />
                </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3 print:hidden">
                <Link :href="route('core.roles.index')">
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
