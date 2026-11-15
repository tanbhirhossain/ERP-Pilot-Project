<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { CalendarRange, ClipboardCheck, RefreshCw, Table2 } from 'lucide-vue-next';

/**
 * The cutlist module's own navigation.
 *
 * The three tabs are the three jobs a month goes through, in order: generate the proposal from HRM,
 * review the rows, sign it off. The old screen put all three on one page — a generate form, a 981-row
 * table with an Adjust button on every row, and an Approve button in the same table — so the sign-off
 * sat beside the thing it was supposed to be judging.
 */

const page = usePage();
const path = computed(() => (page.url.split('?')[0] ?? '').replace(/\/+$/, ''));

const cutlistId = computed<number | null>(() => {
  const match = path.value.match(/^\/payroll\/cutlists\/(\d+)(\/|$)/);
  return match ? Number(match[1]) : null;
});

interface Tab { key: string; label: string; href: string; icon: unknown; active: boolean }

const tabs = computed<Tab[]>(() => {
  const base = '/payroll/cutlists';
  const id = cutlistId.value;
  return [
    { key: 'periods', label: 'Periods', href: base, icon: CalendarRange, active: path.value === base },
    { key: 'review', label: 'Review rows', href: id ? `${base}/${id}` : base, icon: Table2, active: id !== null && path.value === `${base}/${id}` },
    { key: 'signoff', label: 'Sign off', href: id ? `${base}/${id}/sign-off` : base, icon: ClipboardCheck, active: id !== null && path.value === `${base}/${id}/sign-off` },
    { key: 'generate', label: 'Generate', href: `${base}#generate`, icon: RefreshCw, active: false },
  ];
});
</script>

<template>
  <div class="-mx-1 overflow-x-auto pb-1">
    <nav class="inline-flex items-center gap-1 rounded-xl border bg-muted/40 p-1" aria-label="Salary cutlists">
      <button
        v-for="tab in tabs"
        :key="tab.key"
        type="button"
        class="flex items-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1.5 text-[13px] font-medium transition"
        :class="tab.active
          ? 'bg-card text-foreground shadow-sm ring-1 ring-border'
          : 'text-muted-foreground hover:bg-card/60 hover:text-foreground'"
        :aria-current="tab.active ? 'page' : undefined"
        @click="router.visit(tab.href)"
      >
        <component :is="tab.icon" class="size-3.5" />
        {{ tab.label }}
      </button>
    </nav>
  </div>
</template>
