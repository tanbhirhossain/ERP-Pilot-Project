<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import DataTable, { type DataTableColumn } from '@core/Components/DataTable.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { Paginator } from '@core/types';

interface ReviewEntry { at: string; by: number | null; name: string | null; reason: string; changes: Record<string, { from: number; to: number }> }
interface Row {
    id: number; employee_id: string; employee_name: string; unpaid_leave_days: number | string; late_count: number; early_out_count: number;
    punishment_days: number; punishable: boolean; missing_punch_count: number; unpaid_leave_deduction: number | string; late_penalty_deduction: number | string;
    missing_punch_deduction: number | string; approved_overtime_minutes: number; approved_extra_duty_minutes: number; overtime_pay: number | string;
    extra_duty_pay: number | string; source_breakdown: { review?: ReviewEntry[] } | null;
}
const props = defineProps<{ cutlist: { id: number; pay_year: number; pay_month: number; status: string; generated_at: string | null; approved_at: string | null; approval_note: string | null; created_by: number | null }; items: Paginator<Row>; editable: boolean; consumedBySheet: boolean; reviewRequired: boolean }>();

const moneyFields = [
    { key: 'unpaid_leave_deduction', label: 'Unpaid leave deduction' },
    { key: 'late_penalty_deduction', label: 'Punishment deduction' },
    { key: 'missing_punch_deduction', label: 'Missing punch deduction' },
    { key: 'overtime_pay', label: 'Overtime pay' },
    { key: 'extra_duty_pay', label: 'Extra duty pay' },
] as const;

const editing = ref<Row | null>(null);
const adjust = useForm<Record<string, number | string | null | boolean>>({ reason: '', unpaid_leave_deduction: null, late_penalty_deduction: null, missing_punch_deduction: null, overtime_pay: null, extra_duty_pay: null });
function openAdjust(row: Row) {
    editing.value = row;
    adjust.clearErrors();
    for (const f of moneyFields) (adjust as any)[f.key] = Number(row[f.key as keyof Row] ?? 0);
    adjust.reason = '';
}
function submitAdjust() {
    if (!editing.value) return;
    adjust.patch(route('payroll.cutlists.items.update', [props.cutlist.id, editing.value.id]), {
        preserveScroll: true,
        onSuccess: () => { editing.value = null; },
    });
}

const signing = ref<'approve' | 'reopen' | null>(null);
const signForm = useForm({ note: '' });
function submitSign() {
    const name = signing.value === 'approve' ? 'payroll.cutlists.approve' : 'payroll.cutlists.reopen';
    signForm.post(route(name, props.cutlist.id), { preserveScroll: true, onSuccess: () => { signing.value = null; } });
}

