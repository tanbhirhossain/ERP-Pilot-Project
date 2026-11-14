<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import RequisitionForm from '../../Components/RequisitionForm.vue';

defineProps<{ canApprove?: boolean }>();
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Requisitions', href: route('purchase.requisitions.index') }, { title: 'New' }]">
        <Head title="New Requisition" />
        <RequisitionForm mode="create" :submit-url="route('purchase.requisitions.store')" :index-url="route('purchase.requisitions.index')" />
    </AdminLayout>
</template>
