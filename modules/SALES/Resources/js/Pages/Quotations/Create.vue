<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import QuotationForm from '../../Components/QuotationForm.vue';
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'Quotations', href: route('sales.quotations.index') }, { title: 'New Quotation' }]">
        <Head title="New Quotation" />
        <QuotationForm
            mode="create"
            :submit-url="route('sales.quotations.store')"
            :index-url="route('sales.quotations.index')"
        />
    </AdminLayout>
</template>
