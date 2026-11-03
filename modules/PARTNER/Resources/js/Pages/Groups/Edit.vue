<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props = defineProps<{ group:any; parentOptions:any[] }>();
const form = useForm({ name: props.group.name, code: props.group.code ?? '', type: props.group.type, parent_id: props.group.parent_id, description: props.group.description ?? '', sort_order: props.group.sort_order ?? 0, is_active: props.group.is_active });
function submit(){ form.put(route('partner.groups.update', props.group.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Groups',href:route('partner.groups.index')},{title:group.name},{title:'Edit'}]">
    <Head :title="`Edit ${group.name}`" />
    <PageHeader title="Edit {{ group.name }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-2xl mx-auto">
      <h1 class="text-xl font-bold mb-4">Edit {{ group.name }}</h1>
      <form @submit.prevent="submit" class="bg-card border rounded-2xl shadow-sm p-4 space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <div class="space-y-2"><Label>Name *</Label><Input v-model="form.name"/></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Code</Label><Input v-model="form.code"/></div><div class="space-y-2"><Label>Type</Label><select v-model="form.type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="vendor">Vendor</option><option value="customer">Customer</option><option value="both">Both</option></select></div></div>
        <div class="space-y-2"><Label>Parent</Label><SearchableSelect v-model="form.parent_id" :options="parentOptions" placeholder="Top level" clearable /></div>
        <div class="space-y-2"><Label>Description</Label><Textarea v-model="form.description" rows="3"/></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Sort Order</Label><Input v-model.number="form.sort_order" type="number"/></div><label class="flex items-center gap-2 text-sm pt-6"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label></div>
        <Button type="submit" :disabled="form.processing">Update Group</Button>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
