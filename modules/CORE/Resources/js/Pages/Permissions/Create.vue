<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Lightbulb, LoaderCircle, Save } from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import InputError from '@/components/InputError.vue';
import AdminLayout from '../../Layouts/AdminLayout.vue';

const form = useForm({
    name: '',
});

const previewGroup = computed(() => {
    const segments = form.name.split('.');
    return segments.length > 1 ? segments[0] : 'general';
});

function submit() {
    form.post(route('core.permissions.store'), {
        preserveScroll: true,
    });
}
</script>

<template>
    <AdminLayout
        :breadcrumbs="[
            { title: 'Access Control', href: route('core.permissions.index') },
            { title: 'Permissions', href: route('core.permissions.index') },
            { title: 'New permission' },
        ]"
    >
        <Head title="New permission" />

        <div class="flex items-center gap-3 print:hidden">
            <Link
                :href="route('core.permissions.index')"
                class="inline-flex size-9 items-center justify-center rounded-lg border text-muted-foreground transition hover:bg-accent hover:text-accent-foreground"
                aria-label="Back to permissions"
            >
                <ArrowLeft class="size-4" />
            </Link>
            <div>
                <h1 class="text-xl font-bold tracking-tight">Create permission</h1>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    Define one atomic capability the system can check.
                </p>
            </div>
        </div>

        <form class="max-w-3xl space-y-6" @submit.prevent="submit">
            <Card>
                <CardHeader>
                    <CardTitle>Permission</CardTitle>
                    <CardDescription>
                        Follow the <code class="rounded bg-muted px-1.5 py-0.5 text-xs">resource.action</code> convention —
                        e.g. <code class="rounded bg-muted px-1.5 py-0.5 text-xs">reports.export</code>.
                    </CardDescription>
                </CardHeader>
                <CardContent class="space-y-5">
                    <div class="space-y-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            placeholder="resource.action"
                            class="font-mono"
                            autofocus
                            required
                        />
                        <InputError :message="form.errors.name" />
                    </div>

                    <div class="flex items-start gap-2.5 rounded-lg border border-sky-500/30 bg-sky-500/5 p-3.5 text-sm">
                        <Lightbulb class="mt-0.5 size-4 shrink-0 text-sky-600 dark:text-sky-400" />
                        <div>
                            <p class="font-medium">Derived group: <strong class="font-semibold">{{ previewGroup }}</strong></p>
                            <p class="mt-0.5 text-xs text-muted-foreground">
                                The group is derived automatically from the prefix before the dot — it powers the
                                permission matrix and list filters.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div class="flex items-center justify-end gap-3 print:hidden">
                <Link :href="route('core.permissions.index')">
                    <Button type="button" variant="outline">Cancel</Button>
                </Link>
                <Button type="submit" :disabled="form.processing" class="shadow-md shadow-primary/20">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    <Save v-else class="size-4" />
                    Create permission
                </Button>
            </div>
        </form>
    </AdminLayout>
</template>
