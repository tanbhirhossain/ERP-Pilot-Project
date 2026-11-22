<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileDown, Printer } from 'lucide-vue-next';
import { printDocument } from '@core/Composables/usePrint';
import { useReportPdf } from '@core/Composables/useReportPdf';
import { computed, reactive } from 'vue';
import { Check, CheckCheck, X, Timer, Clock3 } from 'lucide-vue-next';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import DataTable, { type BulkAction, type DataTableColumn } from '@core/Components/DataTable.vue';
import type { Paginator } from '@core/types';
import { confirmDialog, promptDialog } from '@core/Composables/useConfirm';
import { useToast } from '@core/Composables/useToast';
import { Button } from '@/components/ui/button';

interface Row {
  id: number; employee_id: string; employee: string; overtime_date: string; overtime_type: string;
  hours: number; source_attendance_hours: number; attendance_overtime_hours: number;
  worked_hours: number; attendance_status: string; status: string;
}
interface Totals {
  days: number; employees: number; approved_hours: number; attendance_hours: number; source_hours: number;
}
interface SheetRow {
  employee_id: string; employee: string; overtime_date: string; overtime_type: string;
  hours: number; source_hours: number; worked_hours: number; attendance_status: string;
}
interface Sheet {
  rows: SheetRow[];
  company: { name: string; address: string; phone: string; email: string; website: string };
  filters: { label: string; value: string }[];
}

const props = defineProps<{
  requests: Paginator<Row>; queueRoute: string; kind: string; canApprove: boolean; filterDefs: any[];
  totals: Totals; sheet: Sheet;
}>();

const editedHours = reactive<Record<number, number>>({});
const toast = useToast();

/* ------------------------------ footer totals ------------------------------ */

/**
 * The footer sums the whole filtered set, not the visible page — a page-local total would change
 * every time the operator pages, which is the opposite of what a total is for.
 */
const totalsRow = computed<Record<string, string | number | null>>(() => ({
  employee_id: `${props.totals.days} day(s) · ${props.totals.employees} employee(s)`,
  overtime_date: null,
  hours: `${Number(props.totals.approved_hours).toFixed(2)} h`,
  worked_hours: `${Number(props.totals.attendance_hours).toFixed(2)} h`,
  attendance_status: null,
  actions: null,
}));

/* ------------------------------- bulk approve ------------------------------ */

const bulkActions = computed<BulkAction[]>(() =>
  props.canApprove ? [{ key: 'approve', label: 'Approve selected', icon: CheckCheck }] : [],
);

function onBulk(action: string, ids: Array<string | number>) {
  if (action !== 'approve') return;

  router.post(
    route('hrm.overtimes.bulk-approve'),
    { ids },
    {
      preserveScroll: true,
      onError: (errors) => toast.error(Object.values(errors).join(' ')),
    },
  );
}
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
      title: `Reject ${row.employee}'s overtime request`,
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
/* ------------------------------ printable sheet ---------------------------- */

/**
 * Print the queue as a document rather than as a screenshot of the screen.
 *
 * The DataTable's own Print button prints the region it marks, which is right for a quick capture but
 * wrong for a report that gets signed: the toolbar is stripped, the columns are whatever the operator
 * had toggled on, and there is nowhere to write a signature. The sheet below is a separate, complete
 * document — letterhead, the filters it was produced under, every row of the current view, the totals,
 * and a signature block — and it is the only thing on the page when `printDocument` runs.
 */
/**
 * Print the sheet, and only the sheet.
 *
 * The table above marks itself as a print region too, so leaving both visible would print the report
 * followed by a screenshot of the live table. `printDocument({ region: true })` opts into isolation;
 * this strips the marker from every other region for the duration of the print and restores it after,
 * so the page keeps working once the dialog closes.
 */
function printSheet() {
  const others = Array.from(document.querySelectorAll('[data-core-print]')).filter(
    (element) => !element.classList.contains('print-sheet'),
  );
  const markers = others.map((element) => element.getAttribute('data-core-print') ?? '');

  others.forEach((element) => element.removeAttribute('data-core-print'));

  const restore = () => {
    others.forEach((element, index) => element.setAttribute('data-core-print', markers[index]));
    window.removeEventListener('afterprint', restore);
  };

  window.addEventListener('afterprint', restore);

  try {
    printDocument({ region: true });
  } finally {
    // The engine waits for fonts before it prints, so the marker must survive that window; it is
    // restored by `afterprint` (or by the timeout below if an engine never fires it).
    window.setTimeout(restore, 8000);
  }
}

