<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
defineProps<{ warehouseOptions:any[] }>();
const form=useForm({ warehouse_id:null as number|null, notes:'', items:[{ product_id:null as number|null, system_qty:null as number|null, counted_qty:null as number|null }] });
function submit(){ form.post(route('inventory.counts.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Counts',href:route('inventory.counts.index')},{title:'Create'}]"><Head title="Create Count"/><PageHeader title="New Physical Count" description="Complete the form — required *" :icon="Package">
    <div class="max-w-2xl mx-auto"><h1 class="text-xl font-bold mb-4">New Physical Count</h1><form @submit.prevent="submit" class="bg-card border rounded-2xl shadow-sm p-4 space-y-4"><div class="space-y-2"><Label>Warehouse *</Label><SearchableSelect v-model="form.warehouse_id" :options="warehouseOptions" placeholder="Select warehouse"/></div><Button type="submit">Create</Button></form></div>    </PageHeader>
  </AdminLayout>
</template>
