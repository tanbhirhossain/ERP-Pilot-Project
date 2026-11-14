<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AdminLayout from '@core/Layouts/AdminLayout.vue';
import PageHeader from '@core/Components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { Paginator } from '@core/types';

interface Row {
    id: number; pay_year: number; pay_month: number; status: string; generated_at: string | null; approved_at: string | null;
    employee_count: number; deduction_total: number | string; earning_total: number | string;
    sheet: { id: number; status: string } | null;
}
const props = defineProps<{ cutlists: Paginator<Row>; defaultYear: number; defaultMonth: number; reviewRequired: boolean }>();

const years = Array.from({ length: 11 }, (_, i) => new Date().getFullYear() - 5 + i);
if (!years.includes(props.defaultYear)) years.push(props.defaultYear);
const gen = useForm<{ pay_year: number; pay_month: number; overwrite_adjustments: boolean }>({
    pay_year: years.includes(props.defaultYear) ? props.defaultYear : years[5],
    pay_month: props.defaultMonth,
    overwrite_adjustments: false,
});
const year = ref<number>(gen.pay_year);
const month = ref<number>(gen.pay_month);

const review = ref<{ row: Row; mode: 'approve' | 'reopen' } | null>(null);
const note = ref('');
const noteForm = useForm({ note: '' });
function openReview(row: Row, mode: 'approve' | 'reopen') { review.value = { row, mode }; note.value = ''; noteForm.clearErrors(); }
function submitReview() {
    if (!review.value) return;
    noteForm.note = note.value;
    const routeName = review.value.mode === 'approve' ? 'payroll.cutlists.approve' : 'payroll.cutlists.reopen';
    noteForm.post(route(routeName, review.value.row.id), {
        preserveScroll: true,
        onSuccess: () => { review.value = null; note.value = ''; },
    });
}