const { downloadPdf } = useReportPdf();
const pdfPayload = computed(() => ({
    title: 'Overtime report',
    permission: 'hrm.overtimes.view',
    orientation: 'landscape',
    meta: Object.fromEntries(props.sheet.filters.map((f: any) => [f.label, f.value])),
    headings: ['Employee', 'Date', 'Type', 'Approved hrs', 'Source hrs', 'Worked hrs', 'Attendance'],
    rows: props.sheet.rows.map((r: any) => [r.employee, r.overtime_date, r.overtime_type, r.hours, r.source_hours, r.worked_hours, r.attendance_status]),
    footer: 'Overtime report · HR',
}));

const printedOn = computed(() => new Date().toLocaleString(undefined, { dateStyle: 'long', timeStyle: 'short' }));
const rangeLabel = computed(() => {
  const from = props.sheet.filters.find((f) => f.label === 'From')?.value;
  const to = props.sheet.filters.find((f) => f.label === 'To')?.value;

  if (!from && !to) return 'All pending attendance-derived days';
  return `${from || 'beginning'} → ${to || 'today'}`;
});

async function syncAttendance() {
  const proceed = await confirmDialog({
    title: 'Backfill approval requests',
    description: 'Backfill missing overtime approval requests from all eligible historical daily attendance records?',
    confirmLabel: 'Backfill',
    danger: false,
  });
  if (!proceed) return;
  router.post(route('hrm.overtimes.sync-attendance'), { kind: 'Overtime' }, { preserveScroll: true });
}
</script>

