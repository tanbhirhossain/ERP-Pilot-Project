<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props=defineProps<{ warehouse:any; userOptions:any[] }>();
const form=useForm({ name:props.warehouse.name, code:props.warehouse.code, type:props.warehouse.type, address_line:props.warehouse.address_line??'', city:props.warehouse.city??'', district:props.warehouse.district??'', phone:props.warehouse.phone??'', email:props.warehouse.email??'', manager_user_id:props.warehouse.manager_user_id, is_default:props.warehouse.is_default, is_active:props.warehouse.is_active, allow_negative_stock:props.warehouse.allow_negative_stock });
function submit(){ form.put(route('inventory.warehouses.update', props.warehouse.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Warehouses',href:route('inventory.warehouses.index')},{title:warehouse.code},{title:'Edit'}]"><Head :title="`Edit ${warehouse.code}`"/><PageHeader title="Edit {{ warehouse.code }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-2xl mx-auto"><h1 class="text-xl font-bold mb-4">Edit {{ warehouse.code }}</h1><form @submit.prevent="submit" class="bg-card border rounded-2xl shadow-sm p-4 space-y-4"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Name</Label><Input v-model="form.name"/></div><div class="space-y-2"><Label>Code</Label><Input v-model="form.code"/></div></div><div class="space-y-2"><Label>Manager</Label><SearchableSelect v-model="form.manager_user_id" :options="userOptions" placeholder="Select" clearable /></div><div class="flex gap-4"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_default" class="rounded"/> Default</label><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label></div><Button type="submit">Update</Button></form></div>    </PageHeader>
  </AdminLayout>
</template>
