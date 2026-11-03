<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
defineProps<{receipt:any, accounts:any[]}>();
const form=reactive({receipt_id: null as any, allocation_basis:'value', notes:'', lines:[{name:'Freight',amount:0,account_id:null},{name:'Customs Duty',amount:0,account_id:null}] as any[]});
function add(){form.lines.push({name:'',amount:0,account_id:null});}
function submit(){router.post(route('purchase.landed-costs.store'), form as any);}
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Landed Costs',href:route('purchase.landed-costs.index')},{title:'Create'}]">
    <Head title="New Landed Cost"/>
    <div class="max-w-4xl mx-auto space-y-4">
      <h1 class="text-xl font-bold">New Landed Cost Allocation</h1>
      <div class="bg-card border rounded-xl p-4 space-y-3">
        <div class="grid md:grid-cols-3 gap-3">
          <div class="space-y-1"><Label>GRN Receipt *</Label><SearchableSelect v-model="form.receipt_id" api-url="/purchase/api/options/receipts" placeholder="Select GRN (posted)" /></div>
          <div class="space-y-1"><Label>Basis</Label><select v-model="form.allocation_basis" class="h-9 w-full rounded border bg-background px-2"><option value="value">By Value (line_total)</option><option value="qty">By Qty</option><option value="weight">By Weight</option></select></div>
          <div class="space-y-1"><Label>Notes</Label><Input v-model="form.notes" placeholder="Import shipment xyz" /></div>
        </div>
        <h3 class="font-semibold mt-4">Cost Lines (Freight, Duty, Insurance…)</h3>
        <div v-for="(l,i) in form.lines" :key="i" class="flex gap-2 items-end border rounded p-2">
          <div class="flex-1"><Label>Name</Label><Input v-model="l.name" placeholder="Freight" /></div>
          <div class="w-32"><Label>Amount BDT</Label><Input v-model.number="l.amount" type="number" step="0.01" /></div>
          <div class="flex-1"><Label>Expense Account (credit)</Label><SearchableSelect v-model="l.account_id" api-url="/accounts/api/options/accounts" placeholder="Optional — defaults 6000" /></div>
          <Button variant="ghost" @click="form.lines.splice(i,1)">✕</Button>
        </div>
        <Button variant="outline" size="sm" @click="add">+ Add Cost</Button>
        <div class="flex justify-end"><Button @click="submit">Create Draft</Button></div>
      </div>
    </div>
  </AdminLayout>
</template>