const adjustErrorList = computed(() => Object.entries(adjust.errors));
function money(x: unknown) { return Number(x || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function reviews(row: Row): ReviewEntry[] { return Array.isArray(row.source_breakdown?.review) ? row.source_breakdown!.review! : []; }
const columns: DataTableColumn[] = [
    { key: 'employee_name', label: 'Employee', sortable: true },
    { key: 'unpaid_leave_days', label: 'Unpaid leave days', align: 'right', sortable: true },
    { key: 'unpaid_leave_deduction', label: 'Unpaid leave deduction', align: 'right' },
    { key: 'late_count', label: 'Late', align: 'right' },
    { key: 'early_out_count', label: 'Early out', align: 'right' },
    { key: 'punishment_days', label: 'Punishment days', align: 'right', sortable: true },
    { key: 'punishable', label: 'HRM policy', align: 'center' },
    { key: 'late_penalty_deduction', label: 'Punishment deduction', align: 'right' },
    { key: 'missing_punch_count', label: 'Missing punches', align: 'right' },
    { key: 'missing_punch_deduction', label: 'Missing punch deduction', align: 'right' },
    { key: 'approved_overtime_minutes', label: 'Approved OT', align: 'right' },
    { key: 'overtime_pay', label: 'OT pay', align: 'right', sortable: true },
    { key: 'approved_extra_duty_minutes', label: 'Approved extra duty', align: 'right' },
    { key: 'extra_duty_pay', label: 'Extra duty pay', align: 'right', sortable: true },
    { key: 'source_breakdown', label: 'Source detail' },
    { key: 'review_actions', label: 'Review' },
];
</script>
<template><AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Salary Cutlists', href: route('payroll.cutlists.index') }, { title: `Cutlist ${cutlist.pay_year}-${String(cutlist.pay_month).padStart(2, '0')}` }]"><Head title="Salary cutlist review"/><div class="mb-4"><PageHeader :title="`Cutlist review · ${cutlist.pay_year}-${String(cutlist.pay_month).padStart(2, '0')}`" :description="`Status: ${cutlist.status} · generated ${cutlist.generated_at || '—'}${cutlist.approved_at ? ` · approved ${String(cutlist.approved_at).slice(0, 10)}` : ''}`">
    <template #actions>
        <Button v-if="cutlist.status === 'generated'" size="sm" @click="signForm.note = ''; signForm.clearErrors(); signing = 'approve'">Approve for payroll</Button>
        <Button v-if="cutlist.status === 'approved'" size="sm" variant="outline" @click="signForm.note = ''; signForm.clearErrors(); signing = 'reopen'">Reopen review</Button>
        <a :href="route('payroll.sheets.index')" class="ml-2 text-sm text-primary underline">Salary sheets</a>
    </template>
</PageHeader></div>

<div class="mb-4 rounded-xl border bg-muted/30 p-3 text-sm space-y-1">
    <p>Punishment is conditional on employee HRM <code>is_punishable</code>: a non-punishable policy produces <strong>no</strong> late/early-out or missing-punch deduction, and attendance records can never stop its payroll — a salary-history gap for such an employee is paid at the nearest known rate and flagged in the slip breakdown instead. Payroll salary-sheet mode determines whether punishment deductions are applied at all. OT/extra-duty amounts include approved records only.</p>
    <p v-if="editable">This cutlist is <strong>under review — not final</strong>. Use <em>Adjust</em> on a row to override what goes into the salary sheet (reason required, logged per row). Nothing is paid until “Approve for payroll” and the sheet is queued.</p>
    <p v-else-if="cutlist.status === 'approved'" class="text-emerald-700 dark:text-emerald-400">Approved and locked for changes. {{ cutlist.approval_note ? `Sign-off note: ${cutlist.approval_note}` : '' }}</p>
    <p v-else-if="consumedBySheet" class="text-amber-700 dark:text-amber-400">A salary sheet already consumes this cutlist — adjustments are closed. Use Payroll → Sheet Reopens to change paid salaries.</p>
    <p v-else class="text-muted-foreground">Regenerate this month from Payroll → Salary Cutlists to refresh it from HRM attendance data.</p>
</div>

<DataTable :route-name="'payroll.cutlists.show'" :route-params="{ cutlist: cutlist.id }" title="Employee cutlist and approvals" :columns="columns" :rows="items.data" :meta="items.meta" :default-sort="{ key: 'employee_name', direction: 'asc' }">
    <template #cell-employee_name="{ row }"><div><strong>{{ row.employee_name }}</strong><span class="block text-xs text-muted-foreground">{{ row.employee_id }}</span>
        <span v-if="reviews(row).length" class="mt-1 inline-block rounded-full bg-sky-500/15 px-2 py-0.5 text-xs font-semibold text-sky-700 dark:text-sky-400">{{ reviews(row).length }} reviewer adjustment{{ reviews(row).length > 1 ? 's' : '' }}</span></div></template>
    <template #cell-unpaid_leave_deduction="{ row }">{{ money(row.unpaid_leave_deduction) }}</template>
    <template #cell-punishable="{ row }">{{ row.punishable ? 'Yes' : 'No' }}</template>
    <template #cell-late_penalty_deduction="{ row }">{{ money(row.late_penalty_deduction) }}</template>
    <template #cell-missing_punch_deduction="{ row }">{{ money(row.missing_punch_deduction) }}</template>
    <template #cell-approved_overtime_minutes="{ row }">{{ (Number(row.approved_overtime_minutes || 0) / 60).toFixed(2) }} h</template>
    <template #cell-overtime_pay="{ row }">{{ money(row.overtime_pay) }}</template>
    <template #cell-approved_extra_duty_minutes="{ row }">{{ (Number(row.approved_extra_duty_minutes || 0) / 60).toFixed(2) }} h</template>
    <template #cell-extra_duty_pay="{ row }">{{ money(row.extra_duty_pay) }}</template>
    <template #cell-source_breakdown="{ row }"><details class="max-w-72"><summary class="cursor-pointer text-primary">View dates / approvals</summary><pre class="mt-2 max-h-48 overflow-auto whitespace-pre-wrap text-[10px]">{{ JSON.stringify(row.source_breakdown || {}, null, 2) }}</pre></details></template>
    <template #cell-review_actions="{ row }">
        <div class="flex flex-col items-end gap-1">
            <Button v-if="editable" size="sm" variant="outline" @click="openAdjust(row)">Adjust</Button>
            <ul v-if="reviews(row).length" class="text-right text-[11px] text-muted-foreground">
                <li v-for="(r, i) in reviews(row).slice().reverse()" :key="i">{{ r.at?.slice(0, 10) }} · {{ r.name || 'system' }}: {{ r.reason }}</li>
            </ul>
        </div>
    </template>
</DataTable>

<Dialog :open="editing !== null" @update:open="(v: boolean) => { if (!v) editing = null; }">
    <DialogContent class="sm:max-w-lg">
        <DialogHeader><DialogTitle>Adjust cutlist row — {{ editing?.employee_name }}</DialogTitle></DialogHeader>
        <p class="text-sm text-muted-foreground">These are the exact amounts the salary calculation consumes. Leave a field as-is to keep it; every change is logged with your reason.</p>
        <div class="mt-2 grid grid-cols-2 gap-3">
            <div v-for="f in moneyFields" :key="f.key">
                <Label>{{ f.label }}</Label>
                <Input v-model="adjust[f.key]" type="number" min="0" step="0.01" class="mt-1" />
            </div>
        </div>
        <div class="mt-3">
            <Label>Reason (required, kept per row and visible to auditors)</Label>
            <Textarea v-model="adjust.reason" class="mt-1" rows="2" maxlength="1000" placeholder="e.g. Two unapproved late marks waived — shift log SL-88 confirms line changeover." />
        </div>
        <p v-for="(msg, key) in adjustErrorList" :key="key" class="mt-1 text-sm text-destructive">{{ key === 'reason' ? '' : key + ': ' }}{{ msg }}</p>
        <DialogFooter>
            <Button variant="outline" @click="editing = null">Cancel</Button>
            <Button :disabled="adjust.processing" @click="submitAdjust">Save adjustment</Button>
        </DialogFooter>
    </DialogContent>
</Dialog>

<Dialog :open="signing !== null" @update:open="(v: boolean) => { if (!v) signing = null; }">
    <DialogContent class="sm:max-w-lg">
        <DialogHeader><DialogTitle>{{ signing === 'approve' ? 'Approve cutlist for payroll' : 'Reopen cutlist review' }}</DialogTitle></DialogHeader>
        <p class="text-sm text-muted-foreground">{{ signing === 'approve' ? 'Sign-off states that every row above was reviewed and is what payroll will pay. The salary sheet for this month cannot be queued while review is required and this is unapproved.' : 'Reopening unlocks adjustments again. Explain why the sign-off is being withdrawn.' }}</p>
        <div class="mt-2">
            <Label>{{ signing === 'approve' ? 'Review note' : 'Reopen reason' }}</Label>
            <Textarea v-model="signForm.note" class="mt-1" rows="3" maxlength="1000" placeholder="e.g. Verified all 142 rows against attendance register; three adjustments per notes." />
            <p v-if="signForm.errors.note" class="mt-1 text-sm text-destructive">{{ signForm.errors.note }}</p>
        </div>
        <DialogFooter>
            <Button variant="outline" @click="signing = null">Cancel</Button>
            <Button :disabled="signForm.processing" @click="submitSign">{{ signing === 'approve' ? 'Approve' : 'Reopen' }}</Button>
        </DialogFooter>
    </DialogContent>
</Dialog>
</AdminLayout></template>
