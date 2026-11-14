<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import RequisitionForm from '../../Components/RequisitionForm.vue';

defineProps<{ requisition: any; canApprove?: boolean }>();
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Requisitions', href: route('purchase.requisitions.index') }, { title: requisition.req_no }]">
        <Head :title="`Edit ${requisition.req_no}`" />
        <RequisitionForm
            mode="edit"
            :requisition="requisition"
            :submit-url="route('purchase.requisitions.update', requisition.id)"
            :index-url="route('purchase.requisitions.index')"
        />
    </AdminLayout>
</template>
