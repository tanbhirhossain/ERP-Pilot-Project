<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import VendorBillForm from '../../Components/VendorBillForm.vue';

defineProps<{ order?: any; receipt?: any }>();
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Vendor Bills', href: route('purchase.bills.index') }, { title: 'New Bill' }]">
        <Head title="New Vendor Bill" />
        <VendorBillForm
            mode="create"
            :order="order"
            :receipt="receipt"
            :submit-url="route('purchase.bills.store')"
            :index-url="route('purchase.bills.index')"
        />
    </AdminLayout>
</template>
