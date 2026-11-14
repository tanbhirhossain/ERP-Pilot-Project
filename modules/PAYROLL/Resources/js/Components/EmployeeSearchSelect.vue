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
const WINDOW_START = 80;
const WINDOW_STEP = 120;
const BROWSE_LIMIT = 200;
const SEARCH_LIMIT = 50;
const DEBOUNCE_MS = 250;

const browseTruncated = ref(false);
const browsedOnce = ref(false);
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

const filteredOptions = computed(() => {
  const term = query.value.trim().toLowerCase();
  if (!term) return allOptions.value;

  return allOptions.value.filter((item) => item.label.toLowerCase().includes(term)
    || (item.description ?? '').toLowerCase().includes(term)
    || String(item.value).toLowerCase().includes(term));
});

/**
 * A scrollable list of 5,000 names is not usable, and silently showing the first 50 is worse — it is how
 * "I cannot see all employees" was reported. So the list is windowed *and says so*, with the rest one click
 * away.
 */
const visibleCount = ref(WINDOW_START);
const visibleOptions = computed(() => filteredOptions.value.slice(0, visibleCount.value));
const hiddenCount = computed(() => Math.max(0, filteredOptions.value.length - visibleOptions.value.length));

function showMore() {
  visibleCount.value += WINDOW_STEP;
}

/**
 * Resolve the label for whatever the parent has selected.
 *
 * This used to be one watcher over `[modelValue, options, remoteOptions]`, and that shared source list is
 * the bug: `remoteOptions` is reassigned by the query watcher on every keystroke, so this callback ran on
 * every keystroke too — and with nothing selected yet (`modelValue: ''`, which is every form's initial
 * state) its first branch wiped `query` and aborted the search it had just scheduled. The input erased
 * itself as you typed, the debounce never fired, and the list stayed frozen on the first page of names the
 * controller happened to pass in. Split by cause: a *selection* change resets the display, an *option-set*
 * change only re-resolves the label.
 */
function syncSelected(value: string): void {
  if (!value) {
    selectedOption.value = null;
    if (query.value) query.value = '';
    if (remoteOptions.value.length) remoteOptions.value = [];
    return;
  }

  const resolved = allOptions.value.find((item) => String(item.value) === String(value));
  if (resolved) {
    selectedOption.value = resolved;
  } else if (String(selectedOption.value?.value ?? '') !== String(value)) {
    // Never display the previous employee's label when a parent changes the selected ID.
    // If the new ID is not in the current option set, show the ID until it is resolved.
    selectedOption.value = { value: String(value), label: String(value) };
  }
}

watch(() => props.modelValue, syncSelected, { immediate: true });
watch([() => props.options, () => remoteOptions.value], () => {
  if (props.modelValue) syncSelected(props.modelValue);
});

async function runSearch(term: string): Promise<void> {
  const requestController = new AbortController();
  controller = requestController;
  const version = ++requestVersion;
  loading.value = true;

  try {
    const endpoint = route('hrm.api.options', { type: 'employees' });
    // An empty term is not "no search", it is "show me the roster": that is what makes the picker able to
    // browse past whatever the page passed as a seed.
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
  activeIndex.value = -1;
  visibleCount.value = WINDOW_START;
  const term = value.trim();

  if (!term) {
    // Keep whatever browse list we already fetched: clearing the box should not empty the picker.
    loading.value = false;
    return;
  }

  debounceTimer = setTimeout(() => { void runSearch(term); }, DEBOUNCE_MS);
});

function selectEmployee(employee: EmployeeOption) {
  selectedOption.value = employee;
  emit('update:modelValue', employee.value);
  query.value = '';
  focused.value = false;
  activeIndex.value = -1;
  visibleCount.value = WINDOW_START;
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

/** Opening the list is also the moment to ask the server for the roster, once, if the page seeded nothing. */
function openList() {
  focused.value = true;
  visibleCount.value = WINDOW_START;

  if (browsedOnce.value || query.value.trim()) return;

  browsedOnce.value = true;
  void runSearch('');
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
        @focus="openList"
        @keydown="onKeydown"
        @click="openList"
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
        {{ query.trim() ? 'No employee matches this search.' : 'Type a name or employee ID to search the whole roster.' }}
      </p>
      <div v-else-if="!searchError" class="border-t px-3 py-2 text-xs text-muted-foreground">
        <p v-if="browseTruncated && !query.trim()">Showing the first {{ BROWSE_LIMIT }} employees — type to search all of them.</p>
        <button v-if="hiddenCount" type="button" class="font-medium text-primary hover:underline" @mousedown.prevent="showMore">
          Show {{ Math.min(WINDOW_STEP, hiddenCount) }} more · {{ visibleOptions.length }} of {{ filteredOptions.length }} listed
        </button>
        <p v-else>{{ filteredOptions.length }} employee{{ filteredOptions.length === 1 ? '' : 's' }} listed</p>
      </div>
    </div>
  </div>
</template>
