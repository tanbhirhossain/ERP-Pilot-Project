<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, LoaderCircle, Save } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import SearchableSelect from '../../Components/SearchableSelect.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import type { SelectOption } from '../../types';

interface Props {
    roleOptions: SelectOption[];
}

defineProps<Props>();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    roles: [] as number[],
    verified: false,
});

function submit() {
    form.post(route('core.users.store'), {
        preserveScroll: true,
        onFinish: () => form.clearErrors(),
    });
}
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Access Control', href: route('core.users.index') }, { title: 'Users', href: route('core.users.index') }, { title: 'New user' }]">
        <Head title="New user" />

        <div class="flex items-center gap-3 print:hidden">
            <Link
                :href="route('core.users.index')"
                class="inline-flex size-9 items-center justify-center rounded-lg border text-muted-foreground transition hover:bg-accent hover:text-accent-foreground"
                aria-label="Back to users"
            >
                <ArrowLeft class="size-4" />
            </Link>
            <div>
                <h1 class="text-xl font-bold tracking-tight">Create user</h1>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    Onboard a new identity and assign its access profile.
                </p>
            </div>
        </div>

        <form class="max-w-3xl space-y-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>Identity</CardTitle>
                    <CardDescription>Core profile details for the new account.</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-5 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="name">Full name</Label>
                        <Input id="name" v-model="form.name" placeholder="Jane Cooper" autofocus required />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="space-y-2">
                        <Label for="email">Email address</Label>
                        <Input id="email" v-model="form.email" type="email" placeholder="jane@company.com" required />
                        <InputError :message="form.errors.email" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Security</CardTitle>
                    <CardDescription>Initial password — the user can change it later from settings.</CardDescription>
                </CardHeader>
                <CardContent class="grid gap-5 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="password">Password</Label>
                        <Input id="password" v-model="form.password" type="password" placeholder="At least 8 characters" required />
                        <InputError :message="form.errors.password" />
                    </div>
                    <div class="space-y-2">
                        <Label for="password_confirmation">Confirm password</Label>
                        <Input id="password_confirmation" v-model="form.password_confirmation" type="password" placeholder="Repeat the password" required />
                        <InputError :message="form.errors.password_confirmation" />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Access</CardTitle>
                    <CardDescription>Roles decide which permissions this account inherits.</CardDescription>
                </CardHeader>
                <CardContent class="space-y-5">
                    <div class="space-y-2">
                        <Label>Roles</Label>
                        <SearchableSelect
                            v-model="form.roles"
                            :options="roleOptions"
                            multiple
                            placeholder="Assign one or more roles…"
                            search-placeholder="Search roles…"
                        />
                        <InputError :message="form.errors.roles" />
                    </div>

                    <label class="flex cursor-pointer items-center gap-2.5 rounded-lg border bg-muted/30 px-3 py-2.5 text-sm transition hover:bg-muted/60">
                        <Checkbox v-model="form.verified" />
                        <span>
                            <span class="font-medium">Email verified</span>
                            <span class="block text-xs text-muted-foreground">
                                Mark the email address as verified immediately.
                            </span>
                        </span>
                    </label>
                </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3 print:hidden">
                <Link :href="route('core.users.index')">
                    <Button type="button" variant="outline">Cancel</Button>
                </Link>
                <Button type="submit" :disabled="form.processing" class="shadow-md shadow-primary/20">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    <Save v-else class="size-4" />
                    Create user
                </Button>
            </div>
        </form>
    </AdminLayout>
</template>
