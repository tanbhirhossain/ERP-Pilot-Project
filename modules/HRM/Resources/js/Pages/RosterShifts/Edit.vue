<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
const props=defineProps<{ shift:{id:number;shift_name:string;start_time?:string;end_time?:string;punch_in_window_start?:string;punch_in_window_end?:string;crosses_midnight:boolean;max_duration_hours:number} }>();
const form=useForm({shift_name:props.shift.shift_name,start_time:props.shift.start_time||'',end_time:props.shift.end_time||'',punch_in_window_start:props.shift.punch_in_window_start||'',punch_in_window_end:props.shift.punch_in_window_end||'',crosses_midnight:props.shift.crosses_midnight,max_duration_hours:props.shift.max_duration_hours});
function submit(){ form.put(route('hrm.shifts.update',props.shift.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Roster' }, { title: 'Shifts', href: route('hrm.shifts.index') }, { title: 'Edit' }]">
    <Head title="Edit Shift"/><PageHeader title="Edit Shift" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl bg-card border rounded-xl shadow-sm p-6">    <form @submit.prevent="submit" class="space-y-4">
      <div class="space-y-2"><Label>Shift Name *</Label><Input v-model="form.shift_name"/></div>
      <div class="grid grid-cols-2 gap-4"><div class="space-y-2"><Label>Start</Label><Input v-model="form.start_time" type="time"/></div><div class="space-y-2"><Label>End</Label><Input v-model="form.end_time" type="time"/></div></div>
      <div class="grid grid-cols-2 gap-4"><div class="space-y-2"><Label>Flex Start</Label><Input v-model="form.punch_in_window_start" type="time"/></div><div class="space-y-2"><Label>Flex End</Label><Input v-model="form.punch_in_window_end" type="time"/></div></div>
      <div class="flex gap-4"><label class="flex items-center gap-2"><input type="checkbox" v-model="form.crosses_midnight"/><span class="text-sm">Crosses Midnight</span></label></div>
      <div class="space-y-2"><Label>Max Duration</Label><Input v-model.number="form.max_duration_hours" type="number"/></div>
      <Button type="submit" :disabled="form.processing">Update Shift</Button>
    </form></div>
      </PageHeader>
  </AdminLayout>
</template>
