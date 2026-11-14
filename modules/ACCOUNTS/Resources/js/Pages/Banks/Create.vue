<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import { useUnsavedGuard } from '@core/Composables/useUnsavedGuard';
defineProps<{ accountOptions:SelectOption[] }>();
const form=useForm({ name:'', code:'', bank_name:'', branch_name:'', account_no:'', account_holder:'', swift_code:'', account_id:null as number|null, currency:'BDT', opening_balance:0, is_default:false, is_active:true, notes:'' });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.post(route('accounts.banks.store')); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Banks', href: route('accounts.banks.index') }, { title: 'Create' }]">
    <Head title="Create Bank" />
    <PageHeader title="Add Bank" description="Complete the form — required *" :icon="Package">
    <div class="space-y-4">
      <form @submit.prevent="submit" class="bg-card border rounded-xl shadow-sm p-4 space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><div class="space-y-2"><Label>Name *</Label><Input v-model="form.name" placeholder="Prime Bank Main"/></div><div class="space-y-2"><Label>Code</Label><Input v-model="form.code" placeholder="BANK-001"/></div></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><div class="space-y-2"><Label>Bank Name *</Label><Input v-model="form.bank_name" placeholder="Prime Bank Ltd"/></div><div class="space-y-2"><Label>Branch</Label><Input v-model="form.branch_name" placeholder="Agrabad"/></div></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><div class="space-y-2"><Label>Account No *</Label><Input v-model="form.account_no"/></div><div class="space-y-2"><Label>Holder</Label><Input v-model="form.account_holder"/></div></div>
        <div class="space-y-2"><Label>Linked COA Account *</Label><SearchableSelect v-model="form.account_id" :options="accountOptions" endpoint="/accounts/api/options/accounts" placeholder="100201 — Prime Bank" /></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><div class="space-y-2"><Label>Opening Balance</Label><Input v-model.number="form.opening_balance" type="number" step="0.01"/></div><div class="space-y-2"><Label>Currency</Label><Input v-model="form.currency"/></div></div>
        <div class="flex gap-4"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_default" class="rounded"/> Default</label><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label></div>
        <Button type="submit">Create</Button>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
