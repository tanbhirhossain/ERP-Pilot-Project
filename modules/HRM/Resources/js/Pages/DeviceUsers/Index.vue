<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Trash2, Link2, Download, AlertCircle } from 'lucide-vue-next';
import { ref, computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import PageHeader from '../../../../../CORE/Resources/js/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import SearchableSelect from '../../../../../CORE/Resources/js/Components/SearchableSelect.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { formatDate } from '../../../../../CORE/Resources/js/Utils/format';

interface Row {
  id: number;
  uid?: string;
  device_user_id: string;
  name?: string;
  employee_id?: string;
  employee?: { employee_id: string; full_name: string };
  created_at: string;
}

const props = defineProps<{ deviceUsers: Paginator<Row>; deviceOptions: any[]; employeeOptions: any[] }>();

const columns: DataTableColumn[] = [
  { key: 'device_user_id', label: 'Device User ID', sortable: true, width: '10rem' },
  { key: 'name', label: 'Name on Device', sortable: true },
  { key: 'employee', label: 'Mapped Employee (EMP-xxxx)', sortable: false },
  { key: 'created_at', label: 'Mapped At', sortable: true, width: '11rem' },
  { key: 'actions', label: '', align: 'right', exportable: false, width: '6rem' },
];

const form = useForm({ uid: '', device_user_id: '', name: '', employee_id: null as string | null });

// Sync helper — pick a device to pull its user list into global mapping
const syncDeviceId = ref<number | null>(null);
const syncing = ref(false);

const selectedDeviceLabel = computed(() => {
  const opt = props.deviceOptions.find((o: any) => o.value === syncDeviceId.value);
  return opt?.label ?? 'unknown';
});

function submit() {
  form.post(route('hrm.device-users.store'), { preserveScroll: true, onSuccess() { form.reset(); } });
}

function del(id: number) {
  if (!confirm('Remove this mapping? Punch IDs will become orphan until remapped.')) return;
  router.delete(route('hrm.device-users.destroy', id), { preserveScroll: true });
}

function syncFromSelectedDevice() {
  if (!syncDeviceId.value) {
    alert('Select a device first.');
    return;
  }
  syncing.value = true;
  router.post(route('hrm.devices.sync-users', syncDeviceId.value), {}, {
    preserveScroll: true,
    onFinish() { syncing.value = false; },
  });
}
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'HRM' }, { title: 'Device Users' }]">
    <Head title="Device ↔ Employee Mapping" />

    <div class="flex flex-col gap-1">
      <p class="text-sm text-muted-foreground">
        One <span class="font-mono font-semibold">Device User ID</span> (ZK UID like <code class="bg-muted px-1 rounded">1001</code>) maps to one
        <span class="font-mono font-semibold">EMP-xxxx</span> <strong>globally</strong> — punch-in on <em>Device A</em> and punch-out on <em>Device B</em> resolves to the same employee. No device binding.
      </p>
      <div class="flex items-start gap-2 bg-amber-500/10 border border-amber-500/20 rounded-xl p-3 text-sm">
        <AlertCircle class="size-4 text-amber-600 mt-0.5 shrink-0" />
        <div>
          <p class="font-medium text-amber-900 dark:text-amber-100">How it works</p>
          <p class="text-muted-foreground leading-relaxed">
            Attendance logs carry <code class="font-mono">device_user_id</code> + <code class="font-mono">punch_time</code> + <code class="font-mono">device_id</code> (only for audit).
            The engine resolves <code class="font-mono">device_user_id</code> → <code class="font-mono">employee_id</code> via this table <strong>ignoring which device</strong> sent the punch. Deduplication uses
            <code class="font-mono">device_user_id + punch_time + device_id</code> so same second on same device is not double-counted, but same UID on different devices is distinct and expected.
          </p>
        </div>
      </div>
    </div>

    <!-- Create global mapping -->
    <div class="bg-card border rounded-xl p-4">
      <h3 class="font-semibold text-sm mb-3 flex items-center gap-2"><Link2 class="size-4" /> New Global Mapping</h3>
      <form @submit.prevent="submit" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <div class="space-y-1">
          <Label>Device User ID *</Label>
          <Input v-model="form.device_user_id" placeholder="e.g. 1 or 1001" />
          <p v-if="form.errors.device_user_id" class="text-xs text-destructive">{{ form.errors.device_user_id }}</p>
          <p class="text-xs text-muted-foreground">ZK UID — must be unique. Same on all devices for this employee.</p>
        </div>
        <div class="space-y-1">
          <Label>Employee *</Label>
          <SearchableSelect v-model="form.employee_id" :options="employeeOptions" endpoint="/hrm/api/options/employees" placeholder="Select EMP-xxxx" />
          <p v-if="form.errors.employee_id" class="text-xs text-destructive">{{ form.errors.employee_id }}</p>
          <p class="text-xs text-muted-foreground">1:1 — one employee → one Device ID.</p>
        </div>
        <div class="space-y-1">
          <Label>Name (from device)</Label>
          <Input v-model="form.name" placeholder="As enrolled on device" />
          <p class="text-xs text-muted-foreground">Optional — shown in lists.</p>
        </div>
        <div class="space-y-1">
          <Label>UID (optional)</Label>
          <Input v-model="form.uid" placeholder="ZK internal UID" />
          <p class="text-xs text-muted-foreground">Alternate lookup key.</p>
        </div>
        <div class="sm:col-span-4 flex items-center gap-2 pt-1">
          <Button type="submit" size="sm" :disabled="form.processing"><Link2 class="size-4" /> Link</Button>
          <span class="text-xs text-muted-foreground">No device needed — mapping is cross-device.</span>
        </div>
      </form>
    </div>

    <!-- Sync from device helper -->
    <div class="bg-card border rounded-xl p-4">
      <h3 class="font-semibold text-sm mb-2 flex items-center gap-2"><Download class="size-4" /> Sync User IDs from a Device (one-time helper)</h3>
      <p class="text-xs text-muted-foreground mb-3">
        Pulls all enrolled UIDs from the selected device and creates <strong>global</strong> rows with <code class="font-mono">device_id = null</code>.
        Already-existing <code class="font-mono">device_user_id</code> are skipped (no duplicates across devices). Afterwards, edit each row to link it to an employee.
      </p>
      <div class="flex flex-col sm:flex-row gap-2 items-end">
        <div class="flex-1 min-w-[240px] space-y-1">
          <Label>Select Device to Sync From</Label>
          <SearchableSelect v-model="syncDeviceId" :options="deviceOptions" placeholder="e.g. Gate (192.168.1.10)" />
        </div>
        <Button type="button" variant="secondary" size="sm" :disabled="!syncDeviceId || syncing" @click="syncFromSelectedDevice">
          <Download class="size-4" />
          {{ syncing ? 'Syncing…' : `Sync from ${selectedDeviceLabel}` }}
        </Button>
      </div>
      <p class="text-xs text-amber-600 mt-2" v-if="deviceOptions.length === 0">No devices configured — <a :href="route('hrm.devices.index')" class="underline">add a device</a> first.</p>
    </div>

    <!-- Global mapping table -->
        <PageHeader title="Employee ↔ Device Mapping" description="One Device User ID (ZK UID like 1001) maps to one
        EMP-xxxx globally — punch-in on Device A and punch-out on Device B resolves to the same employee. No device binding." :icon="Link2" >
    <DataTable
      route-name="hrm.device-users.index"
      title="Global Mappings"
      :columns="columns"
      :rows="(deviceUsers.data as unknown as Record<string, unknown>[])"
      :meta="deviceUsers.meta"
    >
      <template #cell-device_user_id="{ row }">
        <span class="font-mono font-semibold bg-muted px-1.5 py-0.5 rounded text-sm">{{ row.device_user_id }}</span>
        <span v-if="row.uid" class="text-xs font-mono text-muted-foreground ml-1">UID:{{ row.uid }}</span>
      </template>
      <template #cell-name="{ row }">
        <span class="text-sm">{{ row.name || '—' }}</span>
      </template>
      <template #cell-employee="{ row }">
        <span v-if="row.employee" class="text-sm font-medium">
          {{ row.employee.full_name }} <span class="font-mono text-xs text-muted-foreground">({{ row.employee.employee_id }})</span>
        </span>
        <span v-else class="text-sm text-amber-600 font-medium">Unmapped — assign employee</span>
      </template>
      <template #cell-created_at="{ row }">
        <span class="text-sm text-muted-foreground">{{ formatDate(row.created_at) }}</span>
      </template>
      <template #cell-actions="{ row }">
        <div class="flex justify-end gap-1">
          <button
            @click="del(row.id)"
            class="size-8 grid place-items-center rounded-md hover:bg-destructive/10 hover:text-destructive"
            title="Remove mapping"
          >
            <Trash2 class="size-4" />
          </button>
        </div>
      </template>
    </DataTable>
      </PageHeader>
  </AdminLayout>
</template>
