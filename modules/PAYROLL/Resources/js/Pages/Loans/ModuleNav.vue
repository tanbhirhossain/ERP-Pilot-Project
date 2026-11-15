<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { CalendarRange, FileStack, LayoutDashboard, PlusCircle } from 'lucide-vue-next';

/**
 * The module's own navigation.
 *
 * A module of five screens needs a way to move between them that is not the sidebar — the sidebar
 * collapses the whole module into one entry, so once you are inside it there is no visible map of
 * where else you can go. Breadcrumbs answer "where am I"; this answers "where can I be".
 *
 * The active tab is derived from the current URL rather than passed in as a prop, because a prop
 * has to be kept in sync by every caller and is wrong the moment one of them forgets. `loans/5` has
 * no tab of its own, so it keeps the register highlighted — which is where it was opened from.
 */

defineProps<{ canManage?: boolean }>();

const page = usePage();
const path = computed(() => (page.url.split('?')[0] ?? '').replace(/\/+$/, ''));

interface Tab {
  key: string;
  label: string;
  href: string;
  icon: unknown;
  /** True when this tab is the page being shown. */
  active: boolean;
}

const tabs = computed<Tab[]>(() => {
  const base = '/payroll/loans';
  const items: Tab[] = [
    { key: 'overview', label: 'Overview', href: base, icon: LayoutDashboard, active: path.value === base },
    {
      key: 'register',
      label: 'Register',
      href: `${base}/register`,
      icon: FileStack,
      // A single advance is reached from the register, so the register owns that URL too.
      active: path.value === `${base}/register` || /^\/payroll\/loans\/\d+$/.test(path.value),
    },
    { key: 'schedule', label: 'Schedule', href: `${base}/schedule`, icon: CalendarRange, active: path.value === `${base}/schedule` },
    { key: 'new', label: 'New request', href: `${base}/create`, icon: PlusCircle, active: path.value === `${base}/create` },
  ];
  return items;
});
</script>

<template>
  <div class="-mx-1 overflow-x-auto pb-1">
    <nav class="inline-flex items-center gap-1 rounded-xl border bg-muted/40 p-1" aria-label="Staff advances">
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
