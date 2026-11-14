<script setup lang="ts">
import PageHeader from '@core/Components/PageHeader.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import { Head } from '@inertiajs/vue3';
import { UserRound, Link2Off } from 'lucide-vue-next';
</script>

<template>
    <Head title="My HR" />
    <AdminLayout>
        <PageHeader title="My HR" description="Employee self-service." :icon="UserRound" />

        <div class="mt-6 grid place-items-center rounded-xl border border-dashed bg-card px-6 py-20 text-center">
            <span class="grid size-16 place-items-center rounded-2xl bg-muted"><Link2Off class="size-8 text-muted-foreground/50" /></span>
            <h2 class="mt-4 text-lg font-semibold">Your account isn't linked to an employee record</h2>
            <p class="mt-2 max-w-md text-[13px] leading-relaxed text-muted-foreground">
                Self-service shows your own payslips, leave and attendance. To enable it, ask your HR administrator to link your user account to your employee profile
                (Employees → edit → <span class="font-medium text-foreground">Linked user</span>). Once linked, this page will show your dashboard.
            </p>
        </div>
    </AdminLayout>
</template>
