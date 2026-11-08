<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import QuotationForm from '../../Components/QuotationForm.vue';

const props = defineProps<{ quotation: any }>();
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Sales & POS' }, { title: quotation.quotation_no }, { title: 'Quotations', href: route('sales.quotations.index') }, { title: 'Edit' }]">
        <Head :title="`Edit ${quotation.quotation_no}`" />
        <QuotationForm
            mode="edit"
            :quotation="props.quotation"
            :submit-url="route('sales.quotations.update', props.quotation.id)"
            :index-url="route('sales.quotations.index')"
        />
    </AdminLayout>
</template>
