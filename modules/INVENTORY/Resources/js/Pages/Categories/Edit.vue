<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { Category } from '@inventory/types';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
const props=defineProps<{ category:Category; parentOptions:SelectOption[] }>();
const form=useForm({ name:props.category.name, code:props.category.code??'', parent_id:props.category.parent_id, description:props.category.description??'', sort_order:props.category.sort_order??0, is_active:props.category.is_active });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.put(route('inventory.categories.update', props.category.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Categories', href: route('inventory.categories.index') }, { title: category.name }, { title: 'Edit' }]"><Head :title="`Edit ${category.name}`"/><PageHeader title="Edit {{ category.name }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="bg-card border rounded-xl shadow-sm p-4 space-y-4"><div class="space-y-2"><Label>Name</Label><Input v-model="form.name"/></div><div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><div class="space-y-2"><Label>Code</Label><Input v-model="form.code"/></div><div class="space-y-2"><Label>Parent</Label><SearchableSelect v-model="form.parent_id" :options="parentOptions" placeholder="Top" clearable /></div></div><Button type="submit">Update</Button></form></div>    </PageHeader>
  </AdminLayout>
</template>
