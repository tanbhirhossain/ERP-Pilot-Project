<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { CalendarDays, FileStack, ListChecks, PieChart, PlusCircle } from 'lucide-vue-next';

/**
 * The salary-sheet module's own navigation.
 *
 * Same contract as the fund and advance modules: breadcrumbs answer "where am I", this answers
 * "where else can I be". The tabs are a map of the *jobs* of monthly payroll, in the order a month
 * is actually closed — see the runs, inspect one, see where the money went, check it is releasable,
 * then start the next one. A tab bar that lists screens alphabetically is a file manager.
 *
 * Active state is derived from the URL rather than passed as a prop: a prop has to be kept in sync by
 * every caller and is wrong the moment one of them forgets.
 */

const page = usePage();
const path = computed(() => (page.url.split('?')[0] ?? '').replace(/\/+$/, ''));

interface Tab {
  key: string;
  label: string;
  href: string;
  icon: unknown;
  active: boolean;
  /** Hidden unless the user may start a run. */
  manageOnly?: boolean;
}

/**
 * Only the sheets themselves know their own ids, so the per-sheet tabs link to the *current* sheet
 * when there is one and to the run list otherwise. That keeps the bar stable across all five pages
 * without every page passing its own history.
 */
const sheetId = computed<number | null>(() => {
  const match = path.value.match(/^\/payroll\/salary-sheets\/(\d+)(\/|$)/);
  return match ? Number(match[1]) : null;
});

const tabs = computed<Tab[]>(() => {
  const base = '/payroll/salary-sheets';
  const current = sheetId.value;
  return [
    {
      key: 'overview',
      label: 'Overview',
      href: base,
      icon: CalendarDays,
      active: path.value === base,
    },
    {
      key: 'sheet',
      label: 'Sheet',
      href: current ? `${base}/${current}` : base,
      icon: FileStack,
      active: current !== null && (path.value === `${base}/${current}` || path.value === `${base}/create`),
    },
    {
      key: 'departments',
      label: 'Departments',
      href: current ? `${base}/${current}/departments` : base,
      icon: PieChart,
      active: current !== null && path.value === `${base}/${current}/departments`,
    },
    {
      key: 'release',
      label: 'Release',
      href: current ? `${base}/${current}/release-check` : base,
      icon: ListChecks,
      active: current !== null && path.value === `${base}/${current}/release-check`,
    },
    { key: 'new', label: 'New run', href: `${base}/create`, icon: PlusCircle, active: path.value === `${base}/create`, manageOnly: true },
  ];
});
</script>

<template>
  <div class="-mx-1 overflow-x-auto pb-1">
    <nav class="inline-flex items-center gap-1 rounded-xl border bg-muted/40 p-1" aria-label="Salary sheets">
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
