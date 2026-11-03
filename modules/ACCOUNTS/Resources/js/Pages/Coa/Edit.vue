<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
const props=defineProps<{ account:any; parentOptions:any[] }>();
const form=useForm({ code:props.account.code, name:props.account.name, name_bn:props.account.name_bn??'', account_type:props.account.account_type, nature:props.account.nature, parent_id:props.account.parent_id, is_group:props.account.is_group, is_active:props.account.is_active, description:props.account.description??'' });
function submit(){ form.put(route('accounts.coa.update', props.account.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Chart of Accounts',href:route('accounts.coa.index')},{title:account.code},{title:'Edit'}]">
    <Head :title="`Edit ${account.code}`" />
    <PageHeader title="Edit {{ account.code }} — {{ account.name }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-2xl mx-auto space-y-4">
      <h1 class="text-xl font-bold">Edit {{ account.code }} — {{ account.name }}</h1>
      <form @submit.prevent="submit" class="bg-card border rounded-2xl shadow-sm p-4 space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4"><div class="space-y-2"><Label>Code</Label><Input v-model="form.code" :disabled="account.is_system" /></div><div class="space-y-2"><Label>Type</Label><select v-model="form.account_type" class="w-full h-9 rounded-md border bg-background px-3 text-sm"><option value="asset">Asset</option><option value="liability">Liability</option><option value="equity">Equity</option><option value="income">Income</option><option value="expense">Expense</option></select></div></div>
        <div class="space-y-2"><Label>Name</Label><Input v-model="form.name"/></div>
        <div class="space-y-2"><Label>Parent</Label><SearchableSelect v-model="form.parent_id" :options="parentOptions" placeholder="Root" clearable /></div>
        <div class="space-y-2"><Label>Description</Label><Textarea v-model="form.description" rows="2"/></div>
        <div class="flex gap-4"><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_group" class="rounded"/> Group</label><label class="flex items-center gap-2 text-sm"><input type="checkbox" v-model="form.is_active" class="rounded"/> Active</label></div>
        <Button type="submit">Update</Button>
      </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
