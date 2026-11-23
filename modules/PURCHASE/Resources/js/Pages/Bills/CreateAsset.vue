<script setup lang="ts">
/**
 * Fixed Asset Purchase — the asset door of the vendor bill.
 *
 * Same bill underneath, locked to asset lines: every line names its class and capitalises —
 * one register row per unit — the moment the bill posts. No GRN ever appears here, because
 * assets never arrive through a stock receipt.
 */
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import VendorBillForm from '../../Components/VendorBillForm.vue';

defineProps<{ order?: any; receipt?: any }>();
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Vendor Bills', href: route('purchase.bills.index') }, { title: 'Fixed Asset Purchase' }]">
        <Head title="New Fixed Asset Purchase" />
        <VendorBillForm
            mode="create"
            :order="order"
            :receipt="receipt"
            :submit-url="route('purchase.bills.store')"
            :index-url="route('purchase.bills.index')"
            lock-line-type="fixed_asset"
            form-title="New Fixed Asset Purchase"
            form-description="Furniture, equipment, vehicles — bought on a purchase order or direct. Posting capitalises every line into the asset register."
        />
    </AdminLayout>
</template>
