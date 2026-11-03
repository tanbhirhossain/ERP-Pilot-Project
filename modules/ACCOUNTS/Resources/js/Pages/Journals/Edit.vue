<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Plus, Trash2 } from 'lucide-vue-next';
import { computed } from 'vue';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props=defineProps<{ journal:any; accountOptions:any[] }>();
const form=useForm({ journal_date: props.journal.journal_date.slice(0,10), type: props.journal.type, reference_no: props.journal.reference_no ?? '', narration: props.journal.narration ?? '', lines: props.journal.lines.map((l:any)=>({ account_id:l.account_id, debit: l.debit>0?Number(l.debit):null, credit: l.credit>0?Number(l.credit):null, description:l.description ?? '' })) });
const totalDebit=computed(()=> form.lines.reduce((s:any,l:any)=> s + (Number(l.debit)||0),0));
const totalCredit=computed(()=> form.lines.reduce((s:any,l:any)=> s + (Number(l.credit)||0),0));
const balanced=computed(()=> Math.abs(totalDebit.value - totalCredit.value) <0.01 && totalDebit.value>0);
function addLine(){ form.lines.push({ account_id:null, debit:null, credit:null, description:'' }); }
function removeLine(i:number){ form.lines.splice(i,1); }
function submit(){ form.put(route('accounts.journals.update', props.journal.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Journals',href:route('accounts.journals.index')},{title:journal.journal_no},{title:'Edit'}]">
    <Head :title="`Edit ${journal.journal_no}`" />
    <div class="max-w-4xl mx-auto space-y-4">
      <h1 class="text-xl font-bold">Edit {{ journal.journal_no }}</h1>
      <p class="text-sm text-amber-600">Only draft journals can be edited. Posted journals must be voided.</p>
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <div class="bg-card border rounded-xl p-4 space-y-4">
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-4"><div class="space-y-2"><Label>Date</Label><Input v-model="form.journal_date" type="date"/></div><div class="space-y-2"><Label>Type</Label><select v-model="form.type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="JV">JV</option><option value="PV">PV</option><option value="RV">RV</option><option value="CV">CV</option><option value="BV">BV</option></select></div><div class="space-y-2"><Label>Ref</Label><Input v-model="form.reference_no"/></div></div>
          <div class="space-y-2"><Label>Narration</Label><Textarea v-model="form.narration" rows="2"/></div>
        </div>
        <div class="bg-card border rounded-xl p-4 space-y-3">
          <div class="flex justify-between"><h3 class="font-semibold text-sm">Lines</h3><span class="text-sm font-mono px-2 py-1 rounded" :class="balanced?'bg-emerald-500/10 text-emerald-600':'bg-rose-500/10 text-rose-600'">Dr {{ totalDebit.toFixed(2) }} = Cr {{ totalCredit.toFixed(2) }}</span></div>
          <div v-for="(line,i) in form.lines" :key="i" class="border rounded p-3 space-y-2 relative"><Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-6" @click="removeLine(i)"><Trash2 class="size-3"/></Button><div class="grid grid-cols-1 sm:grid-cols-3 gap-3"><div class="space-y-1"><Label>Account</Label><SearchableSelect v-model="line.account_id" :options="accountOptions" endpoint="/accounts/api/options/accounts" placeholder="Search"/></div><div class="space-y-1"><Label>Debit</Label><Input v-model.number="line.debit" type="number" step="0.01" @input="line.credit=null"/></div><div class="space-y-1"><Label>Credit</Label><Input v-model.number="line.credit" type="number" step="0.01" @input="line.debit=null"/></div></div></div>
          <Button type="button" variant="outline" size="sm" @click="addLine"><Plus class="size-4"/> Add Line</Button>
        </div>
        <Button type="submit" :disabled="!balanced">Update</Button>
      </form>
    </div>
  </AdminLayout>
</template>
