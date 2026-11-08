<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SalesOrderForm from '../../Components/SalesOrderForm.vue';
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: 'New Order' }]">
        <Head title="New Sales Order" />
        <SalesOrderForm
            mode="create"
            :submit-url="route('sales.orders.store')"
            :index-url="route('sales.orders.index')"
        />
    </AdminLayout>
</template>
