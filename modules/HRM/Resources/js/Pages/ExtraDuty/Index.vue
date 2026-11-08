<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { Check, X, UserCheck, Clock3 } from 'lucide-vue-next';
import AdminLayout from '../../../../../CORE/Resources/js/Layouts/AdminLayout.vue';
import DataTable, { type DataTableColumn } from '../../../../../CORE/Resources/js/Components/DataTable.vue';
import type { Paginator } from '../../../../../CORE/Resources/js/types';
import { confirmDialog, promptDialog } from '../../../../../CORE/Resources/js/Composables/useConfirm';
import { useToast } from '../../../../../CORE/Resources/js/Composables/useToast';
import { Button } from '@/components/ui/button';

interface Row {
  id: number; employee_id: string; employee: string; overtime_date: string; overtime_type: string;
  hours: number; source_attendance_hours: number; attendance_overtime_hours: number;
  worked_hours: number; attendance_status: string; status: string;
}
defineProps<{ requests: Paginator<Row>; queueRoute: string; kind: string; canApprove: boolean; filterDefs: any[] }>();
const editedHours = reactive<Record<number, number>>({});
const toast = useToast();
const columns: DataTableColumn[] = [
  { key: 'employee_id', label: 'Employee', sortable: true },
  { key: 'overtime_date', label: 'Date', sortable: true },
  { key: 'hours', label: 'Approved hours', align: 'center' },
  { key: 'worked_hours', label: 'Attendance hours', align: 'center' },
  { key: 'attendance_status', label: 'Attendance status', align: 'center' },
  { key: 'actions', label: 'Decision', align: 'right', exportable: false, width: '9rem' },
];
function setHours(id: number, event: Event) { editedHours[id] = Number((event.target as HTMLInputElement).value); }
async function decide(row: Row, decision: 'approve' | 'reject') {
  if (decision === 'reject') {
    const reason = await promptDialog({
      title: `Reject ${row.employee}'s extra duty request`,
      description: 'The reason is written to the audit trail and shown to the employee.',
      inputLabel: 'Rejection reason',
      inputPlaceholder: 'Why is this request being rejected?',
      inputType: 'textarea',
      confirmLabel: 'Reject request',
    });
    if (reason === null) return;
    // window.prompt() swallowed this case: a too-short answer did nothing and said nothing.
    if (reason.trim().length < 3) {
      toast.warning('A rejection reason needs at least three characters.');
      return;
    }
    router.post(route('hrm.overtimes.reject', row.id), { reason: reason.trim() }, { preserveScroll: true, onError: (errors) => toast.error(Object.values(errors).join(' ')) });
    return;
  }
  const hours = Number(editedHours[row.id] ?? row.hours);
  if (!(hours > 0) || hours > row.source_attendance_hours || hours > 24) {
    toast.warning(`Approved hours must be greater than zero and no more than the source attendance amount (${row.source_attendance_hours.toFixed(2)} h).`);
    return;
  }
  router.post(route('hrm.overtimes.approve', row.id), { hours }, { preserveScroll: true, onError: (errors) => toast.error(Object.values(errors).join(' ')) });
}
async function syncAttendance() {
  const proceed = await confirmDialog({
    title: 'Backfill approval requests',
    description: 'Backfill missing Extra Duty approval requests from all eligible historical daily attendance records?',
    confirmLabel: 'Backfill',
    danger: false,
  });
  if (!proceed) return;
  router.post(route('hrm.overtimes.sync-attendance'), { kind: 'Extra Duty' }, { preserveScroll: true });
}
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'OT & ED' }, { title: 'Extra Duty Approval' }]">
    <Head title="Extra Duty Approval" />
    <div class="flex items-start justify-between gap-4">
      <div>
        <h1 class="flex items-center gap-2 text-xl font-bold"><UserCheck class="size-5" /> Extra Duty Approval</h1>
        <p class="mt-1 text-sm text-muted-foreground">Attendance days classified as Extra Duty are loaded automatically for the recent period. Review/edit payable hours; only approved hours enter payroll.</p>
      </div>
      <div class="flex gap-2">
        <Button v-if="canApprove" variant="outline" @click="syncAttendance">Backfill older attendance</Button>
        <Link :href="route('hrm.attendance.dailies.index')"><Button variant="outline">Daily attendance</Button></Link>
      </div>
    </div>
    <DataTable :route-name="queueRoute" title="Pending attendance approvals" :columns="columns" :rows="requests.data as unknown as Record<string, unknown>[]" :meta="requests.meta" :filter-defs="filterDefs">
      <template #cell-employee_id="{ row }"><div><p class="font-mono font-semibold">{{ row.employee_id }}</p><p class="text-xs text-muted-foreground">{{ row.employee }}</p></div></template>
      <template #cell-overtime_date="{ row }"><span class="inline-flex items-center gap-1"><Clock3 class="size-3" />{{ row.overtime_date }}</span></template>
      <template #cell-hours="{ row }">
        <input type="number" min="0.01" :max="Number(row.source_attendance_hours)" step="0.01" :value="editedHours[Number(row.id)] ?? Number(row.hours)" :disabled="!canApprove" @input="setHours(Number(row.id), $event)" class="h-9 w-28 rounded-md border bg-background px-2 text-center font-mono" aria-label="Editable approved extra-duty hours" />
        <span class="block text-xs text-muted-foreground">Source attendance: {{ Number(row.source_attendance_hours || 0).toFixed(2) }} h</span>
      </template>
      <template #cell-worked_hours="{ row }">{{ Number(row.worked_hours || 0).toFixed(2) }} h</template>
      <template #cell-attendance_status="{ row }"><span class="rounded-full border bg-muted px-2 py-1 text-xs">{{ row.attendance_status }}</span></template>
      <template #cell-actions="{ row }"><div class="flex justify-end gap-2"><Button size="sm" class="bg-emerald-600 hover:bg-emerald-700" :disabled="!canApprove" @click="decide(row as unknown as Row, 'approve')"><Check class="size-4" /> Approve</Button><Button size="icon" variant="destructive" :disabled="!canApprove" title="Reject" @click="decide(row as unknown as Row, 'reject')"><X class="size-4" /></Button></div></template>
    </DataTable>
  </AdminLayout>
</template>
