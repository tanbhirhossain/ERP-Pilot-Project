<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
defineProps<{ parentOptions:SelectOption[] }>();
const form=useForm({ name:'', code:'', parent_id:null as number|null, description:'', sort_order:0, is_active:true });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.post(route('inventory.categories.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Inventory' }, { title: 'Categories', href: route('inventory.categories.index') }, { title: 'Create' }]">
    <Head title="Create Category" />
    <PageHeader title="Add Category" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl">
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <FormSection title="Details" :cols="2">

        <FormField label="Name" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.name" placeholder="Fabric"/>
    </template>
</FormField>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Code">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.code" placeholder="FAB"/>
    </template>
</FormField><FormField label="Parent">
    <template #default="{ id, invalid }">
        <SearchableSelect :id="id" v-model="form.parent_id" :options="parentOptions" placeholder="Top level" clearable />
    </template>
</FormField></div>
        <FormField label="Description">
    <template #default="{ id, invalid }">
        <Textarea :id="id" :aria-invalid="invalid" v-model="form.description" rows="3"/>
    </template>
</FormField>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Sort">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model.number="form.sort_order" type="number"/>
    </template>
</FormField><label class="flex items-center gap-2 text-sm pt-6"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label></div>
        <FormActions submit-label="Create" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('inventory.categories.index')" />
          </FormSection>
    </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
