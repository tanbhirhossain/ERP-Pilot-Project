<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { VendorBill } from '@purchase/types';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import VendorBillForm from '../../Components/VendorBillForm.vue';

const props = defineProps<{ bill: VendorBill }>();
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: bill.bill_no }, { title: 'Vendor Bills', href: route('purchase.bills.index') }, { title: 'Edit' }]">
        <Head :title="`Edit ${bill.bill_no}`" />
        <VendorBillForm
            mode="edit"
            :bill="props.bill"
            :submit-url="route('purchase.bills.update', props.bill.id)"
            :index-url="route('purchase.bills.index')"
        />
    </AdminLayout>
</template>
