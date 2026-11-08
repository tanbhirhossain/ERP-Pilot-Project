<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';

interface EmployeeOption {
  value: string;
  label: string;
  description?: string;
}

const props = withDefaults(defineProps<{
  modelValue: string[];
  options?: EmployeeOption[];
  placeholder?: string;
}>(), {
  options: () => [],
  placeholder: 'Search employee name or ID…',
});

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();
const query = ref('');
const remoteOptions = ref<EmployeeOption[]>([]);
const selectedCache = ref<Record<string, EmployeeOption>>({});
const loading = ref(false);
const searchError = ref('');
let debounceTimer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | null = null;
let requestVersion = 0;

const allOptions = computed(() => {
  const unique = new Map<string, EmployeeOption>();
  for (const item of [...Object.values(selectedCache.value), ...props.options, ...remoteOptions.value]) {
    unique.set(String(item.value), item);
  }
  return [...unique.values()];
});

const visibleOptions = computed(() => {
  const term = query.value.trim().toLowerCase();
  const matches = term
    ? allOptions.value.filter((item) => item.label.toLowerCase().includes(term)
      || (item.description ?? '').toLowerCase().includes(term)
      || String(item.value).toLowerCase().includes(term))
    : allOptions.value;
  return matches.slice(0, 50);
});

const selectedOptions = computed(() => props.modelValue.map((id) =>
  allOptions.value.find((item) => String(item.value) === String(id)) ?? { value: id, label: id },
));

function rememberSelectedOption(id: string): void {
  const option = allOptions.value.find((item) => String(item.value) === String(id));
  if (option) selectedCache.value = { ...selectedCache.value, [id]: option };
}

function toggle(id: string): void {
  const next = new Set(props.modelValue);
  if (next.has(id)) {
    next.delete(id);
    const cache = { ...selectedCache.value };
    delete cache[id];
    selectedCache.value = cache;
  } else {
    rememberSelectedOption(id);
    next.add(id);
  }
  emit('update:modelValue', [...next]);
}

function remove(id: string): void {
  const cache = { ...selectedCache.value };
  delete cache[id];
  selectedCache.value = cache;
  emit('update:modelValue', props.modelValue.filter((value) => value !== id));
}

watch(query, (value) => {
  if (debounceTimer) clearTimeout(debounceTimer);
  controller?.abort();
  const version = ++requestVersion;
  searchError.value = '';
  const term = value.trim();

  if (term.length < 2) {
    remoteOptions.value = [];
    loading.value = false;
    return;
  }

  remoteOptions.value = [];
  debounceTimer = setTimeout(async () => {
    const requestController = new AbortController();
    controller = requestController;
    loading.value = true;

    try {
      const endpoint = route('hrm.api.options', { type: 'employees' });
      const response = await fetch(`${endpoint}?search=${encodeURIComponent(term)}&limit=50`, {
        signal: requestController.signal,
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
      });
      if (!response.ok) throw new Error(`Employee search failed (${response.status}).`);
      const payload = await response.json() as { items?: EmployeeOption[] };
      if (version === requestVersion) remoteOptions.value = Array.isArray(payload.items) ? payload.items : [];
    } catch (error) {
      if ((error as Error).name !== 'AbortError' && version === requestVersion) {
        searchError.value = 'Could not search employees. Check your connection or permissions and try again.';
      }
    } finally {
      if (version === requestVersion) loading.value = false;
    }
  }, 250);
});

watch(() => props.modelValue, (ids) => {
  const selected = new Set(ids.map(String));
  const cache = { ...selectedCache.value };
  for (const id of Object.keys(cache)) {
    if (!selected.has(id)) delete cache[id];
  }
  selectedCache.value = cache;
  for (const id of ids) rememberSelectedOption(String(id));
}, { immediate: true });

onBeforeUnmount(() => {
  if (debounceTimer) clearTimeout(debounceTimer);
  controller?.abort();
});
</script>

<template>
  <div class="space-y-2">
    <div class="relative">
      <input
        v-model="query"
        type="search"
        :placeholder="placeholder"
        autocomplete="off"
        aria-label="Search employees"
        class="h-10 w-full rounded-md border bg-background px-3 pr-24 text-sm"
      />
      <span v-if="loading" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">Searching…</span>
    </div>
    <p v-if="searchError" class="text-xs text-destructive">{{ searchError }}</p>
    <div v-if="selectedOptions.length" class="flex flex-wrap gap-1">
      <button
        v-for="employee in selectedOptions"
        :key="employee.value"
        type="button"
        class="rounded-full bg-primary/10 px-2 py-1 text-xs text-primary"
        @click="remove(employee.value)"
      >{{ employee.label }} ×</button>
    </div>
    <div class="max-h-48 overflow-auto rounded-md border p-2">
      <label
        v-for="employee in visibleOptions"
        :key="employee.value"
        class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-muted"
      >
        <input type="checkbox" :checked="modelValue.includes(employee.value)" @change="toggle(employee.value)" />
        <span>{{ employee.label }}</span>
      </label>
      <p v-if="!visibleOptions.length" class="p-2 text-xs text-muted-foreground">
        {{ loading ? 'Searching…' : query.trim().length < 2 ? 'Type at least 2 characters to search.' : 'No employees match.' }}
      </p>
    </div>
    <p class="text-xs text-muted-foreground">{{ modelValue.length }} employee(s) selected.</p>
  </div>
</template>