<template>
  <AdminLayout :breadcrumbs="[{ title: 'OT & ED' }, { title: 'Overtime Approval' }]">
    <Head title="Overtime Approval" />
    <div class="flex items-start justify-between gap-4">
      <div>
        <h1 class="flex items-center gap-2 text-xl font-bold"><Timer class="size-5" /> Overtime Approval</h1>
        <p class="mt-1 text-sm text-muted-foreground">Attendance days with `total_overtime_hours` greater than 0.50 hours are loaded automatically for the recent period. Review/edit payable hours; only approved hours enter payroll.</p>
      </div>
      <div class="flex gap-2">
        <Button class="rounded-full" @click="downloadPdf(pdfPayload)"><FileDown class="size-4" /> Download PDF</Button>
        <Button variant="outline" @click="printSheet"><Printer class="size-4" /> Print report</Button>
        <Button v-if="canApprove" variant="outline" @click="syncAttendance">Backfill older attendance</Button>
        <Link :href="route('hrm.attendance.dailies.index')"><Button variant="outline">Daily attendance</Button></Link>
      </div>
    </div>
    <DataTable
      :route-name="queueRoute"
      title="Pending attendance approvals"
      :columns="columns"
      :rows="requests.data"
      :meta="requests.meta"
      :filter-defs="filterDefs"
      :selectable="canApprove"
      :bulk-actions="bulkActions"
      :totals="totalsRow"
      totals-label="Total (filtered)"
      @bulk="onBulk"
    >
      <template #cell-employee_id="{ row }"><div><p class="font-mono font-semibold">{{ row.employee_id }}</p><p class="text-xs text-muted-foreground">{{ row.employee }}</p></div></template>
      <template #cell-overtime_date="{ row }"><span class="inline-flex items-center gap-1"><Clock3 class="size-3" />{{ row.overtime_date }}</span></template>
      <template #cell-hours="{ row }">
        <input type="number" min="0.01" :max="Number(row.source_attendance_hours)" step="0.01" :value="editedHours[Number(row.id)] ?? Number(row.hours)" :disabled="!canApprove" @input="setHours(Number(row.id), $event)" class="h-9 w-28 rounded-md border bg-background px-2 text-center font-mono" aria-label="Editable approved overtime hours" />
        <span class="block text-xs text-muted-foreground">Source OT: {{ Number(row.attendance_overtime_hours || 0).toFixed(2) }} h</span>
      </template>
      <template #cell-worked_hours="{ row }">{{ Number(row.worked_hours || 0).toFixed(2) }} h</template>
      <template #cell-attendance_status="{ row }"><span class="rounded-full border bg-muted px-2 py-1 text-xs">{{ row.attendance_status }}</span></template>
      <template #cell-actions="{ row }"><div class="flex justify-end gap-2"><Button size="sm" class="bg-emerald-600 hover:bg-emerald-700" :disabled="!canApprove" @click="decide(row as unknown as Row, 'approve')"><Check class="size-4" /> Approve</Button><Button size="icon" variant="destructive" :disabled="!canApprove" title="Reject" @click="decide(row as unknown as Row, 'reject')"><X class="size-4" /></Button></div></template>
    </DataTable>

    <!--
      Printable document.

      It carries its own `data-core-print` because region isolation reveals only the *innermost* marked
      element: the DataTable above is itself a print region, and hiding everything except an outer
      wrapper would reveal both — the report, followed by a screenshot of the live table. One region
      per page, and on this page the document is that region.
    -->
    <div class="print-sheet" data-core-print>
      <header class="sheet-head">
        <div>
          <p class="sheet-company">{{ sheet.company.name }}</p>
          <p v-if="sheet.company.address" class="sheet-muted">{{ sheet.company.address }}</p>
          <p class="sheet-muted">
            <span v-if="sheet.company.phone">{{ sheet.company.phone }}</span>
            <span v-if="sheet.company.email"> · {{ sheet.company.email }}</span>
            <span v-if="sheet.company.website"> · {{ sheet.company.website }}</span>
          </p>
        </div>
        <div class="sheet-title">
          <h1>{{ kind === 'Extra Duty' ? 'Extra Duty Approval Report' : 'Overtime Approval Report' }}</h1>
          <p class="sheet-muted">Pending attendance-derived requests</p>
        </div>
      </header>

      <section class="sheet-meta">
        <dl>
          <div><dt>Period</dt><dd>{{ rangeLabel }}</dd></div>
          <div><dt>Records</dt><dd>{{ sheet.rows.length }}</dd></div>
          <div><dt>Employees</dt><dd>{{ totals.employees }}</dd></div>
          <div><dt>Generated</dt><dd>{{ printedOn }}</dd></div>
        </dl>
        <div v-if="sheet.filters.length" class="sheet-filters">
          <span class="sheet-muted">Produced under:</span>
          <span v-for="filter in sheet.filters" :key="filter.label" class="sheet-chip">
            {{ filter.label }}: {{ filter.value }}
          </span>
        </div>
      </section>

      <table class="sheet-table">
        <thead>
          <tr>
            <th class="sheet-num">#</th>
            <th>Employee ID</th>
            <th>Name</th>
            <th>Date</th>
            <th>Type</th>
            <th class="sheet-right">Attendance</th>
            <th class="sheet-right">Source eligible</th>
            <th class="sheet-right">Approved</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in sheet.rows" :key="`${row.employee_id}-${row.overtime_date}`">
            <td class="sheet-num">{{ index + 1 }}</td>
            <td class="sheet-mono">{{ row.employee_id }}</td>
            <td>{{ row.employee }}</td>
            <td class="sheet-mono">{{ row.overtime_date }}</td>
            <td>{{ row.overtime_type }}</td>
            <td class="sheet-right sheet-mono">{{ Number(row.worked_hours || 0).toFixed(2) }}</td>
            <td class="sheet-right sheet-mono">{{ Number(row.source_hours || 0).toFixed(2) }}</td>
            <td class="sheet-right sheet-mono">{{ Number(row.hours || 0).toFixed(2) }}</td>
            <td>{{ row.attendance_status }}</td>
          </tr>
          <tr v-if="sheet.rows.length === 0">
            <td colspan="9" class="sheet-empty">No pending records match the selected filters.</td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="6" class="sheet-right"><strong>Total ({{ totals.days }} days)</strong></td>
            <td class="sheet-right sheet-mono"><strong>{{ Number(totals.source_hours).toFixed(2) }}</strong></td>
            <td class="sheet-right sheet-mono"><strong>{{ Number(totals.approved_hours).toFixed(2) }}</strong></td>
            <td />
          </tr>
        </tfoot>
      </table>

      <p class="sheet-note sheet-muted">
        Attendance hours are the recorded worked time for the day; source eligible hours are the portion
        the attendance engine classified as overtime pay for that day; approved hours are what enters payroll.
        Approval is only final once the signature block below is completed.
      </p>

      <footer class="sheet-signatures">
        <div><span class="sheet-line" /><span class="sheet-muted">Prepared by</span></div>
        <div><span class="sheet-line" /><span class="sheet-muted">Checked by</span></div>
        <div><span class="sheet-line" /><span class="sheet-muted">Approved by</span></div>
      </footer>
    </div>
  </AdminLayout>
</template>
