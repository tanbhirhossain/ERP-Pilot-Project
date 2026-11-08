<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import ProductForm from '../../Components/ProductForm.vue';

defineProps<{
    product: any;
    categoryOptions: any[];
    brandOptions: any[];
    uomOptions: any[];
    warehouseOptions: any[];
}>();
</script>

<template>
    <AdminLayout
        :breadcrumbs="[{ title: 'Inventory' }, { title: 'Products', href: route('inventory.products.index') }, { title: product.sku }, { title: 'Edit' }]"
    >
        <Head :title="`Edit ${product.sku}`" />

        <ProductForm
            mode="edit"
            :product="product"
            :category-options="categoryOptions"
            :brand-options="brandOptions"
            :uom-options="uomOptions"
            :warehouse-options="warehouseOptions"
            :submit-url="route('inventory.products.update', product.id)"
            :index-url="route('inventory.products.index')"
        />
    </AdminLayout>
</template>
