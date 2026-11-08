<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
const form=useForm({shift_name:'',start_time:'',end_time:'',punch_in_window_start:'',punch_in_window_end:'',crosses_midnight:false,max_duration_hours:24});
function submit(){ form.post(route('hrm.shifts.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Roster' }, { title: 'Shifts', href: route('hrm.shifts.index') }, { title: 'Create' }]">
    <Head title="Create Shift"/><PageHeader title="New Shift" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl bg-card border rounded-xl shadow-sm p-6">    <form @submit.prevent="submit" class="space-y-4">
      <div class="space-y-2"><Label>Shift Name *</Label><Input v-model="form.shift_name" placeholder="Day Shift A"/><p v-if="form.errors.shift_name" class="text-sm text-destructive">{{ form.errors.shift_name }}</p></div>
      <div class="grid grid-cols-2 gap-4"><div class="space-y-2"><Label>Start Time</Label><Input v-model="form.start_time" type="time"/></div><div class="space-y-2"><Label>End Time</Label><Input v-model="form.end_time" type="time"/></div></div>
      <div class="grid grid-cols-2 gap-4"><div class="space-y-2"><Label>Flex Window Start</Label><Input v-model="form.punch_in_window_start" type="time"/></div><div class="space-y-2"><Label>Flex Window End</Label><Input v-model="form.punch_in_window_end" type="time"/></div></div>
      <div class="flex gap-4"><label class="flex items-center gap-2"><input type="checkbox" v-model="form.crosses_midnight"/><span class="text-sm">Crosses Midnight</span></label></div>
      <div class="space-y-2"><Label>Max Duration (hours, 1-72)</Label><Input v-model.number="form.max_duration_hours" type="number"/><p v-if="form.errors.max_duration_hours" class="text-sm text-destructive">{{ form.errors.max_duration_hours }}</p></div>
      <Button type="submit" :disabled="form.processing">Create Shift</Button>
    </form></div>
      </PageHeader>
  </AdminLayout>
</template>
