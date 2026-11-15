<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { CalendarCheck2, CalendarDays, ClipboardList, FileBarChart2, Settings2, Tag } from 'lucide-vue-next';

/**
 * Leave's own navigation.
 *
 * Six tabs, because leave is six jobs and not one: decide today (Desk), find an application
 * (Requests), work the queue (Approvals), see what people have left (Balances), change the rules
 * (Types), and close the year (Year-end). Reports sits with the numbers.
 *
 * The old module was a single table with four counters — the approval decision, the balance register
 * and the type policy all lived on top of each other, and the person approving a request was reading
 * the same screen as the person editing the policy that priced it.
 */

const page = usePage();
const path = computed(() => (page.url.split('?')[0] ?? '').replace(/\/+$/, ''));

interface Tab { key: string; label: string; href: string; icon: unknown; active: boolean; match: (p: string) => boolean }

const tabs = computed<Tab[]>(() => {
  const items: Array<Omit<Tab, 'active'>> = [
    { key: 'desk', label: 'Desk', href: '/hrm/leaves', icon: CalendarCheck2, match: (p) => p === '/hrm/leaves' },
    { key: 'requests', label: 'Requests', href: '/hrm/leaves/requests', icon: ClipboardList, match: (p) => p === '/hrm/leaves/requests' || /^\/hrm\/leaves\/\d+$/.test(p) || p === '/hrm/leaves/create' || /^\/hrm\/leaves\/\d+\/edit$/.test(p) },
    { key: 'approvals', label: 'Approvals', href: '/hrm/leaves/approvals', icon: CalendarCheck2, match: (p) => p === '/hrm/leaves/approvals' },
    { key: 'balances', label: 'Balances', href: '/hrm/leaves/balances', icon: CalendarDays, match: (p) => p === '/hrm/leaves/balances' },
    { key: 'types', label: 'Types & policy', href: '/hrm/leave-types', icon: Tag, match: (p) => p.startsWith('/hrm/leave-types') },
    { key: 'year-end', label: 'Year-end', href: '/hrm/leave-year-end', icon: Settings2, match: (p) => p.startsWith('/hrm/leave-year-end') },
    { key: 'reports', label: 'Reports', href: '/hrm/leaves/reports', icon: FileBarChart2, match: (p) => p === '/hrm/leaves/reports' },
  ];

  return items.map((item) => ({ ...item, active: item.match(path.value) }));
});
</script>

<template>
  <div class="-mx-1 overflow-x-auto pb-1">
    <nav class="inline-flex items-center gap-1 rounded-xl border bg-muted/40 p-1" aria-label="Leave">
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
