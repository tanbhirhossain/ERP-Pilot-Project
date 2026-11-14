<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

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
const WINDOW_START = 80;
const WINDOW_STEP = 120;
const BROWSE_LIMIT = 200;
const SEARCH_LIMIT = 50;
const DEBOUNCE_MS = 250;

const browseTruncated = ref(false);
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

const filteredOptions = computed(() => {
  const term = query.value.trim().toLowerCase();
  if (!term) return allOptions.value;

  return allOptions.value.filter((item) => item.label.toLowerCase().includes(term)
    || (item.description ?? '').toLowerCase().includes(term)
    || String(item.value).toLowerCase().includes(term));
});

/**
 * Windowed, with the remainder one click away. A silent 50-row ceiling is what made these pickers look as
 * though they "cannot see all employees": everyone past the first 50 names was unreachable, and nothing on
 * screen said a 51st existed.
 */
const visibleCount = ref(WINDOW_START);
const visibleOptions = computed(() => filteredOptions.value.slice(0, visibleCount.value));
const hiddenCount = computed(() => Math.max(0, filteredOptions.value.length - visibleOptions.value.length));

function showMore(): void {
  visibleCount.value += WINDOW_STEP;
}

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

async function runSearch(term: string): Promise<void> {
  const requestController = new AbortController();
  controller = requestController;
  const version = ++requestVersion;
  loading.value = true;

  try {
    const endpoint = route('hrm.api.options', { type: 'employees' });
    // An empty term means "give me the roster", not "give me nothing" — that is the browse path.
    const limit = term ? SEARCH_LIMIT : BROWSE_LIMIT;
    const response = await fetch(`${endpoint}?search=${encodeURIComponent(term)}&limit=${limit}`, {
      signal: requestController.signal,
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
    });
    if (!response.ok) throw new Error(`Employee search failed (${response.status}).`);
    const payload = await response.json() as { items?: EmployeeOption[] };

    if (version === requestVersion) {
      remoteOptions.value = Array.isArray(payload.items) ? payload.items : [];
      browseTruncated.value = term === '' && remoteOptions.value.length >= limit;
    }
  } catch (error) {
    if ((error as Error).name !== 'AbortError' && version === requestVersion) {
      searchError.value = 'Could not search employees. Check your connection or permissions and try again.';
    }
  } finally {
    if (version === requestVersion) loading.value = false;
  }
}

watch(query, (value) => {
  if (debounceTimer) clearTimeout(debounceTimer);
  controller?.abort();
  requestVersion++;
  searchError.value = '';
  visibleCount.value = WINDOW_START;
  const term = value.trim();

  if (!term) {
    // Keep the browse list that is already there: clearing the box must not empty the picker.
    loading.value = false;
    return;
  }

  debounceTimer = setTimeout(() => { void runSearch(term); }, DEBOUNCE_MS);
});

// The multi picker has no dropdown to open, so the roster is fetched as soon as the form mounts —
// provided the page seeded it with nothing, which is the case when it has more employees than its cap.
onMounted(() => {
  if (!props.options.length) void runSearch('');
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
        {{ loading ? 'Searching…' : query.trim() ? 'No employees match.' : 'Type a name or employee ID to search the whole roster.' }}
      </p>
      <div v-else-if="browseTruncated || hiddenCount" class="border-t px-2 pt-2 text-xs text-muted-foreground">
        <p v-if="browseTruncated && !query.trim()">Showing the first {{ BROWSE_LIMIT }} employees — type to search all of them.</p>
        <button v-if="hiddenCount" type="button" class="font-medium text-primary hover:underline" @click="showMore">
          Show {{ Math.min(WINDOW_STEP, hiddenCount) }} more · {{ visibleOptions.length }} of {{ filteredOptions.length }} listed
        </button>
      </div>
    </div>
    <p class="text-xs text-muted-foreground">{{ modelValue.length }} employee(s) selected.</p>
  </div>
</template>
