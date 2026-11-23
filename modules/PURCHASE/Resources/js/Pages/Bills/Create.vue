<script setup lang="ts">
/**
 * Inventory Purchase — the goods door of the vendor bill.
 *
 * The form locks to goods lines here, so this page reads exactly like the bill entry it
 * always was. Asset orders that land here get a cross-link, not a lecture.
 */
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import VendorBillForm from '../../Components/VendorBillForm.vue';

defineProps<{ order?: any; receipt?: any }>();
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Vendor Bills', href: route('purchase.bills.index') }, { title: 'Inventory Purchase' }]">
        <Head title="New Inventory Purchase" />
        <VendorBillForm
            mode="create"
            :order="order"
            :receipt="receipt"
            :submit-url="route('purchase.bills.store')"
            :index-url="route('purchase.bills.index')"
            lock-line-type="goods"
            form-title="New Inventory Purchase"
            form-description="Stock and consumables, billed against a GRN, a purchase order, or nothing at all."
        />
    </AdminLayout>
</template>
