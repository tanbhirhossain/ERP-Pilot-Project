<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ArrowLeftRight, CalendarDays } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props = defineProps<{ employeeOptions:{value:string,label:string}[]; shiftOptions:{value:number,label:string}[] }>();
const form = useForm({ roster_duty_id: null as number|null, requester_employee_id: '', target_employee_id: '' as string|null, requested_shift_id: null as number|null, target_shift_id: null as number|null, swap_date: '', reason: '' });
function submit(){ form.post(route('hrm.swaps.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'HRM'},{title:'Roster Swaps', href: route('hrm.swaps.index')},{title:'New Request'}]">
    <Head title="New Swap Request"/>
    <div class="max-w-3xl mx-auto space-y-4">
      <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold flex items-center gap-2"><ArrowLeftRight class="size-5"/> New Swap Request</h1>
        <Link :href="route('hrm.swaps.index')"><Button variant="outline" size="sm"><ArrowLeft class="size-4"/> Back</Button></Link>
      </div>
      <p class="text-sm text-muted-foreground">Swap your shift with another employee or change to a different shift. Manager approval will exchange the duties automatically.</p>
      <form @submit.prevent="submit" class="bg-card border rounded-xl p-6 space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div v-if="false" class="space-y-1"><Label>Roster Duty ID (optional FK)</Label><Input v-model.number="form.roster_duty_id as any" type="number" placeholder="Leave blank if swap by date"/></div>
          <div class="space-y-1"><Label>Your Employee ID *</Label><SearchableSelect v-model="form.requester_employee_id" :options="employeeOptions" endpoint="/hrm/api/options/employees" placeholder="Select yourself" :error="form.errors.requester_employee_id"/></div>
          <div class="space-y-1"><Label>Swap With Employee</Label><SearchableSelect v-model="form.target_employee_id" :options="employeeOptions" endpoint="/hrm/api/options/employees" placeholder="Optional — leave blank for shift-only swap" clearable :error="form.errors.target_employee_id as any"/></div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div class="space-y-1"><Label>Swap Date *</Label><Input v-model="form.swap_date" type="date" :error="form.errors.swap_date"/><p v-if="form.errors.swap_date" class="text-xs text-destructive">{{ form.errors.swap_date }}</p></div>
          <div class="space-y-1"><Label>Requested Shift</Label><SearchableSelect v-model="form.requested_shift_id" :options="shiftOptions" placeholder="Your new shift (optional)" clearable/></div>
          <div class="space-y-1"><Label>Target / Exchange Shift</Label><SearchableSelect v-model="form.target_shift_id" :options="shiftOptions" placeholder="Their shift (if exchanging)" clearable/></div>
        </div>
        <div class="space-y-1"><Label>Reason</Label><Textarea v-model="form.reason" rows="3" placeholder="Reason for swap (e.g. medical, personal)" :error="form.errors.reason as any"/><p v-if="form.errors.reason" class="text-xs text-destructive">{{ form.errors.reason }}</p></div>
        <div class="flex items-center justify-end gap-2 pt-2">
          <Link :href="route('hrm.swaps.index')"><Button type="button" variant="outline">Cancel</Button></Link>
          <Button type="submit" :disabled="form.processing" class="bg-indigo-600 hover:bg-indigo-700"><CalendarDays class="size-4"/>{{ form.processing ? 'Submitting...' : 'Submit Request' }}</Button>
        </div>
      </form>
      <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-xs text-amber-800">On approval, roster duties for the swap date are exchanged between you and the target employee. Status becomes <b>Swapped</b>.</div>
    </div>
  </AdminLayout>
</template>
