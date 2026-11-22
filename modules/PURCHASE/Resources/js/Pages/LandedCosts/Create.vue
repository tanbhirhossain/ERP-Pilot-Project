<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import type { GoodsReceipt } from '@purchase/types';
import { reactive } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { X, Plus, Landmark } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import SearchableSelect from '@core/Components/SearchableSelect.vue';
import FormSection from '@core/Components/FormSection.vue';
import FormField from '@core/Components/FormField.vue';
import FormActions from '@core/Components/FormActions.vue';
import FormSelect from '@core/Components/FormSelect.vue';

defineProps<{ receipt: GoodsReceipt; accounts: any[] }>();

const form = reactive({
    receipt_id: null as number | string | null,
    allocation_basis: 'value',
    notes: '',
    lines: [
        { name: 'Freight', amount: 0, account_id: null },
        { name: 'Customs Duty', amount: 0, account_id: null },
    ] as any[],
});

function add() {
    form.lines.push({ name: '', amount: 0, account_id: null });
}

function submit() {
    router.post(route('purchase.landed-costs.store'), form as any);
}
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'Purchase' }, { title: 'Landed Costs', href: route('purchase.landed-costs.index') }, { title: 'Create' }]">
    <Head title="New Landed Cost" />
    <div class="space-y-4">
      <PageHeader title="New Landed Cost Allocation" description="Spread freight, duty and insurance across the goods receipt's lines — the basis decides how." :icon="Landmark" />

      <form @submit.prevent="submit" class="space-y-4">
      <FormSection title="Allocation" :cols="3" dense>
        <FormField label="GRN Receipt" required>
          <SearchableSelect v-model="form.receipt_id" endpoint="/purchase/api/options/receipts" placeholder="Select GRN (posted)" />
        </FormField>
        <FormField label="Basis">
          <template #default="{ id, invalid }">
            <FormSelect v-model="form.allocation_basis" :id="id" :invalid="invalid">
              <option value="value">By Value (line_total)</option>
              <option value="qty">By Qty</option>
              <option value="weight">By Weight</option>
            </FormSelect>
          </template>
        </FormField>
        <FormField label="Notes">
          <template #default="{ id, invalid }">
            <Input v-model="form.notes" :id="id" :aria-invalid="invalid" placeholder="Import shipment xyz" />
          </template>
        </FormField>
      </FormSection>

      <FormSection title="Cost Lines" description="Freight, duty, insurance and any other landed cost." :icon="Landmark" plain dense>
        <div class="space-y-2.5">
          <div
            v-for="(l, i) in form.lines"
            :key="i"
            class="grid items-end gap-x-3 gap-y-2 rounded-xl border bg-card p-3 sm:grid-cols-[minmax(0,1.4fr)_8rem_minmax(0,1.4fr)_auto]"
          >
            <FormField label="Name">
              <template #default="{ id, invalid }">
                <Input v-model="l.name" :id="id" :aria-invalid="invalid" placeholder="Freight" />
              </template>
            </FormField>
            <FormField label="Amount BDT">
              <template #default="{ id, invalid }">
                <Input v-model.number="l.amount" :id="id" :aria-invalid="invalid" type="number" step="0.01" class="font-mono" />
              </template>
            </FormField>
            <FormField label="Expense Account (credit)" help="Optional — defaults to 6000.">
              <SearchableSelect v-model="l.account_id" endpoint="/accounts/api/options/accounts" placeholder="Defaults 6000" clearable />
            </FormField>
            <Button variant="ghost" size="icon" class="size-9 shrink-0 text-muted-foreground hover:text-destructive" :aria-label="`Remove cost line ${i + 1}`" @click="form.lines.splice(i, 1)">
              <X class="size-4" />
            </Button>
          </div>
          <Button type="button" variant="outline" size="sm" @click="add">
            <Plus class="size-4" /> Add Cost
          </Button>
        </div>
      </FormSection>

      <FormActions submit-label="Create Draft" :processing="false" cancel-href="route('purchase.landed-costs.index')" />
      </form>
    </div>
  </AdminLayout>
</template>
