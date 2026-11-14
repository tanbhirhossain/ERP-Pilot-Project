<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, reactive } from 'vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ClipboardCheck, Plus } from 'lucide-vue-next';
defineProps<{ templates:any; assignments:any }>();
const showTpl=ref(false);
const tplForm=reactive({name:'',category:'Onboarding',description:'',items:[{title:'',responsible_role:'HR'}] as any[]});
function addItem(){ tplForm.items.push({title:'',responsible_role:'HR'}); }
function createTpl(){ router.post(route('hrm.checklists.templates.store'), tplForm as any, {onSuccess:()=>{showTpl.value=false; tplForm.name=''; tplForm.items=[{title:'',responsible_role:'HR'}];}}); }
function seed(){ router.post(route('hrm.checklists.seed')); }
const assignForm=reactive({employee_id:null as number | string | null, template_id:null as number | string | null});
function assign(){ router.post(route('hrm.checklists.assign'), assignForm as any); }
const expanded = ref<Set<number>>(new Set());
function toggleOpen(id:number){
  const next = new Set(expanded.value);
  if (next.has(id)) { next.delete(id); } else { next.add(id); }
  expanded.value = next;
}
const STATUSES = ['Pending','Done','Skipped','N/A'] as const;
/** Posting re-renders the page, so scroll position and expansion must survive. */
function setStatus(pid:number, status:string){
  router.post(route('hrm.checklists.progress', pid), {status}, {preserveScroll:true});
}
function statusTone(status:string):string{
  switch(status){
    case 'Done':    return 'bg-emerald-600 text-white hover:bg-emerald-700';
    case 'Skipped': return 'bg-amber-500 text-white hover:bg-amber-600';
    case 'N/A':     return 'bg-slate-500 text-white hover:bg-slate-600';
    default:        return 'border bg-card hover:bg-accent';
  }
}
function ordered(a:any){
  return [...(a.progress ?? [])].sort((x:any,y:any) => (x.item?.sort_order ?? 0) - (y.item?.sort_order ?? 0));
}
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'On/Offboarding Checklists' }]">
    <Head title="Checklists — HRM"/>
    <div class="max-w-7xl mx-auto space-y-6">
      <div class="flex items-center justify-between"><div><h1 class="text-2xl font-bold flex items-center gap-2"><ClipboardCheck class="size-6 text-primary"/> Onboarding / Offboarding Checklists</h1><p class="text-sm text-muted-foreground">Template-driven: create Onboarding (Day-1) or Offboarding (Exit) templates → assign to employee → each item Done/Skipped → progress auto-calculated. Used for joining and separation.</p></div><div class="flex gap-2"><Button variant="outline" @click="seed">Seed Defaults</Button><Button @click="showTpl=!showTpl" class="bg-primary"><Plus class="size-4 mr-1"/>New Template</Button></div></div>

      <div v-if="showTpl" class="bg-card border rounded-xl p-5 space-y-4">
        <h3 class="font-semibold">New Template</h3>
        <div class="grid md:grid-cols-3 gap-4">
          <div class="space-y-1"><Label>Name *</Label><Input v-model="tplForm.name" placeholder="Standard Onboarding — Factory"/></div>
          <div class="space-y-1"><Label>Category *</Label><select v-model="tplForm.category" class="h-9 w-full rounded-md border bg-background px-3 text-sm"><option>Onboarding</option><option>Offboarding</option></select></div>
          <div class="space-y-1"><Label>Description</Label><Input v-model="tplForm.description" placeholder="Day 1 → Day 30"/></div>
        </div>
        <div class="space-y-2"><Label>Items</Label><div v-for="(it,i) in tplForm.items" :key="i" class="flex gap-2"><Input v-model="it.title" placeholder="Task title — e.g., IT: Email setup" class="flex-1"/><select v-model="it.responsible_role" class="h-9 rounded-md border bg-background px-3 text-sm"><option>HR</option><option>IT</option><option>Admin</option><option>Finance</option><option>Payroll</option><option>Manager</option></select><Button variant="ghost" @click="tplForm.items.splice(i,1)" size="sm">✕</Button></div><Button variant="outline" size="sm" @click="addItem">+ Add Item</Button></div>
        <div class="flex justify-end"><Button @click="createTpl" class="bg-emerald-600 hover:bg-emerald-700">Create Template</Button></div>
      </div>

      <div class="grid lg:grid-cols-2 gap-6">
        <div class="bg-card border rounded-xl overflow-hidden">
          <div class="p-4 border-b"><h3 class="font-semibold text-sm">Templates — {{ templates.total }}</h3></div>
          <div class="divide-y">
            <div v-for="t in templates.data" :key="t.id" class="p-4 flex items-center justify-between"><div><div class="font-medium text-sm">{{ t.name }}</div><div class="text-xs text-muted-foreground">{{ t.category }} • {{ t.items_count }} items • {{ t.is_active?'Active':'Inactive' }}</div></div><span :class="['px-2 py-1 rounded text-xs font-medium', t.category==='Onboarding'?'bg-blue-500 text-white':'bg-amber-500 text-white']">{{ t.category }}</span></div>
            <div v-if="!templates.data.length" class="p-8 text-center text-sm text-muted-foreground">No templates — click Seed Defaults or create one.</div>
          </div>
        </div>
        <div class="bg-card border rounded-xl p-5 space-y-4">
          <h3 class="font-semibold text-sm">Assign Checklist to Employee</h3>
          <div class="grid md:grid-cols-2 gap-4">
            <div class="space-y-1"><Label>Employee *</Label><SearchableSelect v-model="assignForm.employee_id" endpoint="/hrm/api/options/employees" placeholder="Employee"/></div>
            <div class="space-y-1"><Label>Template *</Label><SearchableSelect v-model="assignForm.template_id" endpoint="/hrm/api/options/checklist-templates" placeholder="Pick template"/></div>
          </div>
          <Button @click="assign" class="w-full bg-primary">Assign & Create Progress Rows</Button>
          <p class="text-xs text-muted-foreground">Employee gets a checklist instance with one progress row per template item (Pending). Each row Done/Skipped/N-A updates %.</p>
        </div>
      </div>

      <div class="bg-card border rounded-xl overflow-hidden">
        <div class="p-4 border-b"><h3 class="font-semibold text-sm">Active Assignments — {{ assignments.total }}</h3></div>
        <div class="divide-y">
          <div v-for="a in assignments.data" :key="a.id" class="p-4 space-y-2">
            <div class="flex items-center justify-between"><div><div class="font-medium text-sm">{{ a.employee?.full_name||a.employee_id }} — {{ a.template?.name }}</div><div class="text-xs text-muted-foreground">{{ a.template?.category }} • {{ a.progress_pct }}% • {{ a.status }}</div></div><div class="w-32 h-2 bg-muted rounded-full overflow-hidden"><div class="h-full bg-primary" :style="{width: a.progress_pct+'%'}"></div></div></div>
            <div class="flex flex-wrap items-center gap-2">
              <Button variant="outline" size="sm" @click="toggleOpen(a.id)" :aria-expanded="expanded.has(a.id)">
                {{ expanded.has(a.id) ? 'Hide items' : `Update ${ordered(a).length} items` }}
              </Button>
              <span v-if="a.status === 'Completed'" class="rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">Complete</span>
              <span class="text-xs text-muted-foreground">{{ ordered(a).filter((p:any)=>p.status==='Done').length }} of {{ ordered(a).length }} done</span>
            </div>

            <div v-if="expanded.has(a.id)" class="space-y-1.5 rounded-lg border bg-muted/20 p-3">
              <div v-for="pr in ordered(a)" :key="pr.id" class="flex flex-wrap items-center justify-between gap-2">
                <div class="min-w-0">
                  <p class="truncate text-sm" :class="pr.status === 'Done' ? 'text-muted-foreground line-through' : ''">
                    {{ pr.item?.title ?? `Item #${pr.checklist_item_id}` }}
                    <span v-if="pr.item?.is_required" class="ml-1 text-xs text-destructive" title="Required">*</span>
                  </p>
                  <p class="text-xs text-muted-foreground">
                    {{ pr.item?.responsible_role ?? 'HR' }}
                    <span v-if="pr.completed_at"> · {{ new Date(pr.completed_at).toLocaleDateString() }}</span>
                    <span v-if="pr.remarks"> · “{{ pr.remarks }}”</span>
                  </p>
                </div>
                <div class="flex flex-wrap gap-1">
                  <button
                    v-for="st in STATUSES" :key="st"
                    type="button"
                    @click="setStatus(pr.id, st)"
                    :aria-pressed="pr.status === st"
                    :class="['rounded-md px-2 py-1 text-xs font-medium transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring', pr.status === st ? statusTone(st) : 'border bg-card text-muted-foreground hover:bg-accent']"
                  >{{ st }}</button>
                </div>
              </div>
              <p v-if="!ordered(a).length" class="text-xs text-muted-foreground">
                This instance has no progress rows — the template was probably empty when it was assigned.
              </p>
            </div>
          </div>
          <div v-if="!assignments.data.length" class="p-8 text-center text-sm text-muted-foreground">No assignments yet — assign an onboarding checklist to a new hire to start.</div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
