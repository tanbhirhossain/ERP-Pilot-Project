<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
const props=defineProps<{ device:{id:number;name:string;device_name?:string;ip_address:string;port:number;serial_number?:string;device_model?:string;location?:string;status:string;is_enabled:boolean;auto_sync_enabled:boolean;sync_interval_minutes:number} }>();
const form=useForm({name:props.device.name,device_name:props.device.device_name||'',ip_address:props.device.ip_address,port:props.device.port,serial_number:props.device.serial_number||'',device_model:props.device.device_model||'',location:props.device.location||'',status:props.device.status,is_enabled:props.device.is_enabled,auto_sync_enabled:props.device.auto_sync_enabled,sync_interval_minutes:props.device.sync_interval_minutes});
function submit(){ form.put(route('hrm.devices.update',props.device.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Devices',href:route('hrm.devices.index')},{title:'Edit'}]">
    <Head title="Edit Device"/><div class="max-w-2xl mx-auto bg-card border rounded-xl p-6"><h1 class="text-lg font-bold mb-4">Edit Device</h1>
    <form @submit.prevent="submit" class="space-y-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Name *</Label><Input v-model="form.name"/></div><div class="space-y-2"><Label>Device Model</Label><Input v-model="form.device_model"/></div></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>IP Address *</Label><Input v-model="form.ip_address"/><p v-if="form.errors.ip_address" class="text-sm text-destructive">{{ form.errors.ip_address }}</p></div><div class="space-y-2"><Label>Port *</Label><Input v-model.number="form.port" type="number"/></div></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Serial</Label><Input v-model="form.serial_number"/></div><div class="space-y-2"><Label>Location</Label><Input v-model="form.location"/></div></div>
      <div class="space-y-2"><Label>Status</Label><select v-model="form.status" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>Active</option><option>Inactive</option><option>Maintenance</option></select></div>
      <div class="flex gap-4"><label class="flex items-center gap-2"><input type="checkbox" v-model="form.is_enabled"/><span class="text-sm">Enabled</span></label><label class="flex items-center gap-2"><input type="checkbox" v-model="form.auto_sync_enabled"/><span class="text-sm">Auto Sync</span></label></div>
      <div v-if="form.auto_sync_enabled" class="space-y-2"><Label>Sync Interval</Label><Input v-model.number="form.sync_interval_minutes" type="number"/></div>
      <Button type="submit" :disabled="form.processing">Update Device</Button>
    </form></div>
  </AdminLayout>
</template>
