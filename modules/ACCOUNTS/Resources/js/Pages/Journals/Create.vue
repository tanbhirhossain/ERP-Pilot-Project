<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Plus, Trash2, Scale, AlertCircle, BookOpen } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import { computed } from 'vue';
defineProps<{ accountOptions:any[] }>();
const form=useForm({ journal_date: new Date().toISOString().slice(0,10), type:'JV', reference_no:'', narration:'', lines:[{ account_id:null as number|null, debit:null as number|null, credit:null as number|null, description:'' }, { account_id:null as number|null, debit:null as number|null, credit:null as number|null, description:'' }] });
const totalDebit=computed(()=> form.lines.reduce((s,l)=> s + (Number(l.debit)||0), 0));
const totalCredit=computed(()=> form.lines.reduce((s,l)=> s + (Number(l.credit)||0), 0));
const balanced=computed(()=> Math.abs(totalDebit.value - totalCredit.value) < 0.01 && totalDebit.value>0);
const diff=computed(()=> totalDebit.value - totalCredit.value);
function addLine(){ form.lines.push({ account_id:null, debit:null, credit:null, description:'' }); }
function removeLine(i:number){ form.lines.splice(i,1); if(!form.lines.length) addLine(); }
function submit(){ form.post(route('accounts.journals.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Journals',href:route('accounts.journals.index')},{title:'Create'}]">
    <Head title="Create Journal" />
    <PageHeader title="New Journal" description="Double-entry — every line hits one COA leaf. Dr and Cr must match before posting. Types drive printed vouchers (JV/PV/RV/CV/BV/SV)." :icon="BookOpen">
      <form @submit.prevent="submit" class="space-y-4 max-w-5xl mx-auto w-full">
        <p v-if="Object.keys(form.errors).length" class="rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive flex items-center gap-2"><AlertCircle class="size-4" /> {{ Object.values(form.errors)[0] as string }}</p>

        <!-- Header -->
        <div class="bg-card border rounded-2xl shadow-sm overflow-hidden">
          <div class="px-5 py-3 border-b bg-muted/30"><h3 class="text-sm font-semibold">Header</h3><p class="text-xs text-muted-foreground">Reference is optional — narration appears on printed voucher.</p></div>
          <div class="p-5 grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="space-y-2"><Label>Date *</Label><Input v-model="form.journal_date" type="date" class="h-9" /></div>
            <div class="space-y-2"><Label>Type *</Label><select v-model="form.type" class="w-full h-9 rounded-lg border bg-background px-3 text-sm focus:ring-2 focus:ring-ring outline-none"><option value="JV">JV — Journal</option><option value="PV">PV — Payment</option><option value="RV">RV — Receipt</option><option value="CV">CV — Contra/Cash</option><option value="BV">BV — Bank</option><option value="SV">SV — Sales</option></select></div>
            <div class="space-y-2"><Label>Reference</Label><Input v-model="form.reference_no" placeholder="INV-001 / CHQ-123" class="h-9" /></div>
            <div class="space-y-2 sm:col-span-3"><Label>Narration</Label><Textarea v-model="form.narration" rows="2" placeholder="Being … (e.g., salary for Jan 2026)" class="resize-none" /></div>
          </div>
        </div>

        <!-- Lines -->
        <div class="bg-card border rounded-2xl shadow-sm overflow-hidden">
          <div class="px-5 py-3 border-b bg-muted/30 flex flex-wrap items-center justify-between gap-3">
            <div><h3 class="text-sm font-semibold">Lines — Dr must equal Cr</h3><p class="text-xs text-muted-foreground">Tip: enter either debit OR credit per line — the other clears.</p></div>
            <span class="text-sm font-mono px-3 py-1.5 rounded-full border font-bold flex items-center gap-1.5" :class="balanced?'bg-emerald-500/10 text-emerald-700 border-emerald-500/20':'bg-rose-500/10 text-rose-700 border-rose-500/20'"><Scale class="size-3.5" /> Dr {{ totalDebit.toFixed(2) }} = Cr {{ totalCredit.toFixed(2) }} {{ balanced?'✓':'' }}</span>
          </div>
          <div class="p-4 space-y-3">
            <div v-for="(line,i) in form.lines" :key="i" class="border rounded-xl p-4 space-y-3 relative bg-muted/[0.25] hover:bg-card transition">
              <Button type="button" variant="ghost" size="icon" class="absolute top-2 right-2 size-7" @click="removeLine(i)"><Trash2 class="size-3.5"/></Button>
              <p class="text-xs font-bold tracking-widest text-muted-foreground uppercase">#{{ i+1 }}</p>
              <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div class="space-y-1 sm:col-span-1"><Label class="text-xs">Account *</Label><SearchableSelect v-model="line.account_id" :options="accountOptions" endpoint="/accounts/api/options/accounts" placeholder="Search COA" /></div>
                <div class="space-y-1"><Label class="text-xs">Debit (BDT)</Label><Input v-model.number="line.debit" type="number" step="0.01" placeholder="0.00" class="h-9 font-mono" @update:model-value="val=>{ if(val) line.credit=null; }" /></div>
                <div class="space-y-1"><Label class="text-xs">Credit (BDT)</Label><Input v-model.number="line.credit" type="number" step="0.01" placeholder="0.00" class="h-9 font-mono" @update:model-value="val=>{ if(val) line.debit=null; }" /></div>
              </div>
              <div class="space-y-1"><Label class="text-xs">Memo</Label><Input v-model="line.description" placeholder="Line note (printed per line)" class="h-9" /></div>
            </div>
            <Button type="button" variant="outline" size="sm" @click="addLine"><Plus class="size-4"/> Add line</Button>
          </div>
        </div>

        <!-- Sticky footer -->
        <div class="sticky bottom-4 z-10 bg-card/95 backdrop-blur border rounded-2xl p-4 shadow-lg flex flex-wrap items-center gap-3">
          <div class="text-sm">
            <p class="font-mono font-bold" :class="balanced?'text-emerald-600':'text-rose-600'">Dr {{ totalDebit.toFixed(2) }} · Cr {{ totalCredit.toFixed(2) }} <span v-if="!balanced" class="text-xs font-normal text-muted-foreground">· diff {{ diff.toFixed(2) }}</span></p>
            <p v-if="!balanced" class="text-xs text-rose-600 flex items-center gap-1"><AlertCircle class="size-3" /> Must balance (Dr = Cr) and total &gt; 0</p>
            <p v-else class="text-xs text-emerald-600">Ready to create</p>
          </div>
          <div class="ml-auto flex gap-2">
            <Button type="button" variant="outline" @click="$inertia.visit(route('accounts.journals.index'))">Cancel</Button>
            <Button type="submit" :disabled="form.processing || !balanced">Create journal</Button>
          </div>
        </div>
      </form>
    </PageHeader>
  </AdminLayout>
</template>
