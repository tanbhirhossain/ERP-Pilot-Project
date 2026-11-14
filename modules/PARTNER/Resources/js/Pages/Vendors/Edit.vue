<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { Partner } from '@partner/types';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PartnerForm from '../../Components/PartnerForm.vue';

defineProps<{ partner: Partner; groupOptions: any[]; priceListOptions: any[]; userOptions: any[] }>();
</script>

<template>
    <AdminLayout
        :breadcrumbs="[{ title: 'Ven & Cust' }, { title: 'Vendors', href: route('partner.vendors.index') }, { title: partner.partner_code }, { title: 'Edit' }]"
    >
        <Head :title="`Edit Vendor`" />

        <PartnerForm
            mode="edit"
            :partner="partner"
            :default-type="partner.type ?? 'vendor'"
            :group-options="groupOptions"
            :price-list-options="priceListOptions"
            :user-options="userOptions"
            :submit-url="route('partner.vendors.update', partner.id)"
            :index-url="route('partner.vendors.index')"
            entity-label="Vendor"
        />
    </AdminLayout>
</template>
