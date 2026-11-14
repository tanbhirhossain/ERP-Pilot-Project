<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { Invoice } from '@sales/types';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import InvoiceForm from '../../Components/InvoiceForm.vue';

const props = defineProps<{ invoice: Invoice }>();
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: invoice.invoice_no }, { title: 'Invoices', href: route('sales.invoices.index') }, { title: 'Edit' }]">
        <Head :title="`Edit ${invoice.invoice_no}`" />
        <InvoiceForm
            mode="edit"
            :invoice="props.invoice"
            :submit-url="route('sales.invoices.update', props.invoice.id)"
            :index-url="route('sales.invoices.index')"
        />
    </AdminLayout>
</template>
