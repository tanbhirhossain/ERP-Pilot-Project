<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
defineProps<{ parentOptions:any[] }>();
const form=useForm({ name:'', code:'', parent_id:null as number|null, description:'', sort_order:0, is_active:true });
function submit(){ form.post(route('inventory.categories.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Categories', href: route('inventory.categories.index') }, { title: 'Create' }]">
    <Head title="Create Category" />
    <PageHeader title="Add Category" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl">
      <form @submit.prevent="submit" class="bg-card border rounded-xl shadow-sm p-4 space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <div class="space-y-2"><Label>Name *</Label><Input v-model="form.name" placeholder="Fabric"/></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><div class="space-y-2"><Label>Code</Label><Input v-model="form.code" placeholder="FAB"/></div><div class="space-y-2"><Label>Parent</Label><SearchableSelect v-model="form.parent_id" :options="parentOptions" placeholder="Top level" clearable /></div></div>
        <div class="space-y-2"><Label>Description</Label><Textarea v-model="form.description" rows="3"/></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><div class="space-y-2"><Label>Sort</Label><Input v-model.number="form.sort_order" type="number"/></div><label class="flex items-center gap-2 text-sm pt-6"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label></div>
        <Button type="submit" :disabled="form.processing">Create</Button>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
