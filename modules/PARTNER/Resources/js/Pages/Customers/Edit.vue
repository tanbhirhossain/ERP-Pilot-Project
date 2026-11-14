<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { Partner } from '@partner/types';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PartnerForm from '../../Components/PartnerForm.vue';

defineProps<{ partner: Partner; groupOptions: any[]; priceListOptions: any[]; userOptions: any[] }>();
</script>

<template>
    <AdminLayout
        :breadcrumbs="[{ title: 'Ven & Cust' }, { title: 'Customers', href: route('partner.customers.index') }, { title: partner.partner_code }, { title: 'Edit' }]"
    >
        <Head :title="`Edit Customer`" />

        <PartnerForm
            mode="edit"
            :partner="partner"
            :default-type="partner.type ?? 'customer'"
            :group-options="groupOptions"
            :price-list-options="priceListOptions"
            :user-options="userOptions"
            :submit-url="route('partner.customers.update', partner.id)"
            :index-url="route('partner.customers.index')"
            entity-label="Customer"
        />
    </AdminLayout>
</template>
