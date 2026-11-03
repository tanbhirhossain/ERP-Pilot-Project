<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
defineProps<{ userOptions:any[] }>();
const form=useForm({ name:'', code:'', short_code:'', type:'main', address_line:'', city:'', district:'', division:'', phone:'', email:'', manager_user_id:null as number|null, is_default:false, is_active:true, allow_negative_stock:false, notes:'' });
function submit(){ form.post(route('inventory.warehouses.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Warehouses',href:route('inventory.warehouses.index')},{title:'Create'}]">
    <Head title="Create Warehouse" />
    <PageHeader title="Add Warehouse" description="Complete the form — required *" :icon="Package">
    <div class="max-w-2xl mx-auto">
      <h1 class="text-xl font-bold mb-4">Add Warehouse</h1>
      <form @submit.prevent="submit" class="bg-card border rounded-2xl shadow-sm p-4 space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Name *</Label><Input v-model="form.name" placeholder="Main Warehouse"/></div><div class="space-y-2"><Label>Code *</Label><Input v-model="form.code" placeholder="WH-001"/></div></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Type *</Label><select v-model="form.type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="main">Main</option><option value="sub">Sub</option><option value="transit">Transit</option><option value="virtual">Virtual</option></select></div><div class="space-y-2"><Label>Manager</Label><SearchableSelect v-model="form.manager_user_id" :options="userOptions" placeholder="Select manager" clearable /></div></div>
        <div class="space-y-2"><Label>Address</Label><Input v-model="form.address_line"/></div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4"><div class="space-y-2"><Label>City</Label><Input v-model="form.city"/></div><div class="space-y-2"><Label>District</Label><Input v-model="form.district"/></div><div class="space-y-2"><Label>Division</Label><Input v-model="form.division"/></div></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Phone</Label><Input v-model="form.phone"/></div><div class="space-y-2"><Label>Email</Label><Input v-model="form.email" type="email"/></div></div>
        <div class="space-y-2"><Label>Notes</Label><Textarea v-model="form.notes" rows="2"/></div>
        <div class="flex gap-4"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_default" class="rounded"/> Default</label><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.allow_negative_stock" class="rounded"/> Allow Negative</label></div>
        <Button type="submit" :disabled="form.processing">Create</Button>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
