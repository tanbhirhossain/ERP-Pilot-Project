<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
defineProps<{ count:any }>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Physical Counts', href: route('inventory.counts.index') }, { title: count.count_no }]"><Head :title="count.count_no"/><PageHeader title="Pages Detail" description="{{ count.warehouse?.name }}" :icon="FileText">
    <div class="space-y-4"><div class="bg-card border rounded-xl shadow-sm p-4"><h1 class="text-xl font-bold">{{ count.count_no }} — {{ count.status }}</h1><p class="text-sm text-muted-foreground">{{ count.warehouse?.name }}</p></div><div class="bg-card border rounded-xl shadow-sm p-4"><h3 class="font-semibold mb-2">Items</h3><div v-for="it in count.items" :key="it.id" class="flex justify-between text-sm border-b py-1"><span>{{ it.product?.name || '#'+it.product_id }}</span><span class="font-mono">System: {{ it.system_qty }} · Counted: {{ it.counted_qty }} · Variance: {{ it.variance_qty }}</span></div></div></div>    </PageHeader>
  </AdminLayout>
</template>
