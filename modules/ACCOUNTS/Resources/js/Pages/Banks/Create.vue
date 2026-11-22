<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { SelectOption } from '@core/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import FormCheckbox from '@core/Components/FormCheckbox.vue';
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
      <form @submit.prevent="submit" class="space-y-4">
        <p v-if="Object.keys(form.errors).length" class="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{{ Object.values(form.errors)[0] }}</p>
        <FormSection title="Details" :cols="2">

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Name" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.name" placeholder="Prime Bank Main"/>
    </template>
</FormField><FormField label="Code">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.code" placeholder="BANK-001"/>
    </template>
</FormField></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Bank Name" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.bank_name" placeholder="Prime Bank Ltd"/>
    </template>
</FormField><FormField label="Branch">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.branch_name" placeholder="Agrabad"/>
    </template>
</FormField></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Account No" required>
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.account_no"/>
    </template>
</FormField><FormField label="Holder">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.account_holder"/>
    </template>
</FormField></div>
        <FormField label="Linked COA Account" required>
    <template #default="{ id, invalid }">
        <SearchableSelect :id="id" v-model="form.account_id" :options="accountOptions" endpoint="/accounts/api/options/accounts" placeholder="100201 — Prime Bank" />
    </template>
</FormField>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4"><FormField label="Opening Balance">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model.number="form.opening_balance" type="number" step="0.01"/>
    </template>
</FormField><FormField label="Currency">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.currency"/>
    </template>
</FormField></div>
        <div class="flex gap-4"><FormCheckbox v-model="form.is_default" label="Default" /><FormCheckbox v-model="form.is_active" label="Active" /></div>
        <FormActions submit-label="Create" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('accounts.banks.index')" />
          </FormSection>
    </form>
    </div>
      </PageHeader>
  </AdminLayout>
</template>
