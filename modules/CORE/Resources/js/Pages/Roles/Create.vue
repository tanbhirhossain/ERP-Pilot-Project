<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle, Save } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import PermissionGrid from '../../Components/PermissionGrid.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import type { SelectOption } from '../../types';

interface Props {
    permissionOptions: SelectOption[];
}

defineProps<Props>();

const form = useForm({
    name: '',
    permissions: [] as number[],
});

function submit() {
    form.post(route('core.roles.store'), {
        preserveScroll: true,
    });
}
</script>

<template>
    <AdminLayout
        :breadcrumbs="[
            { title: 'Access Control', href: route('core.roles.index') },
            { title: 'Roles', href: route('core.roles.index') },
            { title: 'New role' },
        ]"
    >
        <Head title="New role" />

        <div class="flex items-center gap-3 print:hidden">
            <Link
                :href="route('core.roles.index')"
                class="inline-flex size-9 items-center justify-center rounded-lg border text-muted-foreground transition hover:bg-accent hover:text-accent-foreground"
                aria-label="Back to roles"
            >
                <ArrowLeft class="size-4" />
            </Link>
            <div>
                <h1 class="text-xl font-bold tracking-tight">Create role</h1>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    Name the profile, then grant the exact permission set it needs.
                </p>
            </div>
        </div>

        <form class="max-w-5xl space-y-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>Role details</CardTitle>
                    <CardDescription>Roles are unique per guard (web).</CardDescription>
                </CardHeader>
                <CardContent class="max-w-md space-y-2">
                    <Label for="name">Role name</Label>
                    <Input id="name" v-model="form.name" placeholder="e.g. Compliance Officer" autofocus required />
                    <InputError :message="form.errors.name" />
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Permissions</CardTitle>
                    <CardDescription>Select the capabilities this role grants — group checkboxes select all.</CardDescription>
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
                    Create role
                </Button>
            </div>
        </form>
    </AdminLayout>
</template>
