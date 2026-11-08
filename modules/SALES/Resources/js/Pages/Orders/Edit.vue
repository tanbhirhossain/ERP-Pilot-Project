<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SalesOrderForm from '../../Components/SalesOrderForm.vue';

const props = defineProps<{ order: any }>();
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: order.order_no }, { title: 'Edit' }]">
        <Head :title="`Edit ${order.order_no}`" />
        <SalesOrderForm
            mode="edit"
            :order="props.order"
            :submit-url="route('sales.orders.update', props.order.id)"
            :index-url="route('sales.orders.index')"
        />
    </AdminLayout>
</template>
