<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
const form=useForm({ name:'FY 2025-2026', code:'FY2526', start_date:'2025-07-01', end_date:'2026-06-30', notes:'' });
function submit(){ form.post(route('accounts.fiscal-years.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Fiscal Years',href:route('accounts.fiscal-years.index')},{title:'Create'}]">
    <Head title="Create Fiscal Year"/>
    <div class="max-w-xl mx-auto"><h1 class="text-xl font-bold mb-4">New Fiscal Year — 12 periods auto-created</h1><form @submit.prevent="submit" class="bg-card border rounded-xl p-4 space-y-4"><p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p><div class="space-y-2"><Label>Name *</Label><Input v-model="form.name"/></div><div class="space-y-2"><Label>Code *</Label><Input v-model="form.code"/></div><div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Start *</Label><Input v-model="form.start_date" type="date"/></div><div class="space-y-2"><Label>End *</Label><Input v-model="form.end_date" type="date"/></div></div><div class="space-y-2"><Label>Notes</Label><Textarea v-model="form.notes" rows="2"/></div><Button type="submit">Create</Button></form></div>
  </AdminLayout>
</template>
