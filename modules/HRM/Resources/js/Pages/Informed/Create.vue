<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {ArrowLeft, Bell, Package} from 'lucide-vue-next';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props=defineProps<{ employeeOptions:{value:string,label:string}[]; informed?: any }>();
const isEdit=computed(()=> !!props.informed);
const form=useForm({ employee_id: props.informed?.employee_id ?? '', informed_date: props.informed?.informed_date ?? new Date().toISOString().slice(0,10), informed_type: props.informed?.informed_type ?? 'Late Coming', expected_time: props.informed?.expected_time ?? '', reason: props.informed?.reason ?? '' });
function submit(){ if(isEdit.value) form.put(route('hrm.informed.update', props.informed.id)); else form.post(route('hrm.informed.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Informed', href: route('hrm.informed.index')},{title: isEdit ? 'Edit' : 'New'}]">
    <Head :title="isEdit ? 'Edit Informed' : 'New Informed Notice'"/>
    <PageHeader :title="isEdit ? 'Edit Notice' : 'New Informed Notice' " description="Complete the form — required *" :icon="Package">
    <div class="max-w-3xl mx-auto space-y-4"><div class="flex items-center justify-between"><h1 class="text-xl font-bold flex items-center gap-2"><Bell class="size-5"/>{{ isEdit ? 'Edit Notice' : 'New Informed Notice' }}</h1><Link :href="route('hrm.informed.index')"><Button variant="outline" size="sm"><ArrowLeft class="size-4"/> Back</Button></Link></div>
      <p class="text-sm text-muted-foreground">Inform supervisor: Late / Early Out / Absent / Field Work / On Duty. Supervisor acknowledges — does NOT auto-mark attendance.</p>
      <form @submit.prevent="submit" class="bg-card border rounded-2xl shadow-sm p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div class="space-y-1"><Label>Employee *</Label><SearchableSelect v-model="form.employee_id" :options="employeeOptions" endpoint="/hrm/api/options/employees" placeholder="Select employee" :error="form.errors.employee_id as any"/><p v-if="form.errors.employee_id" class="text-xs text-destructive">{{ form.errors.employee_id }}</p></div>
          <div class="space-y-1"><Label>Date *</Label><Input v-model="form.informed_date" type="date"/><p v-if="form.errors.informed_date" class="text-xs text-destructive">{{ form.errors.informed_date }}</p></div>
          <div class="space-y-1"><Label>Type *</Label><select v-model="form.informed_type" class="w-full rounded-md border bg-background px-3 py-2 text-sm"><option>Late Coming</option><option>Early Out</option><option>Absent Informed</option><option>Field Work</option><option>On Duty</option><option>Other</option></select></div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="space-y-1"><Label>Expected Time</Label><Input v-model="form.expected_time" type="time" placeholder="Arrival/departure if Late/Early"/><p class="text-xs text-muted-foreground">Only for Late/Early — e.g., will arrive 11:30</p></div>
          <div class="space-y-1 bg-muted/40 rounded-lg p-3 text-xs text-muted-foreground"><b>Tip:</b> Informed ≠ Leave. It’s a heads-up. Approved leave requires Leave Request. This notice keeps supervisor in loop and can be shown on Daily Attendance as remark.</div>
        </div>
        <div class="space-y-1"><Label>Reason</Label><Textarea v-model="form.reason" rows="3" placeholder="Reason — traffic, medical, field visit..."/></div>
        <div class="flex justify-end gap-2 pt-2"><Link :href="route('hrm.informed.index')"><Button type="button" variant="outline">Cancel</Button></Link><Button type="submit" :disabled="form.processing" class="bg-indigo-600 hover:bg-indigo-700"><Bell class="size-4"/>{{ form.processing ? 'Saving...' : isEdit ? 'Update' : 'Submit Notice' }}</Button></div>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
