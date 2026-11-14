<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import ProductForm from '../../Components/ProductForm.vue';

defineProps<{ categoryOptions: any[]; brandOptions: any[]; uomOptions: any[]; warehouseOptions: any[] }>();
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Products', href: route('inventory.products.index') }, { title: 'Create' }]">
        <Head title="Create Product" />

        <ProductForm
            mode="create"
            :category-options="categoryOptions"
            :brand-options="brandOptions"
            :uom-options="uomOptions"
            :warehouse-options="warehouseOptions"
            :submit-url="route('inventory.products.store')"
            :index-url="route('inventory.products.index')"
        />
    </AdminLayout>
</template>
