<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
const form=useForm({name:'',device_name:'',ip_address:'',port:4370,serial_number:'',device_model:'',location:'',status:'Active',is_enabled:true,auto_sync_enabled:false,sync_interval_minutes:15});
function submit(){ form.post(route('hrm.devices.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Devices',href:route('hrm.devices.index')},{title:'Create'}]">
    <Head title="Add Biometric Device"/><div class="max-w-2xl mx-auto bg-card border rounded-xl p-6"><h1 class="text-lg font-bold mb-4">Add Biometric Device</h1>
    <form @submit.prevent="submit" class="space-y-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="space-y-2"><Label>Name *</Label><Input v-model="form.name" placeholder="Main Gate ZK-T50"/><p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p></div>
        <div class="space-y-2"><Label>Device Model</Label><Input v-model="form.device_model" placeholder="ZK-T50"/></div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="space-y-2"><Label>IP Address *</Label><Input v-model="form.ip_address" placeholder="192.168.1.100"/><p v-if="form.errors.ip_address" class="text-sm text-destructive">{{ form.errors.ip_address }}</p></div>
        <div class="space-y-2"><Label>Port *</Label><Input v-model.number="form.port" type="number"/><p v-if="form.errors.port" class="text-sm text-destructive">{{ form.errors.port }}</p></div>
      </div>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="space-y-2"><Label>Serial Number</Label><Input v-model="form.serial_number"/></div>
        <div class="space-y-2"><Label>Location</Label><Input v-model="form.location" placeholder="Building A, Floor 1"/></div>
      </div>
      <div class="space-y-2"><Label>Status</Label><select v-model="form.status" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option>Active</option><option>Inactive</option><option>Maintenance</option></select></div>
      <div class="flex flex-wrap gap-4">
        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.is_enabled"/><span class="text-sm">Enabled</span></label>
        <label class="flex items-center gap-2"><input type="checkbox" v-model="form.auto_sync_enabled"/><span class="text-sm">Auto Sync</span></label>
      </div>
      <div class="space-y-2" v-if="form.auto_sync_enabled"><Label>Sync Interval (minutes)</Label><Input v-model.number="form.sync_interval_minutes" type="number" min="1"/></div>
      <Button type="submit" :disabled="form.processing">Add Device</Button>
    </form></div>
  </AdminLayout>
</template>
