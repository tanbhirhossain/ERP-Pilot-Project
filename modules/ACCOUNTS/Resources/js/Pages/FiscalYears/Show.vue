<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import StatusBadge from '../../../../../CORE/Resources/js/Components/StatusBadge.vue';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';
defineProps<{ fiscalYear:any }>();
function close(id:number){ if(confirm('Close FY? All draft journals must be posted/voided. Periods will be locked.')) router.post(route('accounts.fiscal-years.close', id)); }
function reopen(id:number){ if(confirm('Reopen FY?')) router.post(route('accounts.fiscal-years.reopen', id)); }
</script>
<template>
  <AdminLayout :breadcrumbs="[{title:'Fiscal Years',href:route('accounts.fiscal-years.index')},{title:fiscalYear.code}]">
    <Head :title="fiscalYear.code" />
    <div class="space-y-4 max-w-4xl mx-auto">
      <div class="bg-card border rounded-xl p-4">
        <div class="flex justify-between gap-4">
          <div><h1 class="text-xl font-bold">{{ fiscalYear.name }} <span class="font-mono text-sm px-2 py-0.5 bg-amber-500/10 text-amber-600 rounded">{{ fiscalYear.code }}</span> <StatusBadge :label="fiscalYear.status" :tone="fiscalYear.status==='open'?'success':'danger'" /></h1><p class="text-sm text-muted-foreground">{{ formatDate(fiscalYear.start_date) }} → {{ formatDate(fiscalYear.end_date) }}</p></div>
          <div class="flex gap-2">
            <Button v-if="fiscalYear.status==='open'" @click="close(fiscalYear.id)" variant="outline" size="sm">Close FY</Button>
            <Button v-if="fiscalYear.status==='closed'" @click="reopen(fiscalYear.id)" size="sm">Reopen</Button>
            <Link :href="route('accounts.fiscal-years.edit', fiscalYear.id)"><Button variant="outline" size="sm">Edit</Button></Link>
          </div>
        </div>
      </div>
      <div class="bg-card border rounded-xl p-4">
        <h3 class="font-semibold mb-2">Periods — {{ fiscalYear.periods.length }}</h3>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-2">
          <div v-for="p in fiscalYear.periods" :key="p.id" class="border rounded p-2 text-sm" :class="p.status==='open'?'bg-emerald-500/5':p.status==='closed'?'bg-amber-500/10':'bg-muted'"><p class="font-semibold">{{ p.name }}</p><p class="text-xs text-muted-foreground">{{ p.start_date }} → {{ p.end_date }}</p><StatusBadge :label="p.status" :tone="p.status==='open'?'success':'warning'" class="mt-1"/></div>
        </div>
      </div>
    </div>
  </AdminLayout>
</template>
