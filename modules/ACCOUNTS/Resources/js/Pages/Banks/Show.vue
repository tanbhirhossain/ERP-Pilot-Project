<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Landmark } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import { formatCurrency } from '../../../../../CORE/Resources/js/Utils/format';
defineProps<{ bank:any }>();
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Banks',href:route('accounts.banks.index')},{title:bank.code}]">
    <Head :title="bank.code" />
    <div class="space-y-4 max-w-3xl mx-auto">
      <div class="bg-card border rounded-xl p-4">
        <div class="flex justify-between"><div><h1 class="text-xl font-bold flex items-center gap-2"><Landmark class="size-5"/> {{ bank.name }} <span class="font-mono text-sm px-2 py-0.5 bg-sky-500/10 text-sky-600 rounded">{{ bank.code }}</span></h1><p class="text-sm text-muted-foreground">{{ bank.bank_name }} · {{ bank.branch_name }} · {{ bank.account_no }}</p></div><Link :href="route('accounts.banks.edit', bank.id)"><Button variant="outline" size="sm">Edit</Button></Link></div>
        <div class="grid grid-cols-2 gap-3 mt-4"><div class="bg-muted/40 rounded p-3 border"><p class="text-xs text-muted-foreground">COA</p><p class="font-bold">{{ bank.account?.code }} {{ bank.account?.name }}</p></div><div class="bg-muted/40 rounded p-3 border"><p class="text-xs text-muted-foreground">Balance</p><p class="text-lg font-bold font-mono">{{ formatCurrency(Number(bank.current_balance)) }}</p></div></div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Transactions — {{ bank.transactions?.length ?? 0 }}</h3>
        <div v-if="!bank.transactions?.length" class="text-sm text-muted-foreground py-4 text-center">No transactions.</div>
        <div v-for="t in bank.transactions" :key="t.id" class="flex justify-between text-sm border-b py-1"><span>{{ t.transaction_date }} · {{ t.type }} · {{ t.reference_no }}</span><span class="font-mono">{{ formatCurrency(Number(t.amount)) }}</span></div>
      </div>
    </div>
  </AdminLayout>
</template>
