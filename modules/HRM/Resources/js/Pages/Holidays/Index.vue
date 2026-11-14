<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarDays, Plus, Trash2, Pencil, X, Check } from 'lucide-vue-next';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import Pagination from '@core/Components/Pagination.vue';
import StaticTable from '@core/Components/StaticTable.vue';
import { useConfirm } from '@core/Composables/useConfirm';
const { confirm } = useConfirm();
interface Holiday {id:number;name:string;holiday_date:string;holiday_type:string;department_id:number|null;department:string|null;notes:string|null}
defineProps<{holidays:{data:Holiday[];links:any[];meta?:any};departmentOptions:{value:number;label:string}[];canManage:boolean}>();
const editing=ref<number|null>(null), open=ref(false);
const form=useForm({name:'',holiday_date:new Date().toISOString().slice(0,10),holiday_type:'Public Holiday',department_id:null as number|null,notes:''});
async function startCreate(){editing.value=null;form.reset();form.holiday_date=new Date().toISOString().slice(0,10);open.value=true;}
async function startEdit(h:Holiday){editing.value=h.id;form.name=h.name;form.holiday_date=h.holiday_date;form.holiday_type=h.holiday_type;form.department_id=h.department_id;form.notes=h.notes??'';open.value=true;}
async function submit(){
  if (editing.value) {
    form.put(route('hrm.holidays.update', editing.value), { preserveScroll:true, onSuccess(){ open.value=false; } });
  } else {
    form.post(route('hrm.holidays.store'), { preserveScroll:true, onSuccess(){ open.value=false; } });
  }
}
async function remove(id:number){if(await confirm({ description: 'Remove this holiday entry?', danger: true }))router.delete(route('hrm.holidays.destroy',id),{preserveScroll:true});}
</script>
<template>
<AdminLayout :breadcrumbs="[{ title: 'Leave' }, { title: 'Holiday Calendar' }]"><Head title="Holiday Calendar"/>
  <PageHeader title="Holiday Calendar" description="Maintain organization-wide or department-specific holiday dates." :icon="CalendarDays">
      <template #actions>
          <Button v-if="canManage" @click="startCreate"><Plus class="size-4"/> Add Holiday</Button>
      </template>
  </PageHeader>
  <form v-if="open" @submit.prevent="submit" class="border bg-card rounded-xl p-5 space-y-4">
    <div class="flex items-center justify-between"><h2 class="font-semibold">{{editing?'Edit':'New'}} Holiday Entry</h2><button type="button" @click="open=false"><X class="size-4"/></button></div>
    <div class="grid sm:grid-cols-2 gap-4"><div class="space-y-1"><Label>Holiday name *</Label><Input v-model="form.name" placeholder="e.g. Eid-ul-Fitr"/><p v-if="form.errors.name" class="text-xs text-destructive">{{form.errors.name}}</p></div><div class="space-y-1"><Label>Date *</Label><Input v-model="form.holiday_date" type="date"/><p v-if="form.errors.holiday_date" class="text-xs text-destructive">{{form.errors.holiday_date}}</p></div>
      <div class="space-y-1"><Label>Type</Label><select v-model="form.holiday_type" class="h-10 w-full rounded-md border bg-background px-3 text-sm"><option>Public Holiday</option><option>Company Holiday</option><option>Religious Holiday</option><option>Other</option></select></div><div class="space-y-1"><Label>Scope</Label><SearchableSelect v-model="form.department_id" :options="departmentOptions" endpoint="/hrm/api/options/departments" placeholder="All departments" clearable/></div>
      <div class="sm:col-span-2 space-y-1"><Label>Notes</Label><Textarea v-model="form.notes" rows="2"/></div></div>
    <div class="flex justify-end gap-2"><Button type="button" variant="outline" @click="open=false">Cancel</Button><Button :disabled="form.processing"><Check class="size-4"/> Save holiday</Button></div>
  </form>
  <StaticTable><thead class="bg-muted/60 text-left"><tr><th class="p-3">Date</th><th class="p-3">Holiday</th><th class="p-3">Type</th><th class="p-3">Scope</th><th class="p-3">Notes</th><th class="p-3"></th></tr></thead><tbody><tr v-for="h in holidays.data" :key="h.id" class="border-t"><td class="p-3 font-mono">{{h.holiday_date}}</td><td class="p-3 font-medium">{{h.name}}</td><td class="p-3">{{h.holiday_type}}</td><td class="p-3">{{h.department||'All departments'}}</td><td class="p-3 text-muted-foreground">{{h.notes||'—'}}</td><td class="p-3 text-right whitespace-nowrap"><template v-if="canManage"><Button variant="ghost" size="icon" @click="startEdit(h)"><Pencil class="size-4"/></Button><Button variant="ghost" size="icon" @click="remove(h.id)"><Trash2 class="size-4"/></Button></template></td></tr><tr v-if="!holidays.data.length"><td colspan="6" class="p-8 text-center text-muted-foreground">No holidays entered yet.</td></tr></tbody></StaticTable>
  <Pagination :links="holidays.links" :total="holidays.total" />
</AdminLayout></template>
