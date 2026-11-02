<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface EmployeeOption {
  value: string;
  label: string;
  description?: string;
}

const props = withDefaults(defineProps<{
  modelValue: string;
  options?: EmployeeOption[];
  placeholder?: string;
}>(), {
  options: () => [],
  placeholder: 'Search by employee name or ID…',
});

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const root = ref<HTMLElement | null>(null);
const query = ref('');
const remoteOptions = ref<EmployeeOption[]>([]);
const selectedOption = ref<EmployeeOption | null>(null);
const focused = ref(false);
const loading = ref(false);
const searchError = ref('');
const activeIndex = ref(-1);
let debounceTimer: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | null = null;
let requestVersion = 0;

const allOptions = computed(() => {
  const unique = new Map<string, EmployeeOption>();
  for (const item of [...(selectedOption.value ? [selectedOption.value] : []), ...props.options, ...remoteOptions.value]) {
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

watch([() => props.modelValue, () => props.options, () => remoteOptions.value], ([value]) => {
  if (!value) {
    selectedOption.value = null;
    if (query.value) query.value = '';
    if (remoteOptions.value.length) remoteOptions.value = [];
    return;
  }

  const normalizedValue = String(value);
  const resolved = allOptions.value.find((item) => String(item.value) === normalizedValue);
  if (resolved) {
    selectedOption.value = resolved;
  } else if (String(selectedOption.value?.value ?? '') !== normalizedValue) {
    // Never display the previous employee's label when a parent changes the selected ID.
    // If the new ID is not in the current option set, show the ID until it is resolved.
    selectedOption.value = { value: normalizedValue, label: normalizedValue };
  }
}, { immediate: true });

watch(query, (value) => {
  if (debounceTimer) clearTimeout(debounceTimer);
  controller?.abort();
  const version = ++requestVersion;
  searchError.value = '';
  activeIndex.value = -1;
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

function selectEmployee(employee: EmployeeOption) {
  selectedOption.value = employee;
  emit('update:modelValue', employee.value);
  query.value = '';
  focused.value = false;
  activeIndex.value = -1;
}

function clearSelection() {
  selectedOption.value = null;
  emit('update:modelValue', '');
  query.value = '';
  focused.value = true;
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape') {
    focused.value = false;
    return;
  }
  if (event.key === 'ArrowDown') {
    event.preventDefault();
    focused.value = true;
    activeIndex.value = Math.min(activeIndex.value + 1, visibleOptions.value.length - 1);
  } else if (event.key === 'ArrowUp') {
    event.preventDefault();
    activeIndex.value = Math.max(activeIndex.value - 1, 0);
  } else if (event.key === 'Enter' && focused.value) {
    event.preventDefault();
    const option = visibleOptions.value[activeIndex.value] ?? visibleOptions.value[0];
    if (option) selectEmployee(option);
  }
}

function onOutsideClick(event: MouseEvent) {
  if (focused.value && root.value && !root.value.contains(event.target as Node)) focused.value = false;
}

onMounted(() => document.addEventListener('click', onOutsideClick, true));
onBeforeUnmount(() => {
  document.removeEventListener('click', onOutsideClick, true);
  if (debounceTimer) clearTimeout(debounceTimer);
  controller?.abort();
});
</script>

<template>
  <div ref="root" class="relative space-y-2">
    <div class="relative">
      <input
        v-model="query"
        type="search"
        :placeholder="placeholder"
        autocomplete="off"
        role="combobox"
        aria-autocomplete="list"
        :aria-expanded="focused"
        aria-controls="employee-search-results"
        class="h-10 w-full rounded-md border bg-background px-3 pr-10 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
        @focus="focused = true"
        @keydown="onKeydown"
      />
      <span v-if="loading" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-muted-foreground">Searching…</span>
      <button v-else-if="modelValue" type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground" aria-label="Clear employee selection" @click="clearSelection">×</button>
    </div>

    <p v-if="selectedOption" class="text-xs text-muted-foreground">Selected: <span class="font-medium text-foreground">{{ selectedOption.label }}</span></p>

    <div v-if="focused" id="employee-search-results" role="listbox" class="absolute left-0 top-full z-50 mt-1 max-h-64 w-full overflow-y-auto rounded-md border bg-popover p-1 text-popover-foreground shadow-lg">
      <p v-if="searchError" class="px-3 py-2 text-xs text-destructive">{{ searchError }}</p>
      <button
        v-for="(employee, index) in visibleOptions"
        :key="employee.value"
        type="button"
        role="option"
        :aria-selected="String(modelValue) === String(employee.value)"
        class="flex w-full flex-col items-start rounded px-3 py-2 text-left text-sm hover:bg-accent"
        :class="activeIndex === index ? 'bg-accent' : ''"
        @mousedown.prevent="selectEmployee(employee)"
      >
        <span>{{ employee.label }}</span>
        <span v-if="employee.description" class="text-xs text-muted-foreground">{{ employee.description }}</span>
      </button>
      <p v-if="!searchError && !loading && !visibleOptions.length" class="px-3 py-2 text-xs text-muted-foreground">
        {{ query.trim().length < 2 ? 'Type at least 2 characters to search.' : 'No employee matches this search.' }}
      </p>
    </div>
  </div>
</template>
