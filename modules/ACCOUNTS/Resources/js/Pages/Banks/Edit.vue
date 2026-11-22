<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import type { Bank } from '@accounts/types';
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
const props=defineProps<{ bank:Bank; accountOptions:SelectOption[] }>();
const form=useForm({ name:props.bank.name, code:props.bank.code, bank_name:props.bank.bank_name, branch_name:props.bank.branch_name??'', account_no:props.bank.account_no, account_holder:props.bank.account_holder??'', swift_code:props.bank.swift_code??'', account_id:props.bank.account_id, opening_balance:props.bank.opening_balance, is_active:props.bank.is_active });
useUnsavedGuard(() => form.isDirty);
function submit(){ form.put(route('accounts.banks.update', props.bank.id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{ title: 'Accounts' }, { title: 'Banks', href: route('accounts.banks.index') }, { title: bank.code }, { title: 'Edit' }]"><Head :title="`Edit ${bank.code}`"/><PageHeader title="Edit {{ bank.code }}" description="Complete the form — required *" :icon="Package">
    <div class="max-w-4xl"><form @submit.prevent="submit" class="space-y-4">
        <FormSection title="Details" :cols="2">
<FormField label="Name">
    <template #default="{ id, invalid }">
        <Input :id="id" :aria-invalid="invalid" v-model="form.name"/>
    </template>
</FormField><FormField label="Linked COA">
    <template #default="{ id, invalid }">
        <SearchableSelect :id="id" v-model="form.account_id" :options="accountOptions" endpoint="/accounts/api/options/accounts"/>
    </template>
</FormField><div class="flex gap-4"><FormCheckbox v-model="form.is_active" label="Active" /></div><FormActions submit-label="Update" :processing="form.processing" :dirty="form.isDirty" cancel-href="route('accounts.banks.index')" />
        </FormSection>
</form></div>    </PageHeader>
  </AdminLayout>
</template>
