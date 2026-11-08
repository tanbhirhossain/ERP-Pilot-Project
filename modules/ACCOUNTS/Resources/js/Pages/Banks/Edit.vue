<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props=defineProps<{ bank:any; accountOptions:any[] }>();
const form=useForm({ name:props.bank.name, code:props.bank.code, bank_name:props.bank.bank_name, branch_name:props.bank.branch_name??'', account_no:props.bank.account_no, account_holder:props.bank.account_holder??'', swift_code:props.bank.swift_code??'', account_id:props.bank.account_id, opening_balance:props.bank.opening_balance, is_active:props.bank.is_active });
function submit(){ form.put(route('accounts.banks.update', props.bank.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Banks', href: route('accounts.banks.index') }, { title: bank.code }, { title: 'Edit' }]"><Head :title="`Edit ${bank.code}`"/><PageHeader title="Edit {{ bank.code }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="bg-card border rounded-xl shadow-sm p-4 space-y-4"><div class="space-y-2"><Label>Name</Label><Input v-model="form.name"/></div><div class="space-y-2"><Label>Linked COA</Label><SearchableSelect v-model="form.account_id" :options="accountOptions" endpoint="/accounts/api/options/accounts"/></div><div class="flex gap-4"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label></div><Button type="submit">Update</Button></form></div>    </PageHeader>
  </AdminLayout>
</template>
