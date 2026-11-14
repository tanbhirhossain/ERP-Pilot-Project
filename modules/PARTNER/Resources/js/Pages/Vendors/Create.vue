<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PartnerForm from '../../Components/PartnerForm.vue';

defineProps<{ groupOptions: any[]; priceListOptions: any[]; userOptions: any[]; defaultType: string }>();
</script>

<template>
    <AdminLayout
        :breadcrumbs="[{ title: 'Ven & Cust' }, { title: 'Vendors', href: route('partner.vendors.index') }, { title: 'Create' }]"
    >
        <Head :title="`Create Vendor`" />

        <PartnerForm
            mode="create"
            :default-type="defaultType"
            :group-options="groupOptions"
            :price-list-options="priceListOptions"
            :user-options="userOptions"
            :submit-url="route('partner.vendors.store')"
            :index-url="route('partner.vendors.index')"
            entity-label="Vendor"
        />
    </AdminLayout>
</template>
