<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import InvoiceForm from '../../Components/InvoiceForm.vue';

defineProps<{ order?: any }>();
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'Invoices', href: route('sales.invoices.index') }, { title: 'New Invoice' }]">
        <Head title="New Invoice" />
        <InvoiceForm
            mode="create"
            :order="order"
            :submit-url="route('sales.invoices.store')"
            :index-url="route('sales.invoices.index')"
        />
    </AdminLayout>
</template>
