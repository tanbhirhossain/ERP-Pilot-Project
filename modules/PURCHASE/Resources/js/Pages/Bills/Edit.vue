<script setup lang="ts">
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import type { VendorBill } from '@purchase/types';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import VendorBillForm from '../../Components/VendorBillForm.vue';

const props = defineProps<{ bill: VendorBill }>();

/**
 * Edit keeps the bill on the page it was born on: an all-asset bill stays locked to asset
 * lines, an all-goods bill to goods. Only a mixed bill — which neither create page can make —
 * keeps the per-line toggle, so it stays editable.
 */
const lockLineType = computed<'goods' | 'fixed_asset' | null>(() => {
    const types = new Set((props.bill.items ?? []).map((it: any) => it.line_type ?? 'goods'));
    if (types.size === 1) return types.has('fixed_asset') ? 'fixed_asset' : 'goods';

    return null;
});
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: bill.bill_no }, { title: 'Vendor Bills', href: route('purchase.bills.index') }, { title: 'Edit' }]">
        <Head :title="`Edit ${bill.bill_no}`" />
        <VendorBillForm
            mode="edit"
            :bill="props.bill"
            :submit-url="route('purchase.bills.update', props.bill.id)"
            :index-url="route('purchase.bills.index')"
            :lock-line-type="lockLineType"
        />
    </AdminLayout>
</template>
