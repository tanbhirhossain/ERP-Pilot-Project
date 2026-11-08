<script setup lang="ts">
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import { useMagicKeys, whenever } from '@vueuse/core';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Search, Clock3, ArrowRight, Command } from 'lucide-vue-next';
import { sidebarMenu } from '../Config/menu';
import { useAcl } from '../Composables/useAcl';

interface PaletteItem {
  key: string;
  label: string;
  route?: string;
  icon?: any;
  group: string;
  keywords?: string;
}

const { can } = useAcl();
const open = ref(false);
const query = ref('');
const recentKey = 'core.recent.pages.v1';

function collectMenuItems(): PaletteItem[] {
  const items: PaletteItem[] = [];
  function walk(nodes: any[], group: string) {
    for (const n of nodes) {
      if (n.permission && !can(n.permission)) continue;
      if (n.route) {
        items.push({
          key: n.key,
          label: n.label,
          route: n.route,
          icon: n.icon,
          group,
          keywords: `${n.label} ${group} ${n.key}`.toLowerCase(),
        });
      }
      if (n.children) walk(n.children, n.label);
    }
  }
  walk(sidebarMenu, 'Menu');
  return items;
}

const allItems = collectMenuItems();

const recentPages = computed<PaletteItem[]>(() => {
  try {
    const raw = localStorage.getItem(recentKey);
    if (!raw) return [];
    const keys: string[] = JSON.parse(raw);
    return keys.map(k => allItems.find(i => i.key === k)).filter(Boolean) as PaletteItem[];
  } catch { return []; }
});

function pushRecent(key: string) {
  try {
    const raw = localStorage.getItem(recentKey);
    let arr: string[] = raw ? JSON.parse(raw) : [];
    arr = [key, ...arr.filter(k => k !== key)].slice(0, 6);
    localStorage.setItem(recentKey, JSON.stringify(arr));
  } catch {}
}

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return recentPages.value.length ? recentPages.value : allItems.slice(0, 8);
  return allItems.filter(i => i.keywords?.includes(q) || i.label.toLowerCase().includes(q)).slice(0, 10);
});

function navigate(item: PaletteItem) {
  if (!item.route) return;
  pushRecent(item.key);
  open.value = false;
  query.value = '';
  router.visit(route(item.route));
}

// Keyboard shortcut: Cmd+K / Ctrl+K
const { Meta_K, Ctrl_K } = useMagicKeys();
whenever(() => Meta_K.value || Ctrl_K.value, () => {
  open.value = !open.value;
});

function onKeydown(e: KeyboardEvent) {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    open.value = !open.value;
  }
  if (e.key === '/' && !open.value && !(e.target instanceof HTMLInputElement)) {
    // Quick open with "/" when not in input
    const active = document.activeElement;
    if (!(active instanceof HTMLInputElement) && !(active instanceof HTMLTextAreaElement)) {
      e.preventDefault();
      open.value = true;
    }
  }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

watch(open, (v) => {
  if (v) setTimeout(() => document.getElementById('cmd-input')?.focus(), 50);
});
</script>

<template>
  <!-- Trigger button (used in header) -->
  <slot :open="() => open = true" />

  <Dialog v-model:open="open">
    <DialogContent class="top-[20%] max-w-lg p-0 gap-0 overflow-hidden border shadow-2xl">
      <div class="flex items-center gap-2 border-b px-3 py-2">
        <Search class="size-4 text-muted-foreground" />
        <Input
          id="cmd-input"
          v-model="query"
          placeholder="Search pages, vendors, invoices… (try 'purchase' or 'leave')"
          class="h-8 border-0 shadow-none focus-visible:ring-0"
        />
        <span class="hidden sm:inline-flex items-center gap-1 rounded bg-muted px-1.5 py-0.5 text-[10px]"><Command class="size-3"/>K</span>
      </div>

      <div class="max-h-80 overflow-y-auto p-2">
        <p v-if="query === '' && recentPages.length" class="px-2 py-1 text-[11px] uppercase tracking-widest text-muted-foreground flex items-center gap-1"><Clock3 class="size-3"/> Recent</p>
        <p v-else-if="query === ''" class="px-2 py-1 text-[11px] uppercase tracking-widest text-muted-foreground">Quick links</p>
        <p v-else class="px-2 py-1 text-[11px] uppercase tracking-widest text-muted-foreground">Results for “{{ query }}” — {{ filtered.length }}</p>

        <button
          v-for="item in filtered"
          :key="item.key"
          class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm hover:bg-accent text-left"
          @click="navigate(item)"
        >
          <component :is="item.icon" v-if="item.icon" class="size-4 text-muted-foreground" />
          <div class="flex-1">
            <div class="font-medium">{{ item.label }}</div>
            <div class="text-xs text-muted-foreground">{{ item.group }} • {{ item.key }}</div>
          </div>
          <ArrowRight class="size-3.5 text-muted-foreground" />
        </button>

        <div v-if="filtered.length === 0" class="p-6 text-center text-sm text-muted-foreground">
          No matches for “{{ query }}”. Try a different keyword.
        </div>
      </div>

      <div class="flex items-center justify-between border-t bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
        <span>↑↓ Navigate • Enter Select • Esc Close</span>
        <span class="hidden sm:inline">Press <kbd class="rounded bg-background border px-1">/</kbd> to open</span>
      </div>
    </DialogContent>
  </Dialog>
</template>