const months = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12];
const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
function period(row: { pay_year: number; pay_month: number }) { return `${monthNames[row.pay_month - 1]} ${row.pay_year}`; }
function money(x: unknown) { return Number(x || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
const statusStyle: Record<string, string> = {
    draft: 'bg-muted text-muted-foreground',
    generated: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    approved: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    locked: 'bg-slate-500/15 text-slate-600 dark:text-slate-300',
};
</script>

<template>
    <AdminLayout :breadcrumbs="[{ title: 'Payroll' }, { title: 'Salary Cutlists' }]">
        <Head title="Salary cutlists" />
        <div class="mb-4">
            <PageHeader title="Salary cutlists — review before the sheet is final"
                description="Exactly the employee-level figures the salary sheet will consume, but not final: generate from HRM, adjust what is wrong (every change keeps a reason), then approve." />
        </div>

        <div class="mb-4 rounded-xl border p-4 bg-card">
            <div class="flex flex-wrap items-end gap-3">
                <div><Label class="mb-1 block">Pay month</Label>
                    <select v-model.number="month" @change="gen.pay_month = month" class="h-9 rounded-md border bg-background px-2 text-sm">
                        <option v-for="m in months" :key="m" :value="m">{{ monthNames[m - 1] }}</option>
                    </select>
                </div>
                <div><Label class="mb-1 block">Year</Label>
                    <select v-model.number="year" @change="gen.pay_year = year" class="h-9 rounded-md border bg-background px-2 text-sm">
                        <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                    </select>
                </div>
                <label class="flex items-center gap-2 pb-2 text-sm">
                    <input v-model="gen.overwrite_adjustments" type="checkbox" class="size-4 rounded border" />
                    Overwrite reviewer adjustments if any exist
                </label>
                <Button class="mb-0.5" :disabled="gen.processing" @click="gen.post(route('payroll.cutlists.generate'), { preserveScroll: true, preserveState: true })">
                    {{ gen.processing ? 'Generating…' : 'Generate / rebuild cutlist from HRM' }}
                </Button>
            </div>
            <p v-if="gen.errors.pay_month" class="mt-2 text-sm text-destructive">{{ gen.errors.pay_month }}</p>
            <p v-if="gen.errors.overwrite_adjustments" class="mt-2 text-sm text-destructive">{{ gen.errors.overwrite_adjustments }}</p>
            <p v-if="gen.errors.cutlist" class="mt-2 text-sm text-destructive">{{ gen.errors.cutlist }}</p>
            <p class="mt-2 text-xs text-muted-foreground">Rebuilding replaces all rows with fresh HRM attendance data and erases reviewer adjustments on it (they are kept for history if you approve first and reopen). A cutlist linked to a ready or released salary sheet cannot be rebuilt.</p>
        </div>

        <div class="mb-4 rounded-xl border p-3 text-sm" :class="reviewRequired ? 'border-emerald-500/40 bg-emerald-500/5' : 'bg-muted/30'">
            <template v-if="reviewRequired">Review mode is <strong>required</strong>: Payroll → Settings blocks queueing a salary sheet for a month until its cutlist shows <em>approved</em> here.</template>
            <template v-else>Review mode is <strong>optional</strong>: salary sheets can be queued without an approved cutlist. Turn the gate on in <strong>Payroll → Settings → Cutlist review</strong> to make approval a hard step.</template>
        </div>

        <div class="rounded-xl border bg-card">
            <table class="w-full text-sm">
                <thead><tr class="border-b text-left text-xs uppercase tracking-wide text-muted-foreground">
                    <th class="px-4 py-3">Period</th><th class="px-4 py-3">Status</th><th class="px-4 py-3 text-right">Employees</th>
                    <th class="px-4 py-3 text-right">Deductions</th><th class="px-4 py-3 text-right">OT / extra duty</th>
                    <th class="px-4 py-3">Salary sheet</th><th class="px-4 py-3 text-right">Actions</th>
                </tr></thead>
                <tbody>
                    <tr v-for="row in cutlists.data" :key="row.id" class="border-b last:border-0 hover:bg-muted/30">
                        <td class="px-4 py-3 font-medium">{{ period(row) }}</td>
                        <td class="px-4 py-3"><span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="statusStyle[row.status] || 'bg-muted'">{{ row.status }}</span>
                            <span v-if="row.status === 'generated' && !row.sheet && reviewRequired" class="ml-2 text-xs text-amber-700 dark:text-amber-400">salary sheet blocked until approved</span></td>
                        <td class="px-4 py-3 text-right">{{ row.employee_count }}</td>
                        <td class="px-4 py-3 text-right">{{ money(row.deduction_total) }}</td>
                        <td class="px-4 py-3 text-right">{{ money(row.earning_total) }}</td>
                        <td class="px-4 py-3"><a v-if="row.sheet" :href="route('payroll.sheets.show', row.sheet.id)" class="text-primary underline">{{ row.sheet.status }}</a><span v-else class="text-muted-foreground">—</span></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-2">
                                <a :href="route('payroll.cutlists.show', row.id)" class="text-sm text-primary underline">Review</a>
                                <Button v-if="row.status === 'generated' && row.employee_count > 0" size="sm" @click="openReview(row, 'approve')">Approve</Button>
                                <Button v-if="row.status === 'approved'" size="sm" variant="outline" @click="openReview(row, 'reopen')">Reopen</Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!cutlists.data.length"><td colspan="7" class="px-4 py-8 text-center text-muted-foreground">No cutlists yet. Pick a month above and generate one from HRM attendance data.</td></tr>
                </tbody>
            </table>
        </div>

        <Dialog :open="review !== null" @update:open="(v: boolean) => { if (!v) review = null; }">
            <DialogContent class="sm:max-w-lg">
                <DialogHeader><DialogTitle>{{ review?.mode === 'approve' ? 'Approve cutlist' : 'Reopen cutlist for review' }}</DialogTitle></DialogHeader>
                <p class="text-sm text-muted-foreground">
                    <template v-if="review?.mode === 'approve'">Approving signs off {{ review ? period(review.row) : '' }} as reviewed ({{ review?.employee_count }} employees, deductions {{ money(review?.deduction_total) }}). Its figures are what the salary sheet pays.</template>
                    <template v-else>Reopening unlocks adjustments again. Explain why the sign-off is being withdrawn — it is stored in the activity log message.</template>
                </p>
                <div class="mt-2">
                    <Label>{{ review?.mode === 'approve' ? 'Review note (kept on the cutlist)' : 'Reopen reason' }}</Label>
                    <Textarea v-model="note" class="mt-1" rows="3" maxlength="1000" placeholder="e.g. Two rows corrected per staff-123 leave conflict note; OT for E-045 verified against shift logs." />
                    <p v-if="noteForm.errors.note" class="mt-1 text-sm text-destructive">{{ noteForm.errors.note }}</p>
                </div>
                <DialogFooter>
                    <Button variant="outline" @click="review = null">Cancel</Button>
                    <Button :disabled="noteForm.processing" @click="submitReview">{{ review?.mode === 'approve' ? 'Approve cutlist' : 'Reopen' }}</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AdminLayout>
</template>
