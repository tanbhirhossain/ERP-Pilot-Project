<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
defineProps<{ parentOptions:SelectOption[] }>();
const form=useForm({ code:'', name:'', name_bn:'', account_type:'asset', nature:'debit', parent_id:null as number|null, is_group:false, is_active:true, opening_balance:0, opening_side:'debit', description:'' });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.post(route('accounts.coa.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Chart of Accounts', href: route('accounts.coa.index') }, { title: 'Create' }]">
    <Head title="Create Account" />
    <PageHeader title="Add Account" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl space-y-4">
      <form @submit.prevent="submit" class="bg-card border rounded-xl shadow-sm p-4 space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          <div class="space-y-2"><Label>Code *</Label><Input v-model="form.code" placeholder="100101"/></div>
          <div class="space-y-2"><Label>Type *</Label><select v-model="form.account_type" class="w-full h-9 rounded-md border bg-background px-3 text-sm" @change="form.nature=(['asset','expense'].includes(form.account_type)?'debit':'credit')"><option value="asset">Asset</option><option value="liability">Liability</option><option value="equity">Equity</option><option value="income">Income</option><option value="expense">Expense</option></select></div>
        </div>
        <div class="space-y-2"><Label>Name *</Label><Input v-model="form.name" placeholder="Cash in Hand — Chattogram"/></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          <div class="space-y-2"><Label>Nature *</Label><select v-model="form.nature" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="debit">Debit</option><option value="credit">Credit</option></select></div>
          <div class="space-y-2"><Label>Parent (Group)</Label><SearchableSelect v-model="form.parent_id" :options="parentOptions" placeholder="Root (no parent)" clearable /></div>
        </div>
        <div class="space-y-2"><Label>Description</Label><Textarea v-model="form.description" rows="2"/></div>
        <div class="flex gap-4">
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_group" class="rounded"/> Group (no postings)</label>
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label>
        </div>
        <Button type="submit" :disabled="form.processing">Create Account</Button>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
