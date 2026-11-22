<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import FormCheckbox from '@core/Components/FormCheckbox.vue';
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
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <FormSection title="Details" :cols="2">

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          <FormField label="Code" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.code" placeholder="100101"/>
    </template>
</FormField>
          <FormField label="Type" required>
    <template #default="{ id, invalid }">
        <FormSelect :id="id" :invalid="invalid" v-model="form.account_type"  @change="form.nature=(['asset','expense'].includes(form.account_type)?'debit':'credit')"><option value="asset">Asset</option><option value="liability">Liability</option><option value="equity">Equity</option><option value="income">Income</option><option value="expense">Expense</option></FormSelect>
    </template>
</FormField>
        </div>
        <FormField label="Name" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.name" placeholder="Cash in Hand — Chattogram"/>
    </template>
</FormField>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
          <FormField label="Nature" required>
    <template #default="{ id, invalid }">
        <FormSelect :id="id" :invalid="invalid" v-model="form.nature" ><option value="debit">Debit</option><option value="credit">Credit</option></FormSelect>
    </template>
</FormField>
          <FormField label="Parent (Group)">
    <template #default="{ id, invalid }">
        <SearchableSelect :id="id" v-model="form.parent_id" :options="parentOptions" placeholder="Root (no parent)" clearable />
    </template>
</FormField>
        </div>
        <FormField label="Description">
    <template #default="{ id, invalid }">
        <Textarea :id="id" :aria-invalid="invalid" v-model="form.description" rows="2"/>
    </template>
</FormField>
        <div class="flex gap-4">
          <FormCheckbox v-model="form.is_group" label="Group (no postings)" />
          <FormCheckbox v-model="form.is_active" label="Active" />
        </div>
        <FormActions submit-label="Create Account" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('accounts.coa.index')" />
          </FormSection>
    </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
